<?PHP

declare(strict_types=1);

namespace app\Http;

use app\Container\Application;
use app\Middleware\MiddlewarePipeline;
use src\Controller\Controller;
use src\Data\Exception\DatabaseConnectionException;

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
            $router = $this->application->get('router');
            $match = $router->match($request->getPath(), $request->getMethod());
            $finalHandler = $match instanceof RouteMatch && $match->route instanceof Route
                ? fn(Request $request): Response => $this->handleController($match->route, $request)
                : fn(): Response => $this->handleNotFound();

            $request->setRouteParameters($match->parameters);

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
        [$controller, $action] = $this->resolveController($route->getController());
        $instance = $this->application->make($controller);
        $response = $instance->{$action}($request);

        if (($response instanceof Response) === false) {
            throw new \UnexpectedValueException(
                sprintf(
                    'Controller [%s::%s] must return %s.', $controller, $action, Response::class
                )
            );
        }

        return $response;
    }

    private function resolveController(string $notation): array
    {
        [$name, $action] = str_contains($notation, '::') === true
            ? explode('::', $notation, 2)
            : [$notation, '__invoke'];

        $name = trim($name);
        $action = trim($action);

        if ($name === '' || $action === '') {
            throw new \InvalidArgumentException(
                sprintf('Invalid controller notation "%s".', $notation),
            );
        }

        $isFullyQualified = str_starts_with($name, '\\') || str_starts_with($name, 'src\\');
        $name = str_replace('/', '\\', ltrim($name, '\\'));
        $class = $isFullyQualified === true ? $name : Controller::CONTROLLER_NAMESPACE . $name;

        if (class_exists($class) === false) {
            throw new \RuntimeException(
                sprintf('Controller "%s" resolved to missing class "%s".', $notation, $class)
            );
        }

        if (method_exists($class, $action) === false) {
            throw new \RuntimeException(
                sprintf('Controller action "%s::%s" does not exist.', $class, $action)
            );
        }

        return [$class, $action];
    }

    private function handleException(\Throwable $throwable): Response
    {
        if ($throwable instanceof DatabaseConnectionException) {
            $message = $throwable->getPublicMessage();
            if (strtolower(ENVIRONMENT) !== 'production' && DEVELOPMENT === true) {
                $message = $throwable->getDevelopmentMessage();
            }

            return Response::html(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), 500);
        }

        return new Response('Internal Server Error', 500);
    }

    private function handleNotFound(): Response
    {
        return new Response('Not Found', 404);
    }
}
