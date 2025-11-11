<?php

declare(strict_types=1);

namespace app\Service;

use app\Container\Application;
use app\Http\Response;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use UnexpectedValueException;

class JWTService
{
    private Application $application;

    private JWT $jwt;

    public function __construct(Application $application, ?JWT $jwt = null)
    {
        $this->application = $application;
        $this->jwt = $jwt ?? new JWT();
    }

    public function authenticate(string $username, bool $hasValidCredentials = false): string|false
    {
        if ($hasValidCredentials === false) {
            return false;
        }

        return $this->jwt->issue($username);
    }

    public function authorize(?string $authorization): Response|array
    {
        $jwtToken = $this->extractBearerToken($authorization);
        if ($jwtToken instanceof Response) {
            return $jwtToken;
        }

        try {
            $payload = $this->jwt->decode($jwtToken);
            $this->jwt->validateClaims($payload);
        } catch (ExpiredException $e) {
            return $this->unauthorizedResponse('Expired access token');
        } catch (SignatureInvalidException $e) {
            return $this->unauthorizedResponse('Signature verification failed');
        } catch (BeforeValidException $e) {
            return $this->unauthorizedResponse('Token cannot yet be used');
        } catch (UnexpectedValueException $e) {
            return $this->unauthorizedResponse('Invalid access token');
        }

        try {
            $refreshedToken = $this->jwt->shouldRefresh($payload)
                ? $this->jwt->refresh($payload)
                : $jwtToken;
        } catch (UnexpectedValueException $e) {
            return $this->unauthorizedResponse('Invalid access token');
        }

        header('Authorization: Bearer ' . $refreshedToken);

        return [
            'token' => $refreshedToken,
            'payload' => $payload,
        ];
    }

    public function refresh(string $jwtToken): Response|string
    {
        try {
            $payload = $this->jwt->decode($jwtToken, JWT::REFRESH_THRESHOLD);
            $this->jwt->validateClaims($payload);
            return $this->jwt->refresh($payload);
        } catch (ExpiredException) {
            return $this->unauthorizedResponse('Expired access token');
        } catch (SignatureInvalidException) {
            return $this->unauthorizedResponse('Signature verification failed');
        } catch (BeforeValidException) {
            return $this->unauthorizedResponse('Token cannot yet be used');
        } catch (UnexpectedValueException) {
            return $this->unauthorizedResponse('Invalid access token');
        }
    }

    private function extractBearerToken(?string $authorization): Response|string
    {
        if ($authorization === null || trim($authorization) === '') {
            return $this->tokenNotFoundResponse();
        }

        if (!preg_match('/Bearer\s+(\S+)/', $authorization, $matches)) {
            return $this->tokenNotFoundResponse();
        }

        return $matches[1];
    }

    private function tokenNotFoundResponse(): Response
    {
        return new Response('Token not found in request', 400);
    }

    private function unauthorizedResponse(string $description): Response
    {
        $header = sprintf(
            'WWW-Authenticate: Bearer realm="User Visible Realm", charset="UTF-8", error="invalid_token", error_description="%s"',
            $description
        );

        return new Response(
            sprintf('401 Unauthorized: %s', $description),
            401,
            [$header]
        );
    }
}
