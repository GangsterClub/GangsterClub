<?PHP

declare(strict_types=1);

namespace src\Business;

use src\Data\Repository\UserEmailChangeRepository;
use src\Data\Repository\UserRepository;
use src\Entity\User;

class AccountService
{
    private const EMAIL_CHANGE_TTL = 3600;

    public const USERNAME_CHANGE_UPDATED = 'updated';
    public const USERNAME_CHANGE_INVALID = 'invalid';
    public const USERNAME_CHANGE_UNCHANGED = 'unchanged';
    public const USERNAME_CHANGE_TAKEN = 'taken';
    public const USERNAME_CHANGE_ERROR = 'error';

    public const EMAIL_CHANGE_CONFIRMED = 'success';
    public const EMAIL_CHANGE_REQUESTED = 'requested';
    public const EMAIL_CHANGE_INVALID = 'invalid';
    public const EMAIL_CHANGE_SAME = 'same';
    public const EMAIL_CHANGE_EXPIRED = 'expired';
    public const EMAIL_CHANGE_CONFLICT = 'conflict';
    public const EMAIL_CHANGE_ERROR = 'error';

    private UserRepository $userRepository;

    private UserEmailChangeRepository $emailChangeRepository;

    private EmailService $emailService;

    public function __construct(\app\Container\Application $application)
    {
        $dbh = $application->get('dbh');
        $this->userRepository = new UserRepository($dbh);
        $this->emailChangeRepository = new UserEmailChangeRepository($dbh);
        $emailService = $application->get('emailService');
        if (($emailService instanceof EmailService) === false) {
            throw new \RuntimeException('emailService service is not available.');
        }

        $this->emailService = $emailService;
    }

    public function changeUsername(User $user, string $username): string
    {
        $username = trim($username);
        if ((bool) preg_match('/^[A-Za-z0-9._-]{3,32}$/', $username) === false) {
            return self::USERNAME_CHANGE_INVALID;
        }

        if (strcasecmp($username, $user->getUsername()) === 0) {
            return self::USERNAME_CHANGE_UNCHANGED;
        }

        if ($this->isUsernameTaken($username, $user->getId()) === true) {
            return self::USERNAME_CHANGE_TAKEN;
        }

        if ($this->userRepository->updateUsername($user->getId(), $username) === false) {
            return self::USERNAME_CHANGE_ERROR;
        }

        return self::USERNAME_CHANGE_UPDATED;
    }

    public function changeEmail(int $userId, string $email): bool
    {
        return $this->userRepository->updateEmail($userId, $email);
    }

    public function requestEmailChange(User $user, string $newEmail): string
    {
        $newEmail = trim($newEmail);
        if (filter_var($newEmail, FILTER_VALIDATE_EMAIL) === false) {
            return self::EMAIL_CHANGE_INVALID;
        }

        if (strcasecmp($newEmail, $user->getEmail()) === 0) {
            return self::EMAIL_CHANGE_SAME;
        }

        if ($this->isEmailInUse($newEmail, $user->getId()) === true) {
            return self::EMAIL_CHANGE_CONFLICT;
        }

        try {
            $rawToken = bin2hex(random_bytes(32));
        } catch (\Throwable $exception) {
            return self::EMAIL_CHANGE_ERROR;
        }

        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', (time() + self::EMAIL_CHANGE_TTL));
        $created = $this->createEmailChangeRequest($user->getId(), $newEmail, $tokenHash, $expiresAt);
        if ($created === false) {
            return self::EMAIL_CHANGE_ERROR;
        }

        $verificationUrl = WEB_ROOT . 'account/email/verify/' . $rawToken;
        $emailSent = $this->emailService->sendEmailChangeVerification($user->getEmail(), $newEmail, $verificationUrl);
        if ($emailSent === false) {
            $this->deletePendingEmailChanges($user->getId());
            return self::EMAIL_CHANGE_ERROR;
        }

        return self::EMAIL_CHANGE_REQUESTED;
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
