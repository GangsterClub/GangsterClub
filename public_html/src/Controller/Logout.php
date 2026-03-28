<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Http\Request;
use app\Http\Response;

class Logout extends Controller
{
    public function __invoke(Request $request): Response
    {
        $auth = $this->auth();
        $auth->logoutUser();
        return Response::redirect(APP_BASE . '/login', 301);
    }
}
