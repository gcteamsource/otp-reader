<?php

declare(strict_types=1);

namespace Greatcode\Tests;

use Greatcode\Google\Credential;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CredentialTest extends TestCase
{
    public function testCredentialGettersAndIsExpired(): void
    {
        $now = time();
        $expiresAt = $now + 3600;

        $credential = new Credential(
            email: 'user@gmail.com',
            accessToken: 'ya29.access-token-123',
            refreshToken: '1//refresh-token-456',
            expiresAt: $expiresAt,
            scope: ['https://www.googleapis.com/auth/gmail.readonly'],
            createdAt: $now,
            updatedAt: $now
        );

        $this->assertSame('user@gmail.com', $credential->getEmail());
        $this->assertSame('ya29.access-token-123', $credential->getAccessToken());
        $this->assertSame('1//refresh-token-456', $credential->getRefreshToken());
        $this->assertSame($expiresAt, $credential->getExpiresAt());
        $this->assertSame(['https://www.googleapis.com/auth/gmail.readonly'], $credential->getScope());
        $this->assertSame($now, $credential->getCreatedAt());
        $this->assertSame($now, $credential->getUpdatedAt());
        $this->assertFalse($credential->isExpired());
        $this->assertFalse($credential->isExpired(60));
    }

    public function testCredentialIsExpiredWithMargin(): void
    {
        $now = time();
        $expiresAt = $now + 30; // expires in 30s

        $credential = new Credential(
            email: 'user@gmail.com',
            accessToken: 'access-token',
            refreshToken: 'refresh-token',
            expiresAt: $expiresAt
        );

        $this->assertFalse($credential->isExpired(0));
        $this->assertTrue($credential->isExpired(60)); // margin 60s -> 30s remaining is considered expired
    }

    public function testWithRefreshedToken(): void
    {
        $now = time();
        $credential = new Credential(
            email: 'user@gmail.com',
            accessToken: 'old-access-token',
            refreshToken: 'refresh-token',
            expiresAt: $now - 100,
            scope: ['scope1'],
            createdAt: $now - 3600,
            updatedAt: $now - 3600
        );

        $refreshed = $credential->withRefreshedToken(
            newAccessToken: 'new-access-token',
            newExpiresAt: $now + 3600,
            newScope: ['scope1', 'scope2']
        );

        $this->assertSame('user@gmail.com', $refreshed->getEmail());
        $this->assertSame('new-access-token', $refreshed->getAccessToken());
        $this->assertSame('refresh-token', $refreshed->getRefreshToken());
        $this->assertSame($now + 3600, $refreshed->getExpiresAt());
        $this->assertSame(['scope1', 'scope2'], $refreshed->getScope());
        $this->assertSame($now - 3600, $refreshed->getCreatedAt());
        $this->assertGreaterThanOrEqual($now, $refreshed->getUpdatedAt());
    }

    public function testToArrayAndFromArraySerialization(): void
    {
        $now = time();
        $credential = new Credential(
            email: 'user%40gmail.com',
            accessToken: 'acc-123',
            refreshToken: 'ref-456',
            expiresAt: $now + 1800,
            scope: ['email', 'profile'],
            createdAt: $now,
            updatedAt: $now
        );

        $array = $credential->toArray();

        $this->assertSame(1, $array['version']);
        $this->assertSame($now, $array['created_at']);
        $this->assertSame($now, $array['updated_at']);
        $this->assertSame('user%40gmail.com', $array['credential']['email']);
        $this->assertSame('acc-123', $array['credential']['access_token']);
        $this->assertSame('ref-456', $array['credential']['refresh_token']);
        $this->assertSame($now + 1800, $array['credential']['expires_at']);
        $this->assertSame(['email', 'profile'], $array['credential']['scope']);

        $restored = Credential::fromArray($array);
        $this->assertSame($credential->getEmail(), $restored->getEmail());
        $this->assertSame($credential->getAccessToken(), $restored->getAccessToken());
        $this->assertSame($credential->getRefreshToken(), $restored->getRefreshToken());
        $this->assertSame($credential->getExpiresAt(), $restored->getExpiresAt());
        $this->assertSame($credential->getScope(), $restored->getScope());
    }

    public function testEmptyEmailThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Credential(
            email: '   ',
            accessToken: 'acc',
            refreshToken: 'ref',
            expiresAt: time() + 3600
        );
    }
}
