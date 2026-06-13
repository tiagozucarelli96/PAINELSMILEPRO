<?php
/**
 * Central de notificacoes dos eventos.
 */

function eventosNotificacoesCentralSchemaMarkerFresh(string $markerName, int $ttl = 900): bool {
    $mtime = @filemtime(sys_get_temp_dir() . '/' . $markerName);
    return $mtime !== false && (time() - $mtime) < $ttl;
}

function eventosNotificacoesCentralEnsureSchema(PDO $pdo): void {
    if (eventosNotificacoesCentralSchemaMarkerFresh('eventos_notificacoes_central_schema_ready')) {
        return;
    }

    $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_notificacoes_eventos BOOLEAN DEFAULT FALSE");
    $pdo->exec("UPDATE usuarios SET perm_notificacoes_eventos = TRUE WHERE COALESCE(perm_superadmin, FALSE) = TRUE");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eventos_notificacoes_central (
            id BIGSERIAL PRIMARY KEY,
            meeting_id BIGINT NOT NULL,
            tipo VARCHAR(80) NOT NULL,
            titulo VARCHAR(180) NOT NULL,
            mensagem TEXT NULL,
            target_url TEXT NOT NULL,
            reference_key VARCHAR(220) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");
    $pdo->exec("ALTER TABLE eventos_notificacoes_central ADD COLUMN IF NOT EXISTS meeting_id BIGINT");
    $pdo->exec("ALTER TABLE eventos_notificacoes_central ADD COLUMN IF NOT EXISTS tipo VARCHAR(80)");
    $pdo->exec("ALTER TABLE eventos_notificacoes_central ADD COLUMN IF NOT EXISTS titulo VARCHAR(180)");
    $pdo->exec("ALTER TABLE eventos_notificacoes_central ADD COLUMN IF NOT EXISTS mensagem TEXT NULL");
    $pdo->exec("ALTER TABLE eventos_notificacoes_central ADD COLUMN IF NOT EXISTS target_url TEXT");
    $pdo->exec("ALTER TABLE eventos_notificacoes_central ADD COLUMN IF NOT EXISTS reference_key VARCHAR(220)");
    $pdo->exec("ALTER TABLE eventos_notificacoes_central ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT NOW()");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_eventos_notificacoes_central_ref ON eventos_notificacoes_central(reference_key)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_notificacoes_central_created ON eventos_notificacoes_central(created_at DESC, id DESC)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_notificacoes_central_meeting ON eventos_notificacoes_central(meeting_id, created_at DESC)");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eventos_notificacoes_central_ignorados (
            notificacao_id BIGINT NOT NULL REFERENCES eventos_notificacoes_central(id) ON DELETE CASCADE,
            usuario_id BIGINT NOT NULL,
            ignored_at TIMESTAMP NOT NULL DEFAULT NOW(),
            PRIMARY KEY (notificacao_id, usuario_id)
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_notificacoes_central_ign_user ON eventos_notificacoes_central_ignorados(usuario_id, ignored_at DESC)");

    @touch(sys_get_temp_dir() . '/eventos_notificacoes_central_schema_ready');
}

function eventosNotificacoesCentralMeetingInfo(PDO $pdo, int $meetingId): array {
    if ($meetingId <= 0) {
        return ['nome_evento' => 'Evento', 'data_evento' => null];
    }

    try {
        $stmt = $pdo->prepare("SELECT me_event_snapshot FROM eventos_reunioes WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $meetingId]);
        $snapshotRaw = $stmt->fetchColumn();
        $snapshot = is_string($snapshotRaw) ? json_decode($snapshotRaw, true) : $snapshotRaw;
        if (!is_array($snapshot)) {
            $snapshot = [];
        }

        return [
            'nome_evento' => trim((string)($snapshot['nome'] ?? 'Evento')) ?: 'Evento',
            'data_evento' => trim((string)($snapshot['data'] ?? '')) ?: null,
        ];
    } catch (Throwable $e) {
        error_log('eventosNotificacoesCentralMeetingInfo: ' . $e->getMessage());
        return ['nome_evento' => 'Evento', 'data_evento' => null];
    }
}

function eventosNotificacoesCentralCriar(PDO $pdo, int $meetingId, string $tipo, string $titulo, string $targetUrl, string $referenceKey, string $mensagem = ''): void {
    if ($meetingId <= 0 || trim($tipo) === '' || trim($titulo) === '' || trim($targetUrl) === '' || trim($referenceKey) === '') {
        return;
    }

    try {
        eventosNotificacoesCentralEnsureSchema($pdo);
        $stmt = $pdo->prepare("
            INSERT INTO eventos_notificacoes_central
                (meeting_id, tipo, titulo, mensagem, target_url, reference_key, created_at)
            VALUES
                (:meeting_id, :tipo, :titulo, :mensagem, :target_url, :reference_key, NOW())
            ON CONFLICT (reference_key) DO NOTHING
        ");
        $stmt->execute([
            ':meeting_id' => $meetingId,
            ':tipo' => trim($tipo),
            ':titulo' => trim($titulo),
            ':mensagem' => trim($mensagem) !== '' ? trim($mensagem) : null,
            ':target_url' => trim($targetUrl),
            ':reference_key' => trim($referenceKey),
        ]);
    } catch (Throwable $e) {
        error_log('eventosNotificacoesCentralCriar: ' . $e->getMessage());
    }
}

function eventosNotificacoesCentralBuscarDashboard(PDO $pdo, int $usuarioId, int $limit = 12): array {
    if ($usuarioId <= 0) {
        return [];
    }

    eventosNotificacoesCentralEnsureSchema($pdo);
    $limit = max(1, min(30, $limit));

    $stmt = $pdo->prepare("
        SELECT
            n.id,
            n.meeting_id,
            n.tipo,
            n.titulo,
            n.mensagem,
            n.target_url,
            n.created_at,
            r.me_event_snapshot
        FROM eventos_notificacoes_central n
        LEFT JOIN eventos_reunioes r ON r.id = n.meeting_id
        WHERE NOT EXISTS (
            SELECT 1
            FROM eventos_notificacoes_central_ignorados i
            WHERE i.notificacao_id = n.id
              AND i.usuario_id = :usuario_id_id
        )
        AND NOT EXISTS (
            SELECT 1
            FROM eventos_notificacoes_central_ignorados i
            JOIN eventos_notificacoes_central ni ON ni.id = i.notificacao_id
            WHERE i.usuario_id = :usuario_id_tipo
              AND ni.meeting_id = n.meeting_id
              AND ni.tipo = n.tipo
        )
        ORDER BY n.created_at DESC, n.id DESC
        LIMIT {$limit}
    ");
    $stmt->execute([
        ':usuario_id_id' => $usuarioId,
        ':usuario_id_tipo' => $usuarioId,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $snapshotRaw = $row['me_event_snapshot'] ?? '';
        $snapshot = is_string($snapshotRaw) ? json_decode($snapshotRaw, true) : $snapshotRaw;
        if (!is_array($snapshot)) {
            $snapshot = [];
        }
        $row['nome_evento'] = trim((string)($snapshot['nome'] ?? 'Evento')) ?: 'Evento';
        $row['data_evento'] = trim((string)($snapshot['data'] ?? ''));
    }
    unset($row);

    return $rows;
}

function eventosNotificacoesCentralIgnorar(PDO $pdo, int $notificationId, int $usuarioId): bool {
    if ($notificationId <= 0 || $usuarioId <= 0) {
        return false;
    }

    eventosNotificacoesCentralEnsureSchema($pdo);

    $stmtInfo = $pdo->prepare("
        SELECT meeting_id, tipo
        FROM eventos_notificacoes_central
        WHERE id = :id
        LIMIT 1
    ");
    $stmtInfo->execute([':id' => $notificationId]);
    $info = $stmtInfo->fetch(PDO::FETCH_ASSOC) ?: [];
    $meetingId = (int)($info['meeting_id'] ?? 0);
    $tipo = trim((string)($info['tipo'] ?? ''));

    if ($meetingId > 0 && $tipo !== '') {
        $stmt = $pdo->prepare("
            INSERT INTO eventos_notificacoes_central_ignorados (notificacao_id, usuario_id, ignored_at)
            SELECT id, :usuario_id, NOW()
            FROM eventos_notificacoes_central
            WHERE meeting_id = :meeting_id
              AND tipo = :tipo
            ON CONFLICT (notificacao_id, usuario_id) DO NOTHING
        ");
        return $stmt->execute([
            ':usuario_id' => $usuarioId,
            ':meeting_id' => $meetingId,
            ':tipo' => $tipo,
        ]);
    }

    $stmt = $pdo->prepare("
        INSERT INTO eventos_notificacoes_central_ignorados (notificacao_id, usuario_id, ignored_at)
        VALUES (:notificacao_id, :usuario_id, NOW())
        ON CONFLICT (notificacao_id, usuario_id) DO NOTHING
    ");
    return $stmt->execute([
        ':notificacao_id' => $notificationId,
        ':usuario_id' => $usuarioId,
    ]);
}
