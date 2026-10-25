<?php

declare(strict_types=1);

namespace src\Business;

final class RecoveryCodeGenerator
{
    public function __construct(private readonly RecoveryCodeCodec $codec)
    {
    }

    /** @return array{codes: array<int, string>, hashes: array<int, string>} */
    public function generate(int $count): array
    {
        $codes = [];
        $hashes = [];
        $generatedCount = 0;
        while ($generatedCount < $count) {
            $code = $this->codec->generate();
            $normalized = $this->codec->normalize($code);
            if ($normalized === null) {
                throw new \RuntimeException('Generated recovery code did not normalize.');
            }

            $hash = $this->codec->hash($normalized);
            if (isset($hashes[$hash]) === true) {
                continue;
            }
            $codes[] = $code;
            $hashes[$hash] = $hash;
            ++$generatedCount;
        }

        return ['codes' => $codes, 'hashes' => array_values($hashes)];
    }
}
