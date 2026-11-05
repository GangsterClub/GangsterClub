<?php

declare(strict_types=1);

namespace src\Identity\Authentication\Http;

use app\Http\Response;
use Twig\Environment;

final class AuthEntryPageRenderer
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function render(string $mode, array $context): Response
    {
        return Response::html($this->twig->render($mode . '.twig', $context));
    }
}
