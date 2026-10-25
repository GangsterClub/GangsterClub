<?php

declare(strict_types=1);

namespace app\Console;

final class MigrationSnapshotStore
{
    private string $snapshotFile;

    public function __construct(string $snapshotDirectory)
    {
        $snapshotDirectory = rtrim($snapshotDirectory, DIRECTORY_SEPARATOR);
        if (is_dir($snapshotDirectory) === false) {
            mkdir($snapshotDirectory, 0770, true);
        }
        $this->snapshotFile = $snapshotDirectory . '/latest_snapshot.json';
    }

    public function exists(): bool
    {
        return is_file($this->snapshotFile);
    }

    public function read(): ?array
    {
        if ($this->exists() === false) {
            return null;
        }

        $payload = json_decode((string) file_get_contents($this->snapshotFile), true);
        if (json_last_error() !== JSON_ERROR_NONE || is_array($payload) === false) {
            return null;
        }

        return $payload;
    }

    public function write(array $payload): void
    {
        file_put_contents(
            $this->snapshotFile,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    public function delete(): void
    {
        if ($this->exists() === true) {
            unlink($this->snapshotFile);
        }
    }
}
