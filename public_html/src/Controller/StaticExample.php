<?PHP

declare(strict_types=1);

namespace src\Controller;

class StaticExample extends Controller
{
    // See /src/View/staticexample.twig or /static/example route
    // This works because the class exists and extends Controller
    // as defined by Controller->__invoke(), initiated by Kernel->handleController()
}
