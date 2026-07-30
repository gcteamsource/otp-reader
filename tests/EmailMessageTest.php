<?php

declare(strict_types=1);

namespace Greatcode\Tests;

use Greatcode\Mail\EmailMessage;
use PHPUnit\Framework\TestCase;

class EmailMessageTest extends TestCase
{
    public function testExtractOtpWithEnglishContext(): void
    {
        $message = new EmailMessage(
            id: '123',
            threadId: 't1',
            subject: 'Your login verification code',
            from: 'auth@service.com',
            to: 'user@gmail.com',
            date: 'Thu, 30 Jul 2026 20:00:00 GMT',
            snippet: 'Your verification code is 482019.',
            body: 'Hello User, your verification code is 482019. It will expire in 10 minutes.'
        );

        $this->assertSame('482019', $message->extractOtp());
    }

    public function testExtractOtpWithIndonesianContext(): void
    {
        $message = new EmailMessage(
            id: '124',
            threadId: 't2',
            subject: 'Kode Verifikasi OTP',
            from: 'no-reply@tokopedia.com',
            to: 'user@gmail.com',
            date: 'Thu, 30 Jul 2026 20:00:00 GMT',
            snippet: 'Kode OTP Anda adalah 920148.',
            body: 'Jangan berikan kode ini kepada siapapun. Kode OTP Anda adalah 920148 berlaku 5 menit.'
        );

        $this->assertSame('920148', $message->extractOtp());
    }

    public function testExtractOtpWithCustomPattern(): void
    {
        $message = new EmailMessage(
            id: '125',
            threadId: 't3',
            subject: 'Security Alert',
            from: 'bank@bank.com',
            to: 'user@gmail.com',
            date: 'Thu, 30 Jul 2026 20:00:00 GMT',
            snippet: 'Use PIN-78291 to confirm transaction.',
            body: 'Use PIN-78291 to confirm transaction of $500.'
        );

        $this->assertSame('78291', $message->extractOtp('/PIN-(\d{5})/'));
    }

    public function testExtractOtpIgnoresYearInGenericPattern(): void
    {
        $message = new EmailMessage(
            id: '126',
            threadId: 't4',
            subject: 'Notification 2026',
            from: 'service@example.com',
            to: 'user@gmail.com',
            date: 'Thu, 30 Jul 2026 20:00:00 GMT',
            snippet: 'Copyright 2026. Your code is 8841.',
            body: 'Welcome to 2026 system. Your code is 8841.'
        );

        $this->assertSame('8841', $message->extractOtp());
    }
}
