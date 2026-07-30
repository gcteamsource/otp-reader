<?php

declare(strict_types=1);

namespace Google\Tests;

use Google\Credential;
use Google\CredentialLock;
use Google\CredentialManager;
use Google\CredentialStorage;
use Google\GoogleOAuthClient;
use Mail\EmailMessage;
use Mail\MailReader;
use PHPUnit\Framework\TestCase;

class MailReaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/mail_reader_test_' . uniqid('', true);
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

    public function testMailReaderFacadeWithMarkAsReadAndRetry(): void
    {
        $storage = new CredentialStorage($this->tempDir);
        $lock = new CredentialLock($this->tempDir);
        $oauthClient = new GoogleOAuthClient('client-id', 'client-secret', function (): array {
            return ['status' => 200, 'body' => '{}'];
        });

        $credentialManager = new CredentialManager($storage, $lock, $oauthClient);
        $credentialManager->saveCredential(new Credential(
            email: 'bot@gmail.com',
            accessToken: 'token-abc',
            refreshToken: 'refresh-xyz',
            expiresAt: time() + 3600
        ));

        $markedAsRead = false;

        $mockHttp = function (string $url, string $method, array $headers, array $body) use (&$markedAsRead): array {
            if (str_contains($url, '/messages/msg1/modify')) {
                $markedAsRead = true;
                return ['status' => 200, 'body' => '{}'];
            }

            if (str_contains($url, '/messages?')) {
                return [
                    'status' => 200,
                    'body' => json_encode([
                        'messages' => [['id' => 'msg1']],
                    ], JSON_THROW_ON_ERROR),
                ];
            }

            if (str_contains($url, '/messages/msg1')) {
                $bodyData = rtrim(strtr(base64_encode('Verification passcode: 994821'), '+/', '-_'), '=');
                return [
                    'status' => 200,
                    'body' => json_encode([
                        'id' => 'msg1',
                        'snippet' => 'Verification passcode: 994821',
                        'payload' => [
                            'headers' => [['name' => 'Subject', 'value' => 'OTP Code']],
                            'body' => ['data' => $bodyData],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ];
            }

            return ['status' => 404, 'body' => '{}'];
        };

        $reader = MailReader::createWithGmail($credentialManager, $mockHttp);

        // Test default OTP extraction with simplified parameters and auto mark read
        $otp = $reader->getLatestOtp(
            email: 'bot@gmail.com',
            from: 'auth@service.com',
            afterTime: time() - 300,
            parser: null,
            maxAttempts: 1,
            delaySeconds: 0,
            autoMarkAsRead: true
        );

        $this->assertSame('994821', $otp);
        $this->assertTrue($markedAsRead);

        // Direct markAsRead test
        $readResult = $reader->markAsRead('bot@gmail.com', 'msg1');
        $this->assertTrue($readResult);
    }

    public function testCreateGmailFactoryAndRegisterAccount(): void
    {
        $mockHttp = function (): array {
            return ['status' => 200, 'body' => '{}'];
        };

        $reader = MailReader::createGmail($this->tempDir, 'client-id', 'client-secret', $mockHttp);
        $reader->registerAccount('simple@gmail.com', 'refresh-token-123');

        $driver = $reader->getDriver();
        $this->assertInstanceOf(\Mail\Drivers\GmailDriver::class, $driver);

        $list = $driver->getCredentialManager()->listCredentials();
        $this->assertContains('simple@gmail.com', $list);
    }

    public function testProcessRegistrationWebHandlingPostRequest(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'email' => 'webuser@gmail.com',
            'type' => 'refresh_token',
            'refresh_token' => 'refresh-token-web-99',
        ];

        $mockHttp = function (): array {
            return ['status' => 200, 'body' => '{}'];
        };

        $reader = MailReader::createGmail($this->tempDir, 'client-id', 'client-secret', $mockHttp);

        ob_start();
        $reader->processRegistrationWeb('client-id', 'client-secret');
        $output = ob_get_clean();

        $this->assertStringContainsString('Account webuser@gmail.com registered successfully', $output);
        $this->assertContains('webuser@gmail.com', $reader->getDriver()->getCredentialManager()->listCredentials());

        unset($_SERVER['REQUEST_METHOD'], $_POST);
    }
}
