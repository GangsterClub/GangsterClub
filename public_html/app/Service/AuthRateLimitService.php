<?php

declare(strict_types=1);

namespace app\Service;

use app\Container\Application;

class AuthRateLimitService
{
    public function __construct(private readonly Application $application)
    {
    }

    public function allowAttempt(string $scope, string $identifier, int $limit, int $windowSeconds): bool
    {
        $bucketKey = $this->bucketKey($scope, $identifier);
        $session = $this->session();
        $bucket = $session->get($bucketKey, []);

        if (is_array($bucket) === false) {
            $bucket = [];
        }

        $now = time();
        $bucket = $this->pruneBucket($bucket, $windowSeconds, $now);

        if (count($bucket) >= $limit) {
            $session->set($bucketKey, $bucket);
            return false;
        }

        $bucket[] = $now;
        $session->set($bucketKey, $bucket);
        return true;
    }

    public function reset(string $scope, string $identifier): void
    {
        $this->session()->remove($this->bucketKey($scope, $identifier));
    }

    private function pruneBucket(array $bucket, int $windowSeconds, int $now): array
    {
        $cutoff = $now - $windowSeconds;
        return array_values(array_filter($bucket, static fn (mixed $stamp): bool => is_int($stamp) === true && $stamp >= $cutoff));
    }

    private function bucketKey(string $scope, string $identifier): string
    {
        return 'auth_rate_limit_' . $scope . '_' . hash('sha256', $identifier);
    }

    private function session(): object
    {
        return $this->application->get('sessionService');
    }
}
