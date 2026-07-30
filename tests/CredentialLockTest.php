<?php

declare(strict_types=1);

namespace Greatcode\OtpReader\Tests;

use Greatcode\OtpReader\Google\CredentialLock;
use PHPUnit\Framework\TestCase;

class CredentialLockTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/google_lock_test_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            @rmdir($this->tempDir);
        }
    }

    public function testExclusiveLockExecutesCallbackAndReturnsValue(): void
    {
        $lock = new CredentialLock($this->tempDir);
        $result = $lock->exclusive('test@example.com', function (): string {
            return 'success';
        });

        $this->assertSame('success', $result);
        $lockFile = $this->tempDir . '/' . rawurlencode('test@example.com') . '.lock';
        $this->assertFileExists($lockFile);
    }

    public function testSharedLockExecutesCallbackAndReturnsValue(): void
    {
        $lock = new CredentialLock($this->tempDir);
        $result = $lock->shared('test@example.com', function (): int {
            return 42;
        });

        $this->assertSame(42, $result);
    }
}
