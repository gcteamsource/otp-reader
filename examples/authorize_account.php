<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Greatcode\OtpReader\Google\Credential;
use Greatcode\OtpReader\Google\CredentialLock;
use Greatcode\OtpReader\Google\CredentialManager;
use Greatcode\OtpReader\Google\CredentialStorage;
use Greatcode\OtpReader\Google\GoogleOAuthClient;

/**
 * Example Best Practice: Storing or authorizing Google OAuth credentials for an account.
 */

$storageDir = __DIR__ . '/../storage/google';
$clientId = getenv('GOOGLE_CLIENT_ID') ?: 'YOUR_GOOGLE_CLIENT_ID';
$clientSecret = getenv('GOOGLE_CLIENT_SECRET') ?: 'YOUR_GOOGLE_CLIENT_SECRET';

$storage = new CredentialStorage($storageDir);
$lock = new CredentialLock($storageDir);
$oauthClient = new GoogleOAuthClient($clientId, $clientSecret);

$credentialManager = new CredentialManager($storage, $lock, $oauthClient);

// Option A: Save existing refreshToken manually
$credential = new Credential(
    email: 'user@gmail.com',
    accessToken: '', // Can be empty initially; getValidCredential() will auto-refresh using refreshToken
    refreshToken: 'YOUR_REFRESH_TOKEN',
    expiresAt: 0 // Force immediate refresh on first access
);

$credentialManager->saveCredential($credential);
echo "✅ Credential for user@gmail.com saved successfully to {$storageDir}/user%40gmail.com.json\n";

// Option B: Authorize using an Authorization Code obtained from Google OAuth consent flow
// $authCode = '4/0AY0e-g...';
// $credential = $credentialManager->authorizeCode('user@gmail.com', $authCode, 'https://example.com/callback');
// echo "✅ Account user@gmail.com authorized successfully.\n";
