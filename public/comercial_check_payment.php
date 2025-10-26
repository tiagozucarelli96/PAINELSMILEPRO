<?php
// comercial_check_payment.php — Verificar status do pagamento
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/asaas_helper.php';

$payment_id = $_GET['payment_id'] ?? '';
$inscricao_id = (int)($_GET['inscricao_id'] ?? 0);

if (!$payment_id || !$inscricao_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetros inválidos']);
    exit;
}

try {
    $asaasHelper = new AsaasHelper();
    $payment_data = $asaasHelper->getPaymentStatus($payment_id);
    
    if ($payment_data) {
        // Atualizar status na base de dados se necessário
        $status_map = [
            'CONFIRMED' => 'pago',
            'RECEIVED' => 'pago',
            'OVERDUE' => 'expirado',
            'PENDING' => 'aguardando'
        ];
        
        $new_status = $status_map[$payment_data['status']] ?? 'aguardando';
        
        $stmt = $pdo->prepare("UPDATE comercial_inscricoes SET pagamento_status = :status WHERE id = :id");
        $stmt->execute([':status' => $new_status, ':id' => $inscricao_id]);
        
        echo json_encode([
            'status' => $payment_data['status'],
            'message' => 'Status atualizado'
        ]);
    } else {
        echo json_encode([
            'status' => 'UNKNOWN',
            'message' => 'Status não encontrado'
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
