<?php

declare(strict_types=1);

namespace Greatcode\Mail;

use DateTimeInterface;
use Greatcode\Mail\Exceptions\MailReaderException;

/**
 * Interface contract for Mail Reader Drivers.
 */
interface MailDriverInterface
{
    /**
     * List messages matching query options.
     *
     * @param string $email Target email account
     * @param array{query?: string, maxResults?: int, includeSpamTrash?: bool} $options Search parameters
     * @return EmailMessage[] List of email messages
     * @throws MailReaderException
     */
    public function listMessages(string $email, array $options = []): array;

    /**
     * Retrieve a full message by message ID.
     *
     * @param string $email Target email account
     * @param string $messageId Message ID
     * @return EmailMessage
     * @throws MailReaderException
     */
    public function getMessage(string $email, string $messageId): EmailMessage;

    /**
     * Mark an email message as read.
     *
     * @param string $email Target email account
     * @param string $messageId Message ID to mark as read
     * @return bool True if successful
     * @throws MailReaderException
     */
    public function markAsRead(string $email, string $messageId): bool;

    /**
     * Retrieve latest OTP code with retry attempts, delay, and automatic mark-as-read.
     *
     * @param string $email Target email account (e.g. 'user@gmail.com')
     * @param string|null $from Sender email or domain filter (e.g. 'auth@service.com' or 'tokopedia')
     * @param int|DateTimeInterface|string|null $afterTime Filter emails received after this time
     * @param (callable(string $body, EmailMessage $message): ?string)|null $parser Custom parser function accepting (body, message)
     * @param int $maxAttempts Maximum polling attempts if no message/OTP is found initially (default: 5)
     * @param int $delaySeconds Delay in seconds between polling attempts (default: 2)
     * @param bool $autoMarkAsRead Automatically mark message as read after successfully retrieving OTP (default: true)
     * @return string|null Extracted OTP code or null if no matching message/code found after max attempts
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
    ): ?string;
}
