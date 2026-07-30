<?php

declare(strict_types=1);

namespace Mail;

use DateTimeInterface;
use Google\Credential;
use Google\CredentialManager;
use Mail\Drivers\GmailDriver;
use Mail\Exceptions\MailReaderException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * High-level Mail Reader Facade for applications and scraping scripts.
 */
class MailReader
{
    public function __construct(
        private readonly MailDriverInterface $driver
    ) {
    }

    /**
     * One-line factory helper to create MailReader with Gmail Driver without manual object instantiation.
     */
    public static function createGmail(
        string $storageDirectory,
        string $clientId,
        string $clientSecret,
        mixed $httpHandler = null,
        ?LoggerInterface $logger = null
    ): self {
        $credentialManager = CredentialManager::create($storageDirectory, $clientId, $clientSecret, $httpHandler, $logger);
        return self::createWithGmail($credentialManager, $httpHandler);
    }

    /**
     * Factory helper to instantiate MailReader with Gmail Driver using an existing CredentialManager.
     */
    public static function createWithGmail(CredentialManager $credentialManager, mixed $httpHandler = null): self
    {
        return new self(new GmailDriver($credentialManager, $httpHandler));
    }

    /**
     * One-line static helper to handle web registration HTTP requests and render UI automatically.
     */
    public static function handleRegistration(
        string $storageDirectory,
        string $clientId,
        string $clientSecret,
        mixed $httpHandler = null
    ): void {
        $reader = self::createGmail($storageDirectory, $clientId, $clientSecret, $httpHandler);
        $reader->processRegistrationWeb($clientId, $clientSecret);
    }

    /**
     * Handle web registration HTTP requests and render UI automatically on an existing MailReader instance.
     */
    public function processRegistrationWeb(string $clientId = '', string $clientSecret = ''): void
    {
        $successMessage = null;
        $errorMessage = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $email = trim($_POST['email'] ?? '');
            $type = trim($_POST['type'] ?? 'refresh_token');
            $refreshToken = trim($_POST['refresh_token'] ?? '');
            $authCode = trim($_POST['code'] ?? '');

            if ($email === '') {
                $errorMessage = 'Email address is required.';
            } elseif ($type === 'refresh_token' && $refreshToken === '') {
                $errorMessage = 'Refresh token is required.';
            } elseif ($type === 'auth_code' && $authCode === '') {
                $errorMessage = 'Authorization code is required.';
            } else {
                try {
                    if ($type === 'refresh_token') {
                        $this->registerAccount($email, $refreshToken);
                        $successMessage = "Account {$email} registered successfully with refresh token!";
                    } else {
                        if ($this->driver instanceof GmailDriver) {
                            $this->driver->getCredentialManager()->authorizeCode($email, $authCode);
                        } else {
                            throw new MailReaderException('Auth code registration is only supported on GmailDriver.');
                        }
                        $successMessage = "Account {$email} authorized successfully using Google Auth Code!";
                    }
                } catch (Throwable $e) {
                    $errorMessage = 'Failed to register account: ' . $e->getMessage();
                }
            }
        }

        $authUrl = '';
        if ($clientId !== '' && $clientId !== 'YOUR_GOOGLE_CLIENT_ID' && $clientId !== 'YOUR_CLIENT_ID') {
            $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                'client_id' => $clientId,
                'redirect_uri' => 'urn:ietf:wg:oauth:2.0:oob',
                'response_type' => 'code',
                'scope' => 'https://www.googleapis.com/auth/gmail.readonly',
                'access_type' => 'offline',
                'prompt' => 'consent',
            ]);
        }

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
        }
        echo OAuthUiRenderer::renderRegisterForm($successMessage, $errorMessage, $authUrl);
    }

    /**
     * One-line helper to register or update an account's refresh token.
     *
     * @throws MailReaderException
     */
    public function registerAccount(string $email, string $refreshToken): void
    {
        if ($this->driver instanceof GmailDriver) {
            $credential = new Credential(
                email: $email,
                accessToken: '',
                refreshToken: $refreshToken,
                expiresAt: 0
            );
            $this->driver->getCredentialManager()->saveCredential($credential);
            return;
        }

        throw new MailReaderException('registerAccount is currently only supported on GmailDriver.');
    }

    /**
     * Get underlying driver instance.
     */
    public function getDriver(): MailDriverInterface
    {
        return $this->driver;
    }

    /**
     * List messages matching search filter options.
     *
     * @param string $email Account email
     * @param array{query?: string, maxResults?: int, includeSpamTrash?: bool} $options Search options
     * @return EmailMessage[]
     * @throws MailReaderException
     */
    public function listMessages(string $email, array $options = []): array
    {
        return $this->driver->listMessages($email, $options);
    }

    /**
     * Get full details of a specific email message.
     *
     * @throws MailReaderException
     */
    public function getMessage(string $email, string $messageId): EmailMessage
    {
        return $this->driver->getMessage($email, $messageId);
    }

    /**
     * Mark an email message as read.
     *
     * @throws MailReaderException
     */
    public function markAsRead(string $email, string $messageId): bool
    {
        return $this->driver->markAsRead($email, $messageId);
    }

    /**
     * Read latest OTP verification code with retry polling, delay, and automatic mark-as-read.
     *
     * @param string $email Account email (e.g. 'user@gmail.com')
     * @param string|null $from Sender email or domain filter (e.g. 'auth@service.com' or 'tokopedia')
     * @param int|DateTimeInterface|string|null $afterTime Filter emails received after this time
     * @param (callable(string $body, EmailMessage $message): ?string)|null $parser Custom parser callable receiving (body, message)
     * @param int $maxAttempts Maximum polling attempts if no message/OTP is found initially (default: 5)
     * @param int $delaySeconds Delay in seconds between polling attempts (default: 2)
     * @param bool $autoMarkAsRead Automatically mark message as read after successfully retrieving OTP (default: true)
     * @return string|null Extracted OTP code or null if not found after max attempts
     * @throws MailReaderException
     */
    public function getLatestOtp(
        string $email,
        ?string $from = null,
        int|DateTimeInterface|string|null $afterTime = null,
        ?callable $parser = null,
        int $maxAttempts = 5,
        int $delaySeconds = 2,
        bool $autoMarkAsRead = true
    ): ?string {
        return $this->driver->getLatestOtp(
            email: $email,
            from: $from,
            afterTime: $afterTime,
            parser: $parser,
            maxAttempts: $maxAttempts,
            delaySeconds: $delaySeconds,
            autoMarkAsRead: $autoMarkAsRead
        );
    }
}
