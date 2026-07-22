<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Http\Request;
use app\Http\Response;

class Login extends Controller
{
    public function __invoke(Request $request): Response
    {
        return (new AuthEntryController($this->application))->handle($request, 'login');
    }
}
