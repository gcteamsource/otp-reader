<?php

declare(strict_types=1);

namespace Greatcode\Google;

use Greatcode\Google\Exceptions\CredentialLockException;

/**
 * Handles file locking operations using flock().
 * This is the ONLY class permitted to call flock() in the library.
 */
class CredentialLock
{
    public function __construct(
        private readonly string $storageDirectory
    ) {}

    /**
     * Execute callback within an exclusive lock (LOCK_EX).
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws CredentialLockException
     */
    public function exclusive(string $email, callable $callback): mixed
    {
        return $this->acquireLock($email, LOCK_EX, $callback);
    }

    /**
     * Execute callback within a shared lock (LOCK_SH).
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws CredentialLockException
     */
    public function shared(string $email, callable $callback): mixed
    {
        return $this->acquireLock($email, LOCK_SH, $callback);
    }

    /**
     * Acquires file lock and executes the provided callback.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws CredentialLockException
     */
    private function acquireLock(string $email, int $lockType, callable $callback): mixed
    {
        if (!is_dir($this->storageDirectory)) {
            if (!@mkdir($this->storageDirectory, 0750, true) && !is_dir($this->storageDirectory)) {
                throw new CredentialLockException(sprintf('Failed to create storage directory "%s".', $this->storageDirectory));
            }
            chmod($this->storageDirectory, 0750);
        }

        $lockFilePath = $this->storageDirectory . '/' . rawurlencode($email) . '.lock';
        $handle = @fopen($lockFilePath, 'c+');

        if ($handle === false) {
            throw new CredentialLockException(sprintf('Failed to open lock file "%s".', $lockFilePath));
        }

        chmod($lockFilePath, 0640);

        if (!flock($handle, $lockType)) {
            fclose($handle);
            throw new CredentialLockException(sprintf('Failed to acquire lock on file "%s".', $lockFilePath));
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
