<?php
// Router para servidor embutido do PHP
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = __DIR__ . $path;

// Se o arquivo solicitado existe (php, css, js, imagens), serve direto
if ($path !== '/' && file_exists($file) && !is_dir($file)) {
    return false; // deixa o servidor embutido servir o arquivo
}

// Caso contrário, cai no index.php (front controller)
require __DIR__ . '/index.php';
