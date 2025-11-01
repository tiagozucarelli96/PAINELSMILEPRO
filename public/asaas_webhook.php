<?php
// asaas_webhook.php — Webhook para receber notificações do ASAAS (Checkout e Pagamentos)
// Documentação: https://docs.asaas.com/docs/eventos-para-checkout

// ============================================
// CRÍTICO: ISOLAMENTO TOTAL DO WEBHOOK
// ============================================
// Este arquivo DEVE ser acessado DIRETAMENTE, nunca via index.php ou router
// NUNCA deve requerer autenticação ou retornar HTML

// 1. Desabilitar completamente output buffering ANTES de qualquer coisa
while (ob_get_level()) {
    ob_end_clean();
}

// 2. Limpar TODOS os headers que possam existir
header_remove();

// 3. Fechar sessão se existir (ANTES de incluir qualquer arquivo)
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// 4. Desabilitar qualquer auto-start de sessão
ini_set('session.auto_start', '0');

// 5. Garantir que não há redirects automáticos
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// 6. Definir headers corretos IMEDIATAMENTE (antes de qualquer include)
http_response_code(200);
header('Content-Type: application/json', true);
header('Cache-Control: no-cache, no-store, must-revalidate', true);
header('X-Content-Type-Options: nosniff', true);
header('Access-Control-Allow-Origin: *', true);
header('Access-Control-Allow-Methods: POST', true);

// 7. Verificar se está sendo acessado via router (logar mas NÃO recusar)
// IMPORTANTE: Asaas exige HTTP 200, então mesmo se acessado via router, retornar 200
if (isset($_GET['page']) || strpos($_SERVER['REQUEST_URI'] ?? '', 'index.php') !== false) {
    // Logar aviso mas continuar processamento (não pode retornar 400)
    error_log("⚠️ Webhook acessado via router (não recomendado) - mas processando mesmo assim");
}

// 8. Verificar método HTTP ANTES de incluir arquivos
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// 9. Incluir arquivos necessários (mas NUNCA incluir index.php ou router)
// NUNCA incluir arquivos que façam verificação de autenticação
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

// Log do webhook para debug
function logWebhook($data) {
    $log = date('Y-m-d H:i:s') . " - " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $log_file = __DIR__ . '/logs/asaas_webhook.log';
    if (!is_dir(dirname($log_file))) {
        mkdir(dirname($log_file), 0755, true);
    }
    file_put_contents($log_file, $log, FILE_APPEND | LOCK_EX);
}

// ============================================
// HEADERS JÁ FORAM DEFINIDOS NO INÍCIO DO ARQUIVO
// NÃO redefinir aqui para evitar conflitos
// ============================================

// Obter dados do webhook
$input = file_get_contents('php://input');
$webhook_data = json_decode($input, true);

// Log do webhook recebido
logWebhook(['raw_input_length' => strlen($input), 'parsed_data' => $webhook_data]);

if (!$webhook_data) {
    // IMPORTANTE: Asaas exige HTTP 200 sempre, mesmo para erros
    // Logar erro mas retornar 200 para não pausar fila
    logWebhook("⚠️ JSON inválido recebido: " . substr($input, 0, 200));
    http_response_code(200);
    header('Content-Type: application/json', true);
    echo json_encode(['status' => 'warning', 'message' => 'Invalid JSON received but logged']);
    exit;
}

try {
    // IDEMPOTÊNCIA: Verificar se evento já foi processado
    // Conforme documentação: https://docs.asaas.com/docs/como-implementar-idempotencia-em-webhooks
    $event_id = $webhook_data['id'] ?? null;
    if ($event_id) {
        try {
            // Tentar inserir evento na tabela de eventos processados
            $stmt = $pdo->prepare("INSERT INTO asaas_webhook_events (asaas_event_id, event_type, payload, processed_at) VALUES (:event_id, :event_type, :payload, NOW())");
            $stmt->execute([
                ':event_id' => $event_id,
                ':event_type' => $webhook_data['event'] ?? 'UNKNOWN',
                ':payload' => $input
            ]);
        } catch (PDOException $e) {
            // Se erro for violação de constraint único (evento já processado)
            if ($e->getCode() == 23505 || strpos($e->getMessage(), 'duplicate key') !== false || strpos($e->getMessage(), 'UNIQUE constraint') !== false) {
                logWebhook("Evento já processado (idempotência): $event_id");
                http_response_code(200);
                echo json_encode(['status' => 'success', 'message' => 'Event already processed']);
                exit;
            }
            // Se for outro erro, logar mas continuar processamento
            logWebhook("Erro ao inserir evento (não é duplicata): " . $e->getMessage());
        }
    }
    
    // Verificar tipo de evento
    $event = $webhook_data['event'] ?? '';
    
    // EVENTOS DE CHECKOUT (conforme documentação)
    if (in_array($event, ['CHECKOUT_CREATED', 'CHECKOUT_CANCELED', 'CHECKOUT_EXPIRED', 'CHECKOUT_PAID'])) {
        $checkout_id = $webhook_data['checkout']['id'] ?? '';
        
        if (!$checkout_id) {
            throw new Exception('Checkout ID not found');
        }
        
        logWebhook("Processando evento de Checkout: $event - Checkout ID: $checkout_id");
        
        // Buscar inscrição pelo checkout_id do Asaas
        $stmt = $pdo->prepare("SELECT * FROM comercial_inscricoes WHERE asaas_checkout_id = :checkout_id");
        $stmt->execute([':checkout_id' => $checkout_id]);
        $inscricao = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Se não encontrou por checkout_id, tentar por payment_id (retrocompatibilidade)
        if (!$inscricao) {
            $stmt = $pdo->prepare("SELECT * FROM comercial_inscricoes WHERE asaas_payment_id = :checkout_id");
            $stmt->execute([':checkout_id' => $checkout_id]);
            $inscricao = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // Processar eventos de checkout
        switch ($event) {
            case 'CHECKOUT_PAID':
                // Checkout pago
                if ($inscricao) {
                    $stmt = $pdo->prepare("UPDATE comercial_inscricoes SET pagamento_status = 'pago' WHERE id = :id");
                    $stmt->execute([':id' => $inscricao['id']]);
                    
                    // Buscar dados da degustação
                    $stmt_deg = $pdo->prepare("SELECT * FROM comercial_degustacoes WHERE id = :degustacao_id");
                    $stmt_deg->execute([':degustacao_id' => $inscricao['degustacao_id']]);
                    $degustacao = $stmt_deg->fetch(PDO::FETCH_ASSOC);
                    
                    // Enviar e-mail de confirmação
                    if ($degustacao && class_exists('ComercialEmailHelper')) {
                        require_once __DIR__ . '/comercial_email_helper.php';
                        $emailHelper = new ComercialEmailHelper();
                        $emailHelper->sendInscricaoConfirmation($inscricao, $degustacao);
                    }
                    
                    logWebhook("Checkout pago confirmado para inscrição ID: " . $inscricao['id']);
                } else {
                    logWebhook("Checkout pago mas inscrição não encontrada: checkout_id=$checkout_id");
                }
                break;
                
            case 'CHECKOUT_CANCELED':
                // Checkout cancelado
                if ($inscricao) {
                    $stmt = $pdo->prepare("UPDATE comercial_inscricoes SET pagamento_status = 'cancelado' WHERE id = :id");
                    $stmt->execute([':id' => $inscricao['id']]);
                    logWebhook("Checkout cancelado para inscrição ID: " . $inscricao['id']);
                }
                break;
                
            case 'CHECKOUT_EXPIRED':
                // Checkout expirado
                if ($inscricao) {
                    $stmt = $pdo->prepare("UPDATE comercial_inscricoes SET pagamento_status = 'expirado' WHERE id = :id");
                    $stmt->execute([':id' => $inscricao['id']]);
                    logWebhook("Checkout expirado para inscrição ID: " . $inscricao['id']);
                }
                break;
                
            case 'CHECKOUT_CREATED':
                // Checkout criado (apenas log)
                logWebhook("Checkout criado: checkout_id=$checkout_id");
                break;
        }
        
        // CRÍTICO: Asaas só aceita HTTP 200 como sucesso
        // Limpar qualquer output anterior
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Remover TODOS os headers
        header_remove();
        
        // Definir headers corretos
        http_response_code(200);
        header('Content-Type: application/json', true);
        header('Cache-Control: no-cache, no-store, must-revalidate', true);
        
        logWebhook("✅ Checkout webhook processado com sucesso - Evento: $event");
        
        echo json_encode(['status' => 'success', 'message' => 'Checkout webhook processed', 'event' => $event]);
        exit;
    }
    
    // EVENTOS DE PAGAMENTO (modo antigo - retrocompatibilidade + QR Code estático)
    $payment_id = $webhook_data['payment']['id'] ?? '';
    $pix_qr_code_id = $webhook_data['payment']['pixQrCodeId'] ?? null; // ID do QR Code estático
    
    if ($payment_id) {
        logWebhook("Processando evento de Pagamento: $event - Payment ID: $payment_id" . ($pix_qr_code_id ? " - QR Code ID: $pix_qr_code_id" : ""));
        
        $inscricao = null;
        
        // PRIORIDADE 1: Buscar por QR Code estático (se veio de QR Code)
        if ($pix_qr_code_id) {
            try {
                // Verificar se coluna existe
                $check_col = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'comercial_inscricoes' AND column_name = 'asaas_qr_code_id'");
                if ($check_col->rowCount() > 0) {
                    $stmt = $pdo->prepare("SELECT * FROM comercial_inscricoes WHERE asaas_qr_code_id = :qr_code_id");
                    $stmt->execute([':qr_code_id' => $pix_qr_code_id]);
                    $inscricao = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($inscricao) {
                        logWebhook("Inscrição encontrada via QR Code estático: ID=" . $inscricao['id']);
                        
                        // Salvar também o payment_id para referência futura
                        try {
                            $check_payment_col = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'comercial_inscricoes' AND column_name = 'asaas_payment_id'");
                            if ($check_payment_col->rowCount() > 0) {
                                $stmt_update = $pdo->prepare("UPDATE comercial_inscricoes SET asaas_payment_id = :payment_id WHERE id = :id");
                                $stmt_update->execute([':payment_id' => $payment_id, ':id' => $inscricao['id']]);
                            }
                        } catch (Exception $e) {
                            logWebhook("Erro ao salvar payment_id: " . $e->getMessage());
                        }
                    }
                }
            } catch (Exception $e) {
                logWebhook("Erro ao buscar por QR Code: " . $e->getMessage());
            }
        }
        
        // PRIORIDADE 2: Buscar inscrição pelo payment_id do ASAAS (se não encontrou por QR Code)
        if (!$inscricao) {
            $stmt = $pdo->prepare("SELECT * FROM comercial_inscricoes WHERE asaas_payment_id = :payment_id");
            $stmt->execute([':payment_id' => $payment_id]);
            $inscricao = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($inscricao) {
                logWebhook("Inscrição encontrada via payment_id: ID=" . $inscricao['id']);
            }
        }
        
        if (!$inscricao) {
            logWebhook("⚠️ Inscrição não encontrada para payment_id: $payment_id" . ($pix_qr_code_id ? " ou qr_code_id: $pix_qr_code_id" : ""));
            // Não lançar exceção para não quebrar o webhook - apenas logar
            http_response_code(200);
            echo json_encode(['status' => 'warning', 'message' => "Inscrição não encontrada para payment_id: $payment_id"]);
            exit;
        }
        
        // Buscar dados da degustação
        $stmt = $pdo->prepare("SELECT * FROM comercial_degustacoes WHERE id = :degustacao_id");
        $stmt->execute([':degustacao_id' => $inscricao['degustacao_id']]);
        $degustacao = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$degustacao) {
            throw new Exception("Degustação não encontrada");
        }
        
        // Processar diferentes tipos de eventos de pagamento
        switch ($event) {
            case 'PAYMENT_CONFIRMED':
            case 'PAYMENT_RECEIVED':
                // Pagamento confirmado
                $stmt = $pdo->prepare("UPDATE comercial_inscricoes SET pagamento_status = 'pago' WHERE id = :id");
                $stmt->execute([':id' => $inscricao['id']]);
                
                // Enviar e-mail de confirmação
                if (class_exists('ComercialEmailHelper')) {
                    require_once __DIR__ . '/comercial_email_helper.php';
                    $emailHelper = new ComercialEmailHelper();
                    $emailHelper->sendInscricaoConfirmation($inscricao, $degustacao);
                }
                
                logWebhook("Pagamento confirmado para inscrição ID: " . $inscricao['id']);
                break;
                
            case 'PAYMENT_OVERDUE':
                // Pagamento vencido
                $stmt = $pdo->prepare("UPDATE comercial_inscricoes SET pagamento_status = 'expirado' WHERE id = :id");
                $stmt->execute([':id' => $inscricao['id']]);
                
                logWebhook("Pagamento vencido para inscrição ID: " . $inscricao['id']);
                break;
                
            case 'PAYMENT_DELETED':
                // Pagamento deletado
                $stmt = $pdo->prepare("UPDATE comercial_inscricoes SET pagamento_status = 'expirado' WHERE id = :id");
                $stmt->execute([':id' => $inscricao['id']]);
                
                logWebhook("Pagamento deletado para inscrição ID: " . $inscricao['id']);
                break;
                
            default:
                logWebhook("Evento de pagamento não tratado: $event");
                break;
        }
        
        // CRÍTICO: Asaas só aceita HTTP 200 como sucesso
        // Qualquer outro código (3xx, 4xx, 5xx) pausa a fila após 15 tentativas
        // GARANTIR que retorna EXATAMENTE 200 com JSON válido
        
        // Limpar qualquer output anterior
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Remover TODOS os headers que podem causar redirect ou problema
        header_remove();
        
        // Definir headers corretos
        http_response_code(200); // CRÍTICO: Deve ser exatamente 200
        header('Content-Type: application/json', true);
        header('Cache-Control: no-cache, no-store, must-revalidate', true);
        header('Pragma: no-cache', true);
        header('Expires: 0', true);
        
        // Log de sucesso
        logWebhook("✅ Webhook processado com sucesso - Evento: $event, Inscrição ID: " . ($inscricao['id'] ?? 'N/A'));
        
        // Retornar resposta JSON válida
        $response = [
            'status' => 'success', 
            'message' => 'Payment webhook processed', 
            'event' => $event,
            'inscricao_id' => $inscricao['id'] ?? null,
            'pix_qr_code_id' => $pix_qr_code_id ?? null
        ];
        
        echo json_encode($response);
        exit;
    }
    
    // Evento não reconhecido - MAS RETORNAR 200 para não pausar fila
    // Asaas só aceita 200 como sucesso, qualquer outro código pausa a fila
    logWebhook("⚠️ Evento não reconhecido mas retornando 200: $event");
    
    // Limpar output
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    header_remove();
    http_response_code(200); // SEMPRE 200, mesmo para eventos não reconhecidos
    header('Content-Type: application/json', true);
    
    echo json_encode(['status' => 'success', 'message' => 'Event received but not processed', 'event' => $event]);
    
} catch (Exception $e) {
    // IMPORTANTE: Mesmo em caso de erro, retornar 200 para não pausar a fila
    // O erro será logado, mas não deve quebrar o webhook
    logWebhook("❌ Erro no webhook: " . $e->getMessage() . " | Stack: " . $e->getTraceAsString());
    
    // Limpar output
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    header_remove();
    http_response_code(200); // Retornar 200 mesmo com erro para não pausar fila
    header('Content-Type: application/json', true);
    
    // Logar erro mas retornar sucesso
    echo json_encode([
        'status' => 'success', 
        'message' => 'Webhook received (error logged)', 
        'event' => $webhook_data['event'] ?? 'UNKNOWN'
    ]);
}
