<?PHP

declare(strict_types=1);

namespace app\Http;

use app\Container\Application;
use app\Middleware\MiddlewarePipeline;
use src\Controller\Controller;

class Kernel
{
    /**
     * Summary of application
     * @var Application
     */
    private Application $application;

    /**
     * Summary of middlewarePipeline
     * @var MiddlewarePipeline
     */
    private MiddlewarePipeline $middlewarePipeline;

    /**
     * Summary of response
     * @var mixed
     */
    private ?Response $response;

    /**
     * Summary of __construct
     * @param \app\Container\Application $application
     */
    public function __construct(Application $application)
    {
        $this->application = $application;
        $this->middlewarePipeline = new MiddlewarePipeline();
        $this->response = null;
    }

    /**
     * Summary of addMiddleware
     * @param callable $middleware
     * @return \app\Http\Kernel
     */
    public function addMiddleware(callable $middleware): self
    {
        $this->middlewarePipeline->addMiddleware($middleware);
        return $this;
    }

    /**
     * Summary of handleRequest
     * @param \app\Http\Request $request
     * @return \app\Http\Response
     */
    public function handleRequest(Request $request): Response
    {
        $method = $request->getMethod();
        $allowedMethods = ($request->getParameter('methods') ?? ['GET']);
        $isAllowed = in_array($method, $allowedMethods) === true;
        $router = $this->application->get('router');
        $route = $router->match(REQUEST_URI, $method);
        $finalHandler = new Response('Empty');
        if ((bool) $route === false && $isAllowed === true) {
            $finalHandler = function () {
                return $this->handleNotFound();
            };
        }

        if (in_array($method, $allowedMethods) === false) {
            $finalHandler = function () {
                return $this->handleMethodNotAllowed();
            };
        }
                
        if ((bool) $route === true && $isAllowed === true) {
            $finalHandler = function ($request) use ($route) {
                return $this->handleController($route, $request);
            };
        }                

        return $this->middlewarePipeline->handle($request, $finalHandler);
    }

    /**
     * Summary of terminate
     * @param \app\Http\Request $request
     * @param \app\Http\Response $response
     * @return void
     */
    public function terminate(Request $request, Response $response): void
    {
        // Perform any termination tasks, such as closing database connections, logging, etc.
        // You can access the request and response objects here if needed.
    }

    /**
     * Summary of handleController
     * @param \app\Http\Route $route
     * @param \app\Http\Request $request
     * @return \app\Http\Response
     */
    private function handleController(Route $route, Request $request): Response
    {
        $action = $act = '__invoke';
        $name = $nameAction = $route->getController();
        if (strpos($nameAction, '::') !== false) {
            list($name, $action) = explode('::', $nameAction);
        }

        $namespace = SRC_CONTROLLER;
        $prefix = str_starts_with($name, $namespace) === false && strpos($name, '\\') === false ? $namespace : '';
        $controller = $prefix.$name;
        $exists = class_exists($controller);
        $action = $exists === true && method_exists($controller, $action) === true ? $action : $act;
        $cntrllr = $exists === true ? $controller : Controller::class;
        $controllerObj = $this->application->make($cntrllr);

        return new Response($controllerObj->$action($request));
    }

    /**
     * Summary of handleNotFound
     * @return \app\Http\Response
     */
    private function handleNotFound(): Response
    {
        return new Response('Not Found', 404);
    }

    /**
     * Summary of handleMethodNotAllowed
     * @return \app\Http\Response
     */
    private function handleMethodNotAllowed(): Response
    {
        return new Response('Method Not Allowed', 405);
    }
}
