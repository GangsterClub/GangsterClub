<?PHP

declare(strict_types=1);

namespace app\Business;

use OTPHP\TOTP;

class TOTPService
{
    public function generateSecret(): string
    {
        $totp = TOTP::create();
        return $totp->getSecret();
    }

    public function generateQRCode($secret): string
    {
        $otp = TOTP::create($secret);
        $qrCodeUrl = $otp->getProvisioningUri();
        return "https://api.qrserver.com/v1/create-qr-code/?data=" . urlencode($qrCodeUrl);
    }

    public function verifyTOTP($secret, $code): bool
    {
        $totp = TOTP::create($secret);
        return $totp->verify($code);
    }
}
