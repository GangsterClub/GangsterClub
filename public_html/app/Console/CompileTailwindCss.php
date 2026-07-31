<?php

declare(strict_types=1);

namespace app\Console;

final class CompileTailwindCss
{
    public function compileTailwind(): bool
    {
        $projectRoot = dirname(__DIR__, 2);
        $inputPath = $projectRoot . '/web/css/tailwind.css';
        $outputPath = $projectRoot . '/web/cache/tailwind.css';

        if (!is_file($inputPath)) {
            fwrite(STDERR, 'Tailwind input file not found.' . PHP_EOL);
            return false;
        }

        $outputDirectory = dirname($outputPath);

        if (
            !is_dir($outputDirectory)
            && !mkdir($outputDirectory, 0775, true)
        ) {
            fwrite(
                STDERR,
                'Unable to create Tailwind output directory.' . PHP_EOL
            );

            return false;
        }

        $process = proc_open(
            [
                'tailwindcss',
                '-i',
                $inputPath,
                '-o',
                $outputPath,
                '--minify',
            ],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            fwrite(STDERR, 'Unable to start Tailwind CSS.' . PHP_EOL);
            return false;
        }

        $standardOutput = stream_get_contents($pipes[1]);
        $errorOutput = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            fwrite(
                STDERR,
                'Tailwind compilation failed.' . PHP_EOL
                . $standardOutput
                . $errorOutput
            );

            return false;
        }

        fwrite(STDOUT, 'Compiled Tailwind CSS.' . PHP_EOL);

        return true;
    }
}
