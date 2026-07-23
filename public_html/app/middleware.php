<?PHP

global $app;

$kernel->addMiddleware(
    function ($request, $next) use ($app) {
        $sessionMiddleware = $app->make(\app\Middleware\Session::class);
        return $sessionMiddleware->handle($request, $next);
    }
);

// Locale depends on $_SESSION for now.
$kernel->addMiddleware(
    function ($request, $next) use ($app) {
        $localeMiddleware = $app->make(\app\Middleware\Locale::class);
        return $localeMiddleware->handle($request, $next);
    }
);

// Mount Twig before CSRF so CSRF failures can render application-styled error pages.
$kernel->addMiddleware(
    function ($request, $next) use ($app) {
        $twigMiddleware = $app->make(\app\Middleware\Twig::class);
        return $twigMiddleware->handle($request, $next);
    }
);

$kernel->addMiddleware(
    function ($request, $next) use ($app) {
        $csrfMiddleware = $app->make(\app\Middleware\Csrf::class);
        return $csrfMiddleware->handle($request, $next);
    }
);

$kernel->addMiddleware(
    function ($request, $next) use ($app) {
        $securityHeadersMiddleware = $app->make(\app\Middleware\SecurityHeaders::class);
        return $securityHeadersMiddleware->handle($request, $next);
    }
);
