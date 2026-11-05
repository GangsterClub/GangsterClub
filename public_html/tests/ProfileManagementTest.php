<?php

declare(strict_types=1);

use app\Http\Request;
use app\Service\TranslationService;
use src\Business\EmailService;
use src\Business\UserService;
use src\Data\Repository\UserEmailChangeRepository;
use src\Data\Repository\UserRepository;
use src\Entity\User;
use src\Identity\Profile\Application\ProfileManagement;
use src\Identity\Profile\Http\ProfileFormHandler;

require_once __DIR__ . '/../vendor/autoload.php';

defined('WEB_ROOT') || define('WEB_ROOT', 'https://example.test/');

final class ProfileTestUsers extends UserRepository
{
    public array $usersByUsername = [];
    public array $usersByEmail = [];
    public bool $usernameUpdateSucceeds = true;
    public bool $emailUpdateSucceeds = true;
    public array $usernameUpdates = [];
    public array $emailUpdates = [];

    public function __construct()
    {
    }

    public function findByUsername(string $username): object|false
    {
        return $this->usersByUsername[strtolower($username)] ?? false;
    }

    public function findByEmail(string $email): object|false
    {
        return $this->usersByEmail[strtolower($email)] ?? false;
    }

    public function updateUsername(int $userId, string $username): bool
    {
        $this->usernameUpdates[] = [$userId, $username];
        return $this->usernameUpdateSucceeds;
    }

    public function updateEmail(int $userId, string $email): bool
    {
        $this->emailUpdates[] = [$userId, $email];
        return $this->emailUpdateSucceeds;
    }
}

final class ProfileTestEmailChanges extends UserEmailChangeRepository
{
    public array $records = [];
    public bool $createSucceeds = true;
    public array $deletedUsers = [];
    public array $deletedIds = [];
    private int $nextId = 1;

    public function __construct()
    {
    }

    public function create(int $userId, string $newEmail, string $tokenHash, string $expiresAt): bool
    {
        if ($this->createSucceeds === false) {
            return false;
        }

        $id = $this->nextId++;
        $this->records[$id] = (object) [
            'id' => $id,
            'user_id' => $userId,
            'new_email' => $newEmail,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'confirmed_at' => null,
        ];
        return true;
    }

    public function findLatestPendingByUserId(int $userId): object|false
    {
        $records = array_reverse($this->records, true);
        foreach ($records as $record) {
            if ((int) $record->user_id === $userId && $record->confirmed_at === null) {
                return $record;
            }
        }
        return false;
    }

    public function findByToken(string $tokenHash): object|false
    {
        foreach ($this->records as $record) {
            if ($record->token_hash === $tokenHash && $record->confirmed_at === null) {
                return $record;
            }
        }
        return false;
    }

    public function markConfirmed(int $id): bool
    {
        if (isset($this->records[$id]) === false) {
            return false;
        }
        $this->records[$id]->confirmed_at = date('Y-m-d H:i:s');
        return true;
    }

    public function deleteByUserId(int $userId): bool
    {
        $this->deletedUsers[] = $userId;
        foreach ($this->records as $id => $record) {
            if ((int) $record->user_id === $userId) {
                unset($this->records[$id]);
            }
        }
        return true;
    }

    public function deleteById(int $id): bool
    {
        $this->deletedIds[] = $id;
        unset($this->records[$id]);
        return true;
    }
}

final class ProfileTestEmail extends EmailService
{
    public bool $succeeds = true;
    public array $messages = [];

    public function __construct()
    {
    }

    public function sendEmailChangeVerification(string $toEmail, string $newEmail, string $verificationUrl): bool
    {
        $this->messages[] = [$toEmail, $newEmail, $verificationUrl];
        return $this->succeeds;
    }
}

final class ProfileTestRequest extends Request
{
    public function __construct(private array $fields)
    {
    }

    public function post(string $key, $default = null): mixed
    {
        return $this->fields[$key] ?? $default;
    }
}

final class ProfileTestTranslation extends TranslationService
{
    public function __construct()
    {
    }

    public function get(string $key, array $replacements = [], bool $useFallback = true): string
    {
        return $key;
    }
}

final class ProfileTestUserService extends UserService
{
    public function __construct(private readonly User $refreshedUser)
    {
    }

    public function getUserById(int $userId): ?User
    {
        return $this->refreshedUser;
    }
}

function assertProfileSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function profileUser(): User
{
    $now = new DateTimeImmutable();
    return new User(7, 'Alice_7', 'alice@example.test', '192.0.2.7', $now, $now, $now);
}

/** @return array{0: ProfileManagement, 1: ProfileTestUsers, 2: ProfileTestEmailChanges, 3: ProfileTestEmail} */
function profileServices(): array
{
    $users = new ProfileTestUsers();
    $changes = new ProfileTestEmailChanges();
    $email = new ProfileTestEmail();
    return [new ProfileManagement($users, $changes, $email), $users, $changes, $email];
}

[$profile, $users] = profileServices();
$user = profileUser();
assertProfileSame(ProfileManagement::USERNAME_CHANGE_INVALID, $profile->changeUsername($user, 'bad name!'), 'Invalid usernames must be rejected.');
assertProfileSame(ProfileManagement::USERNAME_CHANGE_UNCHANGED, $profile->changeUsername($user, 'alice_7'), 'Case-only username changes must remain unchanged.');
$users->usersByUsername['bob-8'] = (object) ['id' => 8];
assertProfileSame(ProfileManagement::USERNAME_CHANGE_TAKEN, $profile->changeUsername($user, 'Bob-8'), 'Existing usernames must remain unavailable.');
$users->usernameUpdateSucceeds = false;
assertProfileSame(ProfileManagement::USERNAME_CHANGE_ERROR, $profile->changeUsername($user, 'Bob-9'), 'Persistence failures must surface as username errors.');
$users->usernameUpdateSucceeds = true;
assertProfileSame(ProfileManagement::USERNAME_CHANGE_UPDATED, $profile->changeUsername($user, ' Bob-9 '), 'Valid unique usernames must update.');
assertProfileSame([7, 'Bob-9'], $users->usernameUpdates[1], 'Username input must retain its existing trim normalization.');

[$profile, $users, $changes, $email] = profileServices();
assertProfileSame(ProfileManagement::EMAIL_CHANGE_INVALID, $profile->requestEmailChange($user, 'not-an-email'), 'Invalid email addresses must be rejected.');
assertProfileSame(ProfileManagement::EMAIL_CHANGE_SAME, $profile->requestEmailChange($user, 'ALICE@example.test'), 'Case-only email changes must remain unchanged.');
$users->usersByEmail['taken@example.test'] = (object) ['id' => 8];
assertProfileSame(ProfileManagement::EMAIL_CHANGE_CONFLICT, $profile->requestEmailChange($user, 'taken@example.test'), 'Existing email addresses must remain unavailable.');
$changes->createSucceeds = false;
assertProfileSame(ProfileManagement::EMAIL_CHANGE_ERROR, $profile->requestEmailChange($user, 'new@example.test'), 'Request persistence failures must surface as errors.');
$changes->createSucceeds = true;
$email->succeeds = false;
assertProfileSame(ProfileManagement::EMAIL_CHANGE_ERROR, $profile->requestEmailChange($user, 'new@example.test'), 'Notification failures must surface as errors.');
assertProfileSame([], $changes->records, 'Notification failures must remove incomplete requests.');
$email->succeeds = true;
assertProfileSame(ProfileManagement::EMAIL_CHANGE_REQUESTED, $profile->requestEmailChange($user, ' new@example.test '), 'Valid unique email changes must be requested.');
assertProfileSame('alice@example.test', $email->messages[1][0], 'Verification must continue going to the current email address.');
assertProfileSame('new@example.test', $email->messages[1][1], 'Email input must retain its existing trim normalization.');
$token = basename($email->messages[1][2]);
assertProfileSame(64, strlen($token), 'Verification tokens must remain 32 random bytes encoded as hexadecimal.');
assertProfileSame(hash('sha256', $token), array_values($changes->records)[0]->token_hash, 'Only the verification-token hash may be persisted.');

assertProfileSame(ProfileManagement::EMAIL_CHANGE_CONFIRMED, $profile->confirmEmailChange($token), 'A valid token must complete the email change.');
assertProfileSame([[7, 'new@example.test']], $users->emailUpdates, 'Confirmation must update the intended user and email.');
assertProfileSame(ProfileManagement::EMAIL_CHANGE_INVALID, $profile->confirmEmailChange($token), 'A consumed token must not be replayable.');

[$profile, $users, $changes] = profileServices();
$changes->create(7, 'late@example.test', hash('sha256', 'expired-token'), date('Y-m-d H:i:s', time() - 1));
assertProfileSame(ProfileManagement::EMAIL_CHANGE_EXPIRED, $profile->confirmEmailChange('expired-token'), 'Expired tokens must be rejected distinctly.');
assertProfileSame([1], $changes->deletedIds, 'Expired token records must be removed.');

$changes->create(7, 'raced@example.test', hash('sha256', 'conflict-token'), date('Y-m-d H:i:s', time() + 3600));
$users->usersByEmail['raced@example.test'] = (object) ['id' => 9];
assertProfileSame(ProfileManagement::EMAIL_CHANGE_CONFLICT, $profile->confirmEmailChange('conflict-token'), 'A uniqueness race must be rechecked at confirmation.');
assertProfileSame([], $changes->records, 'Conflicted requests must be invalidated.');

$changes->create(7, 'retry@example.test', hash('sha256', 'retry-token'), date('Y-m-d H:i:s', time() + 3600));
$users->emailUpdateSucceeds = false;
assertProfileSame(ProfileManagement::EMAIL_CHANGE_INVALID, $profile->confirmEmailChange('retry-token'), 'Update failures must preserve the existing verification result.');
assertProfileSame(1, count($changes->records), 'A database update failure must leave the pending token available for retry.');

$pendingId = array_key_last($changes->records);
if ($pendingId === null) {
    throw new RuntimeException('The database failure must leave a pending request.');
}
$changes->records[$pendingId]->expires_at = date('Y-m-d H:i:s', time() - 1);
assertProfileSame(null, $profile->getPendingEmailChange(7), 'Expired pending requests must not be presented.');
assertProfileSame([], $changes->records, 'Expired pending requests must be cleaned up.');

[$profile, $users] = profileServices();
$refreshed = new User(
    7,
    'Updated-7',
    'alice@example.test',
    '192.0.2.7',
    new DateTimeImmutable(),
    new DateTimeImmutable(),
    new DateTimeImmutable()
);
$handler = new ProfileFormHandler(
    $profile,
    new ProfileTestUserService($refreshed),
    new ProfileTestTranslation()
);
$invalidResult = $handler->handle(new ProfileTestRequest([
    'submit_username' => '1',
    'username' => 'invalid username',
    'submit_email' => '1',
    'new_email' => 'invalid email',
]), $user);
assertProfileSame(
    ['account.username-invalid', 'account.email-change-invalid'],
    $invalidResult['errors'],
    'Profile form validation failures must retain their translated message keys and ordering.'
);
$successResult = $handler->handle(new ProfileTestRequest([
    'submit_username' => '1',
    'username' => 'Updated-7',
]), $user);
assertProfileSame($refreshed, $successResult['user'], 'A successful username update must refresh the Account view user.');
assertProfileSame(
    ['account.username-updated'],
    $successResult['success'],
    'Successful username changes must retain their existing success message.'
);

fwrite(STDOUT, "Profile management tests passed.\n");
