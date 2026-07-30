<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Greatcode\OtpReader\Mail\Exceptions\MailReaderException;
use Greatcode\OtpReader\Mail\MailReader;

/**
 * Super Simple Usage Example: Reading OTP in Scraping Scripts with Greatcode\OtpReader namespace.
 */

// 1. Inisialisasi MailReader dalam 1 Baris Kode!
$mailReader = MailReader::createGmail(
    storageDirectory: __DIR__ . '/../storage/google',
    clientId: getenv('GOOGLE_CLIENT_ID') ?: 'YOUR_CLIENT_ID',
    clientSecret: getenv('GOOGLE_CLIENT_SECRET') ?: 'YOUR_CLIENT_SECRET'
);

// 2. (Opsional) Daftarkan / Update Refresh Token Akun dalam 1 Baris
// $mailReader->registerAccount('user@gmail.com', 'YOUR_REFRESH_TOKEN');

$emailAccount = 'user@gmail.com';

try {
    echo "[$emailAccount] Menunggu email OTP...\n";

    // 3. Ambil OTP dalam 1 Baris! (Otomatis retry polling, delay 2 detik, dan mark as read)
    $otp = $mailReader->getLatestOtp(
        email: $emailAccount,
        from: 'tokopedia',
        afterTime: time() - 300
    );

    if ($otp !== null) {
        echo "✅ Kode OTP Berhasil Ditemukan: {$otp}\n";
    } else {
        echo "❌ Kode OTP tidak ditemukan.\n";
    }

} catch (MailReaderException $e) {
    echo "⚠️ Mail Reader Error: " . $e->getMessage() . "\n";
}
