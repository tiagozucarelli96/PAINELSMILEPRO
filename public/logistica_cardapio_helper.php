<?php
/**
 * logistica_cardapio_helper.php
 * Estrutura de seções, pacotes, vínculos de cardápio e escolhas do cliente.
 */
require_once __DIR__ . '/eventos_notificacoes_central_helper.php';

function logistica_cardapio_require_pacotes_helper(): void
{
    require_once __DIR__ . '/pacotes_evento_helper.php';
}

function logistica_cardapio_require_eventos_reuniao_helper(): void
{
    require_once __DIR__ . '/eventos_reuniao_helper.php';
}

function logistica_cardapio_has_table(PDO $pdo, string $table_name): bool
{
    static $cache = [];
    $table_name = trim(strtolower($table_name));
    if ($table_name === '') {
        return false;
    }

    if (array_key_exists($table_name, $cache)) {
        return $cache[$table_name];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = CURRENT_SCHEMA()
              AND table_name = :table_name
            LIMIT 1
        ");
        $stmt->execute([':table_name' => $table_name]);
        $cache[$table_name] = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('logistica_cardapio_has_table: ' . $e->getMessage());
        $cache[$table_name] = false;
    }

    return $cache[$table_name];
}

function logistica_cardapio_has_column(PDO $pdo, string $table_name, string $column_name): bool
{
    static $cache = [];
    $key = strtolower(trim($table_name)) . '.' . strtolower(trim($column_name));
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = CURRENT_SCHEMA()
              AND table_name = :table_name
              AND column_name = :column_name
            LIMIT 1
        ");
        $stmt->execute([
            ':table_name' => trim(strtolower($table_name)),
            ':column_name' => trim(strtolower($column_name)),
        ]);
        $cache[$key] = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('logistica_cardapio_has_column: ' . $e->getMessage());
        $cache[$key] = false;
    }

    return $cache[$key];
}

function logistica_cardapio_normalizar_ids(array $values): array
{
    $normalized = [];
    foreach ($values as $value) {
        if (is_array($value)) {
            foreach (logistica_cardapio_normalizar_ids($value) as $nested_id) {
                $normalized[$nested_id] = $nested_id;
            }
            continue;
        }

        $raw = trim((string)$value);
        if ($raw === '' || !ctype_digit($raw)) {
            continue;
        }

        $id = (int)$raw;
        if ($id <= 0) {
            continue;
        }
        $normalized[$id] = $id;
    }

    return array_values($normalized);
}

function logistica_cardapio_validar_item_tipo(string $item_tipo): string
{
    $item_tipo = strtolower(trim($item_tipo));
    return in_array($item_tipo, ['insumo', 'receita'], true) ? $item_tipo : '';
}

function logistica_cardapio_bool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (int)$value === 1;
    }

    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 't', 'true', 's', 'sim', 'yes', 'y', 'on'], true);
}

function logistica_cardapio_fetch_valid_ids(PDO $pdo, string $sql_prefix, array $ids): array
{
    $ids = logistica_cardapio_normalizar_ids($ids);
    if (empty($ids)) {
        return [];
    }

    $placeholders = [];
    $params = [];
    foreach ($ids as $index => $id) {
        $placeholder = ':id_' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $id;
    }

    $sql = $sql_prefix . ' (' . implode(', ', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

function logistica_cardapio_item_key(string $item_tipo, int $item_id): string
{
    $item_tipo = logistica_cardapio_validar_item_tipo($item_tipo);
    if ($item_tipo === '' || $item_id <= 0) {
        return '';
    }
    return $item_tipo . ':' . $item_id;
}

function logistica_cardapio_item_parse_key(string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '' || strpos($raw, ':') === false) {
        return null;
    }

    [$item_tipo, $item_id_raw] = explode(':', $raw, 2);
    $item_tipo = logistica_cardapio_validar_item_tipo($item_tipo);
    $item_id_raw = trim($item_id_raw);
    if ($item_tipo === '' || $item_id_raw === '' || !ctype_digit($item_id_raw)) {
        return null;
    }

    $item_id = (int)$item_id_raw;
    if ($item_id <= 0) {
        return null;
    }

    return [
        'item_tipo' => $item_tipo,
        'item_id' => $item_id,
        'key' => logistica_cardapio_item_key($item_tipo, $item_id),
    ];
}

function logistica_cardapio_normalizar_secao_row(array $row): array
{
    $row['id'] = (int)($row['id'] ?? 0);
    $row['nome'] = trim((string)($row['nome'] ?? ''));
    $row['descricao'] = trim((string)($row['descricao'] ?? ''));
    $row['ordem'] = (int)($row['ordem'] ?? 0);
    $row['ativo'] = !empty($row['ativo']);
    $row['visivel_portal_infantil'] = !empty($row['visivel_portal_infantil']);
    return $row;
}

function logistica_cardapio_normalizar_pacote_secao_row(array $row): array
{
    $row['id'] = (int)($row['id'] ?? 0);
    $row['pacote_evento_id'] = (int)($row['pacote_evento_id'] ?? 0);
    $row['secao_cardapio_id'] = (int)($row['secao_cardapio_id'] ?? 0);
    $row['quantidade_maxima'] = max(0, (int)($row['quantidade_maxima'] ?? 0));
    $row['exigir_quantidade_exata'] = !array_key_exists('exigir_quantidade_exata', $row) || !empty($row['exigir_quantidade_exata']);
    $row['selecionar_todos_itens'] = !empty($row['selecionar_todos_itens']);
    $row['ordem'] = (int)($row['ordem'] ?? 0);
    $row['secao_nome'] = trim((string)($row['secao_nome'] ?? $row['nome'] ?? ''));
    $row['secao_descricao'] = trim((string)($row['secao_descricao'] ?? ''));
    $row['secao_ativo'] = !array_key_exists('secao_ativo', $row) || !empty($row['secao_ativo']);
    $row['secao_visivel_portal_infantil'] = !empty($row['secao_visivel_portal_infantil']);
    return $row;
}

function logistica_cardapio_slug_simples(string $value): string
{
    $value = function_exists('mb_strtolower')
        ? trim(mb_strtolower($value, 'UTF-8'))
        : trim(strtolower($value));
    $map = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c',
    ];
    $value = strtr($value, $map);
    return preg_replace('/[^a-z0-9]+/', ' ', $value) ?: '';
}

function logistica_cardapio_tipo_evento_eh_infantil(?string $tipo_evento_real, ?string $pacote_nome = null): bool
{
    $tipo_slug = trim(logistica_cardapio_slug_simples((string)$tipo_evento_real));
    if (in_array($tipo_slug, ['infantil', 'festa infantil', 'aniversario infantil'], true)) {
        return true;
    }

    $pacote_slug = trim(logistica_cardapio_slug_simples((string)$pacote_nome));
    return in_array($pacote_slug, ['diver', 'diverkids', 'diver kids'], true);
}

function logistica_cardapio_recalcular_summary(array &$contexto): void
{
    $secoes = $contexto['secoes'] ?? [];
    $contexto['summary']['secoes_total'] = count($secoes);
    $contexto['summary']['itens_total'] = array_sum(array_map(static fn($secao) => count($secao['itens'] ?? []), $secoes));
    $contexto['summary']['selecionados_total'] = array_sum(array_map(static fn($secao) => (int)($secao['selected_count'] ?? 0), $secoes));
}

function logistica_cardapio_contexto_filtrar_portal_cliente(array $contexto): array
{
    if (empty($contexto['ok'])) {
        return $contexto;
    }

    $visibleSectionIds = [];
    $secoes = [];
    foreach (($contexto['secoes'] ?? []) as $secao) {
        if (!empty($secao['visivel_portal_cliente'])) {
            $visibleSectionIds[(int)($secao['id'] ?? 0)] = true;
            $secoes[] = $secao;
        }
    }

    $exclusiveGroups = [];
    foreach (($contexto['exclusive_groups'] ?? []) as $group) {
        $options = [];
        foreach (($group['options'] ?? []) as $option) {
            $secaoId = (int)($option['secao_id'] ?? 0);
            if ($secaoId > 0 && !empty($visibleSectionIds[$secaoId])) {
                $options[] = $option;
            }
        }
        if (!empty($options)) {
            $group['options'] = $options;
            $exclusiveGroups[] = $group;
        }
    }

    $contexto['secoes'] = $secoes;
    $contexto['exclusive_groups'] = $exclusiveGroups;
    logistica_cardapio_recalcular_summary($contexto);
    return $contexto;
}

function logistica_cardapio_resposta_normalizar(?array $row): ?array
{
    if (!$row) {
        return null;
    }

    $row['id'] = (int)($row['id'] ?? 0);
    $row['meeting_id'] = (int)($row['meeting_id'] ?? 0);
    $row['portal_id'] = isset($row['portal_id']) ? (int)$row['portal_id'] : null;
    $row['submitted_at'] = trim((string)($row['submitted_at'] ?? ''));
    $row['locked'] = $row['submitted_at'] !== '';
    return $row;
}

function logistica_cardapio_schema_marker_path(bool $withMeetingSchema): string
{
    return $withMeetingSchema
        ? '/tmp/logistica_cardapio_schema_full_ready_v4'
        : '/tmp/logistica_cardapio_schema_basic_ready_v4';
}

function logistica_cardapio_ensure_adicional_morango_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cardapio_respostas ADD COLUMN IF NOT EXISTS adicional_morango_bolo BOOLEAN NOT NULL DEFAULT FALSE");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cardapio_respostas ADD COLUMN IF NOT EXISTS adicional_morango_updated_by INTEGER NULL");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cardapio_respostas ADD COLUMN IF NOT EXISTS adicional_morango_updated_at TIMESTAMP NULL");
    } catch (Throwable $e) {
        error_log('logistica_cardapio_ensure_adicional_morango_schema: ' . $e->getMessage());
    }

    $done = true;
}

function logistica_cardapio_ensure_secao_infantil_schema(PDO $pdo): void
{
    static $done = false;
    if ($done || !painel_runtime_schema_setup_enabled()) {
        return;
    }

    try {
        $pdo->exec("ALTER TABLE IF EXISTS logistica_cardapio_secoes ADD COLUMN IF NOT EXISTS visivel_portal_infantil BOOLEAN NOT NULL DEFAULT FALSE");
        $pdo->exec("
            UPDATE logistica_cardapio_secoes
            SET visivel_portal_infantil = TRUE,
                updated_at = NOW()
            WHERE deleted_at IS NULL
              AND (
                  lower(trim(nome)) IN ('massa do bolo', 'recheio do bolo', 'sabor do bolo')
                  OR lower(nome) LIKE '%sabor%bolo%'
              )
        ");
    } catch (Throwable $e) {
        error_log('logistica_cardapio_ensure_secao_infantil_schema: ' . $e->getMessage());
    }

    $done = true;
}

function logistica_cardapio_schema_basico_pronto(PDO $pdo, bool $withMeetingSchema): bool
{
    try {
        $requiredTables = [
            'logistica_cardapio_secoes',
            'logistica_pacotes_evento_secoes',
            'logistica_cardapio_item_pacotes',
            'logistica_cardapio_item_secoes',
        ];
        if ($withMeetingSchema) {
            $requiredTables[] = 'eventos_cardapio_respostas';
            $requiredTables[] = 'eventos_cardapio_resposta_itens';
        }

        $placeholders = [];
        $params = [];
        foreach ($requiredTables as $index => $table) {
            $key = ':table_' . $index;
            $placeholders[] = $key;
            $params[$key] = $table;
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*)::int
            FROM information_schema.tables
            WHERE table_schema = CURRENT_SCHEMA()
              AND table_name IN (" . implode(', ', $placeholders) . ")
        ");
        $stmt->execute($params);
        if ((int)$stmt->fetchColumn() !== count($requiredTables)) {
            return false;
        }

        $stmt = $pdo->query("
            SELECT EXISTS (
                SELECT 1
                FROM information_schema.columns
                WHERE table_schema = CURRENT_SCHEMA()
                  AND table_name = 'logistica_cardapio_secoes'
                  AND column_name = 'visivel_portal_infantil'
            )
        ");
        if (!(bool)$stmt->fetchColumn()) {
            return false;
        }

        if ($withMeetingSchema) {
            foreach (['adicional_morango_bolo', 'adicional_morango_updated_by', 'adicional_morango_updated_at'] as $column) {
                if (!logistica_cardapio_has_column($pdo, 'eventos_cardapio_respostas', $column)) {
                    return false;
                }
            }
        }

        return true;
    } catch (Throwable $e) {
        error_log('logistica_cardapio_schema_basico_pronto: ' . $e->getMessage());
        return false;
    }
}

function logistica_cardapio_ensure_pacote_secao_regras_schema(PDO $pdo): void
{
    static $done = false;
    if ($done || !painel_runtime_schema_setup_enabled()) {
        return;
    }

    try {
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento_secoes ADD COLUMN IF NOT EXISTS exigir_quantidade_exata BOOLEAN NOT NULL DEFAULT TRUE");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento_secoes ADD COLUMN IF NOT EXISTS selecionar_todos_itens BOOLEAN NOT NULL DEFAULT FALSE");
    } catch (Throwable $e) {
        error_log('logistica_cardapio_ensure_pacote_secao_regras_schema: ' . $e->getMessage());
    }

    $done = true;
}

function logistica_cardapio_schema_marker_is_fresh(bool $withMeetingSchema): bool
{
    $ttl = max(300, (int)(painel_env('LOGISTICA_CARDAPIO_SCHEMA_TTL_SECONDS', '3600') ?? '3600'));
    $mtime = @filemtime(logistica_cardapio_schema_marker_path($withMeetingSchema));
    if ($mtime === false) {
        return false;
    }

    return (time() - $mtime) < $ttl;
}

function logistica_cardapio_touch_schema_marker(bool $withMeetingSchema): void
{
    @touch(logistica_cardapio_schema_marker_path($withMeetingSchema));
}

function logistica_cardapio_ensure_schema(PDO $pdo, bool $withMeetingSchema = true): void
{
    static $done = [];
    $cacheKey = $withMeetingSchema ? 'full' : 'basic';
    if (!empty($done[$cacheKey])) {
        return;
    }

    if (!painel_runtime_schema_setup_enabled()) {
        $done[$cacheKey] = true;
        return;
    }

    logistica_cardapio_ensure_secao_infantil_schema($pdo);

    if (logistica_cardapio_schema_marker_is_fresh($withMeetingSchema)) {
        $done[$cacheKey] = true;
        return;
    }

    if (logistica_cardapio_schema_basico_pronto($pdo, $withMeetingSchema)) {
        $done[$cacheKey] = true;
        logistica_cardapio_touch_schema_marker($withMeetingSchema);
        return;
    }

    logistica_cardapio_require_pacotes_helper();
    pacotes_evento_ensure_schema($pdo);
    if ($withMeetingSchema) {
        logistica_cardapio_require_eventos_reuniao_helper();
    }
    if ($withMeetingSchema && function_exists('eventos_reuniao_ensure_schema')) {
        eventos_reuniao_ensure_schema($pdo);
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS logistica_cardapio_secoes (
                id BIGSERIAL PRIMARY KEY,
                nome VARCHAR(180) NOT NULL,
                descricao TEXT NULL,
                ordem INTEGER NOT NULL DEFAULT 0,
                ativo BOOLEAN NOT NULL DEFAULT TRUE,
                visivel_portal_infantil BOOLEAN NOT NULL DEFAULT FALSE,
                created_by_user_id INTEGER NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
                deleted_at TIMESTAMP NULL,
                deleted_by_user_id INTEGER NULL
            )
        ");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_cardapio_secoes ADD COLUMN IF NOT EXISTS nome VARCHAR(180)");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_cardapio_secoes ADD COLUMN IF NOT EXISTS descricao TEXT NULL");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_cardapio_secoes ADD COLUMN IF NOT EXISTS ordem INTEGER NOT NULL DEFAULT 0");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_cardapio_secoes ADD COLUMN IF NOT EXISTS ativo BOOLEAN NOT NULL DEFAULT TRUE");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_cardapio_secoes ADD COLUMN IF NOT EXISTS visivel_portal_infantil BOOLEAN NOT NULL DEFAULT FALSE");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_cardapio_secoes ADD COLUMN IF NOT EXISTS created_by_user_id INTEGER NULL");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_cardapio_secoes ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT NOW()");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_cardapio_secoes ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT NOW()");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_cardapio_secoes ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_cardapio_secoes ADD COLUMN IF NOT EXISTS deleted_by_user_id INTEGER NULL");
        $pdo->exec("
            UPDATE logistica_cardapio_secoes
            SET visivel_portal_infantil = TRUE,
                updated_at = NOW()
            WHERE deleted_at IS NULL
              AND (
                  lower(trim(nome)) IN ('massa do bolo', 'recheio do bolo', 'sabor do bolo')
                  OR lower(nome) LIKE '%sabor%bolo%'
              )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_logistica_cardapio_secoes_nome ON logistica_cardapio_secoes(lower(nome))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_logistica_cardapio_secoes_status ON logistica_cardapio_secoes(ativo, deleted_at, ordem)");
    } catch (Throwable $e) {
        error_log('logistica_cardapio_ensure_schema secoes: ' . $e->getMessage());
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS logistica_pacotes_evento_secoes (
                id BIGSERIAL PRIMARY KEY,
                pacote_evento_id BIGINT NOT NULL REFERENCES logistica_pacotes_evento(id) ON DELETE CASCADE,
                secao_cardapio_id BIGINT NOT NULL REFERENCES logistica_cardapio_secoes(id) ON DELETE CASCADE,
                quantidade_maxima INTEGER NOT NULL DEFAULT 1,
                exigir_quantidade_exata BOOLEAN NOT NULL DEFAULT TRUE,
                selecionar_todos_itens BOOLEAN NOT NULL DEFAULT FALSE,
                ordem INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento_secoes ADD COLUMN IF NOT EXISTS pacote_evento_id BIGINT");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento_secoes ADD COLUMN IF NOT EXISTS secao_cardapio_id BIGINT");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento_secoes ADD COLUMN IF NOT EXISTS quantidade_maxima INTEGER NOT NULL DEFAULT 1");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento_secoes ADD COLUMN IF NOT EXISTS exigir_quantidade_exata BOOLEAN NOT NULL DEFAULT TRUE");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento_secoes ADD COLUMN IF NOT EXISTS selecionar_todos_itens BOOLEAN NOT NULL DEFAULT FALSE");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento_secoes ADD COLUMN IF NOT EXISTS ordem INTEGER NOT NULL DEFAULT 0");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento_secoes ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT NOW()");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento_secoes ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT NOW()");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_logistica_pacotes_evento_secoes ON logistica_pacotes_evento_secoes(pacote_evento_id, secao_cardapio_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_logistica_pacotes_evento_secoes_ordem ON logistica_pacotes_evento_secoes(pacote_evento_id, ordem, secao_cardapio_id)");
    } catch (Throwable $e) {
        error_log('logistica_cardapio_ensure_schema pacote secoes: ' . $e->getMessage());
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS logistica_cardapio_item_pacotes (
                id BIGSERIAL PRIMARY KEY,
                item_tipo VARCHAR(20) NOT NULL,
                item_id BIGINT NOT NULL,
                pacote_evento_id BIGINT NOT NULL REFERENCES logistica_pacotes_evento(id) ON DELETE CASCADE,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_cardapio_item_pacotes ADD COLUMN IF NOT EXISTS item_tipo VARCHAR(20)");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_cardapio_item_pacotes ADD COLUMN IF NOT EXISTS item_id BIGINT");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_cardapio_item_pacotes ADD COLUMN IF NOT EXISTS pacote_evento_id BIGINT");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_cardapio_item_pacotes ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT NOW()");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_cardapio_item_pacotes ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT NOW()");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_logistica_cardapio_item_pacotes ON logistica_cardapio_item_pacotes(item_tipo, item_id, pacote_evento_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_logistica_cardapio_item_pacotes_item ON logistica_cardapio_item_pacotes(item_tipo, item_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_logistica_cardapio_item_pacotes_pacote ON logistica_cardapio_item_pacotes(pacote_evento_id)");
    } catch (Throwable $e) {
        error_log('logistica_cardapio_ensure_schema item pacotes: ' . $e->getMessage());
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS logistica_cardapio_item_secoes (
                id BIGSERIAL PRIMARY KEY,
                item_tipo VARCHAR(20) NOT NULL,
                item_id BIGINT NOT NULL,
                secao_cardapio_id BIGINT NOT NULL REFERENCES logistica_cardapio_secoes(id) ON DELETE CASCADE,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_cardapio_item_secoes ADD COLUMN IF NOT EXISTS item_tipo VARCHAR(20)");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_cardapio_item_secoes ADD COLUMN IF NOT EXISTS item_id BIGINT");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_cardapio_item_secoes ADD COLUMN IF NOT EXISTS secao_cardapio_id BIGINT");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_cardapio_item_secoes ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT NOW()");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_cardapio_item_secoes ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT NOW()");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_logistica_cardapio_item_secoes ON logistica_cardapio_item_secoes(item_tipo, item_id, secao_cardapio_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_logistica_cardapio_item_secoes_item ON logistica_cardapio_item_secoes(item_tipo, item_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_logistica_cardapio_item_secoes_secao ON logistica_cardapio_item_secoes(secao_cardapio_id)");
    } catch (Throwable $e) {
        error_log('logistica_cardapio_ensure_schema item secoes: ' . $e->getMessage());
    }

    try {
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cliente_portais ADD COLUMN IF NOT EXISTS visivel_cardapio BOOLEAN NOT NULL DEFAULT TRUE");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cliente_portais ADD COLUMN IF NOT EXISTS editavel_cardapio BOOLEAN NOT NULL DEFAULT TRUE");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cliente_portais ALTER COLUMN visivel_cardapio SET DEFAULT TRUE");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cliente_portais ALTER COLUMN editavel_cardapio SET DEFAULT TRUE");
    } catch (Throwable $e) {
        error_log('logistica_cardapio_ensure_schema portal cardapio: ' . $e->getMessage());
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS eventos_cardapio_respostas (
                id BIGSERIAL PRIMARY KEY,
                meeting_id BIGINT NOT NULL UNIQUE REFERENCES eventos_reunioes(id) ON DELETE CASCADE,
                portal_id BIGINT NULL REFERENCES eventos_cliente_portais(id) ON DELETE SET NULL,
                submitted_at TIMESTAMP NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cardapio_respostas ADD COLUMN IF NOT EXISTS meeting_id BIGINT");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cardapio_respostas ADD COLUMN IF NOT EXISTS portal_id BIGINT NULL");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cardapio_respostas ADD COLUMN IF NOT EXISTS submitted_at TIMESTAMP NULL");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cardapio_respostas ADD COLUMN IF NOT EXISTS adicional_morango_bolo BOOLEAN NOT NULL DEFAULT FALSE");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cardapio_respostas ADD COLUMN IF NOT EXISTS adicional_morango_updated_by INTEGER NULL");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cardapio_respostas ADD COLUMN IF NOT EXISTS adicional_morango_updated_at TIMESTAMP NULL");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cardapio_respostas ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT NOW()");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cardapio_respostas ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT NOW()");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_eventos_cardapio_respostas_meeting ON eventos_cardapio_respostas(meeting_id)");
    } catch (Throwable $e) {
        error_log('logistica_cardapio_ensure_schema respostas: ' . $e->getMessage());
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS eventos_cardapio_resposta_itens (
                id BIGSERIAL PRIMARY KEY,
                resposta_id BIGINT NOT NULL REFERENCES eventos_cardapio_respostas(id) ON DELETE CASCADE,
                secao_cardapio_id BIGINT NOT NULL REFERENCES logistica_cardapio_secoes(id) ON DELETE CASCADE,
                item_tipo VARCHAR(20) NOT NULL,
                item_id BIGINT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cardapio_resposta_itens ADD COLUMN IF NOT EXISTS resposta_id BIGINT");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cardapio_resposta_itens ADD COLUMN IF NOT EXISTS secao_cardapio_id BIGINT");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cardapio_resposta_itens ADD COLUMN IF NOT EXISTS item_tipo VARCHAR(20)");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cardapio_resposta_itens ADD COLUMN IF NOT EXISTS item_id BIGINT");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cardapio_resposta_itens ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT NOW()");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_eventos_cardapio_resposta_itens ON eventos_cardapio_resposta_itens(resposta_id, secao_cardapio_id, item_tipo, item_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_cardapio_resposta_itens_secao ON eventos_cardapio_resposta_itens(secao_cardapio_id)");
    } catch (Throwable $e) {
        error_log('logistica_cardapio_ensure_schema resposta itens: ' . $e->getMessage());
    }

    $done[$cacheKey] = true;
    logistica_cardapio_touch_schema_marker($withMeetingSchema);
}

function logistica_cardapio_listar_secoes(PDO $pdo, bool $incluir_inativas = false): array
{
    logistica_cardapio_ensure_schema($pdo, false);
    $where_ativo = $incluir_inativas ? '' : 'AND ativo = TRUE';

    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM logistica_cardapio_secoes
            WHERE deleted_at IS NULL
              {$where_ativo}
            ORDER BY ordem ASC, lower(nome) ASC, id ASC
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('logistica_cardapio_listar_secoes: ' . $e->getMessage());
        return [];
    }

    return array_map('logistica_cardapio_normalizar_secao_row', $rows);
}

function logistica_cardapio_secao_get(PDO $pdo, int $secao_id): ?array
{
    logistica_cardapio_ensure_schema($pdo, false);
    if ($secao_id <= 0) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM logistica_cardapio_secoes
            WHERE id = :id
              AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':id' => $secao_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('logistica_cardapio_secao_get: ' . $e->getMessage());
        return null;
    }

    return $row ? logistica_cardapio_normalizar_secao_row($row) : null;
}

function logistica_cardapio_secao_salvar(
    PDO $pdo,
    int $secao_id,
    string $nome,
    ?string $descricao = null,
    int $ordem = 0,
    bool $ativo = true,
    int $user_id = 0,
    bool $visivel_portal_infantil = false
): array {
    logistica_cardapio_ensure_schema($pdo, false);

    $nome = trim($nome);
    if ($nome === '') {
        return ['ok' => false, 'error' => 'Nome da seção é obrigatório.'];
    }

    if (function_exists('mb_strlen') && mb_strlen($nome, 'UTF-8') > 180) {
        return ['ok' => false, 'error' => 'Nome da seção muito longo.'];
    }

    $descricao = trim((string)$descricao);
    if (function_exists('mb_strlen') && mb_strlen($descricao, 'UTF-8') > 2000) {
        $descricao = mb_substr($descricao, 0, 2000, 'UTF-8');
    }

    try {
        if ($secao_id > 0) {
            $stmt = $pdo->prepare("
                UPDATE logistica_cardapio_secoes
                SET nome = :nome,
                    descricao = :descricao,
                    ordem = :ordem,
                    ativo = :ativo,
                    visivel_portal_infantil = :visivel_portal_infantil,
                    updated_at = NOW()
                WHERE id = :id
                  AND deleted_at IS NULL
                RETURNING *
            ");
            $stmt->execute([
                ':nome' => $nome,
                ':descricao' => $descricao !== '' ? $descricao : null,
                ':ordem' => max(0, $ordem),
                ':ativo' => $ativo ? 1 : 0,
                ':visivel_portal_infantil' => $visivel_portal_infantil ? 1 : 0,
                ':id' => $secao_id,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$row) {
                return ['ok' => false, 'error' => 'Seção não encontrada.'];
            }
            return ['ok' => true, 'mode' => 'updated', 'secao' => logistica_cardapio_normalizar_secao_row($row)];
        }

        $stmt = $pdo->prepare("
            INSERT INTO logistica_cardapio_secoes
                (nome, descricao, ordem, ativo, visivel_portal_infantil, created_by_user_id, created_at, updated_at)
            VALUES
                (:nome, :descricao, :ordem, :ativo, :visivel_portal_infantil, :created_by_user_id, NOW(), NOW())
            RETURNING *
        ");
        $stmt->execute([
            ':nome' => $nome,
            ':descricao' => $descricao !== '' ? $descricao : null,
            ':ordem' => max(0, $ordem),
            ':ativo' => $ativo ? 1 : 0,
            ':visivel_portal_infantil' => $visivel_portal_infantil ? 1 : 0,
            ':created_by_user_id' => $user_id > 0 ? $user_id : null,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        return ['ok' => true, 'mode' => 'created', 'secao' => $row ? logistica_cardapio_normalizar_secao_row($row) : null];
    } catch (Throwable $e) {
        error_log('logistica_cardapio_secao_salvar: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao salvar seção do cardápio.'];
    }
}

function logistica_cardapio_secao_alterar_status(PDO $pdo, int $secao_id, bool $ativo): array
{
    logistica_cardapio_ensure_schema($pdo, false);
    if ($secao_id <= 0) {
        return ['ok' => false, 'error' => 'Seção inválida.'];
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE logistica_cardapio_secoes
            SET ativo = :ativo,
                updated_at = NOW()
            WHERE id = :id
              AND deleted_at IS NULL
            RETURNING *
        ");
        $stmt->execute([
            ':ativo' => $ativo ? 1 : 0,
            ':id' => $secao_id,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return ['ok' => false, 'error' => 'Seção não encontrada.'];
        }
        return ['ok' => true, 'secao' => logistica_cardapio_normalizar_secao_row($row)];
    } catch (Throwable $e) {
        error_log('logistica_cardapio_secao_alterar_status: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao alterar status da seção.'];
    }
}

function logistica_cardapio_secao_excluir(PDO $pdo, int $secao_id, int $user_id = 0): array
{
    logistica_cardapio_ensure_schema($pdo, false);
    if ($secao_id <= 0) {
        return ['ok' => false, 'error' => 'Seção inválida.'];
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE logistica_cardapio_secoes
            SET deleted_at = NOW(),
                deleted_by_user_id = :user_id,
                updated_at = NOW()
            WHERE id = :id
              AND deleted_at IS NULL
            RETURNING *
        ");
        $stmt->execute([
            ':id' => $secao_id,
            ':user_id' => $user_id > 0 ? $user_id : null,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return ['ok' => false, 'error' => 'Seção não encontrada.'];
        }
        return ['ok' => true, 'secao' => logistica_cardapio_normalizar_secao_row($row)];
    } catch (Throwable $e) {
        error_log('logistica_cardapio_secao_excluir: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao excluir seção do cardápio.'];
    }
}

function logistica_cardapio_item_relacoes_get(PDO $pdo, string $item_tipo, int $item_id): array
{
    logistica_cardapio_ensure_schema($pdo, false);
    $item_tipo = logistica_cardapio_validar_item_tipo($item_tipo);
    if ($item_tipo === '' || $item_id <= 0) {
        return ['pacote_ids' => [], 'secao_ids' => []];
    }

    $pacote_ids = [];
    $secao_ids = [];

    try {
        $stmt = $pdo->prepare("
            SELECT pacote_evento_id
            FROM logistica_cardapio_item_pacotes
            WHERE item_tipo = :item_tipo
              AND item_id = :item_id
        ");
        $stmt->execute([
            ':item_tipo' => $item_tipo,
            ':item_id' => $item_id,
        ]);
        $pacote_ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {
        error_log('logistica_cardapio_item_relacoes_get pacote_ids: ' . $e->getMessage());
    }

    try {
        $stmt = $pdo->prepare("
            SELECT secao_cardapio_id
            FROM logistica_cardapio_item_secoes
            WHERE item_tipo = :item_tipo
              AND item_id = :item_id
        ");
        $stmt->execute([
            ':item_tipo' => $item_tipo,
            ':item_id' => $item_id,
        ]);
        $secao_ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {
        error_log('logistica_cardapio_item_relacoes_get secao_ids: ' . $e->getMessage());
    }

    return [
        'pacote_ids' => array_values(array_unique(array_filter($pacote_ids, static fn($id) => $id > 0))),
        'secao_ids' => array_values(array_unique(array_filter($secao_ids, static fn($id) => $id > 0))),
    ];
}

function logistica_cardapio_item_salvar_relacoes(PDO $pdo, string $item_tipo, int $item_id, array $pacote_ids, array $secao_ids): array
{
    logistica_cardapio_ensure_schema($pdo, false);
    $item_tipo = logistica_cardapio_validar_item_tipo($item_tipo);
    if ($item_tipo === '' || $item_id <= 0) {
        return ['ok' => false, 'error' => 'Item inválido para vínculo de cardápio.'];
    }

    $pacote_ids = logistica_cardapio_normalizar_ids($pacote_ids);
    $secao_ids = logistica_cardapio_normalizar_ids($secao_ids);

    try {
        if (!empty($pacote_ids)) {
            $valid_ids = logistica_cardapio_fetch_valid_ids($pdo, "
                SELECT id
                FROM logistica_pacotes_evento
                WHERE deleted_at IS NULL
                  AND id IN", $pacote_ids);
            sort($valid_ids);
            $expected = $pacote_ids;
            sort($expected);
            if ($valid_ids !== $expected) {
                return ['ok' => false, 'error' => 'Há pacote(s) inválido(s) no vínculo do cardápio.'];
            }
        }

        if (!empty($secao_ids)) {
            $valid_ids = logistica_cardapio_fetch_valid_ids($pdo, "
                SELECT id
                FROM logistica_cardapio_secoes
                WHERE deleted_at IS NULL
                  AND id IN", $secao_ids);
            sort($valid_ids);
            $expected = $secao_ids;
            sort($expected);
            if ($valid_ids !== $expected) {
                return ['ok' => false, 'error' => 'Há seção(ões) inválida(s) no vínculo do cardápio.'];
            }
        }

        $stmt_delete = $pdo->prepare("
            DELETE FROM logistica_cardapio_item_pacotes
            WHERE item_tipo = :item_tipo
              AND item_id = :item_id
        ");
        $stmt_delete->execute([
            ':item_tipo' => $item_tipo,
            ':item_id' => $item_id,
        ]);

        if (!empty($pacote_ids)) {
            $stmt_insert = $pdo->prepare("
                INSERT INTO logistica_cardapio_item_pacotes
                    (item_tipo, item_id, pacote_evento_id, created_at, updated_at)
                VALUES
                    (:item_tipo, :item_id, :pacote_evento_id, NOW(), NOW())
            ");
            foreach ($pacote_ids as $pacote_id) {
                $stmt_insert->execute([
                    ':item_tipo' => $item_tipo,
                    ':item_id' => $item_id,
                    ':pacote_evento_id' => $pacote_id,
                ]);
            }
        }

        $stmt_delete = $pdo->prepare("
            DELETE FROM logistica_cardapio_item_secoes
            WHERE item_tipo = :item_tipo
              AND item_id = :item_id
        ");
        $stmt_delete->execute([
            ':item_tipo' => $item_tipo,
            ':item_id' => $item_id,
        ]);

        if (!empty($secao_ids)) {
            $stmt_insert = $pdo->prepare("
                INSERT INTO logistica_cardapio_item_secoes
                    (item_tipo, item_id, secao_cardapio_id, created_at, updated_at)
                VALUES
                    (:item_tipo, :item_id, :secao_cardapio_id, NOW(), NOW())
            ");
            foreach ($secao_ids as $secao_id) {
                $stmt_insert->execute([
                    ':item_tipo' => $item_tipo,
                    ':item_id' => $item_id,
                    ':secao_cardapio_id' => $secao_id,
                ]);
            }
        }

        return [
            'ok' => true,
            'relacoes' => [
                'pacote_ids' => $pacote_ids,
                'secao_ids' => $secao_ids,
            ],
        ];
    } catch (Throwable $e) {
        error_log('logistica_cardapio_item_salvar_relacoes: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao salvar vínculos do cardápio.'];
    }
}

function logistica_cardapio_pacote_regras_listar(PDO $pdo, int $pacote_id): array
{
    logistica_cardapio_ensure_schema($pdo, false);
    if ($pacote_id <= 0) {
        return [];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT ps.*,
                   s.nome AS secao_nome,
                   s.descricao AS secao_descricao,
                   s.ativo AS secao_ativo,
                   COALESCE(s.visivel_portal_infantil, FALSE) AS secao_visivel_portal_infantil
            FROM logistica_pacotes_evento_secoes ps
            JOIN logistica_cardapio_secoes s ON s.id = ps.secao_cardapio_id
            WHERE ps.pacote_evento_id = :pacote_id
              AND s.deleted_at IS NULL
            ORDER BY ps.ordem ASC, s.ordem ASC, lower(s.nome) ASC, ps.id ASC
        ");
        $stmt->execute([':pacote_id' => $pacote_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('logistica_cardapio_pacote_regras_listar: ' . $e->getMessage());
        return [];
    }

    return array_map('logistica_cardapio_normalizar_pacote_secao_row', $rows);
}

function logistica_cardapio_pacote_regras_salvar(PDO $pdo, int $pacote_id, array $rules): array
{
    logistica_cardapio_ensure_schema($pdo, false);
    logistica_cardapio_ensure_pacote_secao_regras_schema($pdo);
    if ($pacote_id <= 0) {
        return ['ok' => false, 'error' => 'Pacote inválido para configuração de seções.'];
    }

    logistica_cardapio_require_pacotes_helper();
    $pacote = pacotes_evento_get($pdo, $pacote_id);
    if (!$pacote) {
        return ['ok' => false, 'error' => 'Pacote não encontrado.'];
    }

    $normalized = [];
    foreach ($rules as $rule) {
        if (!is_array($rule)) {
            continue;
        }

        $secao_id_raw = trim((string)($rule['secao_cardapio_id'] ?? $rule['secao_id'] ?? ''));
        $quantidade_raw = trim((string)($rule['quantidade_maxima'] ?? ''));
        $ordem_raw = trim((string)($rule['ordem'] ?? ''));

        if ($secao_id_raw === '' || !ctype_digit($secao_id_raw)) {
            continue;
        }

        $secao_id = (int)$secao_id_raw;
        if ($secao_id <= 0) {
            continue;
        }

        $quantidade = is_numeric($quantidade_raw) ? (int)$quantidade_raw : 0;
        $ordem = is_numeric($ordem_raw) ? (int)$ordem_raw : 0;
        $selecionar_todos_itens = (string)($rule['selecionar_todos_itens'] ?? '0') === '1';
        if ($quantidade <= 0 && !$selecionar_todos_itens) {
            continue;
        }

        $normalized[$secao_id] = [
            'secao_cardapio_id' => $secao_id,
            'quantidade_maxima' => $quantidade,
            'exigir_quantidade_exata' => !array_key_exists('exigir_quantidade_exata', $rule)
                || (string)($rule['exigir_quantidade_exata'] ?? '0') === '1',
            'selecionar_todos_itens' => $selecionar_todos_itens,
            'ordem' => max(0, $ordem),
        ];
    }

    $secao_ids = array_keys($normalized);
    if (!empty($secao_ids)) {
        $valid_secoes = logistica_cardapio_listar_secoes($pdo, true);
        $valid_secao_map = [];
        foreach ($valid_secoes as $secao) {
            $valid_secao_map[(int)$secao['id']] = true;
        }

        foreach ($secao_ids as $secao_id) {
            if (empty($valid_secao_map[(int)$secao_id])) {
                return ['ok' => false, 'error' => 'Há seção(ões) inválida(s) na configuração do pacote.'];
            }
        }
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("DELETE FROM logistica_pacotes_evento_secoes WHERE pacote_evento_id = :pacote_id");
        $stmt->execute([':pacote_id' => $pacote_id]);

        if (!empty($normalized)) {
            $stmt_insert = $pdo->prepare("
                INSERT INTO logistica_pacotes_evento_secoes
                    (pacote_evento_id, secao_cardapio_id, quantidade_maxima, exigir_quantidade_exata, selecionar_todos_itens, ordem, created_at, updated_at)
                VALUES
                    (:pacote_evento_id, :secao_cardapio_id, :quantidade_maxima, :exigir_quantidade_exata, :selecionar_todos_itens, :ordem, NOW(), NOW())
            ");
            foreach ($normalized as $rule) {
                $stmt_insert->execute([
                    ':pacote_evento_id' => $pacote_id,
                    ':secao_cardapio_id' => (int)$rule['secao_cardapio_id'],
                    ':quantidade_maxima' => (int)$rule['quantidade_maxima'],
                    ':exigir_quantidade_exata' => !empty($rule['exigir_quantidade_exata']) ? 'true' : 'false',
                    ':selecionar_todos_itens' => !empty($rule['selecionar_todos_itens']) ? 'true' : 'false',
                    ':ordem' => (int)$rule['ordem'],
                ]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('logistica_cardapio_pacote_regras_salvar: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao salvar regras do pacote.'];
    }

    return [
        'ok' => true,
        'regras' => logistica_cardapio_pacote_regras_listar($pdo, $pacote_id),
    ];
}

function logistica_cardapio_resposta_get(PDO $pdo, int $meeting_id): ?array
{
    logistica_cardapio_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM eventos_cardapio_respostas
            WHERE meeting_id = :meeting_id
            LIMIT 1
        ");
        $stmt->execute([':meeting_id' => $meeting_id]);
        $row = logistica_cardapio_resposta_normalizar($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
        if (!$row) {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT ri.*,
                   s.nome AS secao_nome,
                   s.ordem AS secao_ordem,
                   CASE
                       WHEN ri.item_tipo = 'insumo' THEN i.nome_oficial
                       WHEN ri.item_tipo = 'receita' THEN r.nome
                       ELSE NULL
                   END AS item_nome
            FROM eventos_cardapio_resposta_itens ri
            JOIN logistica_cardapio_secoes s ON s.id = ri.secao_cardapio_id
            LEFT JOIN logistica_insumos i
                ON ri.item_tipo = 'insumo'
               AND i.id = ri.item_id
            LEFT JOIN logistica_receitas r
                ON ri.item_tipo = 'receita'
               AND r.id = ri.item_id
            WHERE ri.resposta_id = :resposta_id
            ORDER BY s.ordem ASC, lower(s.nome) ASC, lower(COALESCE(
                CASE
                    WHEN ri.item_tipo = 'insumo' THEN i.nome_oficial
                    WHEN ri.item_tipo = 'receita' THEN r.nome
                    ELSE ''
                END, ''
            )) ASC, ri.id ASC
        ");
        $stmt->execute([':resposta_id' => (int)$row['id']]);
        $items = [];
        $selected_by_section = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $item_row) {
            $secao_id = (int)($item_row['secao_cardapio_id'] ?? 0);
            $item_tipo = logistica_cardapio_validar_item_tipo((string)($item_row['item_tipo'] ?? ''));
            $item_id = (int)($item_row['item_id'] ?? 0);
            $key = logistica_cardapio_item_key($item_tipo, $item_id);
            if ($secao_id > 0 && $key !== '') {
                if (!isset($selected_by_section[$secao_id])) {
                    $selected_by_section[$secao_id] = [];
                }
                $selected_by_section[$secao_id][] = $key;
            }

            $items[] = [
                'id' => (int)($item_row['id'] ?? 0),
                'secao_cardapio_id' => $secao_id,
                'secao_nome' => trim((string)($item_row['secao_nome'] ?? '')),
                'item_tipo' => $item_tipo,
                'item_id' => $item_id,
                'item_nome' => trim((string)($item_row['item_nome'] ?? '')),
                'key' => $key,
            ];
        }

        foreach ($selected_by_section as &$values) {
            $values = array_values(array_unique($values));
        }
        unset($values);

        $row['items'] = $items;
        $row['selected_by_section'] = $selected_by_section;
        return $row;
    } catch (Throwable $e) {
        error_log('logistica_cardapio_resposta_get: ' . $e->getMessage());
        return null;
    }
}

function logistica_cardapio_evento_resumo(PDO $pdo, int $meeting_id): array
{
    logistica_cardapio_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return [
            'ok' => false,
            'error' => 'Reunião inválida.',
            'summary' => [
                'has_pacote' => false,
                'pacote_nome' => '',
                'secoes_total' => 0,
                'itens_total' => 0,
                'selecionados_total' => 0,
                'submitted_at' => '',
            ],
        ];
    }

    $emptySummary = [
        'has_pacote' => false,
        'pacote_nome' => '',
        'secoes_total' => 0,
        'itens_total' => 0,
        'selecionados_total' => 0,
        'submitted_at' => '',
    ];

    try {
        $stmt = $pdo->prepare("
            SELECT r.id,
                   r.pacote_evento_id,
                   p.nome AS pacote_nome
            FROM eventos_reunioes r
            LEFT JOIN logistica_pacotes_evento p
                ON p.id = r.pacote_evento_id
               AND p.deleted_at IS NULL
            WHERE r.id = :meeting_id
            LIMIT 1
        ");
        $stmt->execute([':meeting_id' => $meeting_id]);
        $meeting = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$meeting) {
            return ['ok' => false, 'error' => 'Reunião não encontrada.', 'summary' => $emptySummary];
        }

        $pacoteId = (int)($meeting['pacote_evento_id'] ?? 0);
        $summary = $emptySummary;
        $summary['has_pacote'] = $pacoteId > 0;
        $summary['pacote_nome'] = trim((string)($meeting['pacote_nome'] ?? ''));

        if ($pacoteId > 0) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)::int AS secoes_total
                FROM logistica_pacotes_evento_secoes ps
                JOIN logistica_cardapio_secoes s ON s.id = ps.secao_cardapio_id
                WHERE ps.pacote_evento_id = :pacote_id
                  AND s.deleted_at IS NULL
                  AND s.ativo = TRUE
            ");
            $stmt->execute([':pacote_id' => $pacoteId]);
            $summary['secoes_total'] = (int)($stmt->fetchColumn() ?: 0);

            $stmt = $pdo->prepare("
                SELECT COUNT(*)::int
                FROM (
                    SELECT DISTINCT ps.secao_cardapio_id, ip.item_tipo, ip.item_id
                    FROM logistica_pacotes_evento_secoes ps
                    JOIN logistica_cardapio_item_pacotes ip
                        ON ip.pacote_evento_id = ps.pacote_evento_id
                    JOIN logistica_cardapio_item_secoes isec
                        ON isec.item_tipo = ip.item_tipo
                       AND isec.item_id = ip.item_id
                       AND isec.secao_cardapio_id = ps.secao_cardapio_id
                    LEFT JOIN logistica_insumos i
                        ON ip.item_tipo = 'insumo'
                       AND i.id = ip.item_id
                    LEFT JOIN logistica_receitas r
                        ON ip.item_tipo = 'receita'
                       AND r.id = ip.item_id
                    WHERE ps.pacote_evento_id = :pacote_id
                      AND (
                          (ip.item_tipo = 'insumo' AND i.ativo = TRUE)
                          OR
                          (ip.item_tipo = 'receita' AND r.ativo = TRUE)
                      )
                ) itens_validos
            ");
            $stmt->execute([':pacote_id' => $pacoteId]);
            $summary['itens_total'] = (int)($stmt->fetchColumn() ?: 0);
        }

        $stmt = $pdo->prepare("
            SELECT submitted_at
            FROM eventos_cardapio_respostas
            WHERE meeting_id = :meeting_id
            LIMIT 1
        ");
        $stmt->execute([':meeting_id' => $meeting_id]);
        $submittedAt = $stmt->fetchColumn();
        $summary['submitted_at'] = is_string($submittedAt) ? $submittedAt : '';

        $stmt = $pdo->prepare("
            SELECT COUNT(*)::int
            FROM eventos_cardapio_resposta_itens ri
            JOIN eventos_cardapio_respostas rr ON rr.id = ri.resposta_id
            WHERE rr.meeting_id = :meeting_id
        ");
        $stmt->execute([':meeting_id' => $meeting_id]);
        $summary['selecionados_total'] = (int)($stmt->fetchColumn() ?: 0);

        return [
            'ok' => true,
            'meeting_id' => $meeting_id,
            'summary' => $summary,
        ];
    } catch (Throwable $e) {
        error_log('logistica_cardapio_evento_resumo: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao carregar resumo do cardápio.', 'summary' => $emptySummary];
    }
}

function logistica_cardapio_evento_contexto(PDO $pdo, int $meeting_id): array
{
    logistica_cardapio_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return ['ok' => false, 'error' => 'Reunião inválida.'];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT r.id,
                   r.pacote_evento_id,
                   r.tipo_evento_real,
                   p.nome AS pacote_nome,
                   p.descricao AS pacote_descricao
            FROM eventos_reunioes r
            LEFT JOIN logistica_pacotes_evento p
                ON p.id = r.pacote_evento_id
               AND p.deleted_at IS NULL
            WHERE r.id = :meeting_id
            LIMIT 1
        ");
        $stmt->execute([':meeting_id' => $meeting_id]);
        $meeting = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('logistica_cardapio_evento_contexto meeting: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao carregar reunião do cardápio.'];
    }

    if (!$meeting) {
        return ['ok' => false, 'error' => 'Reunião não encontrada.'];
    }

    $pacote_id = (int)($meeting['pacote_evento_id'] ?? 0);
    $tipo_evento_real = trim((string)($meeting['tipo_evento_real'] ?? ''));
    $pacote = null;
    if ($pacote_id > 0) {
        $pacote = [
            'id' => $pacote_id,
            'nome' => trim((string)($meeting['pacote_nome'] ?? '')),
            'descricao' => trim((string)($meeting['pacote_descricao'] ?? '')),
        ];
    }
    $is_infantil = logistica_cardapio_tipo_evento_eh_infantil($tipo_evento_real, (string)($pacote['nome'] ?? ''));

    $regras = [];
    if ($pacote_id > 0) {
        try {
            $stmt = $pdo->prepare("
                SELECT ps.*,
                       s.nome AS secao_nome,
                       s.descricao AS secao_descricao,
                       s.ordem AS secao_ordem,
                       s.ativo AS secao_ativo,
                       COALESCE(s.visivel_portal_infantil, FALSE) AS secao_visivel_portal_infantil
                FROM logistica_pacotes_evento_secoes ps
                JOIN logistica_cardapio_secoes s ON s.id = ps.secao_cardapio_id
                WHERE ps.pacote_evento_id = :pacote_id
                  AND s.deleted_at IS NULL
                  AND s.ativo = TRUE
                ORDER BY ps.ordem ASC, s.ordem ASC, lower(s.nome) ASC, ps.id ASC
            ");
            $stmt->execute([':pacote_id' => $pacote_id]);
            $regras = array_map('logistica_cardapio_normalizar_pacote_secao_row', $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) {
            error_log('logistica_cardapio_evento_contexto regras: ' . $e->getMessage());
        }
    }

    $secoes = [];
    $secoes_index = [];
    foreach ($regras as $regra) {
        $secao_id = (int)$regra['secao_cardapio_id'];
        if ($secao_id <= 0) {
            continue;
        }
        $secoes[$secao_id] = [
            'id' => $secao_id,
            'nome' => (string)$regra['secao_nome'],
            'descricao' => (string)$regra['secao_descricao'],
            'ordem' => (int)($regra['ordem'] ?? 0),
            'quantidade_maxima' => (int)$regra['quantidade_maxima'],
            'exigir_quantidade_exata' => !empty($regra['exigir_quantidade_exata']),
            'selecionar_todos_itens' => !empty($regra['selecionar_todos_itens']),
            'visivel_portal_infantil' => !empty($regra['secao_visivel_portal_infantil']),
            'visivel_portal_cliente' => !$is_infantil || !empty($regra['secao_visivel_portal_infantil']),
            'itens' => [],
            'selected_keys' => [],
            'selected_count' => 0,
        ];
        $secoes_index[$secao_id] = [];
    }

    if ($pacote_id > 0 && !empty($secoes)) {
        $queries = [
            'insumo' => "
                SELECT ps.secao_cardapio_id,
                       i.id AS item_id,
                       i.nome_oficial AS nome,
                       i.foto_url,
                       i.foto_chave_storage
                FROM logistica_pacotes_evento_secoes ps
                JOIN logistica_cardapio_item_pacotes ip
                    ON ip.pacote_evento_id = ps.pacote_evento_id
                   AND ip.item_tipo = 'insumo'
                JOIN logistica_cardapio_item_secoes isec
                    ON isec.item_tipo = 'insumo'
                   AND isec.item_id = ip.item_id
                   AND isec.secao_cardapio_id = ps.secao_cardapio_id
                JOIN logistica_insumos i
                    ON i.id = ip.item_id
                WHERE ps.pacote_evento_id = :pacote_id
                  AND i.ativo = TRUE
                ORDER BY lower(i.nome_oficial) ASC, i.id ASC
            ",
            'receita' => "
                SELECT ps.secao_cardapio_id,
                       r.id AS item_id,
                       r.nome,
                       r.foto_url,
                       r.foto_chave_storage
                FROM logistica_pacotes_evento_secoes ps
                JOIN logistica_cardapio_item_pacotes ip
                    ON ip.pacote_evento_id = ps.pacote_evento_id
                   AND ip.item_tipo = 'receita'
                JOIN logistica_cardapio_item_secoes isec
                    ON isec.item_tipo = 'receita'
                   AND isec.item_id = ip.item_id
                   AND isec.secao_cardapio_id = ps.secao_cardapio_id
                JOIN logistica_receitas r
                    ON r.id = ip.item_id
                WHERE ps.pacote_evento_id = :pacote_id
                  AND r.ativo = TRUE
                ORDER BY lower(r.nome) ASC, r.id ASC
            ",
        ];

        foreach ($queries as $item_tipo => $sql) {
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':pacote_id' => $pacote_id]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $secao_id = (int)($row['secao_cardapio_id'] ?? 0);
                    $item_id = (int)($row['item_id'] ?? 0);
                    if ($secao_id <= 0 || $item_id <= 0 || !isset($secoes[$secao_id])) {
                        continue;
                    }

                    $key = logistica_cardapio_item_key($item_tipo, $item_id);
                    if ($key === '' || isset($secoes_index[$secao_id][$key])) {
                        continue;
                    }
                    $secoes_index[$secao_id][$key] = true;

                    $secoes[$secao_id]['itens'][] = [
                        'key' => $key,
                        'item_tipo' => $item_tipo,
                        'item_id' => $item_id,
                        'nome' => trim((string)($row['nome'] ?? '')),
                        'foto_url' => trim((string)($row['foto_url'] ?? '')),
                        'foto_chave_storage' => trim((string)($row['foto_chave_storage'] ?? '')),
                        'checked' => false,
                    ];
                }
            } catch (Throwable $e) {
                error_log('logistica_cardapio_evento_contexto itens ' . $item_tipo . ': ' . $e->getMessage());
            }
        }
    }

    $resposta = logistica_cardapio_resposta_get($pdo, $meeting_id);
    if ($resposta && !empty($resposta['items'])) {
        foreach ($resposta['items'] as $selected_item_row) {
            $secao_id = (int)($selected_item_row['secao_cardapio_id'] ?? 0);
            $item_tipo = logistica_cardapio_validar_item_tipo((string)($selected_item_row['item_tipo'] ?? ''));
            $item_id = (int)($selected_item_row['item_id'] ?? 0);
            $key = logistica_cardapio_item_key($item_tipo, $item_id);
            if ($secao_id <= 0 || $item_id <= 0 || $key === '' || !isset($secoes[$secao_id])) {
                continue;
            }
            if (isset($secoes_index[$secao_id][$key])) {
                continue;
            }

            $secoes_index[$secao_id][$key] = true;
            $secoes[$secao_id]['itens'][] = [
                'key' => $key,
                'item_tipo' => $item_tipo,
                'item_id' => $item_id,
                'nome' => trim((string)($selected_item_row['item_nome'] ?? '')) ?: 'Item selecionado anteriormente',
                'foto_url' => '',
                'foto_chave_storage' => '',
                'checked' => false,
                'legacy_selected' => true,
            ];
        }
    }
    $exclusive_groups = [];
    $exclusive_section_ids = [];
    if ($pacote && logistica_cardapio_slug_simples((string)($pacote['nome'] ?? '')) === 'estrela') {
        $ilha_options = [];
        foreach ($secoes as $secao_id => $secao) {
            $secao_slug = logistica_cardapio_slug_simples((string)($secao['nome'] ?? ''));
            if ($secao_slug === 'ilha fria' || $secao_slug === 'ilha teen') {
                $ilha_options[$secao_slug] = (int)$secao_id;
            }
        }

        if (isset($ilha_options['ilha fria'], $ilha_options['ilha teen'])) {
            $selected_secao_id = 0;
            if ($resposta && !empty($resposta['selected_by_section'])) {
                foreach ($ilha_options as $secao_id) {
                    if (!empty($resposta['selected_by_section'][$secao_id])) {
                        $selected_secao_id = (int)$secao_id;
                        break;
                    }
                }
            }

            $ordered_options = [];
            foreach (['ilha fria', 'ilha teen'] as $secao_slug) {
                $secao_id = (int)$ilha_options[$secao_slug];
                $secoes[$secao_id]['exclusive_group_key'] = 'estrela_ilha';
                $secoes[$secao_id]['exclusive_group_label'] = 'Escolha sua ilha';
                $secoes[$secao_id]['selecionar_todos_itens'] = true;
                $exclusive_section_ids[$secao_id] = 'estrela_ilha';
                $ordered_options[] = [
                    'secao_id' => $secao_id,
                    'label' => (string)$secoes[$secao_id]['nome'],
                    'descricao' => (string)($secoes[$secao_id]['descricao'] ?? ''),
                    'item_count' => count($secoes[$secao_id]['itens'] ?? []),
                    'selected' => $selected_secao_id === $secao_id,
                ];
            }

            $exclusive_groups[] = [
                'key' => 'estrela_ilha',
                'label' => 'Escolha sua ilha',
                'description' => 'Escolha uma das ilhas do pacote Estrela. Os itens da ilha escolhida são fixos.',
                'selected_secao_id' => $selected_secao_id,
                'options' => $ordered_options,
            ];
        }
    }

    $selected_total = 0;
    foreach ($secoes as &$secao) {
        $secao_id = (int)$secao['id'];
        if (isset($exclusive_section_ids[$secao_id])) {
            $selected_in_group = false;
            foreach ($exclusive_groups as $group) {
                if (($group['key'] ?? '') === $exclusive_section_ids[$secao_id]) {
                    $selected_in_group = (int)($group['selected_secao_id'] ?? 0) === $secao_id;
                    break;
                }
            }

            $fixed_keys = [];
            foreach ($secao['itens'] as &$item) {
                $item['checked'] = $selected_in_group;
                $item['fixed_selected'] = true;
                if ($selected_in_group) {
                    $fixed_keys[] = (string)$item['key'];
                }
            }
            unset($item);

            $secao['selected_keys'] = $fixed_keys;
            $secao['selected_count'] = count($fixed_keys);
            if ((int)$secao['quantidade_maxima'] <= 0) {
                $secao['quantidade_maxima'] = count($secao['itens']);
            }
            continue;
        }

        if (empty($secao['selecionar_todos_itens'])) {
            continue;
        }

        $fixed_keys = [];
        foreach ($secao['itens'] as &$item) {
            $item['checked'] = true;
            $item['fixed_selected'] = true;
            $fixed_keys[] = (string)$item['key'];
        }
        unset($item);

        $secao['selected_keys'] = $fixed_keys;
        $secao['selected_count'] = count($fixed_keys);
        if ((int)$secao['quantidade_maxima'] <= 0) {
            $secao['quantidade_maxima'] = count($fixed_keys);
        }
    }
    unset($secao);

    if ($resposta && !empty($resposta['selected_by_section'])) {
        foreach ($secoes as $secao_id => &$secao) {
            if (isset($exclusive_section_ids[(int)$secao_id])) {
                $selected_total += (int)$secao['selected_count'];
                continue;
            }
            if (!empty($secao['selecionar_todos_itens'])) {
                $selected_total += $secao['selected_count'];
                continue;
            }
            $selected_keys = array_values(array_unique($resposta['selected_by_section'][$secao_id] ?? []));
            $secao['selected_keys'] = $selected_keys;
            foreach ($secao['itens'] as &$item) {
                $item['checked'] = in_array($item['key'], $selected_keys, true);
            }
            unset($item);
            $secao['selected_count'] = count($selected_keys);
            $selected_total += $secao['selected_count'];
        }
        unset($secao);
    } else {
        foreach ($secoes as $secao) {
            $selected_total += (int)($secao['selected_count'] ?? 0);
        }
    }

    return [
        'ok' => true,
        'meeting_id' => $meeting_id,
        'pacote' => $pacote,
        'tipo_evento_real' => $tipo_evento_real,
        'is_infantil' => $is_infantil,
        'secoes' => array_values($secoes),
        'exclusive_groups' => $exclusive_groups,
        'resposta' => $resposta,
        'locked' => !empty($resposta['locked']),
        'summary' => [
            'has_pacote' => $pacote !== null,
            'pacote_nome' => (string)($pacote['nome'] ?? ''),
            'secoes_total' => count($secoes),
            'itens_total' => array_sum(array_map(static fn($secao) => count($secao['itens']), $secoes)),
            'selecionados_total' => $selected_total,
            'submitted_at' => (string)($resposta['submitted_at'] ?? ''),
        ],
    ];
}

function logistica_cardapio_resposta_salvar_cliente(PDO $pdo, int $meeting_id, ?int $portal_id, array $selected_by_section_input, array $exclusive_input = []): array
{
    logistica_cardapio_ensure_schema($pdo);
    $contexto = logistica_cardapio_evento_contexto($pdo, $meeting_id);
    if (empty($contexto['ok'])) {
        return $contexto;
    }
    $contexto = logistica_cardapio_contexto_filtrar_portal_cliente($contexto);

    if (empty($contexto['pacote']['id'])) {
        return ['ok' => false, 'error' => 'O evento ainda não possui pacote configurado para o cardápio.'];
    }

    if (empty($contexto['secoes'])) {
        return ['ok' => false, 'error' => 'O pacote deste evento ainda não possui seções configuradas no cardápio.'];
    }

    if (!empty($contexto['locked'])) {
        return ['ok' => false, 'error' => 'O cardápio já foi enviado e está bloqueado para edição.'];
    }

    $normalized_selected = [];
    foreach ($selected_by_section_input as $secao_id_raw => $values) {
        $secao_id_raw = trim((string)$secao_id_raw);
        if ($secao_id_raw === '' || !ctype_digit($secao_id_raw)) {
            continue;
        }
        $secao_id = (int)$secao_id_raw;
        if ($secao_id <= 0) {
            continue;
        }

        $values = is_array($values) ? $values : [$values];
        foreach ($values as $value) {
            $parsed = logistica_cardapio_item_parse_key((string)$value);
            if (!$parsed) {
                continue;
            }
            if (!isset($normalized_selected[$secao_id])) {
                $normalized_selected[$secao_id] = [];
            }
            $normalized_selected[$secao_id][$parsed['key']] = $parsed;
        }
    }

    $selected_rows = [];
    $exclusive_section_ids = [];
    foreach (($contexto['exclusive_groups'] ?? []) as $group) {
        $group_key = trim((string)($group['key'] ?? ''));
        if ($group_key === '') {
            continue;
        }

        $valid_options = [];
        foreach (($group['options'] ?? []) as $option) {
            $secao_id = (int)($option['secao_id'] ?? 0);
            if ($secao_id > 0) {
                $valid_options[$secao_id] = true;
                $exclusive_section_ids[$secao_id] = $group_key;
            }
        }

        $chosen_raw = trim((string)($exclusive_input[$group_key] ?? ''));
        if ($chosen_raw === '' || !ctype_digit($chosen_raw) || !isset($valid_options[(int)$chosen_raw])) {
            return [
                'ok' => false,
                'error' => 'Escolha uma opção em "' . (string)($group['label'] ?? 'grupo do cardápio') . '".',
            ];
        }

        $chosen_secao_id = (int)$chosen_raw;
        foreach ($contexto['secoes'] as $secao) {
            if ((int)$secao['id'] !== $chosen_secao_id) {
                continue;
            }

            foreach (($secao['itens'] ?? []) as $allowed_item) {
                $selected_rows[] = [
                    'secao_cardapio_id' => $chosen_secao_id,
                    'item_tipo' => (string)$allowed_item['item_tipo'],
                    'item_id' => (int)$allowed_item['item_id'],
                ];
            }
            break;
        }
    }

    foreach ($contexto['secoes'] as $secao) {
        $secao_id = (int)$secao['id'];
        if (isset($exclusive_section_ids[$secao_id])) {
            continue;
        }

        $quantidade_maxima = max(0, (int)$secao['quantidade_maxima']);
        $exigir_quantidade_exata = !empty($secao['exigir_quantidade_exata']);
        $selecionar_todos_itens = !empty($secao['selecionar_todos_itens']);
        $allowed_items = [];
        foreach ($secao['itens'] as $item) {
            $allowed_items[$item['key']] = $item;
        }

        if ($selecionar_todos_itens) {
            foreach ($allowed_items as $allowed_item) {
                $selected_rows[] = [
                    'secao_cardapio_id' => $secao_id,
                    'item_tipo' => (string)$allowed_item['item_tipo'],
                    'item_id' => (int)$allowed_item['item_id'],
                ];
            }
            continue;
        }

        if ($exigir_quantidade_exata && $quantidade_maxima > count($allowed_items)) {
            return [
                'ok' => false,
                'error' => 'A seção "' . $secao['nome'] . '" exige ' . $quantidade_maxima . ' escolhas, mas possui apenas ' . count($allowed_items) . ' item(ns) disponível(is).',
            ];
        }

        $selected_items = $normalized_selected[$secao_id] ?? [];
        $selected_keys = array_keys($selected_items);
        foreach ($selected_keys as $selected_key) {
            if (!isset($allowed_items[$selected_key])) {
                return [
                    'ok' => false,
                    'error' => 'Há item(ns) inválido(s) selecionado(s) na seção "' . $secao['nome'] . '".',
                ];
            }
        }

        if (count($selected_keys) > $quantidade_maxima) {
            return [
                'ok' => false,
                'error' => 'Selecione no máximo ' . $quantidade_maxima . ' opção(ões) na seção "' . $secao['nome'] . '".',
            ];
        }

        if ($exigir_quantidade_exata && count($selected_keys) !== $quantidade_maxima) {
            return [
                'ok' => false,
                'error' => 'Selecione ' . $quantidade_maxima . ' opção(ões) na seção "' . $secao['nome'] . '".',
            ];
        }

        foreach ($selected_items as $selected_item) {
            $selected_rows[] = [
                'secao_cardapio_id' => $secao_id,
                'item_tipo' => (string)$selected_item['item_tipo'],
                'item_id' => (int)$selected_item['item_id'],
            ];
        }
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO eventos_cardapio_respostas
                (meeting_id, portal_id, submitted_at, created_at, updated_at)
            VALUES
                (:meeting_id, :portal_id, NOW(), NOW(), NOW())
            ON CONFLICT (meeting_id)
            DO UPDATE SET
                portal_id = EXCLUDED.portal_id,
                submitted_at = EXCLUDED.submitted_at,
                updated_at = NOW()
            RETURNING *
        ");
        $stmt->execute([
            ':meeting_id' => $meeting_id,
            ':portal_id' => $portal_id && $portal_id > 0 ? $portal_id : null,
        ]);
        $resposta = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $resposta_id = (int)($resposta['id'] ?? 0);
        if ($resposta_id <= 0) {
            throw new RuntimeException('Resposta do cardápio não pôde ser criada.');
        }

        $stmt = $pdo->prepare("DELETE FROM eventos_cardapio_resposta_itens WHERE resposta_id = :resposta_id");
        $stmt->execute([':resposta_id' => $resposta_id]);

        if (!empty($selected_rows)) {
            $stmt = $pdo->prepare("
                INSERT INTO eventos_cardapio_resposta_itens
                    (resposta_id, secao_cardapio_id, item_tipo, item_id, created_at)
                VALUES
                    (:resposta_id, :secao_cardapio_id, :item_tipo, :item_id, NOW())
            ");
            foreach ($selected_rows as $row) {
                $stmt->execute([
                    ':resposta_id' => $resposta_id,
                    ':secao_cardapio_id' => (int)$row['secao_cardapio_id'],
                    ':item_tipo' => (string)$row['item_tipo'],
                    ':item_id' => (int)$row['item_id'],
                ]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('logistica_cardapio_resposta_salvar_cliente: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao salvar as escolhas do cardápio.'];
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE eventos_cliente_portais
            SET editavel_cardapio = FALSE
            WHERE meeting_id = :meeting_id
        ");
        $stmt->execute([':meeting_id' => $meeting_id]);
    } catch (Throwable $e) {
        error_log('logistica_cardapio_resposta_salvar_cliente bloquear edicao: ' . $e->getMessage());
    }

    try {
        eventosNotificacoesCentralCriar(
            $pdo,
            $meeting_id,
            'cardapio_finalizado',
            'Cliente finalizou o cardápio',
            'index.php?page=eventos_cardapio&id=' . $meeting_id,
            'cardapio_finalizado:' . $meeting_id . ':' . (int)($resposta['id'] ?? 0),
            'Escolhas do cardápio enviadas pelo cliente.'
        );
    } catch (Throwable $e) {
        error_log('logistica_cardapio_resposta_salvar_cliente notificacao central: ' . $e->getMessage());
    }

    return [
        'ok' => true,
        'resposta' => logistica_cardapio_resposta_get($pdo, $meeting_id),
    ];
}

function logistica_cardapio_adicional_morango_get(PDO $pdo, int $meeting_id): array
{
    logistica_cardapio_ensure_schema($pdo);
    logistica_cardapio_ensure_adicional_morango_schema($pdo);
    if ($meeting_id <= 0) {
        return [
            'ok' => false,
            'adicional_morango_bolo' => false,
            'error' => 'Reunião inválida.',
        ];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT adicional_morango_bolo,
                   adicional_morango_updated_by,
                   adicional_morango_updated_at
            FROM eventos_cardapio_respostas
            WHERE meeting_id = :meeting_id
            LIMIT 1
        ");
        $stmt->execute([':meeting_id' => $meeting_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'ok' => true,
            'adicional_morango_bolo' => logistica_cardapio_bool($row['adicional_morango_bolo'] ?? false),
            'updated_by' => isset($row['adicional_morango_updated_by']) ? (int)$row['adicional_morango_updated_by'] : null,
            'updated_at' => trim((string)($row['adicional_morango_updated_at'] ?? '')),
        ];
    } catch (Throwable $e) {
        error_log('logistica_cardapio_adicional_morango_get: ' . $e->getMessage());
        return [
            'ok' => false,
            'adicional_morango_bolo' => false,
            'error' => 'Erro ao carregar adicional de morango.',
        ];
    }
}

function logistica_cardapio_adicional_morango_salvar(PDO $pdo, int $meeting_id, bool $ativo, ?int $usuario_id): array
{
    logistica_cardapio_ensure_schema($pdo);
    logistica_cardapio_ensure_adicional_morango_schema($pdo);
    if ($meeting_id <= 0) {
        return ['ok' => false, 'error' => 'Reunião inválida.'];
    }

    try {
        $ativoSql = $ativo ? 'TRUE' : 'FALSE';

        $stmt = $pdo->prepare("
            SELECT id
            FROM eventos_cardapio_respostas
            WHERE meeting_id = :meeting_id
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([':meeting_id' => $meeting_id]);
        $respostaId = (int)($stmt->fetchColumn() ?: 0);

        if ($respostaId > 0) {
            $stmt = $pdo->prepare("
                UPDATE eventos_cardapio_respostas
                SET adicional_morango_bolo = {$ativoSql},
                    adicional_morango_updated_by = :usuario_id,
                    adicional_morango_updated_at = NOW(),
                    updated_at = NOW()
                WHERE id = :resposta_id
                RETURNING adicional_morango_bolo,
                          adicional_morango_updated_by,
                          adicional_morango_updated_at
            ");
            $stmt->execute([
                ':resposta_id' => $respostaId,
                ':usuario_id' => $usuario_id && $usuario_id > 0 ? $usuario_id : null,
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO eventos_cardapio_respostas
                    (meeting_id, adicional_morango_bolo, adicional_morango_updated_by, adicional_morango_updated_at, created_at, updated_at)
                VALUES
                    (:meeting_id, {$ativoSql}, :usuario_id, NOW(), NOW(), NOW())
                RETURNING adicional_morango_bolo,
                          adicional_morango_updated_by,
                          adicional_morango_updated_at
            ");
            $stmt->execute([
                ':meeting_id' => $meeting_id,
                ':usuario_id' => $usuario_id && $usuario_id > 0 ? $usuario_id : null,
            ]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'ok' => true,
            'adicional_morango_bolo' => logistica_cardapio_bool($row['adicional_morango_bolo'] ?? false),
            'updated_by' => isset($row['adicional_morango_updated_by']) ? (int)$row['adicional_morango_updated_by'] : null,
            'updated_at' => trim((string)($row['adicional_morango_updated_at'] ?? '')),
        ];
    } catch (Throwable $e) {
        error_log('logistica_cardapio_adicional_morango_salvar: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao salvar adicional de morango.'];
    }
}

function logistica_cardapio_pick_text(array $source, array $paths): string
{
    foreach ($paths as $path) {
        $current = $source;
        foreach (explode('.', $path) as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                $current = null;
                break;
            }
            $current = $current[$part];
        }
        if (is_scalar($current)) {
            $value = trim((string)$current);
            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}

function logistica_cardapio_cliente_contato(PDO $pdo, int $meeting_id): array
{
    logistica_cardapio_require_eventos_reuniao_helper();
    eventos_reuniao_ensure_schema($pdo);
    $portalKeySql = eventos_cliente_portal_public_key_sql($pdo, 'p');

    $contato = [
        'nome' => 'Cliente',
        'telefone' => '',
        'portal_url' => '',
    ];

    try {
        $stmt = $pdo->prepare("
            SELECT
                r.me_event_id,
                r.me_event_snapshot,
                {$portalKeySql} AS portal_token
            FROM eventos_reunioes r
            LEFT JOIN eventos_cliente_portais p ON p.meeting_id = r.id
            WHERE r.id = :meeting_id
            LIMIT 1
        ");
        $stmt->execute([':meeting_id' => $meeting_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $snapshot = json_decode((string)($row['me_event_snapshot'] ?? '{}'), true);
        $snapshot = is_array($snapshot) ? $snapshot : [];

        $nome = logistica_cardapio_pick_text($snapshot, ['cliente.nome', 'nomecliente', 'nomeCliente', 'cliente_nome']);
        if ($nome !== '') {
            $contato['nome'] = $nome;
        }

        $telefone = logistica_cardapio_pick_text($snapshot, [
            'cliente.telefone',
            'cliente.whatsapp',
            'cliente.celular',
            'telefonecliente',
            'telefoneCliente',
            'whatsappcliente',
            'whatsappCliente',
            'telefone',
            'whatsapp',
        ]);
        if ($telefone !== '') {
            $contato['telefone'] = $telefone;
        }

        $token = trim((string)($row['portal_token'] ?? ''));
        if ($token !== '') {
            $contato['portal_url'] = eventos_cliente_portal_base_url() . '/index.php?page=eventos_cliente_cardapio&token=' . urlencode($token);
        }

        $meEventId = (int)($row['me_event_id'] ?? 0);
        if ($meEventId > 0 && logistica_cardapio_has_table($pdo, 'logistica_eventos_espelho')) {
            $phoneParts = [];
            $joinClientes = '';
            if (logistica_cardapio_has_column($pdo, 'logistica_eventos_espelho', 'whatsapp_cliente') || logistica_cardapio_has_column($pdo, 'logistica_eventos_espelho', 'telefone_cliente')) {
                if (logistica_cardapio_has_column($pdo, 'logistica_eventos_espelho', 'whatsapp_cliente')) {
                    $phoneParts[] = "NULLIF(TRIM(e.whatsapp_cliente), '')";
                }
                if (logistica_cardapio_has_column($pdo, 'logistica_eventos_espelho', 'telefone_cliente')) {
                    $phoneParts[] = "NULLIF(TRIM(e.telefone_cliente), '')";
                }
            }
            if (
                logistica_cardapio_has_column($pdo, 'logistica_eventos_espelho', 'cliente_cadastro_id')
                && logistica_cardapio_has_table($pdo, 'comercial_cadastro_clientes')
                && logistica_cardapio_has_column($pdo, 'comercial_cadastro_clientes', 'telefone_whatsapp')
            ) {
                $joinClientes = 'LEFT JOIN comercial_cadastro_clientes cc ON cc.id = e.cliente_cadastro_id';
                $phoneParts[] = "NULLIF(TRIM(cc.telefone_whatsapp), '')";
            }
            $phoneExpr = !empty($phoneParts) ? 'COALESCE(' . implode(', ', $phoneParts) . ", '')" : "''";

            $nomeExpr = logistica_cardapio_has_column($pdo, 'logistica_eventos_espelho', 'nome_evento')
                ? "COALESCE(NULLIF(TRIM(e.nome_evento), ''), '')"
                : "''";
            $stmtEvento = $pdo->prepare("
                SELECT {$phoneExpr} AS telefone_cliente, {$nomeExpr} AS nome_evento
                FROM logistica_eventos_espelho e
                {$joinClientes}
                WHERE e.me_event_id = :me_event_id
                ORDER BY e.id DESC
                LIMIT 1
            ");
            $stmtEvento->execute([':me_event_id' => $meEventId]);
            $evento = $stmtEvento->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($contato['telefone'] === '' && trim((string)($evento['telefone_cliente'] ?? '')) !== '') {
                $contato['telefone'] = trim((string)$evento['telefone_cliente']);
            }
            if ($contato['nome'] === 'Cliente' && trim((string)($evento['nome_evento'] ?? '')) !== '') {
                $contato['nome'] = trim((string)$evento['nome_evento']);
            }
        }
    } catch (Throwable $e) {
        error_log('logistica_cardapio_cliente_contato: ' . $e->getMessage());
    }

    return $contato;
}

function logistica_cardapio_notificar_desbloqueio_whatsapp(PDO $pdo, int $meeting_id): array
{
    $contato = logistica_cardapio_cliente_contato($pdo, $meeting_id);
    $telefone = trim((string)($contato['telefone'] ?? ''));
    if ($telefone === '') {
        return ['ok' => false, 'error' => 'Telefone do cliente não encontrado.'];
    }

    $nome = trim((string)($contato['nome'] ?? 'Cliente')) ?: 'Cliente';
    $portalUrl = trim((string)($contato['portal_url'] ?? ''));
    $mensagem = "Olá, {$nome}. Tudo bem?\n\n"
        . "Liberamos novamente o cardápio do seu evento para edição. Você já pode acessar o portal e ajustar suas escolhas.\n\n";
    if ($portalUrl !== '') {
        $mensagem .= "Acesse o cardápio por aqui:\n{$portalUrl}\n\n";
    }
    $mensagem .= "Depois de ajustar, lembre-se de enviar novamente para nossa equipe.";

    try {
        require_once __DIR__ . '/core/notification_dispatcher.php';
        $dispatcher = new NotificationDispatcher($pdo);
        $ok = $dispatcher->sendWhatsappDirect($telefone, $mensagem, $nome, ['whatsapp_provider' => 'smclick']);
        return [
            'ok' => (bool)$ok,
            'telefone' => $telefone,
            'error' => $ok ? null : 'Falha ao enviar WhatsApp pela SMClick.',
        ];
    } catch (Throwable $e) {
        error_log('logistica_cardapio_notificar_desbloqueio_whatsapp: ' . $e->getMessage());
        return ['ok' => false, 'telefone' => $telefone, 'error' => 'Erro ao enviar WhatsApp.'];
    }
}

function logistica_cardapio_resposta_desbloquear_cliente(PDO $pdo, int $meeting_id): array
{
    logistica_cardapio_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return ['ok' => false, 'error' => 'Reunião inválida'];
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE eventos_cardapio_respostas
            SET submitted_at = NULL,
                updated_at = NOW()
            WHERE meeting_id = :meeting_id
              AND submitted_at IS NOT NULL
            RETURNING id
        ");
        $stmt->execute([':meeting_id' => $meeting_id]);
        $resposta_id = (int)($stmt->fetchColumn() ?: 0);
        if ($resposta_id <= 0) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Cardápio não está bloqueado por envio do cliente.'];
        }

        $stmt = $pdo->prepare("
            UPDATE eventos_cliente_portais
            SET visivel_cardapio = TRUE,
                editavel_cardapio = TRUE
            WHERE meeting_id = :meeting_id
        ");
        $stmt->execute([':meeting_id' => $meeting_id]);

        if (function_exists('eventosNotificacoesCentralEnsureSchema')) {
            eventosNotificacoesCentralEnsureSchema($pdo);
            $stmt = $pdo->prepare("
                DELETE FROM eventos_notificacoes_central
                WHERE meeting_id = :meeting_id
                  AND tipo IN ('cardapio_finalizado', 'cardapio_desbloqueio_solicitado')
            ");
            $stmt->execute([':meeting_id' => $meeting_id]);
        }

        $pdo->commit();
        $whatsapp = logistica_cardapio_notificar_desbloqueio_whatsapp($pdo, $meeting_id);
        return [
            'ok' => true,
            'resposta_id' => $resposta_id,
            'resposta' => logistica_cardapio_resposta_get($pdo, $meeting_id),
            'whatsapp' => $whatsapp,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('logistica_cardapio_resposta_desbloquear_cliente: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao desbloquear o cardápio.'];
    }
}

function logistica_cardapio_solicitar_desbloqueio_cliente(PDO $pdo, int $meeting_id): array
{
    logistica_cardapio_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return ['ok' => false, 'error' => 'Reunião inválida'];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id, submitted_at
            FROM eventos_cardapio_respostas
            WHERE meeting_id = :meeting_id
              AND submitted_at IS NOT NULL
            LIMIT 1
        ");
        $stmt->execute([':meeting_id' => $meeting_id]);
        $resposta = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$resposta) {
            return ['ok' => false, 'error' => 'O cardápio não está bloqueado para solicitar desbloqueio.'];
        }

        eventosNotificacoesCentralCriar(
            $pdo,
            $meeting_id,
            'cardapio_desbloqueio_solicitado',
            'Cliente solicitou desbloqueio do cardápio',
            'index.php?page=eventos_organizacao&id=' . $meeting_id,
            'cardapio_desbloqueio_solicitado:' . $meeting_id . ':' . (int)($resposta['id'] ?? 0),
            'O cliente solicitou reabertura do cardápio para ajustar as escolhas.'
        );

        return ['ok' => true];
    } catch (Throwable $e) {
        error_log('logistica_cardapio_solicitar_desbloqueio_cliente: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao enviar a solicitação.'];
    }
}

function logistica_cardapio_evento_resetar(PDO $pdo, int $meeting_id): void
{
    logistica_cardapio_ensure_schema($pdo);
    if ($meeting_id <= 0) {
        return;
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM eventos_cardapio_respostas WHERE meeting_id = :meeting_id LIMIT 1");
        $stmt->execute([':meeting_id' => $meeting_id]);
        $resposta_id = (int)($stmt->fetchColumn() ?: 0);
        if ($resposta_id <= 0) {
            return;
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("DELETE FROM eventos_cardapio_resposta_itens WHERE resposta_id = :resposta_id");
        $stmt->execute([':resposta_id' => $resposta_id]);
        $stmt = $pdo->prepare("DELETE FROM eventos_cardapio_respostas WHERE id = :resposta_id");
        $stmt->execute([':resposta_id' => $resposta_id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('logistica_cardapio_evento_resetar: ' . $e->getMessage());
    }
}
