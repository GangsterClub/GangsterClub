<?PHP

define('GCO_START', microtime(true)); // TBD

if (file_exists($maintenance = __DIR__.'/../app/maintenance.php'))
    require_once $maintenance;

require_once __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../app/app.php';

$kernel = $app->make(app\Http\Kernel::class);

$response = $kernel->handleRequest(
    $request = app\Http\Request::capture($app->get('router'))
)->send();

$kernel->terminate($request, $response);
