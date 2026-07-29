<?php

declare(strict_types=1);

namespace src\Business;

final class RecoveryCodeCodec
{
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    public const SYMBOL_COUNT = 20;
    public const GROUP_SIZE = 5;
    public const ENTROPY_BITS = 100;

    public function __construct(private readonly string $pepper)
    {
        if (strlen($pepper) < 32) {
            throw new \InvalidArgumentException('The recovery-code pepper must contain at least 32 bytes.');
        }
    }

    public function generate(): string
    {
        $bytes = random_bytes(13);
        $buffer = 0;
        $bufferBits = 0;
        $symbols = '';

        for ($index = 0; $index < strlen($bytes) && strlen($symbols) < self::SYMBOL_COUNT; ++$index) {
            $buffer = ($buffer << 8) | ord($bytes[$index]);
            $bufferBits += 8;

            while ($bufferBits >= 5 && strlen($symbols) < self::SYMBOL_COUNT) {
                $bufferBits -= 5;
                $alphabetIndex = ($buffer >> $bufferBits) & 31;
                $symbols .= self::ALPHABET[$alphabetIndex];
                $buffer &= (1 << $bufferBits) - 1;
            }
        }

        if (strlen($symbols) !== self::SYMBOL_COUNT) {
            throw new \RuntimeException('Unable to generate a complete recovery code.');
        }

        return implode('-', str_split($symbols, self::GROUP_SIZE));
    }

    public function normalize(string $code): ?string
    {
        $normalized = strtoupper($code);
        $normalized = str_replace(['-', ' ', "\t", "\r", "\n"], '', $normalized);
        $normalized = strtr($normalized, [
            'O' => '0',
            'I' => '1',
            'L' => '1',
        ]);

        if (strlen($normalized) !== self::SYMBOL_COUNT
            || strspn($normalized, self::ALPHABET) !== self::SYMBOL_COUNT
        ) {
            return null;
        }

        return $normalized;
    }

    public function hash(string $normalizedCode): string
    {
        if (strlen($normalizedCode) !== self::SYMBOL_COUNT
            || strspn($normalizedCode, self::ALPHABET) !== self::SYMBOL_COUNT
        ) {
            throw new \InvalidArgumentException('Only normalized recovery codes may be hashed.');
        }

        return hash_hmac('sha256', 'recovery-code:' . $normalizedCode, $this->pepper);
    }
}
