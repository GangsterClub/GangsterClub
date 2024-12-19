<?PHP

declare(strict_types=1);

namespace app\Service;

use app\Container\Application;
use app\Http\Response;
use Firebase\JWT\JWT;

class JWTService
{
    /**
     * Summary of application
     * @var Application
     */
    private Application $application;

    /**
     * Summary of __construct
     * @param \app\Container\Application $application
     */
    public function __construct(Application $application)
    {
        $this->application = $application;
    }

    /**
     * Summary of authenticate
     * @param string $username
     * @param bool $hasValidCredentials
     * @return string|false
     */
    public function authenticate(string $username, bool $hasValidCredentials = false) : string|false
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

    /**
     * Summary of authorize
     * @param string $authorization
     * @return \app\Http\Response|true
     */
    public function authorize(string $authorization) : Response|true
    {
        if (! preg_match('/Bearer\s(\S+)/', $authorization, $matches)) {
            header('HTTP/1.0 400 Bad Request');
            return new Response('Token not found in request', 400);
        }

        $jwtToken = $matches[1];
        if (! $jwtToken) {
            // No token was able to be extracted from the authorization header
            header('HTTP/1.0 400 Bad Request');
            return new Response('Token not found in request', 400);
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
            return new Response('401 Unauthorized: Invalid access token', 401);
        }
        header('Authorization: Bearer ' . $jwtToken);
        return true;
    }
}
