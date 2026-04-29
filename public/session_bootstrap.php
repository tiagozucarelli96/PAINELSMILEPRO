<?php
declare(strict_types=1);

require_once __DIR__ . '/env_bootstrap.php';

if (defined('PAINEL_SESSION_BOOTSTRAPPED')) {
    return;
}
define('PAINEL_SESSION_BOOTSTRAPPED', true);

final class PainelPostgresSessionHandler implements SessionHandlerInterface
{
    private ?PDO $pdo = null;
    private bool $tableReady = false;
    private int $ttl;

    public function __construct(int $ttl)
    {
        $this->ttl = $ttl;
    }

    public function open(string $path, string $name): bool
    {
        try {
            $this->pdo = $this->connect();
            if ($this->pdo === null) {
                return false;
            }
            $this->ensureTable();
            return true;
        } catch (Throwable $e) {
            error_log('Session open failed: ' . $e->getMessage());
            $this->pdo = null;
            return false;
        }
    }

    public function close(): bool
    {
        $this->pdo = null;
        return true;
    }

    public function read(string $id): string
    {
        if ($this->pdo === null) {
            return '';
        }

        try {
            $stmt = $this->pdo->prepare(
                'SELECT session_data
                   FROM app_sessions
                  WHERE session_id = :id
                    AND expires_at > NOW()
                  LIMIT 1'
            );
            $stmt->execute([':id' => $id]);
            $encoded = $stmt->fetchColumn();

            if (!is_string($encoded) || $encoded === '') {
                return '';
            }

            $decoded = base64_decode($encoded, true);
            return $decoded === false ? '' : $decoded;
        } catch (Throwable $e) {
            error_log('Session read failed: ' . $e->getMessage());
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        if ($this->pdo === null) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO app_sessions (session_id, session_data, last_activity, expires_at)
                 VALUES (:id, :data, NOW(), NOW() + (:ttl || \' seconds\')::interval)
                 ON CONFLICT (session_id) DO UPDATE
                 SET session_data = EXCLUDED.session_data,
                     last_activity = EXCLUDED.last_activity,
                     expires_at = EXCLUDED.expires_at'
            );

            return $stmt->execute([
                ':id' => $id,
                ':data' => base64_encode($data),
                ':ttl' => (string)$this->ttl,
            ]);
        } catch (Throwable $e) {
            error_log('Session write failed: ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        if ($this->pdo === null) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare('DELETE FROM app_sessions WHERE session_id = :id');
            return $stmt->execute([':id' => $id]);
        } catch (Throwable $e) {
            error_log('Session destroy failed: ' . $e->getMessage());
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        if ($this->pdo === null) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare('DELETE FROM app_sessions WHERE expires_at <= NOW()');
            $stmt->execute();
            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('Session GC failed: ' . $e->getMessage());
            return false;
        }
    }

    private function ensureTable(): void
    {
        if ($this->tableReady || $this->pdo === null) {
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS app_sessions (
                session_id VARCHAR(128) PRIMARY KEY,
                session_data TEXT NOT NULL,
                last_activity TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                expires_at TIMESTAMPTZ NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_app_sessions_expires_at
                ON app_sessions (expires_at)'
        );

        $this->tableReady = true;
    }

    private function connect(): ?PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $databaseConfig = painel_database_config_from_url();
        if ($databaseConfig !== null) {
            $pdo = new PDO($databaseConfig['dsn'], $databaseConfig['user'], $databaseConfig['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec($databaseConfig['search_path_sql']);
            return $pdo;
        }

        if (painel_is_local_request()) {
            throw new RuntimeException('DATABASE_URL não definido no ambiente local para inicialização de sessão.');
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=disable',
            painel_env('DB_HOST', 'localhost'),
            painel_env('DB_PORT', '5432'),
            painel_env('DB_NAME', 'painel_smile')
        );

        return new PDO($dsn, painel_env('DB_USER', 'tiagozucarelli'), painel_env('DB_PASS', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}

$https = false;
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $https = true;
} elseif (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    $https = true;
}

$sessionLifetime = (int)(painel_env('SESSION_LIFETIME_SECONDS', (string)(60 * 60 * 24 * 14)) ?? (string)(60 * 60 * 24 * 14));
if ($sessionLifetime <= 0) {
    $sessionLifetime = 60 * 60 * 24 * 14;
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', $https ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');
session_name(painel_env('SESSION_COOKIE_NAME', 'PAINELSMILESESSID') ?? 'PAINELSMILESESSID');
session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path' => '/',
    'domain' => '',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);

try {
    $handler = new PainelPostgresSessionHandler($sessionLifetime);
    if (@session_set_save_handler($handler, true)) {
        register_shutdown_function('session_write_close');
    }
} catch (Throwable $e) {
    error_log('Session bootstrap fallback to default handler: ' . $e->getMessage());
}
