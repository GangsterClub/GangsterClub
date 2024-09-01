<?php

require_once __DIR__.'/helper.functions.php';

$app = new app\Container\Application(
    ($_ENV['APP_BASE_PATH'] ?? dirname(__DIR__))
);

return $app;
