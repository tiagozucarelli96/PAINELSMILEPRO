<?php
/**
 * eventos_historico_helper.php
 * Auditoria simples de alterações nos eventos organizados.
 */

function eventos_historico_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    if (function_exists('painel_runtime_schema_setup_enabled') && !painel_runtime_schema_setup_enabled()) {
        $done = true;
        return;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS eventos_historico (
                id BIGSERIAL PRIMARY KEY,
                meeting_id BIGINT NOT NULL,
                me_event_id BIGINT NULL,
                acao VARCHAR(80) NOT NULL,
                titulo VARCHAR(180) NOT NULL,
                descricao TEXT NULL,
                dados_antes JSONB NULL,
                dados_depois JSONB NULL,
                usuario_id INTEGER NULL,
                usuario_nome VARCHAR(180) NULL,
                origem VARCHAR(40) NOT NULL DEFAULT 'painel',
                criado_em TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_historico_meeting ON eventos_historico(meeting_id, criado_em DESC)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_historico_me_event ON eventos_historico(me_event_id, criado_em DESC)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_historico_usuario ON eventos_historico(usuario_id, criado_em DESC)");
    } catch (Throwable $e) {
        error_log('eventos_historico_ensure_schema: ' . $e->getMessage());
    }

    $done = true;
}

function eventos_historico_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_name = :table
              AND column_name = :column
              AND table_schema = ANY (current_schemas(FALSE))
            LIMIT 1
        ");
        $stmt->execute([
            ':table' => $table,
            ':column' => $column,
        ]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function eventos_historico_usuario_nome(PDO $pdo, int $usuarioId): string
{
    if ($usuarioId <= 0) {
        return '';
    }

    try {
        $parts = [];
        foreach (['nome', 'nome_completo', 'email'] as $column) {
            if (eventos_historico_has_column($pdo, 'usuarios', $column)) {
                $parts[] = "NULLIF(TRIM({$column}), '')";
            }
        }
        $parts[] = "'Usuário #' || id::text";
        $stmt = $pdo->prepare("
            SELECT COALESCE(" . implode(', ', $parts) . ")
            FROM usuarios
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $usuarioId]);
        return trim((string)($stmt->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return '';
    }
}

function eventos_historico_me_event_id(PDO $pdo, int $meetingId): int
{
    if ($meetingId <= 0) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare("SELECT COALESCE(me_event_id, 0) FROM eventos_reunioes WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $meetingId]);
        return (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function eventos_historico_registrar(
    PDO $pdo,
    int $meetingId,
    string $acao,
    string $titulo,
    ?string $descricao = null,
    ?array $antes = null,
    ?array $depois = null,
    int $usuarioId = 0,
    string $origem = 'painel'
): void {
    if ($meetingId <= 0 || trim($acao) === '' || trim($titulo) === '') {
        return;
    }

    eventos_historico_ensure_schema($pdo);

    try {
        $usuarioNome = eventos_historico_usuario_nome($pdo, $usuarioId);
        $stmt = $pdo->prepare("
            INSERT INTO eventos_historico
                (meeting_id, me_event_id, acao, titulo, descricao, dados_antes, dados_depois, usuario_id, usuario_nome, origem, criado_em)
            VALUES
                (:meeting_id, :me_event_id, :acao, :titulo, :descricao, CAST(:dados_antes AS jsonb), CAST(:dados_depois AS jsonb), :usuario_id, :usuario_nome, :origem, NOW())
        ");
        $stmt->execute([
            ':meeting_id' => $meetingId,
            ':me_event_id' => eventos_historico_me_event_id($pdo, $meetingId) ?: null,
            ':acao' => substr(trim($acao), 0, 80),
            ':titulo' => substr(trim($titulo), 0, 180),
            ':descricao' => $descricao !== null ? trim($descricao) : null,
            ':dados_antes' => $antes !== null ? json_encode($antes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':dados_depois' => $depois !== null ? json_encode($depois, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':usuario_id' => $usuarioId > 0 ? $usuarioId : null,
            ':usuario_nome' => $usuarioNome !== '' ? $usuarioNome : null,
            ':origem' => substr(trim($origem) !== '' ? trim($origem) : 'painel', 0, 40),
        ]);
    } catch (Throwable $e) {
        error_log('eventos_historico_registrar: ' . $e->getMessage());
    }
}

function eventos_historico_listar(PDO $pdo, int $meetingId, int $limit = 120): array
{
    eventos_historico_ensure_schema($pdo);

    if ($meetingId <= 0) {
        return [];
    }

    $limit = max(10, min(300, $limit));

    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM eventos_historico
            WHERE meeting_id = :meeting_id
            ORDER BY criado_em DESC, id DESC
            LIMIT {$limit}
        ");
        $stmt->execute([':meeting_id' => $meetingId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('eventos_historico_listar: ' . $e->getMessage());
        return [];
    }
}

function eventos_historico_meeting_por_me_event(PDO $pdo, int $meEventId): int
{
    if ($meEventId <= 0) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id
            FROM eventos_reunioes
            WHERE me_event_id = :me_event_id
            ORDER BY updated_at DESC NULLS LAST, id DESC
            LIMIT 1
        ");
        $stmt->execute([':me_event_id' => $meEventId]);
        return (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}
