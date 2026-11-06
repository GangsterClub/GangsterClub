<?php

declare(strict_types=1);

use app\Service\AuthService;
use app\Service\AuthSessionKeys;
use app\Service\CsrfService;
use app\Service\SecurityFlowSession;
use app\Service\SessionService;
use src\Business\AuthenticationRateLimitContext;
use src\Business\AuthenticationRateLimitBucketDimension;
use src\Business\AuthenticatorTOTPService;
use src\Business\AuthenticatorTOTPPurpose;
use src\Business\RateLimitExceededException;
use src\Business\RecoveryFeatureService;
use src\Business\UserService;
use src\Identity\AccountSecurity\Application\AuthenticatorDisableOutcome;
use src\Identity\AccountSecurity\Application\DisableAuthenticator;
use src\Identity\AccountSecurity\Application\TwoStepPreferenceOutcome;
use src\Identity\AccountSecurity\Application\UpdateTwoStepPreference;

require_once __DIR__ . '/../vendor/autoload.php';

defined('AUTHENTICATOR_TOTP_DIGITS') || define('AUTHENTICATOR_TOTP_DIGITS', 6);

final class AccountSecurityApplicationSession extends SessionService
{
    public array $values = [];

    public function __construct()
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->values[$key]);
    }
}

final class AccountSecurityApplicationCsrf extends CsrfService
{
    public int $rotations = 0;

    public function __construct()
    {
    }

    public function rotateToken(): string
    {
        ++$this->rotations;
        return 'rotated-token';
    }
}

final class AccountSecurityApplicationAuthenticator extends AuthenticatorTOTPService
{
    public array $verifyCalls = [];
    public bool $verified = true;
    public bool $rateLimited = false;
    public bool $enabled = true;

    public function __construct()
    {
    }

    public function verify(
        int $userId,
        AuthenticatorTOTPPurpose $purpose,
        string $submittedCode,
        AuthenticationRateLimitContext $context
    ): bool {
        $this->verifyCalls[] = [$userId, $purpose, $submittedCode, $context];
        if ($this->rateLimited === true) {
            throw new RateLimitExceededException('authenticator_totp_verify', 60);
        }
        return $this->verified;
    }

    public function hasEnabledAuthenticator(int $userId): bool
    {
        return $this->enabled;
    }
}

final class AccountSecurityApplicationRecovery extends RecoveryFeatureService
{
    public array $calls = [];
    public bool $succeeds = true;

    public function __construct()
    {
    }

    public function disableAuthenticatorAndRecoveryCodes(int $userId): bool
    {
        $this->calls[] = $userId;
        return $this->succeeds;
    }
}

final class AccountSecurityApplicationUsers extends UserService
{
    public array $updates = [];
    public bool $succeeds = true;

    public function __construct()
    {
    }

    public function setTwoStepVerificationRequired(int $userId, bool $required): bool
    {
        $this->updates[] = [$userId, $required];
        return $this->succeeds;
    }
}

function assertAccountSecurityApplicationSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$session = new AccountSecurityApplicationSession();
$session->values[AuthSessionKeys::PENDING_AUTHENTICATOR_SECRET] = 'pending-secret';
$session->values['_IPaddress'] = '192.0.2.75';
$session->values[AuthSessionKeys::SECURITY_CHALLENGE_BINDING] = 'existing-security-binding';
$csrf = new AccountSecurityApplicationCsrf();
$auth = new AuthService($session, $csrf);
$securityFlow = new SecurityFlowSession($session);
$authenticator = new AccountSecurityApplicationAuthenticator();
$recovery = new AccountSecurityApplicationRecovery();
$disable = new DisableAuthenticator($authenticator, $recovery, $session, $securityFlow, $auth);

assertAccountSecurityApplicationSame(
    AuthenticatorDisableOutcome::CODE_REQUIRED,
    $disable->disable(42, '12'),
    'Malformed codes must be rejected before verification.'
);
assertAccountSecurityApplicationSame([], $authenticator->verifyCalls, 'Malformed codes must not reach verification.');

$authenticator->verified = false;
assertAccountSecurityApplicationSame(
    AuthenticatorDisableOutcome::INVALID_CODE,
    $disable->disable(42, '123456'),
    'Invalid codes must retain the invalid-code outcome.'
);
assertAccountSecurityApplicationSame(
    AuthenticatorTOTPPurpose::AUTHENTICATOR_DISABLE,
    $authenticator->verifyCalls[0][1],
    'Disablement must use its dedicated authenticator purpose.'
);

$authenticator->rateLimited = true;
assertAccountSecurityApplicationSame(
    AuthenticatorDisableOutcome::INVALID_CODE,
    $disable->disable(42, '123456'),
    'Rate limiting must remain indistinguishable from an invalid code.'
);
$authenticator->rateLimited = false;
$authenticator->verified = true;
$recovery->succeeds = false;
assertAccountSecurityApplicationSame(
    AuthenticatorDisableOutcome::ERROR,
    $disable->disable(42, '123456'),
    'Authenticator and recovery persistence failures must surface as errors.'
);
assertAccountSecurityApplicationSame('pending-secret', $securityFlow->getPendingAuthenticatorSecret(), 'Failed disablement must preserve pending setup state.');
assertAccountSecurityApplicationSame(0, $csrf->rotations, 'Failed disablement must not rotate CSRF.');

$recovery->succeeds = true;
assertAccountSecurityApplicationSame(
    AuthenticatorDisableOutcome::DISABLED,
    $disable->disable(42, ' 123 456 '),
    'Valid codes must disable the authenticator and recovery codes.'
);
assertAccountSecurityApplicationSame(null, $securityFlow->getPendingAuthenticatorSecret(), 'Successful disablement must clear pending setup state.');
assertAccountSecurityApplicationSame(1, $csrf->rotations, 'Successful disablement must rotate CSRF exactly once.');

$users = new AccountSecurityApplicationUsers();
$preference = new UpdateTwoStepPreference($authenticator, $users);
$authenticator->enabled = false;
assertAccountSecurityApplicationSame(
    TwoStepPreferenceOutcome::AUTHENTICATOR_REQUIRED,
    $preference->update(42, true),
    'Enabling two-step verification must require an enabled authenticator.'
);
assertAccountSecurityApplicationSame([], $users->updates, 'Missing-authenticator failures must not persist a preference.');

$authenticator->enabled = true;
$users->succeeds = false;
assertAccountSecurityApplicationSame(TwoStepPreferenceOutcome::ERROR, $preference->update(42, true), 'Preference persistence failures must surface as errors.');
$users->succeeds = true;
assertAccountSecurityApplicationSame(TwoStepPreferenceOutcome::ENABLED, $preference->update(42, true), 'The required preference must enable successfully.');
assertAccountSecurityApplicationSame(TwoStepPreferenceOutcome::DISABLED, $preference->update(42, false), 'The optional preference must disable successfully.');
assertAccountSecurityApplicationSame(
    [[42, true], [42, true], [42, false]],
    $users->updates,
    'Preference persistence must retain the submitted boolean semantics.'
);

$dimensions = $authenticator->verifyCalls[0][3]->dimensionValues();
assertAccountSecurityApplicationSame(
    '192.0.2.75',
    $dimensions[AuthenticationRateLimitBucketDimension::IP_ADDRESS->value] ?? null,
    'The disable rate-limit adapter must retain the session IP dimension.'
);
assertAccountSecurityApplicationSame(
    'existing-security-binding',
    $dimensions[AuthenticationRateLimitBucketDimension::SESSION->value] ?? null,
    'The disable rate-limit adapter must retain the security-flow binding as its session dimension.'
);

fwrite(STDOUT, "Account security application tests passed.\n");
