<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

/** Native RFC 6238 SHA-1, six digits, 30-second step and at most one-step clock skew. */
final class StaffTotp
{
    public function createSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function matchingCounter(?string $secret, string $code, DateTimeImmutable $at): ?int
    {
        if (! is_string($secret) || ! preg_match('/\A[A-Z2-7]{32}\z/', $secret) || ! preg_match('/\A[0-9]{6}\z/', $code)) {
            return null;
        }
        $key = $this->base32Decode($secret);
        if (strlen($key) !== 20) {
            return null;
        }
        $current = intdiv($at->getTimestamp(), 30);
        foreach ([$current, $current - 1, $current + 1] as $counter) {
            if ($counter < 0) {
                continue;
            }
            $hash = hash_hmac('sha1', pack('N2', intdiv($counter, 4294967296), $counter % 4294967296), $key, true);
            $offset = ord($hash[19]) & 15;
            $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;
            if (hash_equals(str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT), $code)) {
                return $counter;
            }
        }

        return null;
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $result = '';
        foreach (str_split($bits, 5) as $chunk) {
            $result .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
        }

        return $result;
    }

    private function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($secret) as $character) {
            $value = strpos($alphabet, $character);
            if ($value === false) {
                return '';
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
