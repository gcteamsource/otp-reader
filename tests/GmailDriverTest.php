<?php

declare(strict_types=1);

namespace Greatcode\Tests;

use Greatcode\Google\Credential;
use Greatcode\Google\CredentialLock;
use Greatcode\Google\CredentialManager;
use Greatcode\Google\CredentialStorage;
use Greatcode\Google\GoogleOAuthClient;
use Greatcode\Mail\Drivers\GmailDriver;
use Greatcode\Mail\EmailMessage;
use PHPUnit\Framework\TestCase;

class GmailDriverTest extends TestCase
{
    private string $tempDir;
    private CredentialManager $credentialManager;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/gmail_driver_test_' . uniqid('', true);
        $storage = new CredentialStorage($this->tempDir);
        $lock = new CredentialLock($this->tempDir);
        $oauthClient = new GoogleOAuthClient('client-id', 'client-secret', function (): array {
            return ['status' => 200, 'body' => '{}'];
        });

        $this->credentialManager = new CredentialManager($storage, $lock, $oauthClient);
        $this->credentialManager->saveCredential(new Credential(
            email: 'user@gmail.com',
            accessToken: 'valid-access-token',
            refreshToken: 'valid-refresh-token',
            expiresAt: time() + 3600
        ));
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

    public function testListAndGetMessage(): void
    {
        $mockHttp = function (string $url, string $method, array $headers): array {
            $this->assertSame('Bearer valid-access-token', $headers['Authorization']);

            if (str_contains($url, '/messages?')) {
                return [
                    'status' => 200,
                    'body' => json_encode([
                        'messages' => [
                            ['id' => 'msg101', 'threadId' => 't101'],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ];
            }

            if (str_contains($url, '/messages/msg101?')) {
                // Base64Url encoded "Your OTP code is 654321"
                $bodyData = rtrim(strtr(base64_encode('Your OTP code is 654321'), '+/', '-_'), '=');

                return [
                    'status' => 200,
                    'body' => json_encode([
                        'id' => 'msg101',
                        'threadId' => 't101',
                        'snippet' => 'Your OTP code is 654321',
                        'internalDate' => 1750000000000,
                        'payload' => [
                            'headers' => [
                                ['name' => 'Subject', 'value' => 'Login Code'],
                                ['name' => 'From', 'value' => 'auth@service.com'],
                                ['name' => 'To', 'value' => 'user@gmail.com'],
                                ['name' => 'Date', 'value' => 'Thu, 30 Jul 2026 20:00:00 GMT'],
                            ],
                            'body' => ['data' => $bodyData],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ];
            }

            return ['status' => 404, 'body' => '{}'];
        };

        $driver = new GmailDriver($this->credentialManager, $mockHttp);
        $messages = $driver->listMessages('user@gmail.com', ['query' => 'is:unread']);

        $this->assertCount(1, $messages);
        $msg = $messages[0];
        $this->assertSame('msg101', $msg->getId());
        $this->assertSame('Login Code', $msg->getSubject());
        $this->assertSame('auth@service.com', $msg->getFrom());
        $this->assertSame('Your OTP code is 654321', $msg->getSnippet());
        $this->assertSame('Your OTP code is 654321', $msg->getBody());
    }

    public function testMarkAsRead(): void
    {
        $markCalled = false;
        $mockHttp = function (string $url, string $method, array $headers, array $body) use (&$markCalled): array {
            if (str_contains($url, '/messages/msg999/modify')) {
                $markCalled = true;
                $this->assertSame('POST', $method);
                $this->assertSame(['removeLabelIds' => ['UNREAD']], $body);
                return ['status' => 200, 'body' => '{}'];
            }
            return ['status' => 404, 'body' => '{}'];
        };

        $driver = new GmailDriver($this->credentialManager, $mockHttp);
        $success = $driver->markAsRead('user@gmail.com', 'msg999');

        $this->assertTrue($success);
        $this->assertTrue($markCalled);
    }

    public function testGetLatestOtpAutomaticallyMarksAsRead(): void
    {
        $markReadCalled = false;

        $mockHttp = function (string $url, string $method, array $headers, array $body) use (&$markReadCalled): array {
            if (str_contains($url, '/messages/otpMsg/modify')) {
                $markReadCalled = true;
                $this->assertSame(['removeLabelIds' => ['UNREAD']], $body);
                return ['status' => 200, 'body' => '{}'];
            }

            if (str_contains($url, '/messages?')) {
                $this->assertStringContainsString('from%3Atokopedia', $url);
                $this->assertStringContainsString('after%3A1750000000', $url);

                return [
                    'status' => 200,
                    'body' => json_encode([
                        'messages' => [
                            ['id' => 'otpMsg', 'threadId' => 'tOtp'],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ];
            }

            if (str_contains($url, '/messages/otpMsg?')) {
                $bodyData = rtrim(strtr(base64_encode('Kode verifikasi Anda 771920.'), '+/', '-_'), '=');
                return [
                    'status' => 200,
                    'body' => json_encode([
                        'id' => 'otpMsg',
                        'snippet' => 'Kode verifikasi Anda 771920.',
                        'payload' => [
                            'headers' => [['name' => 'Subject', 'value' => 'OTP']],
                            'body' => ['data' => $bodyData],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ];
            }

            return ['status' => 404, 'body' => '{}'];
        };

        $driver = new GmailDriver($this->credentialManager, $mockHttp);
        $otp = $driver->getLatestOtp(
            email: 'user@gmail.com',
            from: 'tokopedia',
            afterTime: 1750000000,
            maxAttempts: 1,
            delaySeconds: 0,
            autoMarkAsRead: true
        );

        $this->assertSame('771920', $otp);
        $this->assertTrue($markReadCalled, 'Message should automatically be marked as read after OTP retrieval.');
    }

    public function testGetLatestOtpRetryPollingWhenEmptyInitially(): void
    {
        $attemptsCount = 0;

        $mockHttp = function (string $url) use (&$attemptsCount): array {
            if (str_contains($url, '/messages?')) {
                $attemptsCount++;
                if ($attemptsCount === 1) {
                    // First attempt: no messages received yet
                    return ['status' => 200, 'body' => json_encode(['messages' => []], JSON_THROW_ON_ERROR)];
                }
                // Second attempt: email arrives
                return [
                    'status' => 200,
                    'body' => json_encode(['messages' => [['id' => 'retryMsg']]], JSON_THROW_ON_ERROR),
                ];
            }

            if (str_contains($url, '/messages/retryMsg')) {
                $bodyData = rtrim(strtr(base64_encode('Your code is 302194.'), '+/', '-_'), '=');
                return [
                    'status' => 200,
                    'body' => json_encode([
                        'id' => 'retryMsg',
                        'snippet' => 'Your code is 302194.',
                        'payload' => [
                            'headers' => [['name' => 'Subject', 'value' => 'Code']],
                            'body' => ['data' => $bodyData],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ];
            }

            return ['status' => 200, 'body' => '{}'];
        };

        $driver = new GmailDriver($this->credentialManager, $mockHttp);

        $otp = $driver->getLatestOtp(
            email: 'user@gmail.com',
            from: 'service.com',
            afterTime: null,
            parser: null,
            maxAttempts: 3,
            delaySeconds: 0,
            autoMarkAsRead: false
        );

        $this->assertSame('302194', $otp);
        $this->assertSame(2, $attemptsCount, 'Should retry polling and succeed on attempt 2.');
    }
}
