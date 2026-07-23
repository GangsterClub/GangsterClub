<?php

declare(strict_types=1);

use app\Container\Application;
use app\Http\Request;
use app\Http\Response;
use src\Controller\Account;

if (defined('DOC_ROOT') === false) {
    define('DOC_ROOT', dirname(__DIR__));
}

if (defined('APP_BASE') === false) {
    define('APP_BASE', '/game');
}

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'app\\' => DOC_ROOT . '/app/',
        'src\\' => DOC_ROOT . '/src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (str_starts_with($class, $prefix) === false) {
            continue;
        }

        $relativeClass = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        if (is_file($file) === true) {
            require $file;
        }
    }
});


final class AccountRedirectTestSession
{
    public function set(string $key, mixed $value): void
    {
    }
}

final class AccountRedirectTestApplication extends Application
{
    public function __construct()
    {
    }

    public function get(string $name): ?object
    {
        if ($name === 'sessionService') {
            return new AccountRedirectTestSession();
        }

        return null;
    }
}

final class AccountRedirectTestRequest extends Request
{
    public function __construct(private string $testMethod)
    {
    }

    public function getMethod(): string
    {
        return $this->testMethod;
    }
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertHeaderContains(Response $response, string $expectedHeader, string $message): void
{
    if (in_array($expectedHeader, $response->getHeaders(), true) === false) {
        throw new RuntimeException($message . ' Headers: ' . var_export($response->getHeaders(), true));
    }
}

$account = (new ReflectionClass(Account::class))->newInstanceWithoutConstructor();
$applicationProperty = new ReflectionProperty(Account::class, 'application');
$applicationProperty->setAccessible(true);
$applicationProperty->setValue($account, new AccountRedirectTestApplication());
$redirectToLogin = new ReflectionMethod(Account::class, 'redirectToLogin');
$redirectToLogin->setAccessible(true);

$getResponse = $redirectToLogin->invoke($account, new AccountRedirectTestRequest('GET'));
assertSameValue(302, $getResponse->getStatusCode(), 'Unauthenticated GET navigation to account should use a temporary 302 redirect.');
assertHeaderContains($getResponse, 'Location: /game/login', 'GET account redirects should point to login.');

$postResponse = $redirectToLogin->invoke($account, new AccountRedirectTestRequest('POST'));
assertSameValue(303, $postResponse->getStatusCode(), 'Unauthenticated POST actions to account should use 303 for Post/Redirect/Get.');
assertHeaderContains($postResponse, 'Location: /game/login', 'POST account redirects should point to login.');

$redirectScanRoots = [DOC_ROOT . '/src', DOC_ROOT . '/app'];
foreach ($redirectScanRoots as $root) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($iterator as $file) {
        if ($file->isFile() === false || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());
        if (preg_match('/Response::redirect\([^;]*,\s*301\s*\)/s', $contents) === 1) {
            throw new RuntimeException('Non-permanent redirects under public_html/src and public_html/app must not use 301: ' . $file->getPathname());
        }
    }
}
