<?php

declare(strict_types=1);

namespace Mail\Drivers;

use Google\CredentialManager;
use Mail\EmailMessage;
use Mail\Exceptions\MailReaderException;
use Mail\MailDriverInterface;
use Throwable;

/**
 * Mail Reader driver for Gmail REST API v1.
 * Uses Google CredentialManager for token management.
 */
class GmailDriver implements MailDriverInterface
{
    private const BASE_URL = 'https://gmail.googleapis.com/gmail/v1/users/me';

    /**
     * @param CredentialManager $credentialManager Google OAuth Credential Manager
     * @param (callable(string $url, string $method, array<string, string> $headers, array<string, mixed> $params): array{status: int, body: string})|null $httpHandler Custom HTTP transport for testing
     */
    public function __construct(
        private readonly CredentialManager $credentialManager,
        private readonly mixed $httpHandler = null
    ) {
    }

    public function getCredentialManager(): CredentialManager
    {
        return $this->credentialManager;
    }

    /**
     * {@inheritdoc}
     */
    public function listMessages(string $email, array $options = []): array
    {
        $credential = $this->credentialManager->getValidCredential($email);
        $accessToken = $credential->getAccessToken();

        $queryParams = [];
        if (!empty($options['query'])) {
            $queryParams['q'] = (string) $options['query'];
        }
        if (!empty($options['maxResults'])) {
            $queryParams['maxResults'] = (int) $options['maxResults'];
        }
        if (!empty($options['includeSpamTrash'])) {
            $queryParams['includeSpamTrash'] = 'true';
        }

        $url = self::BASE_URL . '/messages';
        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        $response = $this->sendRequest($url, 'GET', $accessToken);
        $data = $this->parseJsonResponse($response, 'Failed to list Gmail messages');

        if (empty($data['messages']) || !is_array($data['messages'])) {
            return [];
        }

        $messages = [];
        foreach ($data['messages'] as $item) {
            if (empty($item['id']) || !is_string($item['id'])) {
                continue;
            }
            try {
                $messages[] = $this->getMessage($email, $item['id']);
            } catch (Throwable $e) {
                // Ignore individual message parse failure when listing
                continue;
            }
        }

        return $messages;
    }

    /**
     * {@inheritdoc}
     */
    public function getMessage(string $email, string $messageId): EmailMessage
    {
        $credential = $this->credentialManager->getValidCredential($email);
        $accessToken = $credential->getAccessToken();

        $url = self::BASE_URL . '/messages/' . rawurlencode($messageId) . '?format=full';

        $response = $this->sendRequest($url, 'GET', $accessToken);
        $data = $this->parseJsonResponse($response, sprintf('Failed to fetch Gmail message ID "%s"', $messageId));

        $id = (string) ($data['id'] ?? $messageId);
        $threadId = (string) ($data['threadId'] ?? '');
        $snippet = (string) ($data['snippet'] ?? '');
        $internalDate = (int) ($data['internalDate'] ?? 0);

        $headers = [];
        if (!empty($data['payload']['headers']) && is_array($data['payload']['headers'])) {
            foreach ($data['payload']['headers'] as $header) {
                if (isset($header['name'], $header['value'])) {
                    $headers[strtolower($header['name'])] = (string) $header['value'];
                }
            }
        }

        $subject = $headers['subject'] ?? '';
        $from = $headers['from'] ?? '';
        $to = $headers['to'] ?? '';
        $date = $headers['date'] ?? '';

        $body = $this->extractMessageBody($data['payload'] ?? []);

        return new EmailMessage(
            id: $id,
            threadId: $threadId,
            subject: $subject,
            from: $from,
            to: $to,
            date: $date,
            snippet: $snippet,
            body: $body,
            internalDate: $internalDate
        );
    }

    /**
     * {@inheritdoc}
     */
    public function markAsRead(string $email, string $messageId): bool
    {
        $credential = $this->credentialManager->getValidCredential($email);
        $accessToken = $credential->getAccessToken();

        $url = self::BASE_URL . '/messages/' . rawurlencode($messageId) . '/modify';
        $payload = ['removeLabelIds' => ['UNREAD']];

        $response = $this->sendRequest($url, 'POST', $accessToken, $payload);
        return $response['status'] >= 200 && $response['status'] < 300;
    }

    /**
     * {@inheritdoc}
     */
    public function getLatestOtp(
        string $email,
        ?string $from = null,
        int|\DateTimeInterface|string|null $afterTime = null,
        ?callable $parser = null,
        int $maxAttempts = 5,
        int $delaySeconds = 2,
        bool $autoMarkAsRead = true
    ): ?string {
        $queryParts = ['is:unread'];

        if ($from !== null && trim($from) !== '') {
            $queryParts[] = 'from:' . trim($from);
        }

        if ($afterTime !== null) {
            $timestamp = null;
            if ($afterTime instanceof \DateTimeInterface) {
                $timestamp = $afterTime->getTimestamp();
            } elseif (is_numeric($afterTime)) {
                $timestamp = (int) $afterTime;
            } elseif (is_string($afterTime) && trim($afterTime) !== '') {
                $parsed = strtotime($afterTime);
                if ($parsed !== false) {
                    $timestamp = $parsed;
                }
            }

            if ($timestamp !== null) {
                $queryParts[] = 'after:' . $timestamp;
            }
        }

        $options = [
            'maxResults' => 5,
            'query' => implode(' ', $queryParts),
        ];

        $attempts = 0;
        $maxAttempts = max(1, $maxAttempts);
        $delaySeconds = max(0, $delaySeconds);

        while ($attempts < $maxAttempts) {
            $attempts++;
            $messages = $this->listMessages($email, $options);

            foreach ($messages as $message) {
                $otp = null;
                if (is_callable($parser)) {
                    $result = $parser($message->getBody(), $message);
                    if (is_string($result) && trim($result) !== '') {
                        $otp = trim($result);
                    }
                } else {
                    $extracted = $message->extractOtp();
                    if ($extracted !== null && trim($extracted) !== '') {
                        $otp = $extracted;
                    }
                }

                if ($otp !== null) {
                    if ($autoMarkAsRead) {
                        try {
                            $this->markAsRead($email, $message->getId());
                        } catch (Throwable $e) {
                            // Ignore mark read errors to return OTP successfully
                        }
                    }
                    return $otp;
                }
            }

            if ($attempts < $maxAttempts && $delaySeconds > 0) {
                sleep($delaySeconds);
            }
        }

        return null;
    }

    /**
     * Extract body text from Gmail payload structure.
     *
     * @param array<string, mixed> $payload
     */
    private function extractMessageBody(array $payload): string
    {
        $bodyText = '';

        // 1. Direct body data
        if (!empty($payload['body']['data']) && is_string($payload['body']['data'])) {
            $bodyText .= $this->decodeBase64Url($payload['body']['data']);
        }

        // 2. Parts recursion
        if (!empty($payload['parts']) && is_array($payload['parts'])) {
            foreach ($payload['parts'] as $part) {
                $mimeType = strtolower((string) ($part['mimeType'] ?? ''));

                if (!empty($part['body']['data']) && is_string($part['body']['data'])) {
                    $decoded = $this->decodeBase64Url($part['body']['data']);
                    if ($mimeType === 'text/plain') {
                        return $decoded; // Prefer text/plain
                    }
                    if ($mimeType === 'text/html' && $bodyText === '') {
                        $bodyText = strip_tags($decoded);
                    }
                }

                if (!empty($part['parts']) && is_array($part['parts'])) {
                    $nestedBody = $this->extractMessageBody($part);
                    if ($nestedBody !== '') {
                        return $nestedBody;
                    }
                }
            }
        }

        return trim($bodyText);
    }

    /**
     * Decode base64url encoded string.
     */
    private function decodeBase64Url(string $data): string
    {
        $padded = strtr($data, '-_', '+/');
        $rem = strlen($padded) % 4;
        if ($rem > 0) {
            $padded .= str_repeat('=', 4 - $rem);
        }
        $decoded = base64_decode($padded, true);
        return $decoded !== false ? $decoded : '';
    }

    /**
     * Send HTTP request using custom transport handler or cURL.
     *
     * @param array<string, mixed>|null $jsonBody Optional JSON payload for POST/PATCH
     * @return array{status: int, body: string}
     * @throws MailReaderException
     */
    private function sendRequest(string $url, string $method, string $accessToken, ?array $jsonBody = null): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json',
        ];

        if ($jsonBody !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        if (is_callable($this->httpHandler)) {
            try {
                return ($this->httpHandler)($url, $method, $headers, $jsonBody ?? []);
            } catch (Throwable $e) {
                throw new MailReaderException('Gmail API request failed: ' . $e->getMessage(), 0, $e);
            }
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new MailReaderException('Failed to initialize cURL session.');
        }

        $formattedHeaders = [];
        foreach ($headers as $k => $v) {
            $formattedHeaders[] = $k . ': ' . $v;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $formattedHeaders,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($jsonBody !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody, JSON_THROW_ON_ERROR));
            }
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($jsonBody !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody, JSON_THROW_ON_ERROR));
            }
        }

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new MailReaderException('cURL request failed: ' . $error);
        }

        return ['status' => $status, 'body' => (string) $body];
    }

    /**
     * Parse JSON HTTP response.
     *
     * @param array{status: int, body: string} $response
     * @return array<string, mixed>
     * @throws MailReaderException
     */
    private function parseJsonResponse(array $response, string $errorPrefix): array
    {
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new MailReaderException(sprintf('%s (HTTP %d): %s', $errorPrefix, $response['status'], $response['body']));
        }

        try {
            $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new MailReaderException($errorPrefix . ': response is not valid JSON array.');
            }
            return $data;
        } catch (Throwable $e) {
            throw new MailReaderException($errorPrefix . ': ' . $e->getMessage(), 0, $e);
        }
    }
}
