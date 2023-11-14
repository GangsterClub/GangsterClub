<?PHP

declare(strict_types=1);

namespace app\Http;

use app\Container\Application;
use src\Controller\Controller;

class Kernel
{
    private Application $application;
    private ?Response $response;

    public function __construct(Application $application)
    {
        $this->application = $application;
        $this->response = null;
    }

    public function handleRequest(Request $request) : Response
    {
        $method = $request->getMethod();
        $allowedMethods = $request->getParameter('methods') ?? ['GET'];
        $router = $this->application->get('router');
        $route = $router->match($_SERVER['REQUEST_URI'], $method);

        if($route && !in_array($method, $allowedMethods))
            return $this->handleMethodNotAllowed();

        if($route)
        {
            $action = $act = '__invoke';
            $name = $nameAction = $route->getController();
            if (strpos($nameAction, '::') !== false)
                list($name, $action) = explode('::', $nameAction);

            $namespace = SRC_CONTROLLER;
            $prefix = !str_starts_with($name, $namespace) && strpos($name, '\\') == false ? $namespace : '';
            $controller = $prefix . $name;
            $exists = class_exists($controller);
            $action = $exists && method_exists($controller, $action) ? $action : $act;
            $cntrllr = $exists ? $controller : Controller::class;
            $controllerObj = $this->application->make($cntrllr);

            $responseContent = $controllerObj->$action($request);
            return new Response($responseContent);
        }
        return $this->handleNotFound();
    }

    public function terminate(Request $request, Response $response) : void
    {
        // Perform any termination tasks, such as closing database connections, logging, etc.
        // You can access the request and response objects here if needed.
    }

    private function handleNotFound() : Response
    {
        return new Response('Not Found', 404);
    }

    private function handleMethodNotAllowed() : Response
    {
        return new Response('Method Not Allowed', 405);
    }
}
