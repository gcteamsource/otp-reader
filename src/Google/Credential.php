<?php

declare(strict_types=1);

namespace Greatcode\OtpReader\Google;

use InvalidArgumentException;

/**
 * Value Object representing Google OAuth Credential data.
 * Pure data object with no side effects or external dependencies.
 */
final class Credential
{
    /**
     * @param string[] $scope
     */
    public function __construct(
        private readonly string $email,
        private readonly string $accessToken,
        private readonly string $refreshToken,
        private readonly int $expiresAt,
        private readonly array $scope = [],
        private readonly int $createdAt = 0,
        private readonly int $updatedAt = 0
    ) {
        if (trim($this->email) === '') {
            throw new InvalidArgumentException('Email cannot be empty.');
        }
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    public function getRefreshToken(): string
    {
        return $this->refreshToken;
    }

    public function getExpiresAt(): int
    {
        return $this->expiresAt;
    }

    /**
     * @return string[]
     */
    public function getScope(): array
    {
        return $this->scope;
    }

    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): int
    {
        return $this->updatedAt;
    }

    /**
     * Checks if the access token is expired or about to expire within the given margin in seconds.
     */
    public function isExpired(int $marginSeconds = 0): bool
    {
        return (time() + $marginSeconds) >= $this->expiresAt;
    }

    /**
     * Return a new Credential instance with updated access token, expiration, and scope.
     */
    public function withRefreshedToken(
        string $newAccessToken,
        int $newExpiresAt,
        ?array $newScope = null,
        ?string $newRefreshToken = null
    ): self {
        $now = time();
        return new self(
            email: $this->email,
            accessToken: $newAccessToken,
            refreshToken: $newRefreshToken ?? $this->refreshToken,
            expiresAt: $newExpiresAt,
            scope: $newScope ?? $this->scope,
            createdAt: $this->createdAt > 0 ? $this->createdAt : $now,
            updatedAt: $now
        );
    }

    /**
     * Convert Credential object into a serializable array format for JSON file storage.
     *
     * @return array{version: int, created_at: int, updated_at: int, credential: array{email: string, access_token: string, refresh_token: string, expires_at: int, scope: string[]}}
     */
    public function toArray(): array
    {
        $now = time();
        $created = $this->createdAt > 0 ? $this->createdAt : $now;
        $updated = $this->updatedAt > 0 ? $this->updatedAt : $now;

        return [
            'version' => 1,
            'created_at' => $created,
            'updated_at' => $updated,
            'credential' => [
                'email' => $this->email,
                'access_token' => $this->accessToken,
                'refresh_token' => $this->refreshToken,
                'expires_at' => $this->expiresAt,
                'scope' => array_values($this->scope),
            ],
        ];
    }

    /**
     * Instantiate Credential from JSON data structure.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['credential']) || !is_array($data['credential'])) {
            throw new InvalidArgumentException('Invalid JSON payload structure: missing "credential" array.');
        }

        $cred = $data['credential'];
        $createdAt = (int) ($data['created_at'] ?? 0);
        $updatedAt = (int) ($data['updated_at'] ?? 0);

        if (empty($cred['email']) || !is_string($cred['email'])) {
            throw new InvalidArgumentException('Invalid or missing "email" in credential data.');
        }

        return new self(
            email: $cred['email'],
            accessToken: (string) ($cred['access_token'] ?? ''),
            refreshToken: (string) ($cred['refresh_token'] ?? ''),
            expiresAt: (int) ($cred['expires_at'] ?? 0),
            scope: is_array($cred['scope'] ?? null) ? array_values($cred['scope']) : [],
            createdAt: $createdAt,
            updatedAt: $updatedAt
        );
    }
}
