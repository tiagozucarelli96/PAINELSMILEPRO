<?php
/**
 * API para Degustações - Buscar e Atualizar via AJAX
 */
// CRÍTICO: Limpar qualquer output anterior
while (ob_get_level() > 0) {
    ob_end_clean();
}

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';
require_once __DIR__ . '/core/helpers.php';

// CRÍTICO: Garantir que sempre retornamos JSON
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Função helper para retornar JSON e sair
function returnJson($data, $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Log para debug (remover em produção)
error_log("=== API Degustação ===");
error_log("REQUEST_METHOD: " . ($_SERVER['REQUEST_METHOD'] ?? 'N/A'));
error_log("ACTION: " . ($_GET['action'] ?? $_POST['action'] ?? 'N/A'));
error_log("POST data: " . json_encode($_POST));

// Verificar login
if (!isset($_SESSION['id']) && !isset($_SESSION['id_usuario'])) {
    error_log("❌ Não autenticado");
    returnJson(['success' => false, 'error' => 'Não autenticado'], 401);
}

// Verificar permissões
if (!lc_can_edit_degustacoes()) {
    error_log("❌ Sem permissão");
    returnJson(['success' => false, 'error' => 'Sem permissão'], 403);
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
        
        error_log("✅ Degustação encontrada: ID {$data['id']}");
        returnJson(['success' => true, 'data' => $data]);
        
    } elseif ($action === 'update') {
        error_log("🔄 Atualizando degustação...");
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
        
        error_log("📝 Executando SQL UPDATE para ID: {$id}");
        error_log("📋 Parâmetros: " . json_encode(array_keys($params)));
        
        $stmt = $pdo->prepare($sql);
        if (!$stmt->execute($params)) {
            $errorInfo = $stmt->errorInfo();
            error_log("❌ Erro PDO: " . json_encode($errorInfo));
            throw new Exception("Erro ao salvar: " . ($errorInfo[2] ?? 'Erro desconhecido'));
        }
        
        error_log("✅ Degustação atualizada com sucesso! ID: {$id}");
        returnJson(['success' => true, 'message' => 'Degustação atualizada com sucesso!']);
        
    } else {
        error_log("❌ Ação inválida: {$action}");
        throw new Exception('Ação inválida: ' . $action);
    }
    
} catch (Exception $e) {
    error_log("❌ Exceção: " . $e->getMessage());
    error_log("Stack: " . $e->getTraceAsString());
    returnJson(['success' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log("❌ Erro fatal: " . $e->getMessage());
    error_log("Stack: " . $e->getTraceAsString());
    returnJson(['success' => false, 'error' => 'Erro interno: ' . $e->getMessage()], 500);
}

