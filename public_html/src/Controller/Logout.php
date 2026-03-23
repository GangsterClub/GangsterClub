<?PHP

declare(strict_types=1);

namespace src\Controller;

use app\Http\Request;

class Logout extends Controller
{
    public function __invoke(Request $request): ?string
    {
        $auth = $this->auth();
        $auth->logoutUser();
        $this->application->header('/login');
        return null;
    }
}
