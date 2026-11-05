<?php

declare(strict_types=1);

namespace src\Identity\Authentication\Http;

use app\Http\Request;
use app\Http\Response;
use app\Http\Router;
use app\Service\AuthService;
use app\Service\FlashBag;
use app\Service\TranslationService;
use src\Business\AuthEntryService;

final class AuthEntryEndpoint
{
    private const LOGIN_RECOVERY_FLASH_BAG = 'login.recovery';

    public function __construct(
        private readonly TranslationService $translation,
        private readonly AuthService $auth,
        private readonly FlashBag $flash,
        private readonly AuthEntryRequestHandler $requests,
        private readonly AuthEntryResponseMapper $responses,
        private readonly AuthEntryViewModelFactory $viewModels,
        private readonly AuthEntryPageRenderer $pages
    ) {
    }

    public function login(Request $request): Response
    {
        return $this->handle($request, AuthEntryService::MODE_LOGIN);
    }

    public function register(Request $request): Response
    {
        return $this->handle($request, AuthEntryService::MODE_REGISTER);
    }

    private function handle(Request $request, string $mode): Response
    {
        $this->translation->setFile($mode);
        if ($this->auth->getAuthenticatedUserId() !== null) {
            return Response::redirect(Router::path('account'), 303);
        }

        $context = [
            $mode => $this->flash->consume($mode),
            'loginRecovery' => $mode === AuthEntryService::MODE_LOGIN
                ? $this->flash->consume(self::LOGIN_RECOVERY_FLASH_BAG)
                : ['errors' => [], 'success' => []],
        ];
        $firstStep = $this->requests->firstStep($request, $mode);
        if ($firstStep !== null) {
            return $this->responses->firstStep($mode, $firstStep);
        }
        $verification = $this->requests->verification($request, $mode);
        if ($verification !== null) {
            return $this->responses->verification($mode, $verification);
        }

        return $this->pages->render(
            $mode,
            array_merge($context, $this->viewModels->build($this->auth, $mode))
        );
    }
}
