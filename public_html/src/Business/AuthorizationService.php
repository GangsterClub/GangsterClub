<?PHP

declare(strict_types=1);

namespace src\Business;

use app\Container\Application;
use app\Http\Response;
use app\Service\JWTService;

class AuthorizationService
{
    private Application $application;

    private JWTService $jwtService;

    public function __construct(Application $application)
    {
        $this->application = $application;
        $this->jwtService = new JWTService($application);
    }

    public function authorize(string $authorization) : void
    {
        $result = $this->jwtService->authorize($authorization);
        if ($result instanceof Response) {
            $result->send();
            $this->application->exit();
        }
    }
}
