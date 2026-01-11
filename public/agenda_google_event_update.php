<?php
// agenda_google_event_update.php — Atualizar campos de visita/contrato em eventos do Google
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['logado']) || empty($_SESSION['perm_administrativo'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado']);
    exit;
}

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/conexao.php';
    
    $pdo = $GLOBALS['pdo'];
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método não permitido');
    }
    
    $event_id = (int)($_POST['event_id'] ?? 0);
    $eh_visita = isset($_POST['eh_visita_agendada']) ? (bool)$_POST['eh_visita_agendada'] : null;
    $contrato_fechado = isset($_POST['contrato_fechado']) ? (bool)$_POST['contrato_fechado'] : null;
    
    if (!$event_id) {
        throw new Exception('ID do evento é obrigatório');
    }
    
    // Verificar se o evento existe
    $stmt = $pdo->prepare("SELECT id FROM google_calendar_eventos WHERE id = :id");
    $stmt->execute([':id' => $event_id]);
    if (!$stmt->fetch()) {
        throw new Exception('Evento não encontrado');
    }
    
    // Atualizar campos
    $updates = [];
    $params = [':id' => $event_id];
    
    if ($eh_visita !== null) {
        $updates[] = "eh_visita_agendada = :eh_visita";
        $params[':eh_visita'] = $eh_visita ? 't' : 'f';
    }
    
    if ($contrato_fechado !== null) {
        $updates[] = "contrato_fechado = :contrato_fechado";
        $params[':contrato_fechado'] = $contrato_fechado ? 't' : 'f';
    }
    
    if (empty($updates)) {
        throw new Exception('Nenhum campo para atualizar');
    }
    
    $updates[] = "atualizado_em = NOW()";
    
    $sql = "UPDATE google_calendar_eventos SET " . implode(', ', $updates) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    echo json_encode([
        'success' => true,
        'message' => 'Evento atualizado com sucesso'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
