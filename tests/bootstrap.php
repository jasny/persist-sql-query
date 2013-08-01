<?php

define('BASE_PATH', dirname(__DIR__));
set_include_path(BASE_PATH . '/src' . PATH_SEPARATOR . BASE_PATH . '/tests/support' . PATH_SEPARATOR . get_include_path());

$loader = require_once BASE_PATH . '/vendor/autoload.php';
$loader->setUseIncludePath(true);
