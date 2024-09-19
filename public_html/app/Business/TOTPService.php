<?PHP

declare(strict_types=1);

namespace app\Business;

use OTPHP\TOTP;

class TOTPService
{
    /**
     * Generate the TOTP secret.
     * @param int $digits
     * @param int $period
     * @return string
     */
    public function generateSecret(int $digits = MFA_TOTP_DIGITS, int $period = MFA_TOTP_PERIOD): string
    {
        $totp = TOTP::generate();
        $totp->setDigits($digits);
        $totp->setPeriod($period);
        return $totp->getSecret();
    }

    /**
     * Generate the 6-digit TOTP from the secret.
     *
     * @param string|null $secret The optional secret to use (generated if null).
     * @param int|null $digits The number of digits for the TOTP (default 6).
     * @param int|null $period The time period for the TOTP (default 30 seconds).
     * @return string The 6-digit TOTP code.
     */
    public function generateTOTP(string $secret = null, int $digits = MFA_TOTP_DIGITS, int $period = MFA_TOTP_PERIOD): string
    {
        $totp = TOTP::create($secret ?? TOTP::generateSecret(), $period);
        $totp->setDigits($digits);
        return $totp->now();
    }

    /**
     * Summary of generateQRCode
     * @param string $secret
     * @return string
     */
    public function generateQRCode(string $secret): string
    {
        $totp = TOTP::create($secret, MFA_TOTP_PERIOD);
        $totp->setDigits(MFA_TOTP_DIGITS);
        $totp->setLabel('michael@gangsterclub.com');
        $qrCodeUrl = $totp->getProvisioningUri();
        return "https://api.qrserver.com/v1/create-qr-code/?data=" . urlencode($qrCodeUrl);
    }

    /**
     * Summary of verifyTOTP
     * @param string $secret
     * @param string $code
     * @return bool
     */
    public function verifyTOTP(string $secret, string $code, int $digits = MFA_TOTP_DIGITS, int $period = MFA_TOTP_PERIOD): bool
    {
        $totp = TOTP::create($secret, $period);
        $totp->setDigits($digits);
        return $totp->verify($code);
    }
}
