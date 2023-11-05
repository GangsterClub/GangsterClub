<?PHP

declare(strict_types=1);

namespace app\Http;

class Kernel
{
    private \app\Container\Application $application;
    private ?Response $response;

    public function __construct(\app\Container\Application $application)
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

        if ($route && !in_array($method, $allowedMethods))
            return $this->handleMethodNotAllowed();

        if ($route) {
            $action = $act = "__invoke";
            $controller = $controllerAction = $route->getController();
            if (strpos($controllerAction, '::') !== false)
                list($controller, $action) = explode('::', $controllerAction);

            $exists = class_exists($controller);
            $action = $exists && method_exists($controller, $action) ? $action : $act;
            $controllerObj = $exists ? new $controller() : new \src\Controller\Controller();
            $responseContent = ($controllerObj)->$action($request);
            return new Response($responseContent);
        }
        return $this->handleNotFound();
    }

    public function send() : void
    {
        $res = $this->response;
        if (isset($res) && is_a($res, 'Response'))
            $res->send();
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

    private function handleMethodNotFound() : Response
    {
        return new Response('Method Not Allowed', 405);
    }
}
