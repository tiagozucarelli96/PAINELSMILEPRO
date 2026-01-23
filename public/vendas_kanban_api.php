<?php
/**
 * vendas_kanban_api.php
 * API REST para Kanban de Acompanhamento de Contratos
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['logado']) || empty($_SESSION['perm_comercial'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

require_once __DIR__ . '/conexao.php';

$pdo = $GLOBALS['pdo'];
$usuario_id = (int)($_SESSION['id'] ?? 0);

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    if ($action === 'mover_card') {
        $card_id = (int)($_POST['card_id'] ?? 0);
        $nova_coluna_id = (int)($_POST['coluna_id'] ?? 0);
        $nova_posicao = (int)($_POST['posicao'] ?? 0);
        
        // Buscar card atual
        $stmt = $pdo->prepare("SELECT * FROM vendas_kanban_cards WHERE id = ?");
        $stmt->execute([$card_id]);
        $card = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$card) {
            throw new Exception('Card não encontrado');
        }
        
        $pdo->beginTransaction();
        
        // Atualizar posição do card
        $stmt = $pdo->prepare("
            UPDATE vendas_kanban_cards 
            SET coluna_id = ?, posicao = ?, atualizado_em = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$nova_coluna_id, $nova_posicao, $card_id]);
        
        // Registrar histórico
        $stmt_hist = $pdo->prepare("
            INSERT INTO vendas_kanban_historico 
            (card_id, coluna_anterior_id, coluna_nova_id, movido_por)
            VALUES (?, ?, ?, ?)
        ");
        $stmt_hist->execute([$card_id, $card['coluna_id'], $nova_coluna_id, $usuario_id]);
        
        $pdo->commit();
        
        echo json_encode(['success' => true]);
        
    } elseif ($action === 'listar_colunas') {
        $board_id = (int)($_GET['board_id'] ?? 0);
        
        if (!$board_id) {
            // Buscar board padrão
            $stmt = $pdo->prepare("SELECT id FROM vendas_kanban_boards WHERE ativo = TRUE LIMIT 1");
            $stmt->execute();
            $board = $stmt->fetch(PDO::FETCH_ASSOC);
            $board_id = $board['id'] ?? 0;
        }
        
        $stmt = $pdo->prepare("
            SELECT vc.*, COUNT(vk.id) as total_cards
            FROM vendas_kanban_colunas vc
            LEFT JOIN vendas_kanban_cards vk ON vk.coluna_id = vc.id
            WHERE vc.board_id = ?
            GROUP BY vc.id
            ORDER BY vc.posicao ASC
        ");
        $stmt->execute([$board_id]);
        $colunas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $colunas]);
        
    } elseif ($action === 'listar_cards') {
        $coluna_id = (int)($_GET['coluna_id'] ?? 0);
        
        $stmt = $pdo->prepare("
            SELECT vk.*, vp.nome_completo, vp.data_evento, vp.unidade, vp.valor_total
            FROM vendas_kanban_cards vk
            LEFT JOIN vendas_pre_contratos vp ON vp.id = vk.pre_contrato_id
            WHERE vk.coluna_id = ?
            ORDER BY vk.posicao ASC, vk.id ASC
        ");
        $stmt->execute([$coluna_id]);
        $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $cards]);
        
    } else {
        throw new Exception('Ação não reconhecida');
    }
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
