<?PHP

declare(strict_types=1);

namespace app\Security;

final class SecuritySecretDecoder
{
    public function getRequiredSecret(string $constantName): string
    {
        if (defined($constantName) === false) {
            throw new \RuntimeException(
                $constantName . ' must be configured before the security service is used.'
            );
        }

        return $this->decode($constantName, constant($constantName));
    }

    private function decode(string $constantName, mixed $encodedSecret): string
    {
        if ($this->isValidEncodedSecret($encodedSecret) === false) {
            throw new \RuntimeException(
                $constantName . ' must contain exactly 64 hexadecimal characters.'
            );
        }

        $decodedSecret = hex2bin($encodedSecret);

        if ($decodedSecret === false || strlen($decodedSecret) !== 32) {
            throw new \RuntimeException(
                $constantName . ' must decode to exactly 32 bytes.'
            );
        }

        return $decodedSecret;
    }

    private function isValidEncodedSecret(mixed $encodedSecret): bool
    {
        return is_string($encodedSecret) === true
            && preg_match('/^[a-fA-F0-9]{64}$/', $encodedSecret) === 1;
    }
}
