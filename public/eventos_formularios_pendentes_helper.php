<?php
/**
 * Regra de notificacao para formularios de cliente em aberto antes do evento.
 */

require_once __DIR__ . '/eventos_reuniao_helper.php';
require_once __DIR__ . '/eventos_notificacoes_central_helper.php';
require_once __DIR__ . '/cliente_notificacoes_helper.php';

const EVENTOS_FORMULARIOS_PENDENTES_MODELO = 'evento_formularios_pendentes_4d';

function eventos_formularios_pendentes_event_date(?string $eventDate = null): string
{
    $eventDate = trim((string)$eventDate);
    if ($eventDate !== '') {
        $ts = strtotime($eventDate);
        if ($ts) {
            return date('Y-m-d', $ts);
        }
    }

    return (new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo')))
        ->modify('+4 days')
        ->format('Y-m-d');
}

function eventos_formularios_pendentes_snapshot(array $row): array
{
    $snapshot = $row['me_event_snapshot'] ?? [];
    if (is_string($snapshot)) {
        $decoded = json_decode($snapshot, true);
        $snapshot = is_array($decoded) ? $decoded : [];
    }
    return is_array($snapshot) ? $snapshot : [];
}

function eventos_formularios_pendentes_pick(array $source, array $paths, string $fallback = ''): string
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
    return $fallback;
}

function eventos_formularios_pendentes_schema_has_fields($schema): bool
{
    if (is_string($schema)) {
        $schema = json_decode($schema, true);
    }
    if (!is_array($schema)) {
        return false;
    }

    $fields = $schema['fields'] ?? $schema;
    if (!is_array($fields)) {
        return false;
    }

    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }
        $type = strtolower(trim((string)($field['type'] ?? '')));
        if (in_array($type, ['divider', 'section', 'note', 'html'], true)) {
            continue;
        }
        if (trim((string)($field['id'] ?? $field['label'] ?? $field['name'] ?? '')) !== '') {
            return true;
        }
    }

    return false;
}

function eventos_formularios_pendentes_label(array $link): string
{
    $title = trim((string)($link['form_title'] ?? ''));
    if ($title !== '') {
        return $title;
    }

    $type = strtolower(trim((string)($link['link_type'] ?? '')));
    if ($type === 'cliente_dj') {
        return 'DJ';
    }

    $slot = max(1, (int)($link['slot_index'] ?? 1));
    return 'Organizacao ' . $slot;
}

function eventos_formularios_pendentes_buscar_eventos(PDO $pdo, string $eventDate, int $limit = 200): array
{
    eventos_reuniao_ensure_schema($pdo);
    $limit = max(1, min(500, $limit));

    $stmt = $pdo->prepare("
        SELECT
            r.id,
            r.me_event_id,
            r.me_event_snapshot,
            p.id AS portal_id,
            p.token AS portal_token
        FROM eventos_reunioes r
        JOIN eventos_cliente_portais p ON p.meeting_id = r.id
        WHERE COALESCE(p.is_active, TRUE) = TRUE
          AND NULLIF(SUBSTRING(COALESCE(r.me_event_snapshot->>'data', '') FROM 1 FOR 10), '') = :event_date
        ORDER BY r.id ASC
        LIMIT {$limit}
    ");
    $stmt->execute([':event_date' => $eventDate]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function eventos_formularios_pendentes_links(PDO $pdo, int $meetingId): array
{
    if ($meetingId <= 0) {
        return [];
    }

    eventos_reuniao_ensure_schema($pdo);
    $where = [
        'meeting_id = :meeting_id',
        "link_type IN ('cliente_dj', 'cliente_formulario')",
        'is_active = TRUE',
    ];

    if (eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'submitted_at')) {
        $where[] = 'submitted_at IS NULL';
    }
    if (eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'portal_visible')) {
        $where[] = 'COALESCE(portal_visible, TRUE) = TRUE';
    }
    if (eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'portal_configured')) {
        $where[] = 'COALESCE(portal_configured, TRUE) = TRUE';
    }

    $slotOrder = eventos_reuniao_has_column($pdo, 'eventos_links_publicos', 'slot_index')
        ? 'COALESCE(slot_index, 1) ASC, '
        : '';

    $stmt = $pdo->prepare("
        SELECT *
        FROM eventos_links_publicos
        WHERE " . implode(' AND ', $where) . "
        ORDER BY link_type ASC, {$slotOrder}id ASC
    ");
    $stmt->execute([':meeting_id' => $meetingId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $pendentes = [];
    foreach ($rows as $row) {
        if (!eventos_formularios_pendentes_schema_has_fields($row['form_schema_json'] ?? [])) {
            continue;
        }
        $row['label'] = eventos_formularios_pendentes_label($row);
        $pendentes[] = $row;
    }

    return $pendentes;
}

function eventos_formularios_pendentes_email_ja_enviado(PDO $pdo, int $meetingId): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM cliente_notificacao_envios
        WHERE chave_modelo = :chave
          AND meeting_id = :meeting_id
          AND status = 'enviado'
        LIMIT 1
    ");
    $stmt->execute([
        ':chave' => EVENTOS_FORMULARIOS_PENDENTES_MODELO,
        ':meeting_id' => $meetingId,
    ]);
    return (bool)$stmt->fetchColumn();
}

function eventos_formularios_pendentes_pre_contrato(PDO $pdo, int $meEventId): array
{
    if ($meEventId <= 0 || !eventos_reuniao_has_table($pdo, 'vendas_pre_contratos')) {
        return [];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT nome_completo, email
            FROM vendas_pre_contratos
            WHERE me_event_id = :me_event_id
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([':me_event_id' => $meEventId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('eventos_formularios_pendentes_pre_contrato: ' . $e->getMessage());
        return [];
    }
}

function eventos_formularios_pendentes_registrar_log(PDO $pdo, array $evento, string $nomeCliente, string $email, string $assunto, string $status = 'pendente', string $erro = ''): int
{
    $stmt = $pdo->prepare("
        INSERT INTO cliente_notificacao_envios
            (chave_modelo, me_event_id, meeting_id, portal_id, cliente_nome, cliente_email, canal, assunto, status, erro, enviado_em)
        VALUES
            (:chave_modelo, :me_event_id, :meeting_id, :portal_id, :cliente_nome, :cliente_email, 'email', :assunto, :status, :erro, CASE WHEN :enviado = TRUE THEN NOW() ELSE NULL END)
        RETURNING id
    ");
    $stmt->execute([
        ':chave_modelo' => EVENTOS_FORMULARIOS_PENDENTES_MODELO,
        ':me_event_id' => (int)($evento['me_event_id'] ?? 0) ?: null,
        ':meeting_id' => (int)($evento['id'] ?? 0) ?: null,
        ':portal_id' => (int)($evento['portal_id'] ?? 0) ?: null,
        ':cliente_nome' => $nomeCliente,
        ':cliente_email' => $email,
        ':assunto' => $assunto,
        ':status' => $status,
        ':erro' => $erro !== '' ? $erro : null,
        ':enviado' => $status === 'enviado' ? 1 : 0,
    ]);
    return (int)$stmt->fetchColumn();
}

function eventos_formularios_pendentes_render_email(array $evento, array $pendentes, string $nomeCliente, string $nomeEvento, string $dataEvento, string $portalUrl): string
{
    $nomeClienteEsc = htmlspecialchars($nomeCliente !== '' ? $nomeCliente : 'Cliente', ENT_QUOTES, 'UTF-8');
    $nomeEventoEsc = htmlspecialchars($nomeEvento !== '' ? $nomeEvento : 'Evento', ENT_QUOTES, 'UTF-8');
    $dataEventoEsc = htmlspecialchars(cliente_notificacoes_data_br($dataEvento), ENT_QUOTES, 'UTF-8');
    $portalUrlEsc = htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8');

    $items = '';
    foreach ($pendentes as $pendente) {
        $label = htmlspecialchars((string)($pendente['label'] ?? 'Formulario'), ENT_QUOTES, 'UTF-8');
        $items .= '<tr><td style="padding:12px 14px;border-bottom:1px solid #dbe3ef;">'
            . '<span style="display:inline-block;width:9px;height:9px;background:#1e3a8a;border-radius:50%;margin-right:9px;"></span>'
            . '<strong style="color:#0f172a;font-size:15px;">Formulario ' . $label . '</strong>'
            . '<div style="color:#64748b;font-size:13px;margin:4px 0 0 22px;">Ainda nao foi finalizado no Portal do Cliente.</div>'
            . '</td></tr>';
    }

    return '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>'
        . '<body style="margin:0;background:#eef3fb;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef3fb;padding:28px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:660px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #dbe3ef;box-shadow:0 10px 30px rgba(30,58,138,.08);">'
        . '<tr><td style="background:#1e3a8a;padding:30px;color:#ffffff;text-align:center;">'
        . '<div style="font-size:18px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;">Grupo Smile Eventos</div>'
        . '<h1 style="margin:16px 0 0;font-size:29px;line-height:1.2;">Ainda faltam informacoes no seu portal</h1>'
        . '<p style="margin:12px auto 0;max-width:500px;color:#dbeafe;font-size:16px;line-height:1.5;">Confira os formularios em aberto para seguirmos com a organizacao do evento.</p>'
        . '</td></tr>'
        . '<tr><td style="padding:28px 30px;">'
        . '<p style="margin:0 0 18px;color:#0f172a;font-size:18px;line-height:1.5;">Ola, <strong>' . $nomeClienteEsc . '</strong>.</p>'
        . '<div style="background:#f8fafc;border:1px solid #dbe3ef;border-radius:12px;padding:15px 16px;margin:0 0 22px;">'
        . '<div style="font-size:13px;color:#64748b;margin-bottom:4px;">Evento</div>'
        . '<div style="font-size:16px;color:#0f172a;font-weight:800;">' . $nomeEventoEsc . ($dataEventoEsc !== '' ? ' - ' . $dataEventoEsc : '') . '</div>'
        . '</div>'
        . '<p style="margin:0 0 16px;color:#27364f;font-size:16px;line-height:1.65;">Identificamos que ainda existe formulario em aberto no Portal do Cliente. Essas informacoes sao importantes para que nossa equipe finalize a organizacao com seguranca.</p>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #dbe3ef;border-radius:12px;overflow:hidden;margin:0 0 22px;background:#ffffff;">'
        . $items
        . '</table>'
        . '<p style="margin:0 0 22px;color:#27364f;font-size:16px;line-height:1.65;">Caso voce ja tenha enviado essas informacoes para nossa equipe por outro canal, pode desconsiderar este aviso.</p>'
        . '<div style="text-align:center;margin:26px 0 24px;">'
        . '<a href="' . $portalUrlEsc . '" style="display:inline-block;background:#1e3a8a;color:#ffffff;text-decoration:none;font-size:16px;font-weight:800;padding:15px 24px;border-radius:8px;">Acessar Portal do Cliente</a>'
        . '</div>'
        . '<p style="margin:0;color:#64748b;font-size:13px;line-height:1.5;">Se o botao nao abrir, copie este link no navegador:<br>'
        . '<a href="' . $portalUrlEsc . '" style="color:#1e3a8a;word-break:break-all;">' . $portalUrlEsc . '</a></p>'
        . '</td></tr>'
        . '<tr><td style="background:#f8fafc;padding:18px 30px;color:#64748b;font-size:12px;text-align:center;">Este e um e-mail automatico do Painel Smile Pro.</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function eventos_formularios_pendentes_processar(PDO $pdo, array $options = []): array
{
    cliente_notificacoes_ensure_schema($pdo);
    eventosNotificacoesCentralEnsureSchema($pdo);

    $dryRun = !empty($options['dry_run']);
    $eventDate = eventos_formularios_pendentes_event_date($options['event_date'] ?? null);
    $limit = (int)($options['limit'] ?? 200);

    $eventos = eventos_formularios_pendentes_buscar_eventos($pdo, $eventDate, $limit);
    $resultado = [
        'success' => true,
        'dry_run' => $dryRun,
        'event_date' => $eventDate,
        'eventos_consultados' => count($eventos),
        'eventos_com_pendencia' => 0,
        'notificacoes_internas' => 0,
        'emails_a_enviar' => 0,
        'emails_enviados' => 0,
        'emails_ignorados' => 0,
        'emails_com_erro' => 0,
        'pendentes' => [],
        'erros' => [],
    ];

    $emailHelper = null;
    if (!$dryRun) {
        cliente_notificacoes_require_email_helper();
        $emailHelper = new EmailGlobalHelper();
    }

    foreach ($eventos as $evento) {
        $meetingId = (int)($evento['id'] ?? 0);
        $pendentes = eventos_formularios_pendentes_links($pdo, $meetingId);
        if (empty($pendentes)) {
            continue;
        }

        $resultado['eventos_com_pendencia']++;
        $snapshot = eventos_formularios_pendentes_snapshot($evento);
        $nomeCliente = eventos_formularios_pendentes_pick($snapshot, ['cliente.nome', 'nomecliente', 'nomeCliente', 'cliente_nome'], 'Cliente');
        $email = eventos_formularios_pendentes_pick($snapshot, ['cliente.email', 'emailcliente', 'emailCliente', 'email', 'cliente_email']);
        $preContrato = eventos_formularios_pendentes_pre_contrato($pdo, (int)($evento['me_event_id'] ?? 0));
        if (($nomeCliente === '' || $nomeCliente === 'Cliente') && trim((string)($preContrato['nome_completo'] ?? '')) !== '') {
            $nomeCliente = trim((string)$preContrato['nome_completo']);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) && trim((string)($preContrato['email'] ?? '')) !== '') {
            $email = trim((string)$preContrato['email']);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) && (int)($evento['me_event_id'] ?? 0) > 0) {
            $email = cliente_notificacoes_me_evento_email_cliente_completo(
                $pdo,
                array_merge(['id' => (int)$evento['me_event_id']], $snapshot),
                $email,
                [],
                true
            );
        }
        $nomeEvento = eventos_formularios_pendentes_pick($snapshot, ['nome', 'tipo', 'tipoevento', 'tipoEvento'], 'Evento');
        $dataEvento = eventos_formularios_pendentes_pick($snapshot, ['data', 'dataevento', 'dataEvento'], $eventDate);
        $portalUrl = eventos_cliente_portal_build_url((string)($evento['portal_token'] ?? ''));

        foreach ($pendentes as $pendente) {
            $label = (string)($pendente['label'] ?? 'Formulario');
            $titulo = 'O formulario ' . $label . ' esta em aberto';
            if (!$dryRun) {
                eventosNotificacoesCentralCriar(
                    $pdo,
                    $meetingId,
                    'formulario_pendente_cliente',
                    $titulo,
                    'index.php?page=eventos_reuniao_final&id=' . $meetingId,
                    'formulario_pendente_cliente:' . $meetingId . ':' . (int)($pendente['id'] ?? 0),
                    'Pendencia identificada automaticamente no Portal do Cliente.'
                );
            }
            $resultado['notificacoes_internas']++;
        }

        $resultado['pendentes'][] = [
            'meeting_id' => $meetingId,
            'me_event_id' => (int)($evento['me_event_id'] ?? 0),
            'cliente' => $nomeCliente,
            'email' => $email,
            'formularios' => array_map(static fn($p) => (string)($p['label'] ?? 'Formulario'), $pendentes),
        ];

        if (eventos_formularios_pendentes_email_ja_enviado($pdo, $meetingId)) {
            $resultado['emails_ignorados']++;
            continue;
        }

        $assunto = 'Faltam informacoes no seu Portal do Cliente';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if (!$dryRun) {
                eventos_formularios_pendentes_registrar_log($pdo, $evento, $nomeCliente, $email, $assunto, 'erro', 'E-mail invalido ou ausente.');
            }
            $resultado['emails_com_erro']++;
            $resultado['erros'][] = ['meeting_id' => $meetingId, 'erro' => 'E-mail invalido ou ausente.'];
            continue;
        }

        if ($dryRun) {
            $resultado['emails_a_enviar']++;
            continue;
        }

        $logId = eventos_formularios_pendentes_registrar_log($pdo, $evento, $nomeCliente, $email, $assunto);
        $html = eventos_formularios_pendentes_render_email($evento, $pendentes, $nomeCliente, $nomeEvento, $dataEvento, $portalUrl);
        $enviado = $emailHelper instanceof EmailGlobalHelper
            ? $emailHelper->enviarEmail($email, $assunto, $html, true)
            : false;
        cliente_notificacoes_atualizar_log_envio(
            $pdo,
            $logId,
            (bool)$enviado,
            $enviado ? '' : 'Falha retornada pelo servico de e-mail.'
        );

        if ($enviado) {
            $resultado['emails_enviados']++;
        } else {
            $resultado['emails_com_erro']++;
            $resultado['erros'][] = ['meeting_id' => $meetingId, 'erro' => 'Falha retornada pelo servico de e-mail.'];
        }
    }

    return $resultado;
}
