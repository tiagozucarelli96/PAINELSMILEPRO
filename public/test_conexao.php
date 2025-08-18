<?php
require_once __DIR__ . '/conexao.php';
header('Content-Type: text/plain; charset=utf-8');

if (!empty($db_error)) {
    echo "ERRO: {$db_error}\n";
    exit;
}

try {
    $stmt = $pdo->query("SELECT DATABASE() AS db, CURRENT_USER() AS user");
    $row  = $stmt->fetch();
    echo "OK: conectado em DB=".$row['db']." | como ".$row['user']."\n";
} catch (Throwable $e) {
    echo "ERRO: ".$e->getMessage()."\n";
}
