<?php

declare(strict_types=1);

namespace Greatcode\OtpReader\Google;

use Greatcode\OtpReader\Google\Exceptions\CredentialException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Primary service orchestrator for managing Google OAuth Credentials.
 * Applications interact exclusively with this class.
 */
class CredentialManager
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly CredentialStorage $storage,
        private readonly CredentialLock $lock,
        private readonly GoogleOAuthClient $oauthClient,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * One-line static factory helper to initialize CredentialManager without manual object creation.
     */
    public static function create(
        string $storageDirectory,
        string $clientId,
        string $clientSecret,
        mixed $httpHandler = null,
        ?LoggerInterface $logger = null
    ): self {
        $storage = new CredentialStorage($storageDirectory, $logger);
        $lock = new CredentialLock($storageDirectory);
        $oauthClient = new GoogleOAuthClient($clientId, $clientSecret, $httpHandler);

        return new self($storage, $lock, $oauthClient, $logger);
    }

    public function getStorage(): CredentialStorage
    {
        return $this->storage;
    }

    public function getLock(): CredentialLock
    {
        return $this->lock;
    }

    public function getOauthClient(): GoogleOAuthClient
    {
        return $this->oauthClient;
    }

    /**
     * Get a valid Credential for the specified email.
     * Automatically acquires exclusive lock, checks expiration, refreshes token if expired, and saves update atomically.
     *
     * @param string $email Account email address
     * @param int $marginSeconds Safety buffer in seconds before token actual expiration time (default 60s)
     * @return Credential Valid non-expired Credential
     * @throws CredentialException
     */
    public function getValidCredential(string $email, int $marginSeconds = 60): Credential
    {
        return $this->lock->exclusive($email, function () use ($email, $marginSeconds): Credential {
            // Step 1: Load credential inside LOCK_EX
            $credential = $this->storage->load($email);

            // Step 2: Check if expired
            if (!$credential->isExpired($marginSeconds)) {
                return $credential;
            }

            // Step 3: Refresh token inside LOCK_EX (prevents race condition across containers)
            $this->logger->info('Refreshing token', ['email' => $email]);

            $refreshResult = $this->oauthClient->refreshAccessToken($credential->getRefreshToken());

            $newAccessToken = $refreshResult['access_token'];
            $newExpiresAt = time() + $refreshResult['expires_in'];
            $newScope = !empty($refreshResult['scope']) ? $refreshResult['scope'] : null;
            $newRefreshToken = $refreshResult['refresh_token'] ?? null;

            $updatedCredential = $credential->withRefreshedToken(
                newAccessToken: $newAccessToken,
                newExpiresAt: $newExpiresAt,
                newScope: $newScope,
                newRefreshToken: $newRefreshToken
            );

            // Step 4: Save updated credential atomically
            $this->storage->save($updatedCredential);

            return $updatedCredential;
        });
    }

    /**
     * Force refresh token for specified email.
     *
     * @throws CredentialException
     */
    public function refreshCredential(string $email): Credential
    {
        return $this->lock->exclusive($email, function () use ($email): Credential {
            $credential = $this->storage->load($email);

            $this->logger->info('Refreshing token', ['email' => $email]);

            $refreshResult = $this->oauthClient->refreshAccessToken($credential->getRefreshToken());

            $newAccessToken = $refreshResult['access_token'];
            $newExpiresAt = time() + $refreshResult['expires_in'];
            $newScope = !empty($refreshResult['scope']) ? $refreshResult['scope'] : null;
            $newRefreshToken = $refreshResult['refresh_token'] ?? null;

            $updatedCredential = $credential->withRefreshedToken(
                newAccessToken: $newAccessToken,
                newExpiresAt: $newExpiresAt,
                newScope: $newScope,
                newRefreshToken: $newRefreshToken
            );

            $this->storage->save($updatedCredential);

            return $updatedCredential;
        });
    }

    /**
     * Exchange an authorization code for initial tokens and store the Credential.
     *
     * @throws CredentialException
     */
    public function authorizeCode(string $email, string $code, string $redirectUri = 'urn:ietf:wg:oauth:2.0:oob'): Credential
    {
        return $this->lock->exclusive($email, function () use ($email, $code, $redirectUri): Credential {
            $this->logger->info('Exchanging authorization code', ['email' => $email]);

            $tokens = $this->oauthClient->exchangeAuthorizationCode($code, $redirectUri);

            $now = time();
            $expiresAt = $now + $tokens['expires_in'];

            $credential = new Credential(
                email: $email,
                accessToken: $tokens['access_token'],
                refreshToken: $tokens['refresh_token'],
                expiresAt: $expiresAt,
                scope: $tokens['scope'],
                createdAt: $now,
                updatedAt: $now
            );

            $this->storage->save($credential);

            return $credential;
        });
    }

    /**
     * Save or overwrite a Credential directly under lock.
     *
     * @throws CredentialException
     */
    public function saveCredential(Credential $credential): void
    {
        $email = $credential->getEmail();
        $this->lock->exclusive($email, function () use ($credential): void {
            $this->storage->save($credential);
        });
    }

    /**
     * Delete credential for email.
     *
     * @throws CredentialException
     */
    public function deleteCredential(string $email): void
    {
        $this->lock->exclusive($email, function () use ($email): void {
            $this->storage->delete($email);
        });
    }

    /**
     * List all stored credential emails.
     *
     * @return string[]
     */
    public function listCredentials(): array
    {
        return $this->storage->list();
    }
}
