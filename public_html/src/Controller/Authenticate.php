<?PHP

declare(strict_types=1);

namespace src\Controller;

use Firebase\JWT\JWT;

class Authenticate extends Controller
{
    public function authenticate($username, $hasValidCredentials = false) : string|false
    {
        if ($hasValidCredentials === true)
        {
            $secretKey  = JWT_SECRET;
            $issuedAt   = new \DateTimeImmutable();
            $expire     = $issuedAt->modify('+6 minutes')->getTimestamp();      // Add 360 seconds
            $serverName = APP_DOMAIN;

            $data = [
                'iat'  => $issuedAt->getTimestamp(),         // Issued at: time when the token was generated
                'iss'  => $serverName,                       // Issuer
                'nbf'  => $issuedAt->getTimestamp(),         // Not before
                'exp'  => $expire,                           // Expire
                'userName' => $username,                     // User name = user.email not user.username
            ];

            // Encode the array to a JWT string.
            return JWT::encode(
                $data,
                $secretKey,
                'HS512'
            );
        }
        return false;
    }
}
