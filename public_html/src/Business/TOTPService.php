<?PHP

declare(strict_types=1);

namespace src\Business;

use chillerlan\QRCode\QRCode;
use OTPHP\TOTP;

class TOTPService
{
    /**
     * Generate the TOTP secret.
     * @param int $digits
     * @param int $period
     * @return string
     */
    public function generateSecret(int $digits = AUTHENTICATOR_TOTP_DIGITS, int $period = AUTHENTICATOR_TOTP_PERIOD): string
    {
        $totp = TOTP::generate();
        $totp->setDigits($digits);
        $totp->setPeriod($period);
        return $totp->getSecret();
    }

    /**
     * Generate the 6-digit TOTP from the secret.
     *
     * @param ?string $secret The optional secret to use (generated if null).
     * @param ?int $digits The number of digits for the TOTP (default 6).
     * @param ?int $period The time period for the TOTP (default 30 seconds).
     * @return string The 6-digit TOTP code.
     */
    public function generateTOTP(?string $secret = null, ?int $digits = AUTHENTICATOR_TOTP_DIGITS, ?int $period = AUTHENTICATOR_TOTP_PERIOD): string
    {
        $totp = TOTP::create($secret ?? $this->generateSecret(), $period);
        $totp->setDigits($digits);
        return $totp->now();
    }

    public function generateProvisioningUri(
        string $secret,
        string $label = APP_NAME,
        string $issuer = APP_NAME,
        int $digits = AUTHENTICATOR_TOTP_DIGITS,
        int $period = AUTHENTICATOR_TOTP_PERIOD
    ): string
    {
        $totp = TOTP::create($secret, $period);
        $totp->setDigits($digits);
        $totp->setLabel($this->sanitizeProvisioningLabel($label));
        $totp->setIssuer($issuer);

        return $totp->getProvisioningUri();
    }

    private function sanitizeProvisioningLabel(string $label): string
    {
        $sanitized = str_replace(':', ' ', trim($label));

        return $sanitized === '' ? APP_NAME : $sanitized;
    }

    public function verifyTOTP(string $secret, string $code, int $digits = AUTHENTICATOR_TOTP_DIGITS, int $period = AUTHENTICATOR_TOTP_PERIOD): bool
    {
        $totp = TOTP::create($secret, $period);
        $totp->setDigits($digits);
        return $totp->verify($code);
    }

    /**
     * Generate a completely local QR-code data URI.
     *
     * The returned value can be used directly as an <img src="..."> value.
     * No secret or provisioning URI leaves the application.
     */
    public function generateQRCode(
        string $secret,
        string $label = APP_NAME,
        string $issuer = APP_NAME,
        int $digits = AUTHENTICATOR_TOTP_DIGITS,
        int $period = AUTHENTICATOR_TOTP_PERIOD
    ): string {
        $provisioningUri = $this->generateProvisioningUri(
            $secret,
            $label,
            $issuer,
            $digits,
            $period
        );

        return (new QRCode())->render($provisioningUri);
    }
}
