<?php
/**
 * logistica_cardapio_helper.php
 * Estrutura de seções, pacotes, vínculos de cardápio e escolhas do cliente.
 */

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
    return $row;
}

function logistica_cardapio_normalizar_pacote_secao_row(array $row): array
{
    $row['id'] = (int)($row['id'] ?? 0);
    $row['pacote_evento_id'] = (int)($row['pacote_evento_id'] ?? 0);
    $row['secao_cardapio_id'] = (int)($row['secao_cardapio_id'] ?? 0);
    $row['quantidade_maxima'] = max(0, (int)($row['quantidade_maxima'] ?? 0));
    $row['ordem'] = (int)($row['ordem'] ?? 0);
    $row['secao_nome'] = trim((string)($row['secao_nome'] ?? $row['nome'] ?? ''));
    $row['secao_descricao'] = trim((string)($row['secao_descricao'] ?? ''));
    $row['secao_ativo'] = !array_key_exists('secao_ativo', $row) || !empty($row['secao_ativo']);
    return $row;
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
        ? '/tmp/logistica_cardapio_schema_full_ready'
        : '/tmp/logistica_cardapio_schema_basic_ready';
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

    if (logistica_cardapio_schema_marker_is_fresh($withMeetingSchema)) {
        $done[$cacheKey] = true;
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
        $pdo->exec("ALTER TABLE IF EXISTS logistica_cardapio_secoes ADD COLUMN IF NOT EXISTS created_by_user_id INTEGER NULL");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_cardapio_secoes ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT NOW()");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_cardapio_secoes ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT NOW()");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_cardapio_secoes ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_cardapio_secoes ADD COLUMN IF NOT EXISTS deleted_by_user_id INTEGER NULL");
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
                ordem INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento_secoes ADD COLUMN IF NOT EXISTS pacote_evento_id BIGINT");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento_secoes ADD COLUMN IF NOT EXISTS secao_cardapio_id BIGINT");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento_secoes ADD COLUMN IF NOT EXISTS quantidade_maxima INTEGER NOT NULL DEFAULT 1");
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
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cliente_portais ADD COLUMN IF NOT EXISTS visivel_cardapio BOOLEAN NOT NULL DEFAULT FALSE");
        $pdo->exec("ALTER TABLE IF EXISTS eventos_cliente_portais ADD COLUMN IF NOT EXISTS editavel_cardapio BOOLEAN NOT NULL DEFAULT FALSE");
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
    int $user_id = 0
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
                (nome, descricao, ordem, ativo, created_by_user_id, created_at, updated_at)
            VALUES
                (:nome, :descricao, :ordem, :ativo, :created_by_user_id, NOW(), NOW())
            RETURNING *
        ");
        $stmt->execute([
            ':nome' => $nome,
            ':descricao' => $descricao !== '' ? $descricao : null,
            ':ordem' => max(0, $ordem),
            ':ativo' => $ativo ? 1 : 0,
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
                   s.ativo AS secao_ativo
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
        if ($quantidade <= 0) {
            continue;
        }

        $normalized[$secao_id] = [
            'secao_cardapio_id' => $secao_id,
            'quantidade_maxima' => $quantidade,
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
                    (pacote_evento_id, secao_cardapio_id, quantidade_maxima, ordem, created_at, updated_at)
                VALUES
                    (:pacote_evento_id, :secao_cardapio_id, :quantidade_maxima, :ordem, NOW(), NOW())
            ");
            foreach ($normalized as $rule) {
                $stmt_insert->execute([
                    ':pacote_evento_id' => $pacote_id,
                    ':secao_cardapio_id' => (int)$rule['secao_cardapio_id'],
                    ':quantidade_maxima' => (int)$rule['quantidade_maxima'],
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
                          (ip.item_tipo = 'insumo' AND i.ativo = TRUE AND COALESCE(i.visivel_na_lista, TRUE) = TRUE)
                          OR
                          (ip.item_tipo = 'receita' AND r.ativo = TRUE AND COALESCE(r.visivel_na_lista, TRUE) = TRUE)
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
    $pacote = null;
    if ($pacote_id > 0) {
        $pacote = [
            'id' => $pacote_id,
            'nome' => trim((string)($meeting['pacote_nome'] ?? '')),
            'descricao' => trim((string)($meeting['pacote_descricao'] ?? '')),
        ];
    }

    $regras = [];
    if ($pacote_id > 0) {
        try {
            $stmt = $pdo->prepare("
                SELECT ps.*,
                       s.nome AS secao_nome,
                       s.descricao AS secao_descricao,
                       s.ordem AS secao_ordem,
                       s.ativo AS secao_ativo
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
                  AND COALESCE(i.visivel_na_lista, TRUE) = TRUE
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
                  AND COALESCE(r.visivel_na_lista, TRUE) = TRUE
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
    $selected_total = 0;
    if ($resposta && !empty($resposta['selected_by_section'])) {
        foreach ($secoes as $secao_id => &$secao) {
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
    }

    return [
        'ok' => true,
        'meeting_id' => $meeting_id,
        'pacote' => $pacote,
        'secoes' => array_values($secoes),
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

function logistica_cardapio_resposta_salvar_cliente(PDO $pdo, int $meeting_id, ?int $portal_id, array $selected_by_section_input): array
{
    logistica_cardapio_ensure_schema($pdo);
    $contexto = logistica_cardapio_evento_contexto($pdo, $meeting_id);
    if (empty($contexto['ok'])) {
        return $contexto;
    }

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
    foreach ($contexto['secoes'] as $secao) {
        $secao_id = (int)$secao['id'];
        $quantidade_maxima = max(0, (int)$secao['quantidade_maxima']);
        $allowed_items = [];
        foreach ($secao['itens'] as $item) {
            $allowed_items[$item['key']] = $item;
        }

        if ($quantidade_maxima > count($allowed_items)) {
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

        if (count($selected_keys) !== $quantidade_maxima) {
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

    return [
        'ok' => true,
        'resposta' => logistica_cardapio_resposta_get($pdo, $meeting_id),
    ];
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
