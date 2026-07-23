<?php
/**
 * cron.php - Endpoint único para todos os crons
 * Acesse: /cron.php?tipo=demandas_fixas&token=SEU_TOKEN
 * 
 * Todas as execuções são registradas na tabela sistema_cron_execucoes
 * para diagnóstico e monitoramento.
 */

// Garantir que não há output buffer interferindo
if (ob_get_level()) {
    ob_end_clean();
}

// Configurar timezone para Brasília
date_default_timezone_set('America/Sao_Paulo');

// Verificar token ANTES de qualquer coisa
$cron_token = getenv('CRON_TOKEN') ?: '';
$request_token = $_GET['token'] ?? '';

// Se token estiver configurado e não corresponder, retornar erro imediatamente
if (!empty($cron_token) && $request_token !== $cron_token) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Token inválido']);
    exit;
}

// Headers JSON
header('Content-Type: application/json; charset=utf-8');

// Determinar qual cron executar
$tipo = $_GET['tipo'] ?? '';

// Carregar logger de cron
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/cron_logger.php';

$pdo = $GLOBALS['pdo'];
$inicio_ms = (int)(microtime(true) * 1000);
$execucao_id = 0;

// Registrar início da execução (se tipo válido)
if (!empty($tipo)) {
    $execucao_id = cron_logger_start($pdo, $tipo);
}

if ($tipo === 'google_calendar_daily') {
    // Sincronização diária do Google Calendar
    try {
        require_once __DIR__ . '/core/helpers.php';
        
        error_log("[GOOGLE_CRON_DAILY] 🔄 Iniciando sincronização diária");
        
        // Marcar todos os calendários ativos como "precisa sincronizar"
        $stmt = $pdo->exec("
            UPDATE google_calendar_config
            SET precisa_sincronizar = TRUE,
                atualizado_em = NOW()
            WHERE ativo = TRUE
        ");
        
        $rows_updated = $stmt;
        error_log("[GOOGLE_CRON_DAILY] 📋 Marcados $rows_updated calendário(s) para sincronização");
        
        // Executar o processador (que já tem lock)
        require_once __DIR__ . '/google_calendar_sync_processor.php';
        $processor_result = google_calendar_sync_processor_run($pdo);
        
        error_log("[GOOGLE_CRON_DAILY] ✅ Sincronização diária concluída");
        
        $resultado = [
            'success' => !empty($processor_result['success']),
            'message' => 'Sincronização diária do Google Calendar iniciada',
            'calendarios_marcados' => $rows_updated,
            'processor' => $processor_result
        ];
        
        cron_logger_finish($pdo, $execucao_id, true, $resultado, $inicio_ms);
        echo json_encode($resultado);
        
    } catch (Exception $e) {
        error_log("[GOOGLE_CRON_DAILY] ❌ Erro: " . $e->getMessage());
        $resultado = ['success' => false, 'error' => $e->getMessage()];
        cron_logger_finish($pdo, $execucao_id, false, $resultado, $inicio_ms);
        http_response_code(500);
        echo json_encode($resultado);
    }
    exit;
}

if ($tipo === 'google_calendar_sync') {
    // Processar sincronizações pendentes (precisa_sincronizar = TRUE)
    // Recomendado rodar a cada 5-10 minutos para aplicar eventos marcados pelo webhook.
    try {
        require_once __DIR__ . '/conexao.php';
        require_once __DIR__ . '/core/helpers.php';
        require_once __DIR__ . '/google_calendar_sync_processor.php';
        $processor_result = google_calendar_sync_processor_run($pdo);

        echo json_encode([
            'success' => !empty($processor_result['success']),
            'message' => 'Processador executado',
            'processor' => $processor_result
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

if ($tipo === 'google_calendar_renewal') {
    // Renovação de webhooks do Google Calendar
    try {
        require_once __DIR__ . '/core/helpers.php';
        require_once __DIR__ . '/core/google_calendar_helper.php';
        
        $helper = new GoogleCalendarHelper();
        
        // Janela de renovação: webhooks que expiram nas próximas 6 horas
        $now_unix = time();
        $threshold_unix = $now_unix + (6 * 60 * 60);
        
        error_log("[GOOGLE_WATCH_RENEWAL] Verificando webhooks próximos de expirar");
        
        // Buscar candidatos ativos e filtrar em PHP para suportar coluna
        // webhook_expiration em timestamp ou em milissegundos.
        $stmt = $pdo->query("
            SELECT id, google_calendar_id, google_calendar_name, webhook_channel_id, webhook_resource_id, webhook_expiration
            FROM google_calendar_config
            WHERE ativo = TRUE 
            AND webhook_resource_id IS NOT NULL
            AND webhook_expiration IS NOT NULL
            ORDER BY atualizado_em ASC
        ");
        $candidatos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $webhooks = [];
        foreach ($candidatos as $webhook) {
            $expira_unix = GoogleCalendarHelper::parseExpirationToUnix($webhook['webhook_expiration'] ?? null);
            if ($expira_unix <= 0) {
                continue;
            }
            if ($expira_unix > $now_unix && $expira_unix <= $threshold_unix) {
                $webhook['_expiration_unix'] = $expira_unix;
                $webhooks[] = $webhook;
            }
        }
        
        if (empty($webhooks)) {
            error_log("[GOOGLE_WATCH_RENEWAL] ✅ Nenhum webhook precisa ser renovado");
            $resultado = [
                'success' => true,
                'message' => 'Nenhum webhook precisa ser renovado',
                'webhooks_renovados' => 0
            ];
            cron_logger_finish($pdo, $execucao_id, true, $resultado, $inicio_ms);
            echo json_encode($resultado);
            exit;
        }
        
        error_log("[GOOGLE_WATCH_RENEWAL] 📋 Encontrados " . count($webhooks) . " webhook(s) para renovar");
        
        $webhook_url = getenv('GOOGLE_WEBHOOK_URL') ?: ($_ENV['GOOGLE_WEBHOOK_URL'] ?? 'https://painelsmilepro-production.up.railway.app/google_calendar_webhook.php');
        $webhook_url = GoogleCalendarHelper::normalizeWebhookUrl($webhook_url);
        $renovados = 0;
        $erros = [];
        
        foreach ($webhooks as $webhook) {
            $calendar_id = $webhook['google_calendar_id'];
            $expiration_date = date('Y-m-d H:i:s', (int)($webhook['_expiration_unix'] ?? 0));
            
            error_log("[GOOGLE_WATCH_RENEWAL] 🔄 Renovando webhook para: $calendar_id (expira em: $expiration_date)");
            
            try {
                if ($webhook['webhook_resource_id']) {
                    try {
                        $helper->stopWebhook($webhook['webhook_resource_id'], $webhook['webhook_channel_id'] ?? null);
                        error_log("[GOOGLE_WATCH_RENEWAL] ✅ Webhook antigo parado");
                    } catch (Exception $e) {
                        error_log("[GOOGLE_WATCH_RENEWAL] ⚠️ Erro ao parar webhook antigo (continuando): " . $e->getMessage());
                    }
                }
                
                $res = $helper->registerWebhook($calendar_id, $webhook_url);
                error_log("[GOOGLE_WATCH_RENEWAL] ✅ Webhook renovado para: $calendar_id");
                $renovados++;
                
            } catch (Exception $e) {
                error_log("[GOOGLE_WATCH_RENEWAL] ❌ Erro ao renovar webhook para $calendar_id: " . $e->getMessage());
                $erros[] = ['calendar_id' => $calendar_id, 'erro' => $e->getMessage()];
            }
        }
        
        error_log("[GOOGLE_WATCH_RENEWAL] ✅ Processamento de renovação concluído");
        
        $resultado = [
            'success' => true,
            'message' => 'Renovação de webhooks do Google Calendar concluída',
            'webhooks_renovados' => $renovados,
            'total_encontrados' => count($webhooks),
            'erros' => $erros
        ];
        
        cron_logger_finish($pdo, $execucao_id, true, $resultado, $inicio_ms);
        echo json_encode($resultado);
        
    } catch (Exception $e) {
        error_log("[GOOGLE_WATCH_RENEWAL] ❌ Erro fatal: " . $e->getMessage());
        $resultado = ['success' => false, 'error' => $e->getMessage()];
        cron_logger_finish($pdo, $execucao_id, false, $resultado, $inicio_ms);
        http_response_code(500);
        echo json_encode($resultado);
    }
    exit;
}

if ($tipo === 'demandas_fixas') {
    try {
        $hoje = new DateTime();
        $dia_semana = (int)$hoje->format('w'); // 0=domingo, 6=sábado
        $dia_mes = (int)$hoje->format('j');
        
        // Buscar demandas fixas ativas
        $stmt = $pdo->query("
            SELECT df.*, db.nome as board_nome, dl.nome as lista_nome
            FROM demandas_fixas df
            JOIN demandas_boards db ON db.id = df.board_id
            JOIN demandas_listas dl ON dl.id = df.lista_id
            WHERE df.ativo = TRUE
        ");
        $fixas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $gerados = 0;
        $erros = [];
        
        foreach ($fixas as $fixa) {
            // Verificar se deve gerar hoje
            $deve_gerar = false;
            
            switch ($fixa['periodicidade']) {
                case 'diaria':
                    $deve_gerar = true;
                    break;
                case 'semanal':
                    if ($fixa['dia_semana'] === $dia_semana) {
                        $deve_gerar = true;
                    }
                    break;
                case 'mensal':
                    if ($fixa['dia_mes'] === $dia_mes) {
                        $deve_gerar = true;
                    }
                    break;
            }
            
            if (!$deve_gerar) continue;
            
            // Verificar se já foi gerado hoje
            $stmt_check = $pdo->prepare("
                SELECT id FROM demandas_fixas_log 
                WHERE demanda_fixa_id = :fixa_id 
                AND dia_gerado = CURRENT_DATE
            ");
            $stmt_check->execute([':fixa_id' => $fixa['id']]);
            
            if ($stmt_check->fetch()) {
                continue; // Já foi gerado hoje
            }
            
            // Buscar posição máxima na lista
            $stmt_pos = $pdo->prepare("
                SELECT COALESCE(MAX(posicao), 0) + 1 as nova_pos 
                FROM demandas_cards 
                WHERE lista_id = :lista_id
            ");
            $stmt_pos->execute([':lista_id' => $fixa['lista_id']]);
            $posicao = (int)$stmt_pos->fetch(PDO::FETCH_ASSOC)['nova_pos'];
            
            // Criar card
            try {
                $pdo->beginTransaction();
                
                $stmt_card = $pdo->prepare("
                    INSERT INTO demandas_cards 
                    (lista_id, titulo, descricao, status, prioridade, posicao, criador_id)
                    VALUES (:lista_id, :titulo, :descricao, 'pendente', 'media', :posicao, 1)
                    RETURNING id
                ");
                $stmt_card->execute([
                    ':lista_id' => $fixa['lista_id'],
                    ':titulo' => $fixa['titulo'],
                    ':descricao' => $fixa['descricao'],
                    ':posicao' => $posicao
                ]);
                
                $card = $stmt_card->fetch(PDO::FETCH_ASSOC);
                $card_id = (int)$card['id'];
                
                // Registrar no log
                $stmt_log = $pdo->prepare("
                    INSERT INTO demandas_fixas_log 
                    (demanda_fixa_id, card_id, dia_gerado)
                    VALUES (:fixa_id, :card_id, CURRENT_DATE)
                ");
                $stmt_log->execute([
                    ':fixa_id' => $fixa['id'],
                    ':card_id' => $card_id
                ]);
                
                $pdo->commit();
                $gerados++;
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                $erros[] = [
                    'fixa_id' => $fixa['id'],
                    'titulo' => $fixa['titulo'],
                    'erro' => $e->getMessage()
                ];
            }
        }
        
        $resultado = [
            'success' => true,
            'gerados' => $gerados,
            'total_fixas' => count($fixas),
            'erros' => $erros
        ];
        
        cron_logger_finish($pdo, $execucao_id, true, $resultado, $inicio_ms);
        echo json_encode($resultado);
        
    } catch (Exception $e) {
        $resultado = ['success' => false, 'error' => $e->getMessage()];
        cron_logger_finish($pdo, $execucao_id, false, $resultado, $inicio_ms);
        http_response_code(500);
        echo json_encode($resultado);
    }
    
} elseif ($tipo === 'notificacoes') {
    // Cron de notificações
    try {
        require_once __DIR__ . '/core/notificacoes_helper.php';
        $notificacoes = new NotificacoesHelper();
        $enviado = $notificacoes->enviarNotificacoesConsolidadas();

        $resultado = [
            'success' => true,
            'enviado' => (bool)$enviado,
            'message' => $enviado ? 'Notificações enviadas' : 'Nenhuma notificação pendente'
        ];

        cron_logger_finish($pdo, $execucao_id, true, $resultado, $inicio_ms);
        echo json_encode($resultado);

    } catch (Exception $e) {
        $resultado = ['success' => false, 'error' => $e->getMessage()];
        cron_logger_finish($pdo, $execucao_id, false, $resultado, $inicio_ms);
        http_response_code(500);
        echo json_encode($resultado);
    }

} elseif ($tipo === 'agenda_visitas_whatsapp') {
    // Cron para confirmação de visitas por WhatsApp às 8h do dia da visita.
    try {
        require_once __DIR__ . '/agenda_helper.php';
        $agenda = new AgendaHelper();
        $resultado = $agenda->processarWhatsappConfirmacoesVisitas(
            isset($_GET['limit']) ? (int)$_GET['limit'] : 100,
            !empty($_GET['dry_run'])
        );

        cron_logger_finish($pdo, $execucao_id, !empty($resultado['success']), $resultado, $inicio_ms);
        echo json_encode($resultado);

    } catch (Exception $e) {
        $resultado = ['success' => false, 'error' => $e->getMessage()];
        cron_logger_finish($pdo, $execucao_id, false, $resultado, $inicio_ms);
        http_response_code(500);
        echo json_encode($resultado);
    }

} elseif ($tipo === 'demandas_resumo_semanal') {
    // Cron de segunda-feira para enviar por WhatsApp as demandas pendentes da semana.
    try {
        require_once __DIR__ . '/demandas_resumo_semanal_helper.php';

        $resultado = demandas_resumo_semanal_processar($pdo, [
            'dry_run' => !empty($_GET['dry_run']),
            'force' => !empty($_GET['force']),
            'ref_date' => $_GET['ref_date'] ?? null,
            'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : 200,
        ]);

        cron_logger_finish($pdo, $execucao_id, !empty($resultado['success']), $resultado, $inicio_ms);
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    } catch (Exception $e) {
        $resultado = ['success' => false, 'error' => $e->getMessage()];
        cron_logger_finish($pdo, $execucao_id, false, $resultado, $inicio_ms);
        http_response_code(500);
        echo json_encode($resultado);
    }

} elseif ($tipo === 'degustacoes_notificacoes') {
    // Cron diário das 9h para lembrar os participantes das degustações do dia.
    try {
        require_once __DIR__ . '/comercial_degustacao_notificacao_helper.php';

        $opcoesDegustacao = [
            'dry_run' => !empty($_GET['dry_run']),
            'force' => !empty($_GET['force']),
            'ref_datetime' => $_GET['ref_datetime'] ?? null,
        ];
        $resultado = null;

        // A prévia precisa retornar os dados na própria resposta. O envio real
        // é iniciado em segundo plano para não estourar o timeout do cron-job.org.
        if (!$opcoesDegustacao['dry_run'] && function_exists('exec')) {
            $runner = __DIR__ . '/comercial_degustacao_notificacao_runner.php';
            $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner);
            if ($opcoesDegustacao['force']) {
                $command .= ' --force';
            }
            if (!empty($opcoesDegustacao['ref_datetime'])) {
                $command .= ' --ref-datetime=' . escapeshellarg((string)$opcoesDegustacao['ref_datetime']);
            }
            $command .= ' > /dev/null 2>&1 < /dev/null & echo $!';

            $output = [];
            $exitCode = 1;
            exec($command, $output, $exitCode);
            $pid = (int)($output[0] ?? 0);

            if ($exitCode === 0 && $pid > 0) {
                $resultado = [
                    'success' => true,
                    'queued' => true,
                    'message' => 'Processamento das notificações iniciado em segundo plano.',
                    'pid' => $pid,
                ];
            }
        }

        if ($resultado === null) {
            // Fallback para ambientes onde processos em segundo plano estejam
            // desabilitados. Ainda preserva o processamento após desconexão.
            ignore_user_abort(true);
            @set_time_limit(900);
            $resultado = degustacao_notificacao_processar($pdo, $opcoesDegustacao);
        }

        cron_logger_finish($pdo, $execucao_id, !empty($resultado['success']), $resultado, $inicio_ms);
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    } catch (Throwable $e) {
        $resultado = ['success' => false, 'error' => $e->getMessage()];
        cron_logger_finish($pdo, $execucao_id, false, $resultado, $inicio_ms);
        http_response_code(500);
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

} elseif ($tipo === 'portao_auto_close') {
    // Cron para processar auto-fechamento do portao
    try {
        require_once __DIR__ . '/core/portao_helper.php';
        $resultado_auto_close = portao_process_auto_close($pdo, false);

        $resultado = [
            'success' => !empty($resultado_auto_close['ok']),
            'executed' => !empty($resultado_auto_close['executed']),
            'reason' => $resultado_auto_close['reason'] ?? null,
            'details' => $resultado_auto_close['result'] ?? null,
        ];

        cron_logger_finish($pdo, $execucao_id, !empty($resultado['success']), $resultado, $inicio_ms);
        echo json_encode($resultado);
    } catch (Exception $e) {
        $resultado = ['success' => false, 'error' => $e->getMessage()];
        cron_logger_finish($pdo, $execucao_id, false, $resultado, $inicio_ms);
        http_response_code(500);
        echo json_encode($resultado);
    }

} elseif ($tipo === 'eventos_limpeza_anexos') {
    // Cron de limpeza de anexos pesados (áudio/vídeo) após 10 dias do evento
    try {
        require_once __DIR__ . '/eventos_limpeza_anexos.php';
        
        $resultado_limpeza = eventos_limpar_anexos_expirados($pdo);
        $stats = eventos_stats_armazenamento($pdo);
        
        $resultado = [
            'success' => true,
            'message' => 'Limpeza de anexos executada',
            'anexos_deletados' => $resultado_limpeza['total_deletados'],
            'erros' => $resultado_limpeza['total_erros'],
            'stats' => $stats
        ];
        
        cron_logger_finish($pdo, $execucao_id, true, $resultado, $inicio_ms);
        echo json_encode($resultado);
        
    } catch (Exception $e) {
        $resultado = ['success' => false, 'error' => $e->getMessage()];
        cron_logger_finish($pdo, $execucao_id, false, $resultado, $inicio_ms);
        http_response_code(500);
        echo json_encode($resultado);
    }
    
} elseif ($tipo === 'eventos_formularios_pendentes') {
    // Cron para avisar formulários/DJ em aberto antes do evento.
    try {
        require_once __DIR__ . '/eventos_formularios_pendentes_helper.php';

        $resultado = eventos_formularios_pendentes_processar($pdo, [
            'dry_run' => !empty($_GET['dry_run']),
            'event_date' => $_GET['event_date'] ?? null,
            'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : 200,
        ]);

        cron_logger_finish($pdo, $execucao_id, !empty($resultado['success']), $resultado, $inicio_ms);
        echo json_encode($resultado);

    } catch (Exception $e) {
        $resultado = ['success' => false, 'error' => $e->getMessage()];
        cron_logger_finish($pdo, $execucao_id, false, $resultado, $inicio_ms);
        http_response_code(500);
        echo json_encode($resultado);
    }

} elseif ($tipo === 'eventos_cliente_pendencias_whatsapp') {
    // Cron diario para avisar clientes sobre pendencias do portal no prazo correto.
    try {
        require_once __DIR__ . '/eventos_cliente_pendencias_whatsapp_helper.php';

        $resultado = eventos_cliente_pendencias_whatsapp_processar($pdo, [
            'dry_run' => !empty($_GET['dry_run']),
            'force' => !empty($_GET['force']),
            'ref_date' => $_GET['ref_date'] ?? null,
            'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : 200,
            'exclude_event_dates' => $_GET['exclude_event_dates'] ?? ($_GET['excluir_datas'] ?? []),
        ]);

        cron_logger_finish($pdo, $execucao_id, !empty($resultado['success']), $resultado, $inicio_ms);
        echo json_encode($resultado);

    } catch (Exception $e) {
        $resultado = ['success' => false, 'error' => $e->getMessage()];
        cron_logger_finish($pdo, $execucao_id, false, $resultado, $inicio_ms);
        http_response_code(500);
        echo json_encode($resultado);
    }

} elseif ($tipo === 'eventos_checklist_cliente_whatsapp') {
    // Lembrete único, às 09h, para tarefas do cliente que vencem hoje.
    try {
        require_once __DIR__ . '/eventos_checklist_whatsapp_helper.php';

        $resultado = eventos_checklist_whatsapp_processar($pdo, [
            'dry_run' => !empty($_GET['dry_run']),
            'force' => !empty($_GET['force']),
            'ref_date' => $_GET['ref_date'] ?? null,
            'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : 200,
        ]);

        cron_logger_finish($pdo, $execucao_id, !empty($resultado['success']), $resultado, $inicio_ms);
        echo json_encode($resultado);

    } catch (Exception $e) {
        $resultado = ['success' => false, 'error' => $e->getMessage()];
        cron_logger_finish($pdo, $execucao_id, false, $resultado, $inicio_ms);
        http_response_code(500);
        echo json_encode($resultado);
    }

} elseif ($tipo === 'eventos_feedback_fim_semana') {
    // Cron de segunda-feira para pedir feedback dos eventos do fim de semana anterior.
    try {
        require_once __DIR__ . '/eventos_feedback_whatsapp_helper.php';

        $resultado = eventos_feedback_whatsapp_processar($pdo, [
            'dry_run' => !empty($_GET['dry_run']),
            'force' => !empty($_GET['force']),
            'ref_date' => $_GET['ref_date'] ?? null,
            'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : 200,
        ]);

        cron_logger_finish($pdo, $execucao_id, !empty($resultado['success']), $resultado, $inicio_ms);
        echo json_encode($resultado);

    } catch (Exception $e) {
        $resultado = ['success' => false, 'error' => $e->getMessage()];
        cron_logger_finish($pdo, $execucao_id, false, $resultado, $inicio_ms);
        http_response_code(500);
        echo json_encode($resultado);
    }

} else {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Tipo de cron não especificado ou inválido.',
        'tipos_disponiveis' => [
            'demandas_fixas',
            'notificacoes',
            'agenda_visitas_whatsapp',
            'demandas_resumo_semanal',
            'degustacoes_notificacoes',
            'portao_auto_close',
            'google_calendar_daily',
            'google_calendar_sync',
            'google_calendar_renewal',
            'eventos_limpeza_anexos',
            'eventos_formularios_pendentes',
            'eventos_cliente_pendencias_whatsapp',
            'eventos_checklist_cliente_whatsapp',
            'eventos_feedback_fim_semana'
        ]
    ]);
}
