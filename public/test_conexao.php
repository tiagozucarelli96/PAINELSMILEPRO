<?php
ini_set('display_errors', 1); error_reporting(E_ALL);
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
$r = $pdo->query('SELECT 1 AS ok')->fetch();
echo $r && isset($r['ok']) ? "OK DB: {$r['ok']}" : "Falhou DB";
