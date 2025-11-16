<?PHP

declare(strict_types=1);

namespace src\Business;

use src\Data\Repository\UserEmailChangeRepository;
use src\Data\Repository\UserRepository;

class AccountService
{
    public const EMAIL_CHANGE_CONFIRMED = 'success';
    public const EMAIL_CHANGE_INVALID = 'invalid';
    public const EMAIL_CHANGE_EXPIRED = 'expired';
    public const EMAIL_CHANGE_CONFLICT = 'conflict';

    private UserRepository $userRepository;

    private UserEmailChangeRepository $emailChangeRepository;

    public function __construct(\app\Container\Application $application)
    {
        $dbh = $application->get('dbh');
        $this->userRepository = new UserRepository($dbh);
        $this->emailChangeRepository = new UserEmailChangeRepository($dbh);
    }

    public function changeUsername(int $userId, string $username): bool
    {
        return $this->userRepository->updateUsername($userId, $username);
    }

    public function changeEmail(int $userId, string $email): bool
    {
        return $this->userRepository->updateEmail($userId, $email);
    }

    public function isUsernameTaken(string $username, int $excludeUserId = 0): bool
    {
        $existing = $this->userRepository->findByUsername($username);
        if ($existing === false) {
            return false;
        }

        return (int) $existing->id !== $excludeUserId;
    }

    public function isEmailInUse(string $email, int $excludeUserId = 0): bool
    {
        $existing = $this->userRepository->findByEmail($email);
        if ($existing === false) {
            return false;
        }

        return (int) $existing->id !== $excludeUserId;
    }

    public function createEmailChangeRequest(int $userId, string $newEmail, string $tokenHash, string $expiresAt): bool
    {
        $this->emailChangeRepository->deleteByUserId($userId);
        return $this->emailChangeRepository->create($userId, $newEmail, $tokenHash, $expiresAt);
    }

    public function getPendingEmailChange(int $userId): ?object
    {
        $pending = $this->emailChangeRepository->findLatestPendingByUserId($userId);
        if ($pending === false) {
            return null;
        }

        if (strtotime($pending->expires_at) < time()) {
            $this->emailChangeRepository->deleteById((int) $pending->id);
            return null;
        }

        return $pending;
    }

    public function deletePendingEmailChanges(int $userId): void
    {
        $this->emailChangeRepository->deleteByUserId($userId);
    }

    public function confirmEmailChange(string $token): string
    {
        if (trim($token) === '') {
            return self::EMAIL_CHANGE_INVALID;
        }

        $tokenHash = hash('sha256', $token);

        $record = $this->emailChangeRepository->findByToken($tokenHash);
        if ($record === false) {
            return self::EMAIL_CHANGE_INVALID;
        }

        if (strtotime($record->expires_at) < time()) {
            $this->emailChangeRepository->deleteById((int) $record->id);
            return self::EMAIL_CHANGE_EXPIRED;
        }

        $userId = (int) $record->user_id;
        if ($this->isEmailInUse($record->new_email, $userId) === true) {
            $this->emailChangeRepository->deleteByUserId($userId);
            return self::EMAIL_CHANGE_CONFLICT;
        }

        $updated = $this->userRepository->updateEmail($userId, $record->new_email);
        if ($updated === false) {
            return self::EMAIL_CHANGE_INVALID;
        }

        $this->emailChangeRepository->markConfirmed((int) $record->id);
        $this->emailChangeRepository->deleteByUserId($userId);

        return self::EMAIL_CHANGE_CONFIRMED;
    }
}
