<?php
/**
 * demandas_fixas_api.php
 * API para gerenciar demandas fixas
 */

ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

require_once __DIR__ . '/conexao.php';

$pdo = $GLOBALS['pdo'];
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

ob_clean();
header('Content-Type: application/json; charset=utf-8');

try {
    if ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $titulo = trim($data['titulo'] ?? '');
        $descricao = $data['descricao'] ?? null;
        $board_id = (int)($data['board_id'] ?? 0);
        $lista_id = (int)($data['lista_id'] ?? 0);
        $periodicidade = $data['periodicidade'] ?? '';
        $dia_semana = isset($data['dia_semana']) ? (int)$data['dia_semana'] : null;
        $dia_mes = isset($data['dia_mes']) ? (int)$data['dia_mes'] : null;
        
        if (empty($titulo) || empty($board_id) || empty($lista_id) || empty($periodicidade)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Campos obrigatórios: título, quadro, lista e periodicidade']);
            exit;
        }
        
        // Validar periodicidade
        if ($periodicidade === 'semanal' && ($dia_semana === null || $dia_semana < 0 || $dia_semana > 6)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Dia da semana inválido']);
            exit;
        }
        
        if ($periodicidade === 'mensal' && ($dia_mes === null || $dia_mes < 1 || $dia_mes > 31)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Dia do mês inválido']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO demandas_fixas 
            (board_id, lista_id, titulo, descricao, periodicidade, dia_semana, dia_mes)
            VALUES (:board_id, :lista_id, :titulo, :descricao, :periodicidade, :dia_semana, :dia_mes)
            RETURNING *
        ");
        $stmt->execute([
            ':board_id' => $board_id,
            ':lista_id' => $lista_id,
            ':titulo' => $titulo,
            ':descricao' => $descricao ?: null,
            ':periodicidade' => $periodicidade,
            ':dia_semana' => $dia_semana,
            ':dia_mes' => $dia_mes
        ]);
        
        $fixa = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'message' => 'Demanda fixa criada com sucesso',
            'data' => $fixa
        ]);
        exit;
        
    } elseif ($method === 'PATCH' && $id) {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $campos = [];
        $valores = [':id' => $id];
        
        if (isset($data['titulo'])) {
            $campos[] = 'titulo = :titulo';
            $valores[':titulo'] = trim($data['titulo']);
        }
        if (isset($data['descricao'])) {
            $campos[] = 'descricao = :descricao';
            $valores[':descricao'] = $data['descricao'] ?: null;
        }
        if (isset($data['ativo'])) {
            $campos[] = 'ativo = :ativo';
            $valores[':ativo'] = $data['ativo'] ? 'TRUE' : 'FALSE';
        }
        
        if (empty($campos)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nenhum campo para atualizar']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            UPDATE demandas_fixas 
            SET " . implode(', ', $campos) . "
            WHERE id = :id
            RETURNING *
        ");
        $stmt->execute($valores);
        
        $fixa = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'message' => 'Demanda fixa atualizada',
            'data' => $fixa
        ]);
        exit;
        
    } elseif ($method === 'DELETE' && $id) {
        $stmt = $pdo->prepare("DELETE FROM demandas_fixas WHERE id = :id");
        $stmt->execute([':id' => $id]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Demanda fixa deletada'
        ]);
        exit;
    }
    
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Rota não encontrada']);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

