<?php

define('ENVIRONMENT', 'testing');
define('DEVELOPMENT', false);
$expectedIpAddress = '10.0.0.1';
$scenarioName = 'non-production';
require __DIR__ . '/SessionServiceConstructorTest.php';
