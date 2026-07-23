<?PHP

declare(strict_types=1);

namespace app\Service;

class AuthRateLimitService
{
    private const SESSION_KEY = '_auth_rate_limits';

    public function __construct(private SessionService $session) {}

    public function allowAttempt(string $scope, string $identifier, int $maxAttempts, int $windowSeconds): bool
    {
        $maxAttempts = max(1, $maxAttempts);
        $windowSeconds = max(1, $windowSeconds);
        $key = $this->key($scope, $identifier);
        $now = time();
        $limits = $this->loadLimits();
        $entry = $limits[$key] ?? ['count' => 0, 'expires_at' => 0];
        $expiresAt = (int) ($entry['expires_at'] ?? 0);
        $count = (int) ($entry['count'] ?? 0);

        if ($expiresAt <= $now) {
            $count = 0;
            $expiresAt = $now + $windowSeconds;
        }

        if ($count >= $maxAttempts) {
            $limits[$key] = ['count' => $count, 'expires_at' => $expiresAt];
            $this->storeLimits($limits);
            return false;
        }

        $limits[$key] = ['count' => $count + 1, 'expires_at' => $expiresAt];
        $this->storeLimits($limits);

        return true;
    }

    private function loadLimits(): array
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $limits = $_SESSION[self::SESSION_KEY] ?? [];
            return is_array($limits) === true ? $limits : [];
        }

        $limits = $this->session->get(self::SESSION_KEY, []);
        return is_array($limits) === true ? $limits : [];
    }

    private function storeLimits(array $limits): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::SESSION_KEY] = $limits;
            return;
        }

        $this->session->set(self::SESSION_KEY, $limits);
    }

    private function key(string $scope, string $identifier): string
    {
        return hash('sha256', strtolower(trim($scope)) . ':' . strtolower(trim($identifier)));
    }
}
