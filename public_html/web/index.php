<?PHP

define('GCO_START', microtime(true));

if ((bool) file_exists($maintenance = __DIR__.'/../app/maintenance.php') === true) {
    require_once $maintenance;
}

require_once __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../app/app.php';

$kernel = $app->make(app\Http\Kernel::class);

require_once __DIR__.'/../app/middleware.php';

$response = $kernel->handleRequest(
    $request = \app\Http\Request::capture()
)->send();

$kernel->terminate($request, $response);
