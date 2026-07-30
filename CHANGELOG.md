# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-07-30

### Added
- **Google OAuth Credential Storage Engine:**
  - Thread-safe `flock(LOCK_EX)` locking via persistent `.lock` files (`CredentialLock`).
  - Atomic file writes using temporary buffering and atomic rename (`CredentialStorage`).
  - Automatic backup creation (`.bak`) and corruption recovery.
  - Full PHP 8.1+ strict types & PSR-12 standard compliance.

- **Mail Reader & Gmail REST API Driver:**
  - `MailDriverInterface` contract abstraction.
  - `GmailDriver` implementing Gmail REST API v1 (`/messages`, `/messages/{id}`, `/messages/{id}/modify`).
  - `EmailMessage` Value Object with automated English & Indonesian OTP code extraction.
  - One-line static factory helpers `MailReader::createGmail()` and `registerAccount()`.
  - Automatic mark-as-read functionality (`markAsRead`).
  - Polling retry mechanism with configurable delay and max attempts in `getLatestOtp()`.
  - Dynamic body parser callable support in `getLatestOtp()`.

- **Web Registration UI:**
  - `OAuthUiRenderer` rendering modern Glassmorphism HTML interface.
  - One-line static handler `MailReader::handleRegistration()` for serving web endpoints.

- **Tests & Documentation:**
  - Comprehensive unit test suite with 33+ tests and 120+ assertions covering concurrency, atomic write, backup recovery, OAuth client, and Mail Reader driver.
