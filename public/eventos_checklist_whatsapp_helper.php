<?php
/**
 * Lembrete único de WhatsApp para tarefas do cliente vencendo hoje.
 */

declare(strict_types=1);

require_once __DIR__ . '/eventos_checklist_planejamento_helper.php';
require_once __DIR__ . '/core/notification_dispatcher.php';

function eventos_checklist_whatsapp_processar(PDO $pdo, array $options = []): array
{
    eventos_checklist_planejamento_ensure_schema($pdo);
    $config = eventos_checklist_planejamento_config($pdo);
    $dryRun = !empty($options['dry_run']);
    $force = !empty($options['force']);
    $refDate = trim((string)($options['ref_date'] ?? date('Y-m-d')));
    $limit = max(1, min(500, (int)($options['limit'] ?? 200)));

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $refDate) !== 1) {
        return ['success' => false, 'error' => 'Data de referência inválida.'];
    }

    if (!$dryRun && (empty($config['portal_cliente_ativo']) || empty($config['whatsapp_cliente_ativo']))) {
        return [
            'success' => true,
            'enabled' => false,
            'message' => 'Portal ou WhatsApp do checklist está desativado.',
            'sent' => 0,
            'failed' => 0,
        ];
    }

    $hour = (int)date('G');
    if (!$force && $hour < 9) {
        return [
            'success' => true,
            'enabled' => true,
            'message' => 'Aguardando o horário de envio das 09:00.',
            'sent' => 0,
            'failed' => 0,
        ];
    }

    $stmt = $pdo->prepare("
        SELECT t.id, t.evento_id, t.titulo, t.whatsapp_mensagem,
               COALESCE(NULLIF(TRIM(e.nome_evento), ''), 'seu evento') AS evento_nome,
               COALESCE(NULLIF(TRIM(c.nome_completo), ''), 'Cliente') AS cliente_nome,
               COALESCE(NULLIF(TRIM(c.telefone_whatsapp), ''), NULLIF(TRIM(e.whatsapp_cliente), ''), NULLIF(TRIM(e.telefone_cliente), ''), '') AS cliente_whatsapp
        FROM eventos_checklist_tarefas t
        JOIN logistica_eventos_espelho e ON e.id = t.evento_id
        LEFT JOIN comercial_cadastro_clientes c ON c.id = e.cliente_cadastro_id
        WHERE t.responsabilidade = 'cliente'
          AND t.visivel_cliente = TRUE
          AND t.status IN ('pendente', 'em_andamento')
          AND t.vencimento = CAST(:ref_date AS DATE)
          AND t.whatsapp_tentado_em IS NULL
        ORDER BY t.id
        LIMIT :limite
    ");
    $stmt->bindValue(':ref_date', $refDate);
    $stmt->bindValue(':limite', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $tarefas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $dispatcher = new NotificationDispatcher($pdo);
    $sent = 0;
    $failed = 0;
    $skipped = 0;
    $items = [];
    $claim = $pdo->prepare("
        UPDATE eventos_checklist_tarefas
        SET whatsapp_tentado_em = NOW(), whatsapp_status = 'processando', updated_at = NOW()
        WHERE id = :id
          AND whatsapp_tentado_em IS NULL
          AND responsabilidade = 'cliente'
          AND status IN ('pendente', 'em_andamento')
          AND vencimento = CAST(:ref_date AS DATE)
        RETURNING id
    ");
    $finish = $pdo->prepare("
        UPDATE eventos_checklist_tarefas
        SET whatsapp_status = :status,
            whatsapp_destinatario = :destinatario,
            updated_at = NOW()
        WHERE id = :id
    ");

    foreach ($tarefas as $tarefa) {
        $mensagem = trim((string)$tarefa['whatsapp_mensagem']);
        if ($mensagem === '') {
            $mensagem = 'Olá, #NOME#. Você tem a tarefa "#TAREFA#" para realizar até hoje no evento "#EVENTO#".';
        }
        $mensagem = strtr($mensagem, [
            '#NOME#' => (string)$tarefa['cliente_nome'],
            '#TAREFA#' => (string)$tarefa['titulo'],
            '#EVENTO#' => (string)$tarefa['evento_nome'],
        ]);
        $telefone = trim((string)$tarefa['cliente_whatsapp']);

        if ($dryRun) {
            $items[] = ['id' => (int)$tarefa['id'], 'telefone' => $telefone, 'mensagem' => $mensagem, 'status' => 'dry_run'];
            continue;
        }

        $claim->execute([':id' => (int)$tarefa['id'], ':ref_date' => $refDate]);
        if ((int)$claim->fetchColumn() <= 0) {
            $skipped++;
            continue;
        }

        $ok = false;
        $status = 'falha';
        if ($telefone === '') {
            $status = 'sem_numero';
        } else {
            $ok = $dispatcher->sendWhatsappDirect(
                $telefone,
                $mensagem,
                (string)$tarefa['cliente_nome'],
                ['whatsapp_provider' => 'smclick']
            );
            $status = $ok ? 'enviado' : 'falha';
        }
        $finish->execute([':status' => $status, ':destinatario' => $telefone ?: null, ':id' => (int)$tarefa['id']]);
        eventos_checklist_planejamento_historico(
            $pdo,
            (int)$tarefa['id'],
            (int)$tarefa['evento_id'],
            'whatsapp_cliente_tentado',
            $ok ? 'Lembrete de vencimento enviado ao cliente.' : 'Tentativa única de lembrete ao cliente não enviada.',
            null,
            ['status' => $status, 'destinatario' => $telefone ?: null],
            0,
            'sistema'
        );

        if ($ok) {
            $sent++;
        } else {
            $failed++;
        }
        $items[] = ['id' => (int)$tarefa['id'], 'telefone' => $telefone, 'status' => $status];
    }

    return [
        'success' => true,
        'enabled' => true,
        'dry_run' => $dryRun,
        'ref_date' => $refDate,
        'found' => count($tarefas),
        'sent' => $sent,
        'failed' => $failed,
        'skipped' => $skipped,
        'items' => $items,
    ];
}
