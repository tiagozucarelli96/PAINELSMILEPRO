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
define('ASAAS_API_KEY', $_ENV['ASAAS_API_KEY'] ?? '$aact_prod_000MzkwODA2MWY2OGM3MWRlMDU2NWM3MzJlNzZmNGZhZGY6OjA2OTVjYTRhLTgzNTctNDkzNC1hMmQyLTEyOTNmMWFjY2NjYjo6JGFhY2hfMmRlNDE2ZTktMzk2OS00YTYzLTkyYmYtNzg2NzUzNmY5NTVl');
define('ASAAS_BASE_URL', $_ENV['ASAAS_BASE_URL'] ?? 'https://api.asaas.com/v3');
define('WEBHOOK_URL', $_ENV['WEBHOOK_URL'] ?? 'https://seudominio.railway.app/public/asaas_webhook.php');
// Chave PIX para QR Codes estáticos (chave aleatória do Asaas)
define('ASAAS_PIX_ADDRESS_KEY', $_ENV['ASAAS_PIX_ADDRESS_KEY'] ?? '3e2aab51-53bb-4a0e-ace1-f12e2c2ad9e5');

// Configurações ME Eventos (se necessário)
define('ME_BASE_URL', $_ENV['ME_BASE_URL'] ?? '');
define('ME_API_KEY', $_ENV['ME_API_KEY'] ?? '');

// Configurações de Segurança
define('JWT_SECRET', $_ENV['JWT_SECRET'] ?? 'sua_chave_secreta_aqui');
define('ENCRYPTION_KEY', $_ENV['ENCRYPTION_KEY'] ?? 'sua_chave_de_criptografia_aqui');

// Configurações VAPID para Web Push Notifications
// Priorizar variáveis de ambiente, mas usar fallback se não estiverem disponíveis
define('VAPID_PUBLIC_KEY', $_ENV['VAPID_PUBLIC_KEY'] ?? getenv('VAPID_PUBLIC_KEY') ?: 'BNxfc5_e-iBuZmSeAQZX5DHfxoEtgb6L9eUkL8TpFkgS1JZpz0hMM9nek7TtLBPAwACFuxjEoKnNYxQlrhALsP8');
define('VAPID_PRIVATE_KEY', $_ENV['VAPID_PRIVATE_KEY'] ?? getenv('VAPID_PRIVATE_KEY') ?: 'xP5iPdM_inQNVlazLlCmij3z4N10-xsmDAw-70KURZc');

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
