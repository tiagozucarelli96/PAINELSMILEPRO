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
        require_once __DIR__ . '/google_calendar_sync_processor.php';
        
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
        $processor_script = __DIR__ . '/google_calendar_sync_processor.php';
        if (file_exists($processor_script)) {
            // Capturar output do processador
            ob_start();
            include $processor_script;
            $processor_output = ob_get_clean();
        } else {
            error_log("[GOOGLE_CRON_DAILY] ⚠️ Processador não encontrado: $processor_script");
        }
        
        error_log("[GOOGLE_CRON_DAILY] ✅ Sincronização diária concluída");
        
        $resultado = [
            'success' => true, 
            'message' => 'Sincronização diária do Google Calendar iniciada',
            'calendarios_marcados' => $rows_updated
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

        $processor_script = __DIR__ . '/google_calendar_sync_processor.php';
        if (!file_exists($processor_script)) {
            throw new Exception('Processador não encontrado: google_calendar_sync_processor.php');
        }

        ob_start();
        include $processor_script;
        $out = trim((string)ob_get_clean());

        echo json_encode([
            'success' => true,
            'message' => 'Processador executado',
            'output' => $out
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
        
        // Calcular timestamp atual em milissegundos
        $now_ms = round(microtime(true) * 1000);
        
        // Calcular timestamp de 6 horas no futuro (threshold para renovação)
        $threshold_ms = $now_ms + (6 * 60 * 60 * 1000); // 6 horas em ms
        
        error_log("[GOOGLE_WATCH_RENEWAL] Verificando webhooks próximos de expirar");
        
        // Buscar webhooks que expiram em menos de 6 horas
        $now_timestamp = date('Y-m-d H:i:s', $now_ms / 1000);
        $threshold_timestamp = date('Y-m-d H:i:s', $threshold_ms / 1000);
        
        $stmt = $pdo->prepare("
            SELECT id, google_calendar_id, google_calendar_name, webhook_channel_id, webhook_resource_id, webhook_expiration
            FROM google_calendar_config
            WHERE ativo = TRUE 
            AND webhook_resource_id IS NOT NULL
            AND webhook_expiration IS NOT NULL
            AND webhook_expiration > :now_ts
            AND webhook_expiration <= :threshold_ts
            ORDER BY webhook_expiration ASC
        ");
        $stmt->execute([
            ':now_ts' => $now_timestamp,
            ':threshold_ts' => $threshold_timestamp
        ]);
        $webhooks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
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
        
        $webhook_url = getenv('GOOGLE_WEBHOOK_URL') ?: ($_ENV['GOOGLE_WEBHOOK_URL'] ?? 'https://painelsmilepro-production.up.railway.app/google/webhook');
        $renovados = 0;
        $erros = [];
        
        foreach ($webhooks as $webhook) {
            $calendar_id = $webhook['google_calendar_id'];
            $expiration_date = $webhook['webhook_expiration'] ?? 'N/A';
            
            error_log("[GOOGLE_WATCH_RENEWAL] 🔄 Renovando webhook para: $calendar_id (expira em: $expiration_date)");
            
            try {
                if ($webhook['webhook_resource_id']) {
                    try {
                        $helper->stopWebhook($webhook['webhook_resource_id']);
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

if ($tipo === 'notificacoes') {
    // Envio consolidado de notificações (e-mail + push)
    try {
        require_once __DIR__ . '/conexao.php';
        require_once __DIR__ . '/core/notificacoes_helper.php';

        $notificacoes = new NotificacoesHelper();
        $enviado = $notificacoes->enviarNotificacoesConsolidadas();

        echo json_encode([
            'success' => (bool)$enviado,
            'message' => $enviado ? 'Notificações enviadas' : 'Nenhuma notificação pendente'
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
        require_once __DIR__ . '/cron_notificacoes.php';
        
        $resultado = [
            'success' => true,
            'message' => 'Processamento de notificações executado'
        ];
        
        cron_logger_finish($pdo, $execucao_id, true, $resultado, $inicio_ms);
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
            'google_calendar_daily',
            'google_calendar_sync',
            'google_calendar_renewal'
        ]
    ]);
}

