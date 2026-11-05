<?php

declare(strict_types=1);

namespace src\Identity\Profile\Http;

use app\Http\Request;
use app\Service\TranslationService;
use src\Business\UserService;
use src\Entity\User;
use src\Identity\Profile\Application\ProfileManagement;

final class ProfileFormHandler
{
    public function __construct(
        private readonly ProfileManagement $profileManagement,
        private readonly UserService $userService,
        private readonly TranslationService $translation
    ) {
    }

    /** @return array{user: User, errors: list<string>, success: list<string>} */
    public function handle(Request $request, User $user): array
    {
        $messages = ['errors' => [], 'success' => []];
        $user = $this->handleUsernameChange($request, $user, $messages);
        $this->handleEmailChange($request, $user, $messages);

        return ['user' => $user] + $messages;
    }

    public function pendingEmailChange(int $userId): ?array
    {
        $pending = $this->profileManagement->getPendingEmailChange($userId);
        if ($pending === null) {
            return null;
        }

        try {
            $expiresAt = new \DateTimeImmutable($pending->expires_at);
        } catch (\Exception) {
            $expiresAt = null;
        }

        return [
            'newEmail' => $pending->new_email,
            'expiresAt' => $expiresAt,
        ];
    }

    private function handleUsernameChange(Request $request, User $user, array &$messages): User
    {
        if ($request->post('submit_username') === null) {
            return $user;
        }

        $status = $this->profileManagement->changeUsername($user, (string) $request->post('username'));
        if ($status === ProfileManagement::USERNAME_CHANGE_UPDATED) {
            $messages['success'][] = $this->translation->get('account.username-updated');
            $refreshed = $this->userService->getUserById($user->getId());
            return $refreshed instanceof User ? $refreshed : $user;
        }

        $messageKeys = [
            ProfileManagement::USERNAME_CHANGE_INVALID => 'account.username-invalid',
            ProfileManagement::USERNAME_CHANGE_UNCHANGED => 'account.username-unchanged',
            ProfileManagement::USERNAME_CHANGE_TAKEN => 'account.username-taken',
            ProfileManagement::USERNAME_CHANGE_ERROR => 'account.username-error',
        ];
        $messages['errors'][] = $this->translation->get($messageKeys[$status] ?? 'account.username-error');

        return $user;
    }

    private function handleEmailChange(Request $request, User $user, array &$messages): void
    {
        if ($request->post('submit_email') === null) {
            return;
        }

        $status = $this->profileManagement->requestEmailChange($user, (string) $request->post('new_email'));
        if ($status === ProfileManagement::EMAIL_CHANGE_REQUESTED) {
            $messages['success'][] = $this->translation->get('account.email-change-requested');
            return;
        }

        $messageKeys = [
            ProfileManagement::EMAIL_CHANGE_INVALID => 'account.email-change-invalid',
            ProfileManagement::EMAIL_CHANGE_SAME => 'account.email-change-same',
            ProfileManagement::EMAIL_CHANGE_CONFLICT => 'account.email-change-conflict',
            ProfileManagement::EMAIL_CHANGE_ERROR => 'account.email-change-error',
        ];
        $messages['errors'][] = $this->translation->get($messageKeys[$status] ?? 'account.email-change-error');
    }
}
