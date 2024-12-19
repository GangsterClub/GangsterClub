<?PHP

declare(strict_types=1);

namespace src\Controller;

use Firebase\JWT\JWT;

class Authorize extends Controller
{
    /**
     * Summary of authorize
     * @param string $authorization
     * @return bool
     */
    public function authorize(string $authorization) : void
    {
        if (! preg_match('/Bearer\s(\S+)/', $authorization, $matches)) {
            header('HTTP/1.0 400 Bad Request');
            print_r('Token not found in request');
            $this->application->exit();
        }

        $jwtToken = $matches[1];
        if (! $jwtToken) {
            // No token was able to be extracted from the authorization header
            header('HTTP/1.0 400 Bad Request');
            print_r('Token not found in request');
            $this->application->exit();
        }

        $secretKey  = JWT_SECRET;
        $token = JWT::decode($jwtToken, $secretKey, ['HS512']);
        $now = new \DateTimeImmutable();
        $serverName = APP_DOMAIN;

        if ($token->iss !== $serverName ||
            $token->nbf > $now->getTimestamp() ||
            $token->exp < $now->getTimestamp())
        {
            header('HTTP/1.1 401 Unauthorized');
            header('WWW-Authenticate: Bearer realm="User Visible Realm", charset="UTF-8", error="invalid_token", error_description="Invalid access token"');
            $this->application->exit();
        }

        // (Re)set token
        header('Authorization: Bearer ' . $jwtToken);
    }
}