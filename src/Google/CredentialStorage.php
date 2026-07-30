<?php

declare(strict_types=1);

namespace Google;

use Google\Exceptions\CredentialCorruptedException;
use Google\Exceptions\CredentialNotFoundException;
use Google\Exceptions\CredentialStorageException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Handles atomic file operations for Credential JSON files.
 * Responsible for atomic saves, file permissions, backup creation, and corruption recovery.
 */
class CredentialStorage
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly string $storageDirectory,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Save credential data atomically and update backup file.
     *
     * @throws CredentialStorageException
     */
    public function save(Credential $credential): void
    {
        $this->ensureDirectoryExists();

        $email = $credential->getEmail();
        $encodedEmail = rawurlencode($email);
        $targetFile = $this->storageDirectory . '/' . $encodedEmail . '.json';
        $tmpFile = $targetFile . '.tmp';
        $bakFile = $targetFile . '.bak';

        $payload = json_encode(
            $credential->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        // Atomic Write Step 1: Write to .tmp file
        $written = @file_put_contents($tmpFile, $payload, LOCK_EX);
        if ($written === false) {
            throw new CredentialStorageException(sprintf('Failed to write temporary credential file "%s".', $tmpFile));
        }

        // Ensure contents are written to disk
        $fp = @fopen($tmpFile, 'r+');
        if ($fp !== false) {
            fflush($fp);
            fclose($fp);
        }

        @chmod($tmpFile, 0640);

        // Atomic Write Step 2: Rename .tmp -> .json
        if (!@rename($tmpFile, $targetFile)) {
            @unlink($tmpFile);
            throw new CredentialStorageException(sprintf('Failed to rename temporary file to "%s".', $targetFile));
        }

        @chmod($targetFile, 0640);

        // Step 3: Create .bak file after successful save
        if (!@copy($targetFile, $bakFile)) {
            $this->logger->warning('Failed to create backup credential file', ['email' => $email]);
        } else {
            @chmod($bakFile, 0640);
        }

        $this->logger->info('Credential updated', ['email' => $email]);
    }

    /**
     * Load credential by email. Attempts recovery from .bak if .json is corrupted.
     *
     * @throws CredentialNotFoundException
     * @throws CredentialCorruptedException
     * @throws CredentialStorageException
     */
    public function load(string $email): Credential
    {
        $this->logger->info('Loading credential', ['email' => $email]);

        $encodedEmail = rawurlencode($email);
        $targetFile = $this->storageDirectory . '/' . $encodedEmail . '.json';
        $bakFile = $targetFile . '.bak';

        if (!file_exists($targetFile)) {
            // Check if primary is missing but backup exists
            if (file_exists($bakFile)) {
                return $this->loadFromBackup($email, $targetFile, $bakFile);
            }
            throw new CredentialNotFoundException(sprintf('Credential file not found for email "%s".', $email));
        }

        $content = @file_get_contents($targetFile);
        if ($content === false) {
            throw new CredentialStorageException(sprintf('Failed to read credential file "%s".', $targetFile));
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new CredentialCorruptedException('Decoded JSON is not an array.');
            }
            return Credential::fromArray($data);
        } catch (Throwable $e) {
            $this->logger->warning('Primary credential file corrupted, attempting recovery from backup', ['email' => $email]);
            return $this->loadFromBackup($email, $targetFile, $bakFile, $e);
        }
    }

    /**
     * Attempt recovery loading from .bak file.
     *
     * @throws CredentialCorruptedException
     */
    private function loadFromBackup(string $email, string $targetFile, string $bakFile, ?Throwable $previous = null): Credential
    {
        if (!file_exists($bakFile)) {
            throw new CredentialCorruptedException(
                sprintf('Credential file corrupted for email "%s" and no backup available.', $email),
                0,
                $previous
            );
        }

        $bakContent = @file_get_contents($bakFile);
        if ($bakContent === false) {
            throw new CredentialCorruptedException(
                sprintf('Failed to read backup credential file for email "%s".', $email),
                0,
                $previous
            );
        }

        try {
            $data = json_decode($bakContent, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new CredentialCorruptedException('Decoded backup JSON is not an array.');
            }
            $credential = Credential::fromArray($data);

            // Restore target file from valid backup
            @copy($bakFile, $targetFile);
            @chmod($targetFile, 0640);

            $this->logger->info('Credential restored', ['email' => $email]);

            return $credential;
        } catch (Throwable $bakException) {
            throw new CredentialCorruptedException(
                sprintf('Both primary and backup credential files corrupted for email "%s".', $email),
                0,
                $bakException
            );
        }
    }

    /**
     * Delete credential and related temporary/backup files.
     *
     * @throws CredentialNotFoundException
     */
    public function delete(string $email): void
    {
        $encodedEmail = rawurlencode($email);
        $targetFile = $this->storageDirectory . '/' . $encodedEmail . '.json';
        $tmpFile = $targetFile . '.tmp';
        $bakFile = $targetFile . '.bak';
        $lockFile = $this->storageDirectory . '/' . $encodedEmail . '.lock';

        if (!file_exists($targetFile) && !file_exists($bakFile)) {
            throw new CredentialNotFoundException(sprintf('Credential file not found for email "%s".', $email));
        }

        @unlink($targetFile);
        @unlink($tmpFile);
        @unlink($bakFile);
        @unlink($lockFile);

        $this->logger->info('Credential deleted', ['email' => $email]);
    }

    /**
     * List all stored credential emails.
     *
     * @return string[] List of email addresses
     */
    public function list(): array
    {
        if (!is_dir($this->storageDirectory)) {
            return [];
        }

        $files = scandir($this->storageDirectory);
        if ($files === false) {
            return [];
        }

        $emails = [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            // Match *.json files only (not .json.tmp or .json.bak)
            if (str_ends_with($file, '.json')) {
                $encodedName = substr($file, 0, -5);
                $decodedEmail = rawurldecode($encodedName);
                if ($decodedEmail !== '') {
                    $emails[] = $decodedEmail;
                }
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * Ensure storage directory exists with 0750 permission.
     *
     * @throws CredentialStorageException
     */
    private function ensureDirectoryExists(): void
    {
        if (!is_dir($this->storageDirectory)) {
            if (!@mkdir($this->storageDirectory, 0750, true) && !is_dir($this->storageDirectory)) {
                throw new CredentialStorageException(sprintf('Failed to create storage directory "%s".', $this->storageDirectory));
            }
        }
        @chmod($this->storageDirectory, 0750);
    }
}
