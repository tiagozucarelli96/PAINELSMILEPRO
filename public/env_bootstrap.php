<?php
declare(strict_types=1);

if (defined('PAINEL_ENV_BOOTSTRAPPED')) {
    return;
}
define('PAINEL_ENV_BOOTSTRAPPED', true);

function painel_set_env_value(string $key, string $value): void
{
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv($key . '=' . $value);
}

function painel_bootstrap_env_file(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        $separatorPos = strpos($line, '=');
        if ($separatorPos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $separatorPos));
        if ($key === '') {
            continue;
        }

        $alreadyDefined = getenv($key);
        if ($alreadyDefined !== false && $alreadyDefined !== '') {
            continue;
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            continue;
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            continue;
        }

        $value = trim(substr($line, $separatorPos + 1));
        if ($value !== '' && (
            ($value[0] === '"' && str_ends_with($value, '"')) ||
            ($value[0] === "'" && str_ends_with($value, "'"))
        )) {
            $value = substr($value, 1, -1);
        }

        painel_set_env_value($key, $value);
    }
}

function painel_env(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return is_string($value) ? $value : (string)$value;
}

function painel_env_bool(string $key, bool $default = false): bool
{
    $value = painel_env($key);
    if ($value === null) {
        return $default;
    }

    return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
}

function painel_is_debug(): bool
{
    return painel_env_bool('APP_DEBUG', false);
}

function painel_is_local_request(): bool
{
    $appEnv = strtolower((string)painel_env('APP_ENV', ''));
    if ($appEnv === 'local') {
        return true;
    }

    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    if ($host !== '') {
        $host = explode(':', $host)[0];
    }

    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function painel_normalize_schema_list(?string $schemaList): array
{
    $schemaList = trim((string)$schemaList);
    if ($schemaList === '') {
        $schemaList = 'smilee12_painel_smile,public';
    }

    $schemas = [];
    foreach (explode(',', $schemaList) as $schema) {
        $schema = trim($schema);
        if ($schema === '') {
            continue;
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema) !== 1) {
            continue;
        }
        $schemas[] = $schema;
    }

    if ($schemas === []) {
        $schemas[] = 'public';
    }

    return array_values(array_unique($schemas));
}

function painel_database_config_from_url(?string $databaseUrl = null): ?array
{
    $databaseUrl = trim((string)($databaseUrl ?? painel_env('DATABASE_URL', '')));
    if ($databaseUrl === '') {
        return null;
    }

    $databaseUrl = preg_replace('#^postgresql://#', 'postgres://', $databaseUrl);
    $parts = parse_url($databaseUrl);
    if (!$parts || empty($parts['host']) || empty($parts['path'])) {
        throw new RuntimeException('DATABASE_URL inválido.');
    }

    $query = [];
    if (!empty($parts['query'])) {
        parse_str((string)$parts['query'], $query);
    }

    $sslmode = isset($query['sslmode']) ? strtolower((string)$query['sslmode']) : 'require';
    $allowedSslModes = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];
    if (!in_array($sslmode, $allowedSslModes, true)) {
        $sslmode = 'require';
    }

    $schemas = painel_normalize_schema_list(painel_env('DB_SCHEMA', 'smilee12_painel_smile,public'));
    $searchPath = implode(', ', $schemas);
    $options = "-c client_encoding=UTF8 -c search_path={$searchPath}";

    return [
        'host' => (string)$parts['host'],
        'port' => isset($parts['port']) ? (int)$parts['port'] : 5432,
        'dbname' => ltrim((string)$parts['path'], '/'),
        'user' => urldecode((string)($parts['user'] ?? '')),
        'pass' => urldecode((string)($parts['pass'] ?? '')),
        'sslmode' => $sslmode,
        'schemas' => $schemas,
        'search_path' => $searchPath,
        'search_path_sql' => 'SET search_path TO ' . $searchPath,
        'dsn' => sprintf(
            "pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;options='-c client_encoding=UTF8 -c search_path=%s'",
            (string)$parts['host'],
            isset($parts['port']) ? (int)$parts['port'] : 5432,
            ltrim((string)$parts['path'], '/'),
            $sslmode,
            $searchPath
        ),
    ];
}

function painel_has_remote_database(?array $databaseConfig = null): bool
{
    $databaseConfig = $databaseConfig ?? painel_database_config_from_url();
    if (!$databaseConfig) {
        return false;
    }

    $host = strtolower((string)($databaseConfig['host'] ?? ''));
    return $host !== '' && !in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function painel_is_admin_session(): bool
{
    return !empty($_SESSION['perm_superadmin'])
        || !empty($_SESSION['perm_administrativo'])
        || !empty($_SESSION['perm_vendas_administracao'])
        || !empty($_SESSION['is_admin']);
}

function painel_should_show_local_production_banner(?array $databaseConfig = null): bool
{
    if (!painel_is_local_request()) {
        return false;
    }

    if (!painel_has_remote_database($databaseConfig)) {
        return false;
    }

    return painel_is_debug() || painel_is_admin_session();
}

painel_bootstrap_env_file(dirname(__DIR__) . '/.env');
