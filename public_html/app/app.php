<?php

$app = new app\Container\Application(
    $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__)
);

return $app;
