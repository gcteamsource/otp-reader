<?php

declare(strict_types=1);

namespace Greatcode\Mail;

/**
 * Value Object representing an Email Message.
 * Includes built-in OTP extraction logic.
 */
final class EmailMessage
{
    public function __construct(
        private readonly string $id,
        private readonly string $threadId,
        private readonly string $subject,
        private readonly string $from,
        private readonly string $to,
        private readonly string $date,
        private readonly string $snippet,
        private readonly string $body,
        private readonly int $internalDate = 0
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getThreadId(): string
    {
        return $this->threadId;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getFrom(): string
    {
        return $this->from;
    }

    public function getTo(): string
    {
        return $this->to;
    }

    public function getDate(): string
    {
        return $this->date;
    }

    public function getSnippet(): string
    {
        return $this->snippet;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getInternalDate(): int
    {
        return $this->internalDate;
    }

    /**
     * Extract OTP verification code from email body, snippet, or subject.
     *
     * @param string|null $pattern Custom regex pattern (e.g. '/\b\d{6}\b/')
     * @return string|null Extracted OTP string or null if not found
     */
    public function extractOtp(?string $pattern = null): ?string
    {
        $searchableTexts = [
            $this->body,
            $this->snippet,
            $this->subject,
        ];

        if ($pattern !== null && $pattern !== '') {
            foreach ($searchableTexts as $text) {
                if (trim($text) === '') {
                    continue;
                }
                if (preg_match($pattern, $text, $matches) === 1) {
                    return $matches[1] ?? $matches[0];
                }
            }
            return null;
        }

        // Default heuristic OTP extraction
        // 1. Look for explicit keyword context followed by digits (e.g. "code is 123456", "OTP Anda: 920148")
        $contextPatternDigits = '/(?:code|otp|verification|verifikasi|pin|password|kode|security|login)[^\d\n]{0,40}?\b(\d{4,8})\b/i';

        foreach ($searchableTexts as $text) {
            if (trim($text) === '') {
                continue;
            }
            if (preg_match($contextPatternDigits, $text, $matches) === 1) {
                return $matches[1];
            }
        }

        // 2. Look for keyword context followed by alphanumeric code (e.g. "OTP: XK92A")
        $contextPatternAlphaNum = '/(?:code|otp|verification|verifikasi|pin|password|kode|security|login)[^a-zA-Z0-9\n]{0,15}?\b([A-Z0-9]{4,8})\b/i';

        foreach ($searchableTexts as $text) {
            if (trim($text) === '') {
                continue;
            }
            if (preg_match($contextPatternAlphaNum, $text, $matches) === 1) {
                return $matches[1];
            }
        }

        // 3. Look for standalone 4 to 8 digit numbers
        $genericPattern = '/\b(\d{4,8})\b/';
        foreach ($searchableTexts as $text) {
            if (trim($text) === '') {
                continue;
            }
            if (preg_match_all($genericPattern, $text, $m) > 0) {
                // Return first 4-8 digit number found
                foreach ($m[1] as $candidate) {
                    // Ignore common 4-digit years (e.g. 2020-2035) unless nothing else is present
                    $val = (int) $candidate;
                    if ($val >= 2020 && $val <= 2035) {
                        continue;
                    }
                    return $candidate;
                }
                return $m[1][0];
            }
        }

        return null;
    }
}
