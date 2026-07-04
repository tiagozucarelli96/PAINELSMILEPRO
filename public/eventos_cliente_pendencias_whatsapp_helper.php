<?php
/**
 * Notificacoes por WhatsApp para pendencias do Portal do Cliente.
 */

require_once __DIR__ . '/eventos_reuniao_helper.php';
require_once __DIR__ . '/logistica_cardapio_helper.php';
require_once __DIR__ . '/core/notification_dispatcher.php';

function eventos_cliente_pendencias_whatsapp_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eventos_cliente_pendencias_whatsapp_envios (
            id BIGSERIAL PRIMARY KEY,
            meeting_id BIGINT NOT NULL,
            me_event_id BIGINT NULL,
            tipo VARCHAR(60) NOT NULL,
            data_evento DATE NULL,
            cliente_nome VARCHAR(255) NULL,
            telefone VARCHAR(40) NULL,
            mensagem TEXT NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'pendente',
            erro TEXT NULL,
            enviado_em TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            UNIQUE (meeting_id, tipo)
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_cliente_pend_whats_status ON eventos_cliente_pendencias_whatsapp_envios(status, tipo, data_evento)");
}

function eventos_cliente_pendencias_whatsapp_data(?string $refDate = null): string
{
    $refDate = trim((string)$refDate);
    if ($refDate !== '') {
        $ts = strtotime($refDate);
        if ($ts) {
            return date('Y-m-d', $ts);
        }
    }

    return (new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
}

function eventos_cliente_pendencias_whatsapp_eventos(PDO $pdo, string $eventDate, int $limit): array
{
    $limit = max(1, min(500, $limit));

    $stmt = $pdo->prepare("
        SELECT
            r.id,
            r.me_event_id,
            r.me_event_snapshot,
            p.id AS portal_id,
            p.token AS portal_token,
            COALESCE(p.is_active, TRUE) AS portal_active,
            COALESCE(p.visivel_convidados, FALSE) AS visivel_convidados,
            COALESCE(p.editavel_convidados, FALSE) AS editavel_convidados,
            COALESCE(p.visivel_cardapio, FALSE) AS visivel_cardapio,
            COALESCE(p.editavel_cardapio, FALSE) AS editavel_cardapio
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

function eventos_cliente_pendencias_whatsapp_listar_links(PDO $pdo, int $meetingId, string $linkType): array
{
    $stmt = $pdo->prepare("
        SELECT
            id,
            is_active,
            portal_visible,
            portal_editable,
            portal_configured,
            form_schema_json,
            submitted_at,
            draft_saved_at
        FROM eventos_links_publicos
        WHERE meeting_id = :meeting_id
          AND link_type = :link_type
          AND is_active = TRUE
        ORDER BY COALESCE(slot_index, 1) ASC, id DESC
    ");
    $stmt->execute([
        ':meeting_id' => $meetingId,
        ':link_type' => $linkType,
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $decoded = json_decode((string)($row['form_schema_json'] ?? '[]'), true);
        $row['form_schema'] = is_array($decoded) ? $decoded : [];
        $row['portal_visible'] = !empty($row['portal_visible']);
        $row['portal_editable'] = !empty($row['portal_editable']);
        $row['submitted_at'] = array_key_exists('submitted_at', $row) && $row['submitted_at'] !== null ? (string)$row['submitted_at'] : null;
    }
    unset($row);

    return $rows;
}

function eventos_cliente_pendencias_whatsapp_snapshot(array $evento): array
{
    $raw = $evento['me_event_snapshot'] ?? [];
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
    return is_array($raw) ? $raw : [];
}

function eventos_cliente_pendencias_whatsapp_tem_form_fields($schema): bool
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

function eventos_cliente_pendencias_whatsapp_formulario_pendente(PDO $pdo, int $meetingId): bool
{
    foreach (['cliente_dj', 'cliente_formulario'] as $linkType) {
        foreach (eventos_cliente_pendencias_whatsapp_listar_links($pdo, $meetingId, $linkType) as $link) {
            if (empty($link['is_active']) || empty($link['portal_visible']) || empty($link['portal_editable'])) {
                continue;
            }
            if (!eventos_cliente_pendencias_whatsapp_tem_form_fields($link['form_schema'] ?? null)) {
                continue;
            }
            if (empty($link['submitted_at'])) {
                return true;
            }
        }
    }

    return false;
}

function eventos_cliente_pendencias_whatsapp_cardapio_resumo(PDO $pdo, int $meetingId): array
{
    $emptySummary = [
        'has_pacote' => false,
        'secoes_total' => 0,
        'itens_total' => 0,
        'submitted_at' => '',
    ];

    $stmt = $pdo->prepare("
        SELECT pacote_evento_id
        FROM eventos_reunioes
        WHERE id = :meeting_id
        LIMIT 1
    ");
    $stmt->execute([':meeting_id' => $meetingId]);
    $pacoteId = (int)($stmt->fetchColumn() ?: 0);
    if ($pacoteId <= 0) {
        return $emptySummary;
    }

    $summary = $emptySummary;
    $summary['has_pacote'] = true;

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

    $stmt = $pdo->prepare("
        SELECT submitted_at
        FROM eventos_cardapio_respostas
        WHERE meeting_id = :meeting_id
        LIMIT 1
    ");
    $stmt->execute([':meeting_id' => $meetingId]);
    $submittedAt = $stmt->fetchColumn();
    $summary['submitted_at'] = is_string($submittedAt) ? $submittedAt : '';

    return $summary;
}

function eventos_cliente_pendencias_whatsapp_cardapio_pendente(PDO $pdo, array $evento): bool
{
    if (empty($evento['visivel_cardapio']) || empty($evento['editavel_cardapio'])) {
        return false;
    }

    $data = eventos_cliente_pendencias_whatsapp_cardapio_resumo($pdo, (int)$evento['id']);
    if (empty($data['has_pacote']) || (int)($data['secoes_total'] ?? 0) <= 0 || (int)($data['itens_total'] ?? 0) <= 0) {
        return false;
    }

    return trim((string)($data['submitted_at'] ?? '')) === '';
}

function eventos_cliente_pendencias_whatsapp_convidados_resumo(PDO $pdo, int $meetingId): array
{
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*)::int AS total,
            COALESCE(SUM(CASE WHEN COALESCE(is_draft, FALSE) THEN 1 ELSE 0 END), 0)::int AS rascunho
        FROM eventos_convidados
        WHERE meeting_id = :meeting_id
          AND deleted_at IS NULL
    ");
    $stmt->execute([':meeting_id' => $meetingId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'rascunho' => 0];
    $total = (int)($row['total'] ?? 0);
    $rascunho = (int)($row['rascunho'] ?? 0);

    return [
        'total' => $total,
        'rascunho' => $rascunho,
        'publicados' => max(0, $total - $rascunho),
    ];
}

function eventos_cliente_pendencias_whatsapp_convidados_pendente(PDO $pdo, array $evento): bool
{
    if (empty($evento['visivel_convidados']) || empty($evento['editavel_convidados'])) {
        return false;
    }

    $resumo = eventos_cliente_pendencias_whatsapp_convidados_resumo($pdo, (int)$evento['id']);
    return (int)($resumo['publicados'] ?? 0) <= 0;
}

function eventos_cliente_pendencias_whatsapp_link(array $evento, string $page): string
{
    $token = trim((string)($evento['portal_token'] ?? ''));
    if ($token === '') {
        return '';
    }
    return eventos_cliente_portal_base_url() . '/index.php?page=' . $page . '&token=' . urlencode($token);
}

function eventos_cliente_pendencias_whatsapp_contato(PDO $pdo, int $meetingId): array
{
    return logistica_cardapio_cliente_contato($pdo, $meetingId);
}

function eventos_cliente_pendencias_whatsapp_mensagem(string $tipo, string $nomeCliente, string $link): string
{
    $nome = trim($nomeCliente) !== '' ? trim($nomeCliente) : 'Cliente';

    if ($tipo === 'cardapio_nao_finalizado') {
        return "Olá, {$nome}, tudo bem?\n\n"
            . "Passando para lembrar que o cardápio do seu evento ainda não foi finalizado no Portal do Cliente.\n\n"
            . "Essa etapa é importante para que nossa equipe consiga organizar tudo com antecedência e garantir que as escolhas estejam alinhadas para o grande dia.\n\n"
            . "Você pode acessar e finalizar por aqui:\n{$link}\n\n"
            . "Pedimos, por favor, que conclua o envio assim que possível.\n\n"
            . "Caso você já tenha combinado essas informações diretamente com nossa equipe, pode desconsiderar esta mensagem.";
    }

    if ($tipo === 'formulario_nao_respondido') {
        return "Olá, {$nome}, tudo bem?\n\n"
            . "Identificamos que ainda existe formulário pendente no Portal do Cliente referente ao seu evento.\n\n"
            . "Essas informações ajudam nossa equipe a organizar os detalhes com mais segurança, como DJ, protocolos, preferências e demais pontos importantes da celebração.\n\n"
            . "Você pode preencher por aqui:\n{$link}\n\n"
            . "Pedimos, por favor, que envie as informações assim que possível.\n\n"
            . "Caso você já tenha preenchido ou enviado essas informações por outro canal, pode desconsiderar esta mensagem.";
    }

    return "Olá, {$nome}, tudo bem?\n\n"
        . "Estamos nos aproximando do seu evento e vimos que a lista de convidados ainda não foi preenchida no Portal do Cliente.\n\n"
        . "Essa lista é importante para organização da recepção, controle interno e conferência das informações no dia do evento.\n\n"
        . "Você pode preencher por aqui:\n{$link}\n\n"
        . "Pedimos, por favor, que finalize o quanto antes para que nossa equipe consiga se organizar corretamente.\n\n"
        . "Caso a lista já tenha sido enviada diretamente para nossa equipe, pode desconsiderar esta mensagem.";
}

function eventos_cliente_pendencias_whatsapp_ja_enviado(PDO $pdo, int $meetingId, string $tipo): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM eventos_cliente_pendencias_whatsapp_envios
        WHERE meeting_id = :meeting_id
          AND tipo = :tipo
          AND status = 'enviada'
        LIMIT 1
    ");
    $stmt->execute([':meeting_id' => $meetingId, ':tipo' => $tipo]);
    return (bool)$stmt->fetchColumn();
}

function eventos_cliente_pendencias_whatsapp_registrar(PDO $pdo, array $evento, string $tipo, string $nome, string $telefone, string $mensagem, string $status, string $erro = ''): void
{
    $eventDate = substr((string)(eventos_cliente_pendencias_whatsapp_snapshot($evento)['data'] ?? ''), 0, 10);
    $stmt = $pdo->prepare("
        INSERT INTO eventos_cliente_pendencias_whatsapp_envios
            (meeting_id, me_event_id, tipo, data_evento, cliente_nome, telefone, mensagem, status, erro, enviado_em, created_at)
        VALUES
            (:meeting_id, :me_event_id, :tipo, NULLIF(:data_evento, '')::date, :cliente_nome, :telefone, :mensagem, :status, :erro, CASE WHEN :status = 'enviada' THEN NOW() ELSE NULL END, NOW())
        ON CONFLICT (meeting_id, tipo) DO UPDATE SET
            cliente_nome = EXCLUDED.cliente_nome,
            telefone = EXCLUDED.telefone,
            mensagem = EXCLUDED.mensagem,
            status = EXCLUDED.status,
            erro = EXCLUDED.erro,
            enviado_em = CASE WHEN EXCLUDED.status = 'enviada' THEN NOW() ELSE eventos_cliente_pendencias_whatsapp_envios.enviado_em END
    ");
    $stmt->execute([
        ':meeting_id' => (int)$evento['id'],
        ':me_event_id' => (int)($evento['me_event_id'] ?? 0) ?: null,
        ':tipo' => $tipo,
        ':data_evento' => $eventDate,
        ':cliente_nome' => $nome,
        ':telefone' => $telefone,
        ':mensagem' => $mensagem,
        ':status' => $status,
        ':erro' => $erro !== '' ? $erro : null,
    ]);
}

function eventos_cliente_pendencias_whatsapp_regras(): array
{
    return [
        'cardapio_nao_finalizado' => [
            'dias' => 16,
            'page' => 'eventos_cliente_cardapio',
            'checker' => 'eventos_cliente_pendencias_whatsapp_cardapio_pendente',
        ],
        'formulario_nao_respondido' => [
            'dias' => 13,
            'page' => 'eventos_cliente_portal',
            'checker' => 'eventos_cliente_pendencias_whatsapp_formulario_pendente',
        ],
        'lista_convidados_nao_preenchida' => [
            'dias' => 5,
            'page' => 'eventos_cliente_convidados',
            'checker' => 'eventos_cliente_pendencias_whatsapp_convidados_pendente',
        ],
    ];
}

function eventos_cliente_pendencias_whatsapp_datas_excluidas($dates): array
{
    if (is_string($dates)) {
        $dates = preg_split('/[,\s]+/', $dates, -1, PREG_SPLIT_NO_EMPTY);
    }
    if (!is_array($dates)) {
        return [];
    }

    $normalized = [];
    foreach ($dates as $date) {
        $date = trim((string)$date);
        if ($date === '') {
            continue;
        }
        $ts = strtotime($date);
        if ($ts) {
            $normalized[date('Y-m-d', $ts)] = true;
        }
    }

    return $normalized;
}

function eventos_cliente_pendencias_whatsapp_processar(PDO $pdo, array $options = []): array
{
    eventos_cliente_pendencias_whatsapp_ensure_schema($pdo);

    $dryRun = !empty($options['dry_run']);
    $refDate = eventos_cliente_pendencias_whatsapp_data($options['ref_date'] ?? null);
    $limit = (int)($options['limit'] ?? 200);
    $rules = eventos_cliente_pendencias_whatsapp_regras();
    $excludedDates = eventos_cliente_pendencias_whatsapp_datas_excluidas($options['exclude_event_dates'] ?? []);
    $dispatcher = $dryRun ? null : new NotificationDispatcher($pdo);

    $resultado = [
        'success' => true,
        'dry_run' => $dryRun,
        'ref_date' => $refDate,
        'consultados' => 0,
        'elegiveis' => 0,
        'enviados' => 0,
        'ignorados' => 0,
        'falhas' => 0,
        'sem_telefone' => 0,
        'datas_excluidas' => array_keys($excludedDates),
        'detalhes' => [],
    ];

    foreach ($rules as $tipo => $rule) {
        $eventDate = (new DateTimeImmutable($refDate))->modify('+' . (int)$rule['dias'] . ' days')->format('Y-m-d');
        if (isset($excludedDates[$eventDate])) {
            $resultado['ignorados']++;
            $resultado['detalhes'][] = [
                'tipo' => $tipo,
                'data_evento' => $eventDate,
                'status' => 'data_excluida',
            ];
            continue;
        }

        $eventos = eventos_cliente_pendencias_whatsapp_eventos($pdo, $eventDate, $limit);
        $resultado['consultados'] += count($eventos);

        foreach ($eventos as $evento) {
            $meetingId = (int)$evento['id'];
            if (eventos_cliente_pendencias_whatsapp_ja_enviado($pdo, $meetingId, $tipo)) {
                $resultado['ignorados']++;
                continue;
            }

            $checker = (string)$rule['checker'];
            $pendente = $tipo === 'formulario_nao_respondido'
                ? $checker($pdo, $meetingId)
                : $checker($pdo, $evento);
            if (!$pendente) {
                continue;
            }

            $resultado['elegiveis']++;
            $contato = eventos_cliente_pendencias_whatsapp_contato($pdo, $meetingId);
            $nome = trim((string)($contato['nome'] ?? 'Cliente')) ?: 'Cliente';
            $telefone = trim((string)($contato['telefone'] ?? ''));
            $link = eventos_cliente_pendencias_whatsapp_link($evento, (string)$rule['page']);
            $mensagem = eventos_cliente_pendencias_whatsapp_mensagem($tipo, $nome, $link);

            $detalhe = [
                'tipo' => $tipo,
                'meeting_id' => $meetingId,
                'me_event_id' => (int)($evento['me_event_id'] ?? 0),
                'cliente' => $nome,
                'telefone' => $telefone,
            ];

            if ($telefone === '') {
                $resultado['sem_telefone']++;
                $resultado['falhas']++;
                $detalhe['status'] = 'sem_telefone';
                if (!$dryRun) {
                    eventos_cliente_pendencias_whatsapp_registrar($pdo, $evento, $tipo, $nome, '', $mensagem, 'erro', 'Telefone do cliente não encontrado.');
                }
                $resultado['detalhes'][] = $detalhe;
                continue;
            }

            if ($link === '') {
                $resultado['falhas']++;
                $detalhe['status'] = 'sem_link_portal';
                if (!$dryRun) {
                    eventos_cliente_pendencias_whatsapp_registrar($pdo, $evento, $tipo, $nome, $telefone, $mensagem, 'erro', 'Link do portal não encontrado.');
                }
                $resultado['detalhes'][] = $detalhe;
                continue;
            }

            if ($dryRun) {
                $detalhe['status'] = 'dry_run';
                $resultado['detalhes'][] = $detalhe;
                continue;
            }

            $ok = $dispatcher instanceof NotificationDispatcher
                ? $dispatcher->sendWhatsappDirect($telefone, $mensagem, $nome, ['whatsapp_provider' => 'smclick'])
                : false;
            if ($ok) {
                eventos_cliente_pendencias_whatsapp_registrar($pdo, $evento, $tipo, $nome, $telefone, $mensagem, 'enviada');
                $resultado['enviados']++;
                $detalhe['status'] = 'enviada';
            } else {
                eventos_cliente_pendencias_whatsapp_registrar($pdo, $evento, $tipo, $nome, $telefone, $mensagem, 'erro', 'Falha ao enviar WhatsApp pela SMClick.');
                $resultado['falhas']++;
                $detalhe['status'] = 'erro';
            }
            $resultado['detalhes'][] = $detalhe;
        }
    }

    return $resultado;
}
