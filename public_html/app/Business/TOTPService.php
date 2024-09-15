<?PHP

declare(strict_types=1);

namespace app\Business;

use OTPHP\TOTP;

class TOTPService
{
    /**
     * Summary of generateSecret
     * @return string
     */
    public function generateSecret(int|null $digits = null, int|null $period = null): string
    {
        $totp = TOTP::create();
        $totp->setDigits(($digits ?? MFA_TOTP_DIGITS));
        $totp->setPeriod(($period ?? MFA_TOTP_PERIOD));
        return $totp->getSecret();
    }

    /**
     * Summary of generateQRCode
     * @param string $secret
     * @return string
     */
    public function generateQRCode(string $secret): string
    {
        $totp = TOTP::create($secret);
        $totp->setDigits(MFA_TOTP_DIGITS);
        $totp->setPeriod(MFA_TOTP_PERIOD);
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
    public function verifyTOTP(string $secret, string $code): bool
    {
        $totp = TOTP::createFromSecret($secret);
        return $totp->verify($code);
    }
}
