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
            $action = "__invoke";
            $controller = $controllerAction = $route->getController();
            if (strpos($controllerAction, '::') !== false)
                list($controller, $action) = explode('::', $controllerAction);
            
            $controllerObj = new $controller();
            $docRoot = $_SERVER['DOCUMENT_ROOT'];
            $loader = new \Twig\Loader\FilesystemLoader($docRoot . '/src/Views/');
            $twig = new \Twig\Environment($loader, [
                'cache' => FALSE,//$docRoot . '/app/cache/TwigCompilation',
            ]);
            $controllerObj->addService('twig', $twig);

            $responseContent = ($controllerObj)->$action($request);
            return new Response($responseContent);
        }

        return $this->handleNotFound();
    }

    public function send() : void
    {
        if ($this->response)
            $this->response->send();
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
