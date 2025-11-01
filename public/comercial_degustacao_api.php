<?php
/**
 * API para Degustações - Buscar e Atualizar via AJAX
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';
require_once __DIR__ . '/core/helpers.php';

header('Content-Type: application/json');

// Verificar login
if (!isset($_SESSION['id']) && !isset($_SESSION['id_usuario'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

// Verificar permissões
if (!lc_can_edit_degustacoes()) {
    http_response_code(403);
    echo json_encode(['error' => 'Sem permissão']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    if ($action === 'get') {
        // Buscar dados da degustação
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            throw new Exception('ID inválido');
        }
        
        // Garantir search_path
        try {
            $pdo->exec("SET search_path TO smilee12_painel_smile, public");
        } catch (Exception $e) {
            // Ignorar erro
        }
        
        $stmt = $pdo->prepare("SELECT * FROM comercial_degustacoes WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $degustacao = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$degustacao) {
            throw new Exception('Degustação não encontrada');
        }
        
        // Formatar dados para o formulário
        $data = [
            'id' => (int)$degustacao['id'],
            'nome' => $degustacao['nome'] ?? $degustacao['titulo'] ?? '',
            'data' => $degustacao['data'] ?? '',
            'hora_inicio' => $degustacao['hora_inicio'] ?? '',
            'hora_fim' => $degustacao['hora_fim'] ?? '',
            'local' => $degustacao['local'] ?? '',
            'capacidade' => (int)($degustacao['capacidade'] ?? 50),
            'data_limite' => $degustacao['data_limite'] ?? '',
            'lista_espera' => !empty($degustacao['lista_espera']),
            'preco_casamento' => (float)($degustacao['preco_casamento'] ?? 150.00),
            'incluidos_casamento' => (int)($degustacao['incluidos_casamento'] ?? 2),
            'preco_15anos' => (float)($degustacao['preco_15anos'] ?? 180.00),
            'incluidos_15anos' => (int)($degustacao['incluidos_15anos'] ?? 3),
            'preco_extra' => (float)($degustacao['preco_extra'] ?? 50.00),
            'instrutivo_html' => $degustacao['instrutivo_html'] ?? '',
            'email_confirmacao_html' => $degustacao['email_confirmacao_html'] ?? '',
            'msg_sucesso_html' => $degustacao['msg_sucesso_html'] ?? '',
            'campos_json' => $degustacao['campos_json'] ?? '[]',
            'token_publico' => $degustacao['token_publico'] ?? ''
        ];
        
        echo json_encode(['success' => true, 'data' => $data]);
        
    } elseif ($action === 'update') {
        // Atualizar degustação
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new Exception('ID inválido');
        }
        
        // Coletar dados do POST
        $nome = trim($_POST['nome'] ?? '');
        $data = $_POST['data'] ?? '';
        $hora_inicio = $_POST['hora_inicio'] ?? '';
        $hora_fim = $_POST['hora_fim'] ?? '';
        $local = trim($_POST['local'] ?? '');
        
        // Se local_custom foi enviado e não está vazio, usar ele
        if (!empty($_POST['local_custom']) && trim($_POST['local_custom']) !== '') {
            $local = trim($_POST['local_custom']);
        }
        
        if (empty($nome) || empty($data) || empty($hora_inicio) || empty($hora_fim) || empty($local)) {
            throw new Exception('Campos obrigatórios não preenchidos');
        }
        
        // Construir data_evento
        $data_evento = $data . ' ' . $hora_inicio . ':00';
        
        $capacidade = (int)($_POST['capacidade'] ?? 50);
        $data_limite = $_POST['data_limite'] ?? '';
        $lista_espera = isset($_POST['lista_espera']) ? 1 : 0;
        $preco_casamento = (float)($_POST['preco_casamento'] ?? 150.00);
        $incluidos_casamento = (int)($_POST['incluidos_casamento'] ?? 2);
        $preco_15anos = (float)($_POST['preco_15anos'] ?? 180.00);
        $incluidos_15anos = (int)($_POST['incluidos_15anos'] ?? 3);
        $preco_extra = (float)($_POST['preco_extra'] ?? 50.00);
        $instrutivo_html = $_POST['instrutivo_html'] ?? '';
        $email_confirmacao_html = $_POST['email_confirmacao_html'] ?? '';
        $msg_sucesso_html = $_POST['msg_sucesso_html'] ?? '';
        $campos_json = $_POST['campos_json'] ?? '[]';
        
        // Validar JSON
        if (!empty($campos_json) && json_decode($campos_json) === null) {
            $campos_json = '[]';
        }
        
        // Garantir search_path
        try {
            $pdo->exec("SET search_path TO smilee12_painel_smile, public");
        } catch (Exception $e) {
            // Ignorar erro
        }
        
        $sql = "UPDATE comercial_degustacoes SET 
                nome = :nome, titulo = :nome, data = :data, 
                data_evento = :data_evento::timestamp,
                hora_inicio = :hora_inicio, hora_fim = :hora_fim,
                local = :local, capacidade = :capacidade, data_limite = :data_limite, lista_espera = :lista_espera,
                preco_casamento = :preco_casamento, incluidos_casamento = :incluidos_casamento,
                preco_15anos = :preco_15anos, incluidos_15anos = :incluidos_15anos, preco_extra = :preco_extra,
                instrutivo_html = :instrutivo_html, email_confirmacao_html = :email_confirmacao_html,
                msg_sucesso_html = :msg_sucesso_html, campos_json = :campos_json
                WHERE id = :id";
        
        $params = [
            ':nome' => $nome,
            ':data' => $data,
            ':data_evento' => $data_evento,
            ':hora_inicio' => $hora_inicio,
            ':hora_fim' => $hora_fim,
            ':local' => $local,
            ':capacidade' => $capacidade,
            ':data_limite' => $data_limite ?: null,
            ':lista_espera' => $lista_espera,
            ':preco_casamento' => $preco_casamento,
            ':incluidos_casamento' => $incluidos_casamento,
            ':preco_15anos' => $preco_15anos,
            ':incluidos_15anos' => $incluidos_15anos,
            ':preco_extra' => $preco_extra,
            ':instrutivo_html' => $instrutivo_html,
            ':email_confirmacao_html' => $email_confirmacao_html,
            ':msg_sucesso_html' => $msg_sucesso_html,
            ':campos_json' => $campos_json,
            ':id' => $id
        ];
        
        $stmt = $pdo->prepare($sql);
        if (!$stmt->execute($params)) {
            $errorInfo = $stmt->errorInfo();
            throw new Exception("Erro ao salvar: " . ($errorInfo[2] ?? 'Erro desconhecido'));
        }
        
        echo json_encode(['success' => true, 'message' => 'Degustação atualizada com sucesso!']);
        
    } else {
        throw new Exception('Ação inválida');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

