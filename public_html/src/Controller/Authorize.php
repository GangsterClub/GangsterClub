<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Http\Response;
use app\Service\JWTService;

class Authorize extends Controller
{
    public function authorize(string $authorization) : void
    {
        $jwtService = new JWTService($this->application);
        $result = $jwtService->authorize($authorization);
        if ($result instanceof Response) {
            $result->send();
            $this->application->exit();
        }
    }
}
