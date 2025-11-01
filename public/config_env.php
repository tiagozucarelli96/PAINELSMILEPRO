<?php
// config_env.php — Configurações de ambiente
// Este arquivo centraliza todas as variáveis de ambiente do sistema

// Configurações do Banco de Dados
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_PORT', $_ENV['DB_PORT'] ?? '5432');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'painelsmile');
define('DB_USER', $_ENV['DB_USER'] ?? 'postgres');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');

// Configurações da Aplicação
define('APP_NAME', $_ENV['APP_NAME'] ?? 'GRUPO Smile EVENTOS');
define('APP_URL', $_ENV['APP_URL'] ?? 'https://seudominio.railway.app');
define('APP_DEBUG', $_ENV['APP_DEBUG'] ?? '0');

// Configurações ASAAS
define('ASAAS_API_KEY', $_ENV['ASAAS_API_KEY'] ?? 'aact_prod_000MzkwODA2MWY2OGM3MWRlMDU2NWM3MzJlNzZmNGZhZGY6OmE2OGQ4NzhlLTc1ZWQtNGMyZC04Mzk2LTg2NWI0YWJiZjA4ZDo6JGFhY2hfOTA2MGY5NzYtMzQ2Zi00OTBmLWJiMTQtYzAyM2FjNTM1NDk5');
define('ASAAS_BASE_URL', $_ENV['ASAAS_BASE_URL'] ?? 'https://www.asaas.com/api/v3');
define('WEBHOOK_URL', $_ENV['WEBHOOK_URL'] ?? 'https://seudominio.railway.app/public/asaas_webhook.php');

// Configurações de E-mail SMTP
define('SMTP_HOST', $_ENV['SMTP_HOST'] ?? '');
define('SMTP_PORT', $_ENV['SMTP_PORT'] ?? '587');
define('SMTP_USERNAME', $_ENV['SMTP_USERNAME'] ?? '');
define('SMTP_PASSWORD', $_ENV['SMTP_PASSWORD'] ?? '');
define('SMTP_FROM_NAME', $_ENV['SMTP_FROM_NAME'] ?? 'GRUPO Smile EVENTOS');
define('SMTP_FROM_EMAIL', $_ENV['SMTP_FROM_EMAIL'] ?? '');
define('SMTP_REPLY_TO', $_ENV['SMTP_REPLY_TO'] ?? '');

// Configurações ME Eventos (se necessário)
define('ME_BASE_URL', $_ENV['ME_BASE_URL'] ?? '');
define('ME_API_KEY', $_ENV['ME_API_KEY'] ?? '');

// Configurações de Segurança
define('JWT_SECRET', $_ENV['JWT_SECRET'] ?? 'sua_chave_secreta_aqui');
define('ENCRYPTION_KEY', $_ENV['ENCRYPTION_KEY'] ?? 'sua_chave_de_criptografia_aqui');

// Configurações de Upload
define('UPLOAD_MAX_SIZE', $_ENV['UPLOAD_MAX_SIZE'] ?? '10485760'); // 10MB
define('UPLOAD_ALLOWED_TYPES', $_ENV['UPLOAD_ALLOWED_TYPES'] ?? 'pdf,jpg,jpeg,png');

// Configurações de Log
define('LOG_LEVEL', $_ENV['LOG_LEVEL'] ?? 'INFO');
define('LOG_FILE', $_ENV['LOG_FILE'] ?? '/app/logs/app.log');

// Função helper para obter variáveis de ambiente
function env($key, $default = null) {
    return $_ENV[$key] ?? $default;
}

// Função para verificar se está em produção
function isProduction() {
    return env('APP_ENV', 'production') === 'production';
}

// Função para obter URL base da aplicação
function getBaseUrl() {
    return APP_URL;
}

// Função para obter URL completa
function getFullUrl($path = '') {
    return getBaseUrl() . '/' . ltrim($path, '/');
}
