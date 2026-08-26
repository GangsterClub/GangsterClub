<?php

declare(strict_types=1);

namespace app\Service;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use src\Business\UserService;
use src\Entity\User;
use UnexpectedValueException;

class JWTService
{
    private JWT $jwt;

    private AuthService $authService;

    public function __construct(JWT $jwt, AuthService $authService, private readonly UserService $userService)
    {
        $this->jwt = $jwt;
        $this->authService = $authService;
    }

    public function authenticate(string $username, bool $hasValidCredentials = false): string|false
    {
        if ($hasValidCredentials === false) {
            return false;
        }

        return $this->jwt->issue($username);
    }

    /**
     * @return array{status: 'authorized', token: string, payload: object}|array{status: 'unauthorized', description: string}
     */
    public function authorize(string $jwtToken): array
    {
        try {
            try {
                $payload = $this->jwt->decode($jwtToken);
            } catch (ExpiredException $e) {
                return $this->authorizeExpiredToken($e->getPayload());
            } catch (SignatureInvalidException $e) {
                return $this->unauthorizedResult('Signature verification failed');
            } catch (BeforeValidException $e) {
                return $this->unauthorizedResult('Token cannot yet be used');
            }

            $identityResponse = $this->authorizePayloadIdentity($payload);
            if ($identityResponse !== null) {
                return $identityResponse;
            }

            try {
                $this->jwt->validateClaims($payload);
            } catch (ExpiredException) {
                return $this->authorizeExpiredToken($payload);
            } catch (BeforeValidException) {
                return $this->unauthorizedResult('Token cannot yet be used');
            }

            $refreshedToken = (bool) $this->jwt->shouldRefresh($payload) === true
                ? $this->jwt->refresh($payload)
                : $jwtToken;
        } catch (UnexpectedValueException) {
            return $this->unauthorizedResult('Invalid access token');
        }

        return [
            'status' => 'authorized',
            'token' => $refreshedToken,
            'payload' => $payload,
        ];
    }

    /**
     * @return array{status: 'authorized', token: string, payload: object}|array{status: 'unauthorized', description: string}
     */
    public function refresh(string $jwtToken): array
    {
        try {
            $payload = $this->jwt->decode($jwtToken, JWT::REFRESH_THRESHOLD);
            $this->jwt->validateClaims($payload);
            return ['status' => 'authorized', 'token' => $this->jwt->refresh($payload), 'payload' => $payload];
        } catch (ExpiredException) {
            return $this->unauthorizedResult('Expired access token');
        } catch (SignatureInvalidException) {
            return $this->unauthorizedResult('Signature verification failed');
        } catch (BeforeValidException) {
            return $this->unauthorizedResult('Token cannot yet be used');
        } catch (UnexpectedValueException) {
            return $this->unauthorizedResult('Invalid access token');
        }
    }

    /** @return null|array{status: 'unauthorized', description: string} */
    private function authorizePayloadIdentity(object $payload): ?array
    {
        if ($this->authService->getAuthenticatedUserId() === null) {
            return null;
        }

        $user = $this->getAuthenticatedUser();
        if ($user === null) {
            return $this->unauthorizedResult('Token identity does not match authenticated session');
        }

        if (is_string($payload->userName ?? null) === false || hash_equals($user->getEmail(), $payload->userName) === false) {
            return $this->unauthorizedResult('Token identity does not match authenticated session');
        }

        return null;
    }

    /**
     * Attempt to re-authorize an expired JWT using the currently authenticated session.
     *
     * @return array{status: 'authorized', token: string, payload: object}|array{status: 'unauthorized', description: string}
     */
    private function authorizeExpiredToken(object $expiredPayload): array
    {
        $identityResponse = $this->authorizePayloadIdentity($expiredPayload);
        if ($identityResponse !== null) {
            return $identityResponse;
        }

        $user = $this->getAuthenticatedUser();
        if ($user === null) {
            return $this->unauthorizedResult('Expired access token');
        }

        try {
            $token = $this->jwt->issue($user->getEmail());
            $payload = $this->jwt->decode($token);
            $this->jwt->validateClaims($payload);
        } catch (ExpiredException | SignatureInvalidException | BeforeValidException | UnexpectedValueException) {
            return $this->unauthorizedResult('Invalid access token');
        }

        return [
            'status' => 'authorized',
            'token' => $token,
            'payload' => $payload,
        ];
    }

    private function getAuthenticatedUser(): ?User
    {
        $userId = $this->authService->getAuthenticatedUserId();
        if ($userId === null) {
            return null;
        }

        return $this->userService->getUserById($userId);
    }

    /** @return array{status: 'unauthorized', description: string} */
    private function unauthorizedResult(string $description): array
    {
        return ['status' => 'unauthorized', 'description' => $description];
    }
}
