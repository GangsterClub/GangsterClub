<?php

declare(strict_types=1);

namespace src\Identity\AccountSecurity\Http;

use app\Http\Request;
use app\Service\TranslationService;
use src\Entity\User;
use src\Identity\AccountSecurity\Application\TwoStepPreferenceOutcome;
use src\Identity\AccountSecurity\Application\UpdateTwoStepPreference;

final class AccountSecurityFormHandler
{
    public function __construct(
        private readonly UpdateTwoStepPreference $twoStepPreference,
        private readonly TranslationService $translation
    ) {
    }

    /** @return array{errors: list<string>, success: list<string>} */
    public function handle(Request $request, User $user): array
    {
        $messages = ['errors' => [], 'success' => []];
        $this->rejectLegacyAuthenticatorActions($request, $messages);
        $this->handleTwoStepPreference($request, $user, $messages);
        return $messages;
    }

    private function rejectLegacyAuthenticatorActions(Request $request, array &$messages): void
    {
        if ($request->post('submit_authenticator_setup') === null
            && $request->post('submit_authenticator_cancel') === null
            && $request->post('submit_authenticator_confirm') === null
        ) {
            return;
        }
        $messages['errors'][] = $this->translation->get('account.authenticator-flow-required');
    }

    private function handleTwoStepPreference(Request $request, User $user, array &$messages): void
    {
        if ($request->post('submit_two_step') === null) {
            return;
        }
        $outcome = $this->twoStepPreference->update(
            $user->getId(),
            $request->post('require_two_step_verification') === '1'
        );
        $messageKeys = [
            TwoStepPreferenceOutcome::AUTHENTICATOR_REQUIRED->value => 'account.two-step-requires-authenticator',
            TwoStepPreferenceOutcome::ERROR->value => 'account.two-step-update-error',
            TwoStepPreferenceOutcome::ENABLED->value => 'account.two-step-enabled',
            TwoStepPreferenceOutcome::DISABLED->value => 'account.two-step-disabled',
        ];
        $message = $this->translation->get($messageKeys[$outcome->value]);
        if (in_array($outcome, [
            TwoStepPreferenceOutcome::ENABLED,
            TwoStepPreferenceOutcome::DISABLED,
        ], true) === true) {
            $messages['success'][] = $message;
            return;
        }
        $messages['errors'][] = $message;
    }
}
