<?php
// asaas_webhook.php — Webhook para receber notificações do ASAAS (Checkout e Pagamentos)
// Documentação: https://docs.asaas.com/docs/eventos-para-checkout
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

// Verificar se é POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

// Obter dados do webhook
$input = file_get_contents('php://input');
$webhook_data = json_decode($input, true);

// Log do webhook recebido
logWebhook(['raw_input_length' => strlen($input), 'parsed_data' => $webhook_data]);

if (!$webhook_data) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
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
        
        // Resposta de sucesso
        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Checkout webhook processed', 'event' => $event]);
        exit;
    }
    
    // EVENTOS DE PAGAMENTO (modo antigo - retrocompatibilidade)
    $payment_id = $webhook_data['payment']['id'] ?? '';
    
    if ($payment_id) {
        logWebhook("Processando evento de Pagamento: $event - Payment ID: $payment_id");
        
        // Buscar inscrição pelo payment_id do ASAAS
        $stmt = $pdo->prepare("SELECT * FROM comercial_inscricoes WHERE asaas_payment_id = :payment_id");
        $stmt->execute([':payment_id' => $payment_id]);
        $inscricao = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$inscricao) {
            throw new Exception("Inscrição não encontrada para payment_id: $payment_id");
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
        
        // Resposta de sucesso
        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Payment webhook processed', 'event' => $event]);
        exit;
    }
    
    // Evento não reconhecido
    logWebhook("Evento não reconhecido: $event");
    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Event received but not processed', 'event' => $event]);
    
} catch (Exception $e) {
    logWebhook("Erro no webhook: " . $e->getMessage() . " | Stack: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
