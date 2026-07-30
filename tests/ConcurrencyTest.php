<?php

declare(strict_types=1);

namespace Greatcode\OtpReader\Tests;

use Greatcode\OtpReader\Google\Credential;
use Greatcode\OtpReader\Google\CredentialLock;
use Greatcode\OtpReader\Google\CredentialManager;
use Greatcode\OtpReader\Google\CredentialStorage;
use Greatcode\OtpReader\Google\GoogleOAuthClient;
use PHPUnit\Framework\TestCase;

class ConcurrencyTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/google_concurrency_test_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            @rmdir($this->tempDir);
        }
    }

    public function testConcurrentGetValidCredentialPreventsDoubleRefresh(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension is not available for process forking concurrency test.');
        }

        $email = 'concurrent@gmail.com';
        $storage = new CredentialStorage($this->tempDir);
        $lock = new CredentialLock($this->tempDir);

        // Save expired credential
        $expiredCredential = new Credential(
            email: $email,
            accessToken: 'initial-access-token',
            refreshToken: 'refresh-token-123',
            expiresAt: time() - 100
        );
        $storage->save($expiredCredential);

        // File to log refresh calls across child processes
        $logFile = $this->tempDir . '/refresh_calls.log';

        $mockHttp = function (string $url, string $method, array $headers, array $params) use ($logFile): array {
            // Simulate network latency during refresh
            usleep(200000); // 200ms

            file_put_contents($logFile, "refreshed\n", FILE_APPEND | LOCK_EX);

            return [
                'status' => 200,
                'body' => json_encode([
                    'access_token' => 'refreshed-token-' . getmypid(),
                    'expires_in' => 3600,
                ], JSON_THROW_ON_ERROR),
            ];
        };

        // Fork 3 child processes that attempt getValidCredential concurrently
        $pids = [];
        for ($i = 0; $i < 3; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Could not fork process.');
            } elseif ($pid === 0) {
                // Child process: prevent PHPUnit shutdown functions from executing twice
                try {
                    $childClient = new GoogleOAuthClient('client-id', 'client-secret', $mockHttp);
                    $childManager = new CredentialManager($storage, $lock, $childClient);
                    $childManager->getValidCredential($email);
                } finally {
                    posix_kill(getmypid(), SIGKILL);
                }
            } else {
                $pids[] = $pid;
            }
        }

        // Parent waits for all child processes to exit
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        // Verify refresh token endpoint was executed EXACTLY ONCE
        $refreshCalls = file_exists($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        $this->assertCount(1, $refreshCalls, 'Expected token refresh to be executed exactly once despite 3 concurrent processes.');

        // Verify final stored credential is valid and refreshed
        $finalCred = $storage->load($email);
        $this->assertFalse($finalCred->isExpired());
        $this->assertStringStartsWith('refreshed-token-', $finalCred->getAccessToken());
    }
}
