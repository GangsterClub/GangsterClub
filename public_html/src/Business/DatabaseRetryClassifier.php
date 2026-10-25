<?php

declare(strict_types=1);

namespace src\Business;

final class DatabaseRetryClassifier
{
    private const MARIADB_DUPLICATE_ENTRY = 1062;
    private const MARIADB_LOCK_WAIT_TIMEOUT = 1205;
    private const MARIADB_DEADLOCK = 1213;

    public function isUniqueConstraintViolation(\PDOException $exception): bool
    {
        $errorInfo = $exception->errorInfo;
        return (string) $exception->getCode() === '23000'
            && is_array($errorInfo)
            && (int) ($errorInfo[1] ?? 0) === self::MARIADB_DUPLICATE_ENTRY;
    }

    public function isRetryableTransactionFailure(\PDOException $exception): bool
    {
        $errorInfo = $exception->errorInfo;
        if (is_array($errorInfo) === false) {
            return false;
        }

        $sqlState = (string) ($errorInfo[0] ?? '');
        $driverCode = (int) ($errorInfo[1] ?? 0);
        return ($driverCode === self::MARIADB_DEADLOCK && $sqlState === '40001')
            || ($driverCode === self::MARIADB_LOCK_WAIT_TIMEOUT && $sqlState === 'HY000');
    }
}
