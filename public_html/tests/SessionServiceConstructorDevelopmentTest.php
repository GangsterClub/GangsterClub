<?php

define('ENVIRONMENT', 'production');
define('DEVELOPMENT', true);
$expectedIpAddress = '10.0.0.1';
$scenarioName = 'development override';
require __DIR__ . '/SessionServiceConstructorTest.php';
