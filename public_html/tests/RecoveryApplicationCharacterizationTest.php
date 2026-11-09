<?php

declare(strict_types=1);

use src\Business\AuthenticationChallengeService;
use src\Business\AuthenticationRateLimitContext;
use src\Business\AuthenticatorTOTPService;
use src\Business\EmailService;
use src\Business\EmailTOTPService;
use src\Business\EmailTOTPPurpose;
use src\Business\RecoveryCodeConsumptionResult;
use src\Business\RecoveryCodeGenerationResult;
use src\Business\RecoveryCodeService;
use src\Business\RecoveryFeatureService;
use src\Business\RecoveryFlowService;
use src\Entity\User;
use src\Identity\Recovery\Application\RecoveryAction;
use src\Identity\Recovery\Application\RecoveryActionInput;
use src\Identity\Recovery\Application\RecoveryCodeLifecycle;
use src\Identity\Recovery\Application\RecoveryEmailVerification;
use src\Identity\Recovery\Application\RecoveryFlowSnapshot;
use src\Identity\Recovery\Application\RecoveryFlowState;
use src\Identity\Recovery\Application\RecoveryIdentityContext;
use src\Identity\Recovery\Application\RecoveryMode;
use src\Identity\Recovery\Application\RecoveryOutcome;
use src\Identity\Recovery\Application\RecoveryRateLimitContextFactory;
use src\Identity\Recovery\Application\RecoveryVerification;

require_once __DIR__ . '/../vendor/autoload.php';

defined('AUTHENTICATOR_TOTP_DIGITS') || define('AUTHENTICATOR_TOTP_DIGITS', 6);
defined('AUTHENTICATOR_TOTP_PERIOD') || define('AUTHENTICATOR_TOTP_PERIOD', 30);

final class RecoveryCharacterizationFlow implements RecoveryFlowState
{
    public ?int $pendingSetId = null;
    public ?int $deliveredSetId = null;
    public ?string $pendingSecret = null;
    public ?string $challengeToken = 'challenge-token';
    public ?string $challengePurpose = 'authenticator_enrollment';
    public int $clearCount = 0;

    public function getChallengeBinding(): string { return 'challenge-binding'; }
    public function snapshot(): RecoveryFlowSnapshot
    {
        return new RecoveryFlowSnapshot(
            $this->challengeToken,
            $this->challengePurpose,
            $this->pendingSetId,
            $this->deliveredSetId,
            $this->pendingSecret
        );
    }
    public function setPendingRecoverySetId(?int $setId): void { $this->pendingSetId = $setId; }
    public function markRecoveryCodesDelivered(int $setId): void { $this->deliveredSetId = $setId; }
    public function setPendingAuthenticatorSecret(?string $secret): void { $this->pendingSecret = $secret; }
    public function setChallenge(string $token, string $purpose): void
    {
        $this->challengeToken = $token;
        $this->challengePurpose = $purpose;
    }
    public function clear(): void
    {
        ++$this->clearCount;
        $this->challengeToken = null;
        $this->challengePurpose = null;
        $this->pendingSetId = null;
        $this->deliveredSetId = null;
        $this->pendingSecret = null;
    }
}

final class RecoveryCharacterizationRates implements RecoveryRateLimitContextFactory
{
    public array $challengeCalls = [];

    public function forChallenge(int $userId, int $challengeId): AuthenticationRateLimitContext
    {
        $this->challengeCalls[] = [$userId, $challengeId];
        return AuthenticationRateLimitContext::forUser($userId, '192.0.2.100', 'session', (string) $challengeId);
    }

    public function forToken(int $userId, string $token): AuthenticationRateLimitContext
    {
        return AuthenticationRateLimitContext::forUser($userId, '192.0.2.100', 'session', $token);
    }
}

final class RecoveryCharacterizationEmailTotp extends EmailTOTPService
{
    public bool $verified = false;
    public array $verifyCalls = [];

    public function __construct() {}

    public function verify(
        int $userId,
        EmailTOTPPurpose $purpose,
        string $submittedCode,
        AuthenticationRateLimitContext $context
    ): bool {
        $this->verifyCalls[] = [$userId, $purpose, $submittedCode, $context];
        return $this->verified;
    }
}

final class RecoveryCharacterizationAuthenticator extends AuthenticatorTOTPService
{
    public int $generatedSecrets = 0;

    public function __construct() {}

    public function generateSecret(
        int $digits = AUTHENTICATOR_TOTP_DIGITS,
        int $period = AUTHENTICATOR_TOTP_PERIOD
    ): string {
        ++$this->generatedSecrets;
        return 'PENDING-SECRET';
    }
}

final class RecoveryCharacterizationChallenges extends AuthenticationChallengeService
{
    public array $transitions = [];
    public array $transitionResults = [];
    public bool $fresh = true;

    public function __construct() {}

    public function transition(
        string $rawToken,
        string $sessionId,
        string $purpose,
        string $expectedState,
        string $nextState
    ): ?object {
        $this->transitions[] = [$rawToken, $sessionId, $purpose, $expectedState, $nextState];
        if ($this->transitionResults !== []) {
            return array_shift($this->transitionResults);
        }
        return (object) ['id' => 501, 'purpose' => $purpose, 'state' => $nextState];
    }

    public function isFreshReauthentication(object $record, string $purpose): bool
    {
        return $this->fresh;
    }

    public function cancel(string $rawToken, string $sessionId, string $purpose): bool
    {
        return true;
    }
}

final class RecoveryCharacterizationCodes extends RecoveryCodeService
{
    public RecoveryCodeConsumptionResult $consumption;
    public array $generationCalls = [];
    public array $invalidations = [];
    private int $nextSetId = 700;

    public function __construct()
    {
        $this->consumption = new RecoveryCodeConsumptionResult(RecoveryCodeConsumptionResult::STATUS_INVALID);
    }

    public function consumeActiveCode(
        int $userId,
        string $submittedCode,
        AuthenticationRateLimitContext $rateLimitContext,
        \src\Business\AuthenticationRateLimitPurpose $purpose
    ): RecoveryCodeConsumptionResult {
        return $this->consumption;
    }

    public function generatePendingSet(
        int $userId,
        \src\Business\AuthenticationRateLimitPurpose $purpose,
        AuthenticationRateLimitContext $rateLimitContext,
        int $authenticatorGeneration = 1,
        ?int $challengeId = null,
        ?int $replacesSetId = null
    ): RecoveryCodeGenerationResult {
        $this->generationCalls[] = [
            $userId, $purpose, $authenticatorGeneration, $challengeId, $replacesSetId,
        ];
        ++$this->nextSetId;
        return new RecoveryCodeGenerationResult(
            $this->nextSetId,
            ['CODE-A', 'CODE-B'],
            new DateTimeImmutable('+15 minutes')
        );
    }

    public function invalidatePendingSet(int $userId, int $setId, string $reason): bool
    {
        $this->invalidations[] = [$userId, $setId, $reason];
        return true;
    }
}

final class RecoveryCharacterizationEmail extends EmailService
{
    public array $securityNotifications = [];
    public array $completionNotifications = [];

    public function __construct() {}

    public function sendSecurityNotification(string $toEmail, string $subject, string $message): bool
    {
        $this->securityNotifications[] = [$toEmail, $subject, $message];
        return true;
    }

    public function sendRecoveryCompletionNotification(string $toEmail, string $purpose, bool $lostFlow): void
    {
        $this->completionNotifications[] = [$toEmail, $purpose, $lostFlow];
    }
}

final class RecoveryCharacterizationFeature extends RecoveryFeatureService
{
    public string $completion = self::COMPLETION_SUCCESS;

    public function __construct() {}

    public function completeAcknowledgedFlow(
        int $userId,
        int $pendingSetId,
        object $challenge,
        ?string $pendingSecret,
        string $challengeToken,
        string $sessionBinding,
        bool $lostFlow
    ): string {
        return $this->completion;
    }
}

final class RecoveryCharacterizationIdentity implements RecoveryIdentityContext
{
    public array $logins = [];
    public int $csrfRotations = 0;

    public function authenticatedUserId(): ?int { return 42; }
    public function pendingUserId(): ?int { return 42; }
    public function pendingAuthenticatorRequired(): bool { return true; }
    public function login(int $userId): void { $this->logins[] = $userId; }
    public function rotateCsrfToken(): void { ++$this->csrfRotations; }
}

function recoveryCharacterizationSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}

function recoveryCharacterizationStub(string $className): object
{
    return (new ReflectionClass($className))->newInstanceWithoutConstructor();
}

function recoveryCharacterizationUser(): User
{
    $now = new DateTimeImmutable();
    return new User(42, 'characterization', 'characterization@example.test', '192.0.2.100', $now, $now, $now);
}

function recoveryCharacterizationChallenge(string $purpose, string $state): object
{
    return (object) [
        'id' => 501,
        'purpose' => $purpose,
        'state' => $state,
        'baseline_authenticator_generation' => 3,
        'baseline_recovery_code_set_id' => 600,
    ];
}

$user = recoveryCharacterizationUser();
$flow = new RecoveryCharacterizationFlow();
$rates = new RecoveryCharacterizationRates();
$emailTotp = new RecoveryCharacterizationEmailTotp();
$authenticator = new RecoveryCharacterizationAuthenticator();
$challenges = new RecoveryCharacterizationChallenges();
$codes = new RecoveryCharacterizationCodes();
$email = new RecoveryCharacterizationEmail();
$feature = new RecoveryCharacterizationFeature();
$identity = new RecoveryCharacterizationIdentity();
$lifecycle = new RecoveryCodeLifecycle($challenges, $codes, $feature, $flow, $identity, $rates, $email);
$emailVerification = new RecoveryEmailVerification(
    $emailTotp,
    $email,
    $authenticator,
    $challenges,
    $flow,
    $rates
);
$verification = new RecoveryVerification(
    $emailVerification,
    $authenticator,
    recoveryCharacterizationStub(RecoveryFlowService::class),
    $challenges,
    $flow,
    $rates,
    $lifecycle
);

$enrollment = recoveryCharacterizationChallenge('authenticator_enrollment', 'email_verification_pending');
$invalidEmail = $verification->handle(
    RecoveryAction::VERIFY_EMAIL,
    new RecoveryActionInput(RecoveryAction::VERIFY_EMAIL, emailCode: '000000'),
    $user,
    $enrollment,
    RecoveryMode::ACCOUNT
);
recoveryCharacterizationSame(RecoveryOutcome::EMAIL_CODE_INVALID, $invalidEmail->outcome, 'Invalid email codes must retain their outcome.');
recoveryCharacterizationSame([], $challenges->transitions, 'Invalid email codes must not advance the challenge.');
recoveryCharacterizationSame(null, $flow->pendingSecret, 'Invalid email codes must not create an authenticator secret.');

$emailTotp->verified = true;
$configuredEmail = $verification->handle(
    RecoveryAction::VERIFY_EMAIL,
    new RecoveryActionInput(RecoveryAction::VERIFY_EMAIL, emailCode: ' 111 111 '),
    $user,
    $enrollment,
    RecoveryMode::ACCOUNT
);
recoveryCharacterizationSame(RecoveryOutcome::CONFIGURE_AUTHENTICATOR, $configuredEmail->outcome, 'Enrollment email verification must configure an authenticator.');
recoveryCharacterizationSame('PENDING-SECRET', $flow->pendingSecret, 'Enrollment email verification must store the generated secret.');
recoveryCharacterizationSame(2, count($challenges->transitions), 'Enrollment email verification must retain both challenge transitions.');
recoveryCharacterizationSame('111111', $emailTotp->verifyCalls[1][2], 'Email OTP whitespace normalization must be preserved.');

$flow->pendingSecret = null;
$flow->challengePurpose = 'lost_authenticator_recovery';
$challenges->transitions = [];
$lostChallenge = recoveryCharacterizationChallenge('lost_authenticator_recovery', 'email_verification_pending');
$lostEmail = $verification->handle(
    RecoveryAction::VERIFY_EMAIL,
    new RecoveryActionInput(RecoveryAction::VERIFY_EMAIL, emailCode: '111111'),
    $user,
    $lostChallenge,
    RecoveryMode::LOST_AUTHENTICATOR
);
recoveryCharacterizationSame(RecoveryOutcome::ENTER_RECOVERY_CODE, $lostEmail->outcome, 'Lost recovery email verification must request a recovery code.');
recoveryCharacterizationSame(null, $flow->pendingSecret, 'Lost recovery email verification must not create a secret before recovery-code verification.');
recoveryCharacterizationSame(2, count($challenges->transitions), 'Lost recovery email verification must retain both challenge transitions.');

$challenges->transitions = [];
$challenges->transitionResults = [null];
$expiredEmail = $verification->handle(
    RecoveryAction::VERIFY_EMAIL,
    new RecoveryActionInput(RecoveryAction::VERIFY_EMAIL, emailCode: '111111'),
    $user,
    $lostChallenge,
    RecoveryMode::LOST_AUTHENTICATOR
);
recoveryCharacterizationSame(RecoveryOutcome::CHALLENGE_EXPIRED, $expiredEmail->outcome, 'Failed email challenge transitions must remain expired.');
recoveryCharacterizationSame(1, count($challenges->transitions), 'An expired first transition must stop subsequent transitions.');

$challenges->transitionResults = [];
$challenges->transitions = [];
$codes->consumption = new RecoveryCodeConsumptionResult(RecoveryCodeConsumptionResult::STATUS_INVALID);
$invalidCode = $verification->handle(
    RecoveryAction::VERIFY_RECOVERY_CODE,
    new RecoveryActionInput(RecoveryAction::VERIFY_RECOVERY_CODE, recoveryCode: 'BAD-CODE'),
    $user,
    recoveryCharacterizationChallenge('lost_authenticator_recovery', 'recovery_code_pending'),
    RecoveryMode::LOST_AUTHENTICATOR
);
recoveryCharacterizationSame(RecoveryOutcome::RECOVERY_CODE_INVALID, $invalidCode->outcome, 'Invalid recovery codes must retain their outcome.');
recoveryCharacterizationSame([], $challenges->transitions, 'Invalid recovery codes must not advance the challenge.');
recoveryCharacterizationSame([], $email->securityNotifications, 'Invalid recovery codes must not send security notifications.');

$codes->consumption = new RecoveryCodeConsumptionResult(RecoveryCodeConsumptionResult::STATUS_RATE_LIMITED, null, 73);
$limitedCode = $verification->handle(
    RecoveryAction::VERIFY_RECOVERY_CODE,
    new RecoveryActionInput(RecoveryAction::VERIFY_RECOVERY_CODE, recoveryCode: 'LIMITED'),
    $user,
    recoveryCharacterizationChallenge('lost_authenticator_recovery', 'recovery_code_pending'),
    RecoveryMode::LOST_AUTHENTICATOR
);
recoveryCharacterizationSame(RecoveryOutcome::FLOW_RATE_LIMITED, $limitedCode->outcome, 'Rate-limited recovery codes must retain their outcome.');
recoveryCharacterizationSame(73, $limitedCode->retryAfterSeconds, 'Recovery-code retry duration must be preserved.');
recoveryCharacterizationSame([], $challenges->transitions, 'Rate-limited recovery codes must not advance the challenge.');

$codes->consumption = new RecoveryCodeConsumptionResult(RecoveryCodeConsumptionResult::STATUS_CONSUMED, 7);
$consumedCode = $verification->handle(
    RecoveryAction::VERIFY_RECOVERY_CODE,
    new RecoveryActionInput(RecoveryAction::VERIFY_RECOVERY_CODE, recoveryCode: 'VALID'),
    $user,
    recoveryCharacterizationChallenge('lost_authenticator_recovery', 'recovery_code_pending'),
    RecoveryMode::LOST_AUTHENTICATOR
);
recoveryCharacterizationSame(RecoveryOutcome::LOST_REPLACEMENT_WARNING, $consumedCode->outcome, 'Consumed recovery codes must retain the replacement warning.');
recoveryCharacterizationSame(7, $consumedCode->remainingCount, 'Consumed recovery codes must retain the remaining count.');
recoveryCharacterizationSame(2, count($challenges->transitions), 'Consumed recovery codes must retain both challenge transitions.');
recoveryCharacterizationSame(1, count($email->securityNotifications), 'Consumed recovery codes must send exactly one security notification.');

$challenges->transitions = [];
$challenges->transitionResults = [null];
$notificationsBeforeFailure = count($email->securityNotifications);
$expiredConsumedCode = $verification->handle(
    RecoveryAction::VERIFY_RECOVERY_CODE,
    new RecoveryActionInput(RecoveryAction::VERIFY_RECOVERY_CODE, recoveryCode: 'VALID'),
    $user,
    recoveryCharacterizationChallenge('lost_authenticator_recovery', 'recovery_code_pending'),
    RecoveryMode::LOST_AUTHENTICATOR
);
recoveryCharacterizationSame(RecoveryOutcome::CHALLENGE_EXPIRED, $expiredConsumedCode->outcome, 'Post-consumption transition failures must remain expired.');
recoveryCharacterizationSame($notificationsBeforeFailure, count($email->securityNotifications), 'Failed post-consumption transitions must not send notifications.');

$challenges->transitionResults = [];
$flow->challengePurpose = 'replace_recovery_codes';
$flow->pendingSetId = null;
$flow->deliveredSetId = null;
$replacement = recoveryCharacterizationChallenge('replace_recovery_codes', 'replacement_confirmed');
$generated = $lifecycle->generate($user, $replacement, 'replacement_confirmed', RecoveryMode::ACCOUNT);
recoveryCharacterizationSame(RecoveryOutcome::CODES_GENERATED, $generated->outcome, 'Pending-set generation must retain its outcome.');
recoveryCharacterizationSame(701, $flow->pendingSetId, 'Pending-set generation must store the generated set.');
recoveryCharacterizationSame(701, $flow->deliveredSetId, 'Successful presentation must mark the set delivered.');
recoveryCharacterizationSame([3, 501, 600], array_slice($codes->generationCalls[0], 2), 'Generation baselines must be preserved.');

$regenerated = $lifecycle->regenerate(
    $user,
    recoveryCharacterizationChallenge('replace_recovery_codes', 'replacement_codes_presented_unacknowledged'),
    RecoveryMode::ACCOUNT
);
recoveryCharacterizationSame(RecoveryOutcome::CODES_REGENERATED, $regenerated->outcome, 'Regeneration must retain its outcome.');
recoveryCharacterizationSame([[42, 701, 'unacknowledged_display']], $codes->invalidations, 'Regeneration must invalidate the previous pending set with the same reason.');
recoveryCharacterizationSame(702, $flow->pendingSetId, 'Regeneration must replace the pending set.');
recoveryCharacterizationSame(702, $flow->deliveredSetId, 'Regeneration must mark the replacement set delivered.');

echo "Recovery application characterization tests passed.\n";
