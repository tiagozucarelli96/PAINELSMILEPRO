<?php
/**
 * Alertas de cancelamento de eventos ME.
 */

require_once __DIR__ . '/conexao.php';

function eventosCancelamentoNormalizeText(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $map = [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c',
    ];
    $value = strtr($value, $map);
    return preg_replace('/\s+/', ' ', $value) ?: '';
}

function eventosCancelamentoTableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = current_schema()
              AND table_name = :table
            LIMIT 1
        ");
        $stmt->execute([':table' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function eventosCancelamentoEnsureSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eventos_cancelamento_alertas (
            id BIGSERIAL PRIMARY KEY,
            me_event_id BIGINT NOT NULL,
            usuario_id BIGINT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
            nome_evento TEXT NOT NULL,
            data_evento DATE NULL,
            local_evento TEXT NULL,
            space_visivel TEXT NULL,
            mensagem TEXT NOT NULL,
            email_enviado_em TIMESTAMP NULL,
            popup_visto_em TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            UNIQUE (me_event_id, usuario_id)
        )
    ");
    $pdo->exec("ALTER TABLE eventos_cancelamento_alertas ADD COLUMN IF NOT EXISTS local_evento TEXT NULL");
    $pdo->exec("ALTER TABLE eventos_cancelamento_alertas ADD COLUMN IF NOT EXISTS space_visivel TEXT NULL");
    $pdo->exec("ALTER TABLE eventos_cancelamento_alertas ADD COLUMN IF NOT EXISTS email_enviado_em TIMESTAMP NULL");
    $pdo->exec("ALTER TABLE eventos_cancelamento_alertas ADD COLUMN IF NOT EXISTS popup_visto_em TIMESTAMP NULL");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_cancelamento_alertas_usuario_popup ON eventos_cancelamento_alertas(usuario_id, popup_visto_em, created_at DESC)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_cancelamento_alertas_evento ON eventos_cancelamento_alertas(me_event_id)");

    $done = true;
}

function eventosCancelamentoPick(array $source, array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $source) && trim((string)$source[$key]) !== '') {
            return trim((string)$source[$key]);
        }
    }
    return $default;
}

function eventosCancelamentoFormatDate(?string $date): string
{
    $date = trim((string)$date);
    if ($date === '') {
        return 'Data não informada';
    }

    $ts = strtotime($date);
    return $ts ? date('d/m/Y', $ts) : $date;
}

function eventosCancelamentoDbDate(?string $date): string
{
    $date = trim((string)$date);
    if ($date === '') {
        return '';
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date;
    }

    $ts = strtotime($date);
    return $ts ? date('Y-m-d', $ts) : '';
}

function eventosCancelamentoResolverEvento(PDO $pdo, int $meEventId, array $eventoData): array
{
    $info = [
        'me_event_id' => $meEventId,
        'nome_evento' => eventosCancelamentoPick($eventoData, ['nomeevento', 'nome', 'titulo'], 'Evento'),
        'data_evento' => eventosCancelamentoPick($eventoData, ['dataevento', 'data_evento', 'data'], ''),
        'local_evento' => eventosCancelamentoPick($eventoData, ['localevento', 'localEvento', 'nomelocal', 'local'], ''),
        'idlocalevento' => (int)eventosCancelamentoPick($eventoData, ['idlocalevento', 'idLocalEvento', 'local_id'], '0'),
        'space_visivel' => eventosCancelamentoPick($eventoData, ['space_visivel', 'spaceVisivel', 'space'], ''),
    ];

    try {
        if (eventosCancelamentoTableExists($pdo, 'logistica_eventos_espelho')) {
            $stmt = $pdo->prepare("
                SELECT e.nome_evento, e.data_evento::text AS data_evento, e.localevento, e.idlocalevento, e.space_visivel
                FROM logistica_eventos_espelho e
                WHERE e.me_event_id = :me_event_id
                LIMIT 1
            ");
            $stmt->execute([':me_event_id' => $meEventId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($info['nome_evento'] === 'Evento' && trim((string)($row['nome_evento'] ?? '')) !== '') {
                $info['nome_evento'] = trim((string)$row['nome_evento']);
            }
            foreach (['data_evento' => 'data_evento', 'local_evento' => 'localevento', 'space_visivel' => 'space_visivel'] as $target => $column) {
                if ($info[$target] === '' && trim((string)($row[$column] ?? '')) !== '') {
                    $info[$target] = trim((string)$row[$column]);
                }
            }
            if ($info['idlocalevento'] <= 0 && !empty($row['idlocalevento'])) {
                $info['idlocalevento'] = (int)$row['idlocalevento'];
            }
        }

        if ($info['space_visivel'] === '' && eventosCancelamentoTableExists($pdo, 'logistica_me_locais')) {
            $mapping = null;
            if ($info['idlocalevento'] > 0) {
                $stmt = $pdo->prepare("
                    SELECT space_visivel
                    FROM logistica_me_locais
                    WHERE me_local_id = :id
                      AND TRIM(COALESCE(space_visivel, '')) <> ''
                    LIMIT 1
                ");
                $stmt->execute([':id' => $info['idlocalevento']]);
                $mapping = $stmt->fetchColumn();
            }
            if (!$mapping && $info['local_evento'] !== '') {
                $stmt = $pdo->prepare("
                    SELECT space_visivel
                    FROM logistica_me_locais
                    WHERE LOWER(TRIM(me_local_nome)) = LOWER(TRIM(:nome))
                      AND TRIM(COALESCE(space_visivel, '')) <> ''
                    LIMIT 1
                ");
                $stmt->execute([':nome' => $info['local_evento']]);
                $mapping = $stmt->fetchColumn();
            }
            if ($mapping) {
                $info['space_visivel'] = trim((string)$mapping);
            }
        }
    } catch (Throwable $e) {
        error_log('eventosCancelamentoResolverEvento: ' . $e->getMessage());
    }

    if ($info['space_visivel'] === '' && $info['local_evento'] !== '') {
        $info['space_visivel'] = $info['local_evento'];
    }

    return $info;
}

function eventosCancelamentoBuscarGerentes(PDO $pdo, string $spaceVisivel): array
{
    $spaceNorm = eventosCancelamentoNormalizeText($spaceVisivel);
    if ($spaceNorm === '') {
        return [];
    }

    $usuarios = [];
    try {
        if (eventosCancelamentoTableExists($pdo, 'usuarios_spaces_visiveis')) {
            $stmt = $pdo->query("
                SELECT u.id, u.nome, u.email, u.cargo, usv.space_visivel
                FROM usuarios u
                INNER JOIN usuarios_spaces_visiveis usv ON usv.usuario_id = u.id
                WHERE COALESCE(u.ativo, TRUE) IS DISTINCT FROM FALSE
                  AND u.cargo IS NOT NULL
                  AND LOWER(u.cargo) LIKE '%gerente%'
                  AND LOWER(u.cargo) LIKE '%evento%'
                ORDER BY u.id ASC
            ");
            foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                if (eventosCancelamentoNormalizeText((string)($row['space_visivel'] ?? '')) === $spaceNorm) {
                    $usuarios[(int)$row['id']] = $row;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('eventosCancelamentoBuscarGerentes: ' . $e->getMessage());
    }

    return array_values($usuarios);
}

function eventosCancelamentoEmailHtml(array $info, string $mensagem): string
{
    $nome = htmlspecialchars((string)$info['nome_evento'], ENT_QUOTES, 'UTF-8');
    $data = htmlspecialchars(eventosCancelamentoFormatDate((string)$info['data_evento']), ENT_QUOTES, 'UTF-8');
    $local = htmlspecialchars((string)($info['local_evento'] ?: 'Local não informado'), ENT_QUOTES, 'UTF-8');
    $space = htmlspecialchars((string)($info['space_visivel'] ?: 'Unidade não identificada'), ENT_QUOTES, 'UTF-8');
    $mensagemHtml = nl2br(htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8'));

    return "
        <div style='font-family:Arial,sans-serif;line-height:1.6;color:#1f2937;max-width:680px;margin:0 auto;'>
            <div style='background:#991b1b;color:#fff;padding:18px 20px;border-radius:10px 10px 0 0;'>
                <h2 style='margin:0;font-size:20px;'>Evento cancelado na ME</h2>
            </div>
            <div style='border:1px solid #fecaca;border-top:none;padding:18px 20px;border-radius:0 0 10px 10px;background:#fff;'>
                <p style='margin:0 0 14px 0;'>{$mensagemHtml}</p>
                <table style='width:100%;border-collapse:collapse;font-size:14px;'>
                    <tr><td style='padding:6px 0;color:#64748b;'>Evento</td><td style='padding:6px 0;font-weight:700;'>{$nome}</td></tr>
                    <tr><td style='padding:6px 0;color:#64748b;'>Data</td><td style='padding:6px 0;'>{$data}</td></tr>
                    <tr><td style='padding:6px 0;color:#64748b;'>Local</td><td style='padding:6px 0;'>{$local}</td></tr>
                    <tr><td style='padding:6px 0;color:#64748b;'>Unidade</td><td style='padding:6px 0;'>{$space}</td></tr>
                </table>
            </div>
        </div>
    ";
}

function eventosCancelamentoRegistrar(PDO $pdo, int $meEventId, array $eventoData = []): array
{
    if ($meEventId <= 0) {
        return ['ok' => false, 'reason' => 'Evento inválido'];
    }

    eventosCancelamentoEnsureSchema($pdo);
    $info = eventosCancelamentoResolverEvento($pdo, $meEventId, $eventoData);
    $gerentes = eventosCancelamentoBuscarGerentes($pdo, (string)$info['space_visivel']);
    if (empty($gerentes)) {
        return ['ok' => true, 'destinatarios' => 0, 'space_visivel' => $info['space_visivel']];
    }

    require_once __DIR__ . '/core/notification_dispatcher.php';
    $dispatcher = new NotificationDispatcher($pdo);
    $dispatcher->ensureInternalSchema();

    $titulo = 'Evento cancelado na ME';
    $dataDb = eventosCancelamentoDbDate((string)$info['data_evento']);
    $mensagem = sprintf(
        'O evento "%s" de %s foi cancelado na ME. Unidade responsável: %s.',
        (string)$info['nome_evento'],
        eventosCancelamentoFormatDate((string)$info['data_evento']),
        (string)($info['space_visivel'] ?: 'não identificada')
    );
    $url = 'index.php?page=agenda_eventos';
    if (!empty($info['data_evento']) && strtotime((string)$info['data_evento'])) {
        $url .= '&mes=' . date('Y-m', strtotime((string)$info['data_evento']));
    }

    $created = 0;
    foreach ($gerentes as $gerente) {
        $usuarioId = (int)($gerente['id'] ?? 0);
        if ($usuarioId <= 0) {
            continue;
        }

        $stmt = $pdo->prepare("
            INSERT INTO eventos_cancelamento_alertas
                (me_event_id, usuario_id, nome_evento, data_evento, local_evento, space_visivel, mensagem, created_at)
            VALUES
                (:me_event_id, :usuario_id, :nome_evento, CAST(NULLIF(:data_evento, '') AS DATE), :local_evento, :space_visivel, :mensagem, NOW())
            ON CONFLICT (me_event_id, usuario_id) DO NOTHING
            RETURNING id
        ");
        $stmt->execute([
            ':me_event_id' => $meEventId,
            ':usuario_id' => $usuarioId,
            ':nome_evento' => (string)$info['nome_evento'],
            ':data_evento' => $dataDb,
            ':local_evento' => (string)$info['local_evento'],
            ':space_visivel' => (string)$info['space_visivel'],
            ':mensagem' => $mensagem,
        ]);
        $alertId = (int)($stmt->fetchColumn() ?: 0);
        if ($alertId <= 0) {
            continue;
        }

        $created++;
        $result = $dispatcher->dispatch(
            [[
                'id' => $usuarioId,
                'email' => (string)($gerente['email'] ?? ''),
            ]],
            [
                'tipo' => 'evento_cancelado',
                'referencia_id' => $meEventId,
                'titulo' => $titulo,
                'mensagem' => $mensagem,
                'url_destino' => $url,
                'email_assunto' => 'Evento cancelado - ' . (string)$info['nome_evento'],
                'email_html' => eventosCancelamentoEmailHtml($info, $mensagem),
            ],
            [
                'internal' => true,
                'push' => true,
                'email' => true,
            ]
        );

        if ((int)($result['enviados_email'] ?? 0) > 0) {
            $pdo->prepare("UPDATE eventos_cancelamento_alertas SET email_enviado_em = NOW() WHERE id = :id")
                ->execute([':id' => $alertId]);
        }
    }

    return ['ok' => true, 'destinatarios' => count($gerentes), 'novos_alertas' => $created, 'space_visivel' => $info['space_visivel']];
}

function eventosCancelamentoBuscarPopupsLogin(PDO $pdo, int $usuarioId, int $limit = 5): array
{
    if ($usuarioId <= 0) {
        return [];
    }

    eventosCancelamentoEnsureSchema($pdo);
    $limit = max(1, min(10, $limit));
    $stmt = $pdo->prepare("
        SELECT id, me_event_id, nome_evento, data_evento::text AS data_evento, local_evento, space_visivel, mensagem, created_at::text AS created_at
        FROM eventos_cancelamento_alertas
        WHERE usuario_id = :usuario_id
          AND popup_visto_em IS NULL
        ORDER BY created_at DESC, id DESC
        LIMIT {$limit}
    ");
    $stmt->execute([':usuario_id' => $usuarioId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!empty($rows)) {
        $ids = array_map(static fn($row) => (int)$row['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $upd = $pdo->prepare("UPDATE eventos_cancelamento_alertas SET popup_visto_em = NOW() WHERE id IN ({$placeholders})");
        $upd->execute($ids);
    }

    foreach ($rows as &$row) {
        $row['data_evento_formatada'] = eventosCancelamentoFormatDate((string)($row['data_evento'] ?? ''));
    }
    unset($row);

    return $rows;
}
