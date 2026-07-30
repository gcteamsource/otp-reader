<?php

declare(strict_types=1);

namespace Google\Tests;

use Google\Credential;
use Google\CredentialLock;
use Google\CredentialManager;
use Google\CredentialStorage;
use Google\GoogleOAuthClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

class CredentialManagerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/google_manager_test_' . uniqid('', true);
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

    public function testGetValidCredentialReturnsExistingIfValid(): void
    {
        $storage = new CredentialStorage($this->tempDir);
        $lock = new CredentialLock($this->tempDir);

        $refreshCalled = false;
        $mockHttp = function () use (&$refreshCalled): array {
            $refreshCalled = true;
            return ['status' => 200, 'body' => '{}'];
        };

        $oauthClient = new GoogleOAuthClient('client-id', 'client-secret', $mockHttp);
        $manager = new CredentialManager($storage, $lock, $oauthClient);

        // Save valid credential (expires in 3600s)
        $credential = new Credential(
            email: 'active@gmail.com',
            accessToken: 'current-token',
            refreshToken: 'refresh-token',
            expiresAt: time() + 3600
        );
        $manager->saveCredential($credential);

        $valid = $manager->getValidCredential('active@gmail.com');

        $this->assertSame('current-token', $valid->getAccessToken());
        $this->assertFalse($refreshCalled, 'Refresh should not be triggered for non-expired credentials.');
    }

    public function testGetValidCredentialRefreshesTokenIfExpired(): void
    {
        $storage = new CredentialStorage($this->tempDir);
        $lock = new CredentialLock($this->tempDir);

        $refreshCount = 0;
        $mockHttp = function (string $url, string $method, array $headers, array $params) use (&$refreshCount): array {
            $refreshCount++;
            return [
                'status' => 200,
                'body' => json_encode([
                    'access_token' => 'refreshed-access-token',
                    'expires_in' => 3600,
                    'scope' => 'email profile',
                ], JSON_THROW_ON_ERROR),
            ];
        };

        $oauthClient = new GoogleOAuthClient('client-id', 'client-secret', $mockHttp);
        $manager = new CredentialManager($storage, $lock, $oauthClient);

        // Save expired credential
        $expiredCredential = new Credential(
            email: 'expired@gmail.com',
            accessToken: 'old-access-token',
            refreshToken: 'valid-refresh-token',
            expiresAt: time() - 100
        );
        $manager->saveCredential($expiredCredential);

        $valid = $manager->getValidCredential('expired@gmail.com');

        $this->assertSame('refreshed-access-token', $valid->getAccessToken());
        $this->assertSame(1, $refreshCount);
        $this->assertFalse($valid->isExpired());
    }

    public function testAuthorizeCodeSavesNewCredential(): void
    {
        $storage = new CredentialStorage($this->tempDir);
        $lock = new CredentialLock($this->tempDir);

        $mockHttp = function (): array {
            return [
                'status' => 200,
                'body' => json_encode([
                    'access_token' => 'new-auth-token',
                    'refresh_token' => 'new-refresh-token',
                    'expires_in' => 3600,
                    'scope' => 'https://www.googleapis.com/auth/gmail.readonly',
                ], JSON_THROW_ON_ERROR),
            ];
        };

        $oauthClient = new GoogleOAuthClient('client-id', 'client-secret', $mockHttp);
        $manager = new CredentialManager($storage, $lock, $oauthClient);

        $cred = $manager->authorizeCode('newuser@gmail.com', 'auth_code_123');

        $this->assertSame('newuser@gmail.com', $cred->getEmail());
        $this->assertSame('new-auth-token', $cred->getAccessToken());
        $this->assertSame('new-refresh-token', $cred->getRefreshToken());

        // Verify stored in storage
        $stored = $storage->load('newuser@gmail.com');
        $this->assertSame('new-auth-token', $stored->getAccessToken());
    }

    public function testLoggerDoesNotLogSensitiveTokens(): void
    {
        $logMessages = [];
        $logger = new class($logMessages) extends AbstractLogger {
            public function __construct(public array &$messages) {}
            public function log($level, string|\Stringable $message, array $context = []): void {
                $this->messages[] = ['message' => (string)$message, 'context' => $context];
            }
        };

        $storage = new CredentialStorage($this->tempDir, $logger);
        $lock = new CredentialLock($this->tempDir);

        $secretAccessToken = 'secret-ya29-access-token-12345';
        $secretRefreshToken = 'secret-1//refresh-token-67890';

        $mockHttp = function () use ($secretAccessToken): array {
            return [
                'status' => 200,
                'body' => json_encode([
                    'access_token' => $secretAccessToken,
                    'expires_in' => 3600,
                ], JSON_THROW_ON_ERROR),
            ];
        };

        $oauthClient = new GoogleOAuthClient('client-id', 'client-secret', $mockHttp);
        $manager = new CredentialManager($storage, $lock, $oauthClient, $logger);

        $credential = new Credential(
            email: 'secret@gmail.com',
            accessToken: 'old-token',
            refreshToken: $secretRefreshToken,
            expiresAt: time() - 10
        );

        $manager->saveCredential($credential);
        $manager->getValidCredential('secret@gmail.com');

        foreach ($logger->messages as $entry) {
            $msg = $entry['message'];
            $contextStr = json_encode($entry['context']);

            $this->assertStringNotContainsString($secretAccessToken, $msg);
            $this->assertStringNotContainsString($secretAccessToken, (string) $contextStr);
            $this->assertStringNotContainsString($secretRefreshToken, $msg);
            $this->assertStringNotContainsString($secretRefreshToken, (string) $contextStr);
        }
    }
}
