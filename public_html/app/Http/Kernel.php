<?PHP

declare(strict_types=1);

namespace app\Http;

use app\Container\Application;
use app\Middleware\MiddlewarePipeline;
use src\Controller\Controller;

class Kernel
{
    private Application $application;
    private MiddlewarePipeline $middlewarePipeline;
    private ?Response $response;

    public function __construct(Application $application)
    {
        $this->application = $application;
        $this->middlewarePipeline = new MiddlewarePipeline();
        $this->response = null;
    }

    public function addMiddleware(callable $middleware): self
    {
        $this->middlewarePipeline->addMiddleware($middleware);
        return $this;
    }

    public function handleRequest(Request $request): Response
    {
        $method = $request->getMethod();
        $allowedMethods = $request->getParameter('methods') ?? ['GET'];
        $router = $this->application->get('router');
        $route = $router->match(REQUEST_URI, $method);
        if (!$route) {
            return $this->handleNotFound();
        }

        if ($route && !in_array($method, $allowedMethods)) {
            return $this->handleMethodNotAllowed();
        }

        if ($route) {
            $finalHandler = function ($request) use ($route) {
                return $this->handleController($route, $request);
            };

            return $this->middlewarePipeline->handle($request, $finalHandler);
        }
    }

    public function terminate(Request $request, Response $response): void
    {
        // Perform any termination tasks, such as closing database connections, logging, etc.
        // You can access the request and response objects here if needed.
    }
    
    private function handleController(Route $route, Request $request): Response
    {
        $action = $act = '__invoke';
        $name = $nameAction = $route->getController();
        if (strpos($nameAction, '::') !== false) {
            list($name, $action) = explode('::', $nameAction);
        }

        $namespace = SRC_CONTROLLER;
        $prefix = !str_starts_with($name, $namespace) && strpos($name, '\\') === false ? $namespace : '';
        $controller = $prefix.$name;
        $exists = class_exists($controller);
        $action = $exists && method_exists($controller, $action) ? $action : $act;
        $cntrllr = $exists ? $controller : Controller::class;
        $controllerObj = $this->application->make($cntrllr);

        return new Response($controllerObj->$action($request));
    }

    private function handleNotFound(): Response
    {
        return new Response('Not Found', 404);
    }

    private function handleMethodNotAllowed(): Response
    {
        return new Response('Method Not Allowed', 405);
    }
}
