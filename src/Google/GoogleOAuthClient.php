<?php

declare(strict_types=1);

namespace Google;

use Google\Exceptions\CredentialRefreshException;
use Throwable;

/**
 * Google OAuth 2.0 API client wrapper for code exchange, token refresh, and token revocation.
 * Has no knowledge of filesystem or storage mechanisms.
 */
class GoogleOAuthClient
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    /**
     * @param string $clientId OAuth 2.0 Client ID
     * @param string $clientSecret OAuth 2.0 Client Secret
     * @param (callable(string $url, string $method, array<string, string> $headers, array<string, mixed> $params): array{status: int, body: string})|null $httpHandler Custom HTTP transport callable for mocking/testing
     */
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly mixed $httpHandler = null
    ) {
    }

    /**
     * Exchange authorization code for initial access and refresh tokens.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, scope: string[]}
     * @throws CredentialRefreshException
     */
    public function exchangeAuthorizationCode(string $code, string $redirectUri = ''): array
    {
        $params = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];

        if ($redirectUri !== '') {
            $params['redirect_uri'] = $redirectUri;
        }

        $response = $this->sendPostRequest(self::TOKEN_URL, $params);
        $data = $this->parseResponse($response, 'Failed to exchange authorization code');

        if (empty($data['access_token'])) {
            throw new CredentialRefreshException('Token exchange response missing access_token.');
        }

        $scopeList = [];
        if (!empty($data['scope']) && is_string($data['scope'])) {
            $scopeList = array_values(array_filter(explode(' ', $data['scope'])));
        }

        return [
            'access_token' => (string) $data['access_token'],
            'refresh_token' => (string) ($data['refresh_token'] ?? ''),
            'expires_in' => (int) ($data['expires_in'] ?? 3600),
            'scope' => $scopeList,
        ];
    }

    /**
     * Refresh access token using refresh_token.
     *
     * @return array{access_token: string, expires_in: int, scope: string[], refresh_token?: string}
     * @throws CredentialRefreshException
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        if (trim($refreshToken) === '') {
            throw new CredentialRefreshException('Cannot refresh token with an empty refresh_token.');
        }

        $params = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];

        $response = $this->sendPostRequest(self::TOKEN_URL, $params);
        $data = $this->parseResponse($response, 'Failed to refresh access token');

        if (empty($data['access_token'])) {
            throw new CredentialRefreshException('Refresh token response missing access_token.');
        }

        $result = [
            'access_token' => (string) $data['access_token'],
            'expires_in' => (int) ($data['expires_in'] ?? 3600),
            'scope' => [],
        ];

        if (!empty($data['scope']) && is_string($data['scope'])) {
            $result['scope'] = array_values(array_filter(explode(' ', $data['scope'])));
        }

        if (!empty($data['refresh_token'])) {
            $result['refresh_token'] = (string) $data['refresh_token'];
        }

        return $result;
    }

    /**
     * Revoke access or refresh token.
     *
     * @throws CredentialRefreshException
     */
    public function revokeToken(string $token): bool
    {
        if (trim($token) === '') {
            return false;
        }

        $params = ['token' => $token];
        $response = $this->sendPostRequest(self::REVOKE_URL, $params);

        return $response['status'] >= 200 && $response['status'] < 300;
    }

    /**
     * Send HTTP POST request via custom handler or native cURL.
     *
     * @param array<string, mixed> $params
     * @return array{status: int, body: string}
     * @throws CredentialRefreshException
     */
    private function sendPostRequest(string $url, array $params): array
    {
        if (is_callable($this->httpHandler)) {
            try {
                return ($this->httpHandler)($url, 'POST', ['Content-Type' => 'application/x-www-form-urlencoded'], $params);
            } catch (Throwable $e) {
                throw new CredentialRefreshException('HTTP request failed: ' . $e->getMessage(), 0, $e);
            }
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new CredentialRefreshException('Failed to initialize cURL session.');
        }

        $postData = http_build_query($params);

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new CredentialRefreshException('cURL request failed: ' . $error);
        }

        return ['status' => $status, 'body' => (string) $body];
    }

    /**
     * Parse JSON HTTP response.
     *
     * @param array{status: int, body: string} $response
     * @return array<string, mixed>
     * @throws CredentialRefreshException
     */
    private function parseResponse(array $response, string $errorPrefix): array
    {
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new CredentialRefreshException(sprintf('%s (HTTP %d): %s', $errorPrefix, $response['status'], $response['body']));
        }

        try {
            $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new CredentialRefreshException($errorPrefix . ': invalid JSON response body.');
            }
            return $data;
        } catch (Throwable $e) {
            throw new CredentialRefreshException($errorPrefix . ': ' . $e->getMessage(), 0, $e);
        }
    }
}
