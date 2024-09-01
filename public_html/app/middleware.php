<?PHP

$kernel->addMiddleware(
    function ($request, $next) use ($app) {
        $sessionMiddleware = $app->make(\app\Middleware\Session::class);
        return $sessionMiddleware->handle($request, $next);
    }
);

// Locale depend on $_SESSION for now
$kernel->addMiddleware(
    function ($request, $next) use ($app) {
        $localeMiddleware = $app->make(\app\Middleware\Locale::class);
        return $localeMiddleware->handle($request, $next);
    }
);

// Other middleware
//$kernel->addMiddleware(function ($request, $next) {
    // Another middleware logic
//    return $next($request);
//});

// Mount twig to your kernel last, if possible
$kernel->addMiddleware(
    function ($request, $next) use ($app) {
        $twigMiddleware = $app->make(\app\Middleware\Twig::class);
        return $twigMiddleware->handle($request, $next);
    }
);
