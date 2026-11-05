<?php

declare(strict_types=1);

namespace src\Identity\Profile\Http;

use app\Http\Request;
use app\Http\Response;
use app\Service\TranslationService;
use src\Identity\Profile\Application\ProfileManagement;
use Twig\Environment;

final class EmailChangeVerification
{
    public function __construct(
        private readonly ProfileManagement $profileManagement,
        private readonly TranslationService $translation,
        private readonly Environment $twig
    ) {
    }

    public function verify(Request $request): Response
    {
        $token = (string) $request->getRouteParameter('token', '');
        $status = $token === ''
            ? ProfileManagement::EMAIL_CHANGE_INVALID
            : $this->profileManagement->confirmEmailChange($token);

        $messageKeys = [
            ProfileManagement::EMAIL_CHANGE_CONFIRMED => 'account.email-change-confirmed',
            ProfileManagement::EMAIL_CHANGE_EXPIRED => 'account.email-change-expired',
            ProfileManagement::EMAIL_CHANGE_CONFLICT => 'account.email-change-conflict-token',
        ];

        return Response::html($this->twig->render('account-email-verify.twig', [
            'verification' => [
                'success' => $status === ProfileManagement::EMAIL_CHANGE_CONFIRMED,
                'message' => $this->translation->get(
                    $messageKeys[$status] ?? 'account.email-change-invalid-token'
                ),
            ],
        ]));
    }
}
