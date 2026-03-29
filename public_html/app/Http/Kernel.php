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

    public function __construct(Application $application)
    {
        $this->application = $application;
        $this->middlewarePipeline = new MiddlewarePipeline();
    }

    public function addMiddleware(callable $middleware): self
    {
        $this->middlewarePipeline->addMiddleware($middleware);
        return $this;
    }

    public function handleRequest(Request $request): Response
    {
        try {
            $method = $request->getMethod();
            $router = $this->application->get('router');
            $route = $router->match(REQUEST_URI, $method);

            $finalHandler = $route instanceof Route
                ? fn(Request $request) => $this->handleController($route, $request)
                : fn() => $this->handleNotFound();

            return $this->middlewarePipeline->handle($request, $finalHandler);
        } catch (\Throwable $throwable) {
            return $this->handleException($throwable);
        }
    }

    public function terminate(Request $request, Response $response): void
    {
        // Perform any termination tasks, such as closing database connections, logging, etc.
        // You can access the request and response objects here if needed.
    }

    private function handleController(Route $route, Request $request): Response
    {
        $action = '__invoke';
        $name = $nameAction = $route->getController();
        if (strpos($nameAction, '::') !== false) {
            list($name, $action) = explode('::', $nameAction);
        }

        $controllerInstance = $this->fetchControllerInstance($name, $action);
        $result = $controllerInstance->$action($request);
        if ($result instanceof Response === false) {
            throw new \UnexpectedValueException(
                sprintf(
                    'Controller [%s::%s] must return %s.',
                    htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($action, ENT_QUOTES, 'UTF-8'),
                    Response::class
                )
            );
        }

        return $result;
    }

    private function fetchControllerInstance(string $name, string $action, string $act = "__invoke"): Controller
    {
        $namespace = SRC_CONTROLLER;
        $prefix = str_starts_with($name, $namespace) === false && strpos($name, '\\') === false ? $namespace : '';
        $controller = $prefix . $name;
        $exists = class_exists($controller);
        $action = $exists === true && method_exists($controller, $action) === true ? $action : $act;
        $cntrllr = $exists === true ? $controller : Controller::class;
        return $this->application->make($cntrllr);
    }

    private function handleException(\Throwable $throwable): Response
    {
        return new Response('Internal Server Error', 500);
    }

    private function handleNotFound(): Response
    {
        return new Response('Not Found', 404);
    }
}
