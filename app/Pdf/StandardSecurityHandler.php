<?php

namespace App\Pdf;

use RuntimeException;

/**
 * The PDF standard security handler (ISO 32000-1 §7.6.3, ISO 32000-2 §7.6.4)
 * with an empty user password: derives the file key and decrypts streams.
 * RC4, AES-128 and AES-256 (revisions 2-6); a real password is refused.
 */
final class StandardSecurityHandler
{
    /** The padding string every password is extended with (Algorithm 2). */
    private const PAD = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    /**
     * @param  string  $key  the file encryption key
     * @param  string  $method  how streams are encrypted: rc4 / aesv2 / aesv3 / identity
     */
    private function __construct(private readonly string $key, private readonly string $method) {}

    /**
     * Build the handler from the /Encrypt dictionary (names as keys,
     * strings as raw bytes) and the first string of the trailer's /ID.
     *
     * @param  array<string, mixed>  $encrypt
     */
    public static function fromEncryptDictionary(array $encrypt, string $firstId): self
    {
        // Only the standard handler.
        if (($encrypt['Filter'] ?? 'Standard') !== 'Standard') {
            throw new RuntimeException(__('This PDF is protected by a security handler (:filter) that cannot be opened here.', ['filter' => (string) $encrypt['Filter']]));
        }

        $version = (int) ($encrypt['V'] ?? 0);
        $revision = (int) ($encrypt['R'] ?? 2);
        $method = self::streamMethod($encrypt, $version);

        // Revisions 5 and 6 derive a 256-bit key from SHA-2 hashes of the password and the salts in /U.
        if ($revision >= 5) {
            return new self(self::keyForRevision5or6($encrypt, $revision), $method);
        }

        // Revisions 2 to 4 derive the key from MD5 of the padded password, /O, /P and the file ID (Algorithm 2).
        $length = $revision === 2 ? 5 : intdiv((int) ($encrypt['Length'] ?? 40), 8);
        $hash = md5(self::PAD.substr((string) ($encrypt['O'] ?? ''), 0, 32).pack('V', (int) ($encrypt['P'] ?? 0)).$firstId
            .($revision >= 4 && ($encrypt['EncryptMetadata'] ?? true) === false ? "\xFF\xFF\xFF\xFF" : ''), true);

        // Revision 3 and up: 50 more rounds of MD5.
        if ($revision >= 3) {
            for ($i = 0; $i < 50; $i++) {
                $hash = md5(substr($hash, 0, $length), true);
            }
        }

        $key = substr($hash, 0, $length);

        // Check the empty user password against /U (Algorithms 4 and 5) so a PDF that really needs a password says so.
        $expected = $revision === 2
            ? self::rc4($key, self::PAD)
            : self::rc4Rounds($key, md5(self::PAD.$firstId, true));

        // Only the first 16 bytes of /U are compared.
        if (! hash_equals(substr($expected, 0, 16), substr((string) ($encrypt['U'] ?? ''), 0, 16))) {
            throw self::needsPassword();
        }

        return new self($key, $method);
    }

    /** Decrypt one stream; RC4 and AES-128 use a per-object key (Algorithm 1). */
    public function decrypt(string $data, int $objectNumber, int $generation): string
    {
        // By the stream crypt method.
        return match ($this->method) {
            'identity' => $data,
            'rc4' => self::rc4($this->objectKey($objectNumber, $generation, false), $data),
            'aesv2' => self::aesCbc($this->objectKey($objectNumber, $generation, true), $data),
            default => self::aesCbc($this->key, $data),
        };
    }

    /**
     * The stream crypt method: RC4 before V4, else the /CF filter /StmF names.
     *
     * @param  array<string, mixed>  $encrypt
     */
    private static function streamMethod(array $encrypt, int $version): string
    {
        // Before V4: always RC4.
        if ($version < 4) {
            return 'rc4';
        }

        $filter = (string) ($encrypt['StmF'] ?? 'Identity');

        // Streams not encrypted.
        if ($filter === 'Identity') {
            return 'identity';
        }

        // The crypt filter's method.
        return match ($encrypt['CF'][$filter]['CFM'] ?? 'None') {
            'V2' => 'rc4',
            'AESV2' => 'aesv2',
            'AESV3' => 'aesv3',
            'None' => 'identity',
            default => throw new RuntimeException(__('This PDF uses an encryption method (:method) that cannot be opened here.', ['method' => (string) $encrypt['CF'][$filter]['CFM']])),
        };
    }

    /**
     * Revisions 5 and 6 (Algorithm 2.A): check /U with its validation salt,
     * then decrypt /UE with the key hashed from the key salt.
     *
     * @param  array<string, mixed>  $encrypt
     */
    private static function keyForRevision5or6(array $encrypt, int $revision): string
    {
        $u = (string) ($encrypt['U'] ?? '');
        $validationSalt = substr($u, 32, 8);
        $keySalt = substr($u, 40, 8);

        // The empty password must match /U.
        if (! hash_equals(substr($u, 0, 32), self::hash2B('', $validationSalt, $revision))) {
            throw self::needsPassword();
        }

        $intermediate = self::hash2B('', $keySalt, $revision);
        $key = openssl_decrypt(substr((string) ($encrypt['UE'] ?? ''), 0, 32), 'aes-256-cbc', $intermediate, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16));

        // /UE did not decrypt.
        if ($key === false) {
            throw self::needsPassword();
        }

        return $key;
    }

    /**
     * The password hash (Algorithm 2.B): plain SHA-256 for revision 5; for 6,
     * rounds of AES-128 choosing SHA-256/384/512, at least 64, until the last
     * byte allows a stop.
     */
    private static function hash2B(string $password, string $salt, int $revision): string
    {
        $k = hash('sha256', $password.$salt, true);

        // Revision 5: SHA-256 only.
        if ($revision === 5) {
            return $k;
        }

        // Revision 6 rounds.
        for ($round = 0; ; $round++) {
            $k1 = str_repeat($password.$k, 64);
            $e = (string) openssl_encrypt($k1, 'aes-128-cbc', substr($k, 0, 16), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($k, 16, 16));
            $algorithm = ['sha256', 'sha384', 'sha512'][array_sum(array_map('ord', str_split(substr($e, 0, 16)))) % 3];
            $k = hash($algorithm, $e, true);

            // Stop after 64 rounds once the last byte is at most round number - 32.
            if ($round >= 63 && ord($e[strlen($e) - 1]) <= $round - 31) {
                return substr($k, 0, 32);
            }
        }
    }

    /** One object's key: MD5 of file key, object number and generation (low bytes first), plus "sAlT" for AES. */
    private function objectKey(int $objectNumber, int $generation, bool $aes): string
    {
        $hash = md5($this->key.substr(pack('V', $objectNumber), 0, 3).substr(pack('v', $generation), 0, 2).($aes ? 'sAlT' : ''), true);

        return substr($hash, 0, min(strlen($this->key) + 5, 16));
    }

    /** AES-CBC with the IV in the first 16 bytes; truncated data is read to its last whole block. */
    private static function aesCbc(string $key, string $data): string
    {
        $iv = substr($data, 0, 16);
        $body = substr($data, 16, intdiv(strlen($data) - 16, 16) * 16);
        $cipher = strlen($key) === 32 ? 'aes-256-cbc' : 'aes-128-cbc';

        // Nothing past the IV.
        if ($body === '') {
            return '';
        }

        $plain = openssl_decrypt($body, $cipher, $key, OPENSSL_RAW_DATA, $iv);

        // Bad padding: keep the bytes without unpadding.
        return $plain !== false ? $plain : (string) openssl_decrypt($body, $cipher, $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
    }

    /** Algorithm 5's twenty RC4 rounds, the key XORed with the round number. */
    private static function rc4Rounds(string $key, string $data): string
    {
        // Rounds 0 to 19.
        for ($round = 0; $round <= 19; $round++) {
            $data = self::rc4($key ^ str_repeat(chr($round), strlen($key)), $data);
        }

        return $data;
    }

    /** RC4, in PHP because OpenSSL 3 ships it only in its legacy provider. */
    private static function rc4(string $key, string $data): string
    {
        $s = range(0, 255);
        $keyLength = strlen($key);

        // Key scheduling.
        for ($i = 0, $j = 0; $i < 256; $i++) {
            $j = ($j + $s[$i] + ord($key[$i % $keyLength])) & 255;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
        }

        $out = '';
        $length = strlen($data);

        // Keystream XORed with the data.
        for ($k = 0, $i = 0, $j = 0; $k < $length; $k++) {
            $i = ($i + 1) & 255;
            $j = ($j + $s[$i]) & 255;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
            $out .= chr(ord($data[$k]) ^ $s[($s[$i] + $s[$j]) & 255]);
        }

        return $out;
    }

    /** The error for a PDF that needs a password. */
    private static function needsPassword(): RuntimeException
    {
        return new RuntimeException(__('This PDF needs a password to open.'));
    }
}
