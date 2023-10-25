<?php

$app = new app\Container\Application(
    $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__)
);

//$app->addService('security', function () {
//    return new \app\Http\Security();
//});

return $app;
