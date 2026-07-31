<?php

declare(strict_types=1);

namespace app\Console;

class CompileTailwindCss
{
    public function compileTailwind(): bool
    {
        $inputPath = __DIR__ . '/../../web/css/tailwind.css';
        $outputPath = __DIR__ . '/../../web/cache/tailwind.css';

        if (is_file($inputPath) === false) {
            fwrite(STDERR, "Tailwind input file not found." . PHP_EOL);
            return false;
        }

        $outputDirectory = dirname($outputPath);

        if (is_dir($outputDirectory) === false && mkdir($outputDirectory, 0775, true) === false) {
            fwrite(STDERR, "Unable to create Tailwind output directory." . PHP_EOL);
            return false;
        }

        $command = sprintf(
            'tailwindcss -i %s -o %s --minify 2>&1',
            escapeshellarg($inputPath),
            escapeshellarg($outputPath)
        );

        $commandOutput = [];
        $exitCode = 0;

        exec($command, $commandOutput, $exitCode);

        if ($exitCode !== 0) {
            fwrite(
                STDERR,
                "Tailwind compilation failed." . PHP_EOL
                . implode(PHP_EOL, $commandOutput)
                . PHP_EOL
            );

            return false;
        }

        fwrite(STDOUT, "Compiled Tailwind CSS." . PHP_EOL);
        return true;
    }
}
