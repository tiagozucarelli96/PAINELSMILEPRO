<?php
// asaas_webhook.php — Webhook para receber notificações do ASAAS
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

// Log do webhook para debug
function logWebhook($data) {
    $log = date('Y-m-d H:i:s') . " - " . json_encode($data) . "\n";
    file_put_contents(__DIR__ . '/logs/asaas_webhook.log', $log, FILE_APPEND | LOCK_EX);
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
logWebhook($webhook_data);

if (!$webhook_data) {
    http_response_code(400);
    exit('Invalid JSON');
}

try {
    // Verificar tipo de evento
    $event = $webhook_data['event'] ?? '';
    $payment_id = $webhook_data['payment']['id'] ?? '';
    $status = $webhook_data['payment']['status'] ?? '';
    
    if (!$payment_id) {
        throw new Exception('Payment ID not found');
    }
    
    // Buscar inscrição pelo payment_id do ASAAS
    $stmt = $pdo->prepare("SELECT * FROM comercial_inscricoes WHERE asaas_payment_id = :payment_id");
    $stmt->execute([':payment_id' => $payment_id]);
    $inscricao = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$inscricao) {
        throw new Exception("Inscrição não encontrada para payment_id: $payment_id");
    }
    
    // Buscar dados da degustação
    $stmt = $pdo->prepare("SELECT * FROM comercial_degustacoes WHERE id = :event_id");
    $stmt->execute([':event_id' => $inscricao['event_id']]);
    $degustacao = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$degustacao) {
        throw new Exception("Degustação não encontrada");
    }
    
    // Processar diferentes tipos de eventos
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
            logWebhook("Evento não tratado: $event");
            break;
    }
    
    // Resposta de sucesso
    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Webhook processed']);
    
} catch (Exception $e) {
    logWebhook("Erro no webhook: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
