<?php
// Router para servidor embutido do PHP (Railway)
// - Injeta conexao.php antes de servir qualquer .php
// - Deixa arquivos estáticos irem direto
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = realpath(__DIR__ . $path);

// Se for arquivo estático existente (css, js, imagens), serve direto
if ($path !== '/' && $file && is_file($file) && !str_ends_with($file, '.php')) {
    return false;
}

// Se for um .php existente, injeta conexao e inclui o arquivo
if ($path !== '/' && $file && is_file($file) && str_ends_with($file, '.php')) {
    // Injeção de conexão (idempotente)
    $conn = __DIR__ . '/conexao.php';
    if (is_file($conn)) { require_once $conn; }
    require $file;
    exit;
}

// Caso contrário, cai no index.php (front controller)
require __DIR__ . '/index.php';
