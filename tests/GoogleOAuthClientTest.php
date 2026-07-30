<?php

declare(strict_types=1);

namespace Greatcode\OtpReader\Tests;

use Greatcode\OtpReader\Google\Exceptions\CredentialRefreshException;
use Greatcode\OtpReader\Google\GoogleOAuthClient;
use PHPUnit\Framework\TestCase;

class GoogleOAuthClientTest extends TestCase
{
    public function testExchangeAuthorizationCodeSuccess(): void
    {
        $mockHttp = function (string $url, string $method, array $headers, array $params): array {
            $this->assertSame('POST', $method);
            $this->assertSame('authorization_code', $params['grant_type']);
            $this->assertSame('sample_code', $params['code']);

            return [
                'status' => 200,
                'body' => json_encode([
                    'access_token' => 'mock-access-token',
                    'refresh_token' => 'mock-refresh-token',
                    'expires_in' => 3600,
                    'scope' => 'https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/userinfo.email',
                ], JSON_THROW_ON_ERROR),
            ];
        };

        $client = new GoogleOAuthClient('client-id', 'client-secret', $mockHttp);
        $result = $client->exchangeAuthorizationCode('sample_code', 'https://example.com/callback');

        $this->assertSame('mock-access-token', $result['access_token']);
        $this->assertSame('mock-refresh-token', $result['refresh_token']);
        $this->assertSame(3600, $result['expires_in']);
        $this->assertSame([
            'https://www.googleapis.com/auth/gmail.readonly',
            'https://www.googleapis.com/auth/userinfo.email',
        ], $result['scope']);
    }

    public function testRefreshAccessTokenSuccess(): void
    {
        $mockHttp = function (string $url, string $method, array $headers, array $params): array {
            $this->assertSame('refresh_token', $params['grant_type']);
            $this->assertSame('valid-refresh-token', $params['refresh_token']);

            return [
                'status' => 200,
                'body' => json_encode([
                    'access_token' => 'new-access-token',
                    'expires_in' => 3600,
                    'scope' => 'scope1 scope2',
                ], JSON_THROW_ON_ERROR),
            ];
        };

        $client = new GoogleOAuthClient('client-id', 'client-secret', $mockHttp);
        $result = $client->refreshAccessToken('valid-refresh-token');

        $this->assertSame('new-access-token', $result['access_token']);
        $this->assertSame(3600, $result['expires_in']);
        $this->assertSame(['scope1', 'scope2'], $result['scope']);
    }

    public function testRefreshAccessTokenHttpErrorThrowsException(): void
    {
        $mockHttp = function (): array {
            return [
                'status' => 400,
                'body' => json_encode(['error' => 'invalid_grant'], JSON_THROW_ON_ERROR),
            ];
        };

        $client = new GoogleOAuthClient('client-id', 'client-secret', $mockHttp);

        $this->expectException(CredentialRefreshException::class);
        $client->refreshAccessToken('bad-refresh-token');
    }

    public function testRevokeToken(): void
    {
        $mockHttp = function (string $url, string $method, array $headers, array $params): array {
            $this->assertSame('token-to-revoke', $params['token']);
            return ['status' => 200, 'body' => '{}'];
        };

        $client = new GoogleOAuthClient('client-id', 'client-secret', $mockHttp);
        $this->assertTrue($client->revokeToken('token-to-revoke'));
    }
}
