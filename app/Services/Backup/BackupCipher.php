<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * Symmetric encryption of a whole backup archive with a passphrase the operator chooses.
 *
 * A configuration backup contains APP_KEY and therefore, indirectly, every encrypted
 * credential in the database. It is meant to be downloaded and carried off the server, so
 * it must be safe to store somewhere the operator does not fully control.
 *
 * The file is processed in chunks rather than read into memory at once, so the same code
 * still works once media files are part of an archive. Every chunk is sealed with
 * AES-256-GCM, and its index plus an end-of-file flag go into the additional data, which
 * makes reordering, dropping or truncating chunks fail loudly instead of silently
 * producing a shorter archive.
 *
 * The passphrase is stretched with PBKDF2-SHA256. There is no way to recover an archive
 * whose passphrase is lost: that is the point, and the UI says so.
 */
class BackupCipher
{
    private const MAGIC = "RRBK1\n";

    private const ITERATIONS = 210000;

    private const SALT_BYTES = 16;

    private const IV_BYTES = 12;

    private const TAG_BYTES = 16;

    private const CHUNK_BYTES = 1048576;

    private const CIPHER = 'aes-256-gcm';

    public function encryptFile(string $sourcePath, string $targetPath, string $passphrase): void
    {
        $source = $this->open($sourcePath, 'rb');
        $target = $this->open($targetPath, 'wb');

        $salt = random_bytes(self::SALT_BYTES);
        $key = $this->deriveKey($passphrase, $salt);

        try {
            fwrite($target, self::MAGIC);
            fwrite($target, pack('N', self::ITERATIONS));
            fwrite($target, $salt);

            $index = 0;
            $plain = fread($source, self::CHUNK_BYTES);

            do {
                $plain = $plain === false ? '' : $plain;
                $next = feof($source) ? '' : (string) fread($source, self::CHUNK_BYTES);
                $isFinal = $next === '';

                $this->writeChunk($target, $key, $plain, $index, $isFinal);

                $index++;
                $plain = $next;
            } while (! $isFinal);
        } finally {
            fclose($source);
            fclose($target);
        }
    }

    public function decryptFile(string $sourcePath, string $targetPath, string $passphrase): void
    {
        $source = $this->open($sourcePath, 'rb');
        $target = $this->open($targetPath, 'wb');

        try {
            if (fread($source, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new RuntimeException('This file is not an encrypted RadioRing backup.');
            }

            $iterations = unpack('N', (string) fread($source, 4))[1] ?? 0;
            $salt = (string) fread($source, self::SALT_BYTES);

            if ($iterations < 1000 || strlen($salt) !== self::SALT_BYTES) {
                throw new RuntimeException('The encrypted backup header is damaged.');
            }

            $key = $this->deriveKey($passphrase, $salt, $iterations);

            $index = 0;

            while (true) {
                [$plain, $isFinal] = $this->readChunk($source, $key, $index);

                fwrite($target, $plain);
                $index++;

                if ($isFinal) {
                    break;
                }
            }
        } finally {
            fclose($source);
            fclose($target);
        }
    }

    /**
     * Is this file an encrypted archive? Read from the file itself, never guessed from
     * the extension, so a renamed file still restores correctly.
     */
    public function isEncrypted(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $magic = fread($handle, strlen(self::MAGIC));
        fclose($handle);

        return $magic === self::MAGIC;
    }

    /**
     * @param  resource  $target
     */
    private function writeChunk($target, string $key, string $plain, int $index, bool $isFinal): void
    {
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';

        $cipherText = openssl_encrypt(
            $plain,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $this->additionalData($index, $isFinal),
            self::TAG_BYTES,
        );

        if ($cipherText === false) {
            throw new RuntimeException('Encrypting the backup failed.');
        }

        fwrite($target, chr($isFinal ? 1 : 0));
        fwrite($target, pack('N', strlen($cipherText)));
        fwrite($target, $iv);
        fwrite($target, $tag);
        fwrite($target, $cipherText);
    }

    /**
     * @param  resource  $source
     * @return array{0: string, 1: bool}
     */
    private function readChunk($source, string $key, int $index): array
    {
        $flag = fread($source, 1);

        if ($flag === false || $flag === '') {
            throw new RuntimeException('The encrypted backup ends before its final chunk. It is truncated.');
        }

        $length = unpack('N', (string) fread($source, 4))[1] ?? null;
        $iv = (string) fread($source, self::IV_BYTES);
        $tag = (string) fread($source, self::TAG_BYTES);

        if ($length === null || strlen($iv) !== self::IV_BYTES || strlen($tag) !== self::TAG_BYTES) {
            throw new RuntimeException('The encrypted backup is damaged.');
        }

        $cipherText = $length === 0 ? '' : (string) fread($source, $length);

        if (strlen($cipherText) !== $length) {
            throw new RuntimeException('The encrypted backup is truncated.');
        }

        $isFinal = $flag === chr(1);

        $plain = openssl_decrypt(
            $cipherText,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $this->additionalData($index, $isFinal),
        );

        if ($plain === false) {
            throw new RuntimeException('The backup could not be decrypted. Wrong passphrase, or the file is damaged.');
        }

        return [$plain, $isFinal];
    }

    private function additionalData(int $index, bool $isFinal): string
    {
        return self::MAGIC.pack('N', $index).($isFinal ? '1' : '0');
    }

    private function deriveKey(string $passphrase, string $salt, int $iterations = self::ITERATIONS): string
    {
        return hash_pbkdf2('sha256', $passphrase, $salt, $iterations, 32, true);
    }

    /**
     * @return resource
     */
    private function open(string $path, string $mode)
    {
        $handle = fopen($path, $mode);

        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}.");
        }

        return $handle;
    }
}
