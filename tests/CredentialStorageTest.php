<?php

declare(strict_types=1);

namespace Greatcode\OtpReader\Tests;

use Greatcode\OtpReader\Google\Credential;
use Greatcode\OtpReader\Google\CredentialStorage;
use Greatcode\OtpReader\Google\Exceptions\CredentialCorruptedException;
use Greatcode\OtpReader\Google\Exceptions\CredentialNotFoundException;
use PHPUnit\Framework\TestCase;

class CredentialStorageTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/google_storage_test_' . uniqid('', true);
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

    public function testSaveAndLoadCredential(): void
    {
        $storage = new CredentialStorage($this->tempDir);
        $credential = new Credential(
            email: 'user1@gmail.com',
            accessToken: 'token-abc',
            refreshToken: 'refresh-xyz',
            expiresAt: time() + 3600,
            scope: ['scope1']
        );

        $storage->save($credential);

        $loaded = $storage->load('user1@gmail.com');
        $this->assertSame('user1@gmail.com', $loaded->getEmail());
        $this->assertSame('token-abc', $loaded->getAccessToken());
        $this->assertSame('refresh-xyz', $loaded->getRefreshToken());

        // Assert backup file created
        $bakPath = $this->tempDir . '/' . rawurlencode('user1@gmail.com') . '.json.bak';
        $this->assertFileExists($bakPath);
    }

    public function testAtomicWriteDoesNotLeaveTempFiles(): void
    {
        $storage = new CredentialStorage($this->tempDir);
        $credential = new Credential(
            email: 'user2@gmail.com',
            accessToken: 'token-123',
            refreshToken: 'refresh-123',
            expiresAt: time() + 3600
        );

        $storage->save($credential);

        $tmpPath = $this->tempDir . '/' . rawurlencode('user2@gmail.com') . '.json.tmp';
        $this->assertFileDoesNotExist($tmpPath);
    }

    public function testCorruptedPrimaryJsonRecoversFromBackup(): void
    {
        $storage = new CredentialStorage($this->tempDir);
        $credential = new Credential(
            email: 'corrupt@gmail.com',
            accessToken: 'valid-access-token',
            refreshToken: 'valid-refresh-token',
            expiresAt: time() + 3600
        );

        // Save creates primary .json and backup .bak
        $storage->save($credential);

        $primaryPath = $this->tempDir . '/' . rawurlencode('corrupt@gmail.com') . '.json';
        // Corrupt primary JSON file
        file_put_contents($primaryPath, '{ INVALID JSON ...');

        // Loading should recover from .bak and restore primary .json
        $recovered = $storage->load('corrupt@gmail.com');
        $this->assertSame('corrupt@gmail.com', $recovered->getEmail());
        $this->assertSame('valid-access-token', $recovered->getAccessToken());

        // Verify primary file content restored
        $restoredContent = file_get_contents($primaryPath);
        $this->assertStringContainsString('valid-access-token', (string) $restoredContent);
    }

    public function testBothPrimaryAndBackupCorruptedThrowsException(): void
    {
        $storage = new CredentialStorage($this->tempDir);
        $credential = new Credential(
            email: 'bad@gmail.com',
            accessToken: 'tok',
            refreshToken: 'ref',
            expiresAt: time() + 3600
        );

        $storage->save($credential);

        $primaryPath = $this->tempDir . '/' . rawurlencode('bad@gmail.com') . '.json';
        $bakPath = $primaryPath . '.bak';

        file_put_contents($primaryPath, 'corrupted');
        file_put_contents($bakPath, 'corrupted');

        $this->expectException(CredentialCorruptedException::class);
        $storage->load('bad@gmail.com');
    }

    public function testMissingFileThrowsNotFoundException(): void
    {
        $storage = new CredentialStorage($this->tempDir);

        $this->expectException(CredentialNotFoundException::class);
        $storage->load('nonexistent@gmail.com');
    }

    public function testDeleteCredential(): void
    {
        $storage = new CredentialStorage($this->tempDir);
        $credential = new Credential(
            email: 'delete@gmail.com',
            accessToken: 'tok',
            refreshToken: 'ref',
            expiresAt: time() + 3600
        );

        $storage->save($credential);
        $this->assertCount(1, $storage->list());

        $storage->delete('delete@gmail.com');
        $this->assertCount(0, $storage->list());
    }

    public function testListCredentials(): void
    {
        $storage = new CredentialStorage($this->tempDir);

        $cred1 = new Credential('a@test.com', 'tok', 'ref', time() + 100);
        $cred2 = new Credential('b@test.com', 'tok', 'ref', time() + 100);

        $storage->save($cred1);
        $storage->save($cred2);

        $list = $storage->list();
        sort($list);
        $this->assertSame(['a@test.com', 'b@test.com'], $list);
    }
}
