<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/env_bootstrap.php';
require_once __DIR__ . '/client_app_api.php';

$debug = painel_is_debug();
ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');
error_reporting($debug ? E_ALL : (E_ALL & ~E_NOTICE));
