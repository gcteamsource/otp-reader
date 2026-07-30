<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Mail\MailReader;

/**
 * Super Simple Web Registration Endpoint.
 * In a single line of code: processes GET/POST requests, validates fields, saves credentials, and renders Glassmorphism HTML UI.
 */
MailReader::handleRegistration(
    storageDirectory: __DIR__ . '/../storage/google',
    clientId: getenv('GOOGLE_CLIENT_ID') ?: 'YOUR_CLIENT_ID',
    clientSecret: getenv('GOOGLE_CLIENT_SECRET') ?: 'YOUR_CLIENT_SECRET'
);
