<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helper.functions.php';

function assertHelperSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

$path = tempnam(sys_get_temp_dir(), 'gangsterclub-env-');
if ($path === false) {
    throw new RuntimeException('Unable to create the temporary environment file.');
}

putenv('HELPER_PARENT_VALUE=parent');
putenv('HELPER_ZERO_VALUE=0');
putenv('HELPER_EMPTY_VALUE=');
file_put_contents($path, implode("\n", [
    'HELPER_EXPANDED=${HELPER_PARENT_VALUE}',
    'HELPER_ZERO_REFERENCE=${HELPER_ZERO_VALUE}',
    'HELPER_EMPTY_REFERENCE=${HELPER_EMPTY_VALUE}',
    'HELPER_MISSING_REFERENCE=${HELPER_MISSING_VALUE}',
]));

try {
    loadEnv($path);
    assertHelperSame('parent', HELPER_EXPANDED, 'Truthy environment references should be expanded.');
    assertHelperSame(
        '${HELPER_ZERO_VALUE}',
        HELPER_ZERO_REFERENCE,
        'A zero environment value should preserve the original placeholder.'
    );
    assertHelperSame(
        '${HELPER_EMPTY_VALUE}',
        HELPER_EMPTY_REFERENCE,
        'An empty environment value should preserve the original placeholder.'
    );
    assertHelperSame(
        '${HELPER_MISSING_VALUE}',
        HELPER_MISSING_REFERENCE,
        'A missing environment value should preserve the original placeholder.'
    );
} finally {
    unlink($path);
}

echo "Helper functions tests passed.\n";
