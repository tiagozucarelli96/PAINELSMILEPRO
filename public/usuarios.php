<?php
// public/usuarios.php — Interface moderna de usuários com integração RH
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';

// Garantir que $pdo está disponível
if (!isset($pdo)) {
    global $pdo;
}

// Verificar permissões - precisa ter perm_configuracoes (já que está dentro de Configurações)
if (empty($_SESSION['logado']) || empty($_SESSION['perm_configuracoes'])) {
    http_response_code(403); 
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <title>Acesso Negado</title>
    </head>
    <body>
        <div style="text-align: center; padding: 50px; font-family: Arial;">
            <h1>🚫 Acesso Negado</h1>
            <p>Você não tem permissão para acessar esta função.</p>
            <a href="index.php?page=dashboard">Voltar para Dashboard</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Processar ações ANTES de qualquer output
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user_id = (int)($_POST['user_id'] ?? $_GET['id'] ?? 0);

// AJAX: Retornar dados do usuário em JSON
// IMPORTANTE: Processar ANTES de qualquer output buffer
if ($action === 'get_user' && !empty($_GET['id'])) {
    // Garantir que não há output buffer ativo
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Limpar qualquer output anterior
    if (headers_sent() === false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
    }
    
    try {
        $id = (int)$_GET['id'];
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // Converter valores boolean/smallint para boolean conforme esperado pelo JS
            foreach ($user as $key => $value) {
                if (strpos($key, 'perm_') === 0) {
                    // Converter smallint (0/1) ou boolean (t/f) ou string ('1'/'0') para boolean
                    if ($value === null || $value === '') {
                        $user[$key] = false;
                    } elseif (is_numeric($value)) {
                        $user[$key] = ((int)$value) === 1;
                    } elseif (is_bool($value)) {
                        $user[$key] = $value;
                    } elseif (is_string($value)) {
                        $user[$key] = in_array(strtolower($value), ['1', 't', 'true', 'yes', 'on'], true);
                    } else {
                        $user[$key] = false;
                    }
                }
            }
            
            $response = json_encode(['success' => true, 'user' => $user], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($response === false) {
                $error_msg = json_last_error_msg();
                echo json_encode(['success' => false, 'message' => 'Erro ao serializar JSON: ' . $error_msg], JSON_UNESCAPED_UNICODE);
            } else {
                echo $response;
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Usuário não encontrado'], JSON_UNESCAPED_UNICODE);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Ação: Excluir usuário
if ($action === 'delete' && $user_id > 0) {
    try {
        // Verificar se não é o próprio usuário logado
        if ($user_id == ($_SESSION['usuario_id'] ?? 0)) {
            $error_message = "Você não pode excluir seu próprio usuário!";
        } else {
            $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = :id");
            $stmt->execute([':id' => $user_id]);
            
            if ($stmt->rowCount() > 0) {
                header('Location: index.php?page=usuarios&success=' . urlencode('Usuário excluído com sucesso!'));
                exit;
            } else {
                $error_message = "Usuário não encontrado.";
            }
        }
    } catch (Exception $e) {
        $error_message = "Erro ao excluir usuário: " . $e->getMessage();
    }
}

if ($action === 'save') {
    try {
        $nome = trim($_POST['nome'] ?? '');
        $login = trim($_POST['login'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $senha = trim($_POST['senha'] ?? '');
        $cargo = trim($_POST['cargo'] ?? '');
        $cpf = trim($_POST['cpf'] ?? '');
        $admissao_data = $_POST['admissao_data'] ?? null;
        $salario_base = (float)($_POST['salario_base'] ?? 0);
        $pix_tipo = trim($_POST['pix_tipo'] ?? '');
        $pix_chave = trim($_POST['pix_chave'] ?? '');
        $status_empregado = $_POST['status_empregado'] ?? 'ativo';
        
        // Permissões
        $permissoes = [
            // Módulos da sidebar
            'perm_agenda' => isset($_POST['perm_agenda']) ? 1 : 0,
            'perm_comercial' => isset($_POST['perm_comercial']) ? 1 : 0,
            'perm_logistico' => isset($_POST['perm_logistico']) ? 1 : 0,
            'perm_configuracoes' => isset($_POST['perm_configuracoes']) ? 1 : 0,
            'perm_cadastros' => isset($_POST['perm_cadastros']) ? 1 : 0,
            'perm_financeiro' => isset($_POST['perm_financeiro']) ? 1 : 0,
            'perm_administrativo' => isset($_POST['perm_administrativo']) ? 1 : 0,
            'perm_rh' => isset($_POST['perm_rh']) ? 1 : 0,
            'perm_banco_smile' => isset($_POST['perm_banco_smile']) ? 1 : 0,
            'perm_banco_smile_admin' => isset($_POST['perm_banco_smile_admin']) ? 1 : 0,
            // Permissões específicas
            'perm_usuarios' => isset($_POST['perm_usuarios']) ? 1 : 0,
            'perm_pagamentos' => isset($_POST['perm_pagamentos']) ? 1 : 0,
            'perm_tarefas' => isset($_POST['perm_tarefas']) ? 1 : 0,
            'perm_demandas' => isset($_POST['perm_demandas']) ? 1 : 0,
            'perm_portao' => isset($_POST['perm_portao']) ? 1 : 0,
            'perm_notas_fiscais' => isset($_POST['perm_notas_fiscais']) ? 1 : 0,
            'perm_estoque_logistico' => isset($_POST['perm_estoque_logistico']) ? 1 : 0,
            'perm_dados_contrato' => isset($_POST['perm_dados_contrato']) ? 1 : 0,
            'perm_uso_fiorino' => isset($_POST['perm_uso_fiorino']) ? 1 : 0
        ];
        
        if ($user_id > 0) {
            // Editar usuário existente
            $sql = "UPDATE usuarios SET 
                    nome = :nome, login = :login, email = :email, cargo = :cargo,
                    cpf = :cpf, admissao_data = :admissao_data, salario_base = :salario_base,
                    pix_tipo = :pix_tipo, pix_chave = :pix_chave, status_empregado = :status_empregado";
            
            $params = [
                ':nome' => $nome, ':login' => $login, ':email' => $email, ':cargo' => $cargo,
                ':cpf' => $cpf, ':admissao_data' => $admissao_data, ':salario_base' => $salario_base,
                ':pix_tipo' => $pix_tipo, ':pix_chave' => $pix_chave, ':status_empregado' => $status_empregado
            ];
            
            if ($senha) {
                $sql .= ", senha = :senha";
                $params[':senha'] = password_hash($senha, PASSWORD_DEFAULT);
            }
            
            // Adicionar permissões
            foreach ($permissoes as $perm => $value) {
                $sql .= ", $perm = :$perm";
                $params[":$perm"] = $value;
            }
            
            $sql .= " WHERE id = :id";
            $params[':id'] = $user_id;
            
        } else {
            // Criar novo usuário
            if (!$senha) {
                throw new Exception("Senha é obrigatória para novos usuários");
            }
            
            $sql = "INSERT INTO usuarios (nome, login, email, senha, cargo, cpf, admissao_data, salario_base, pix_tipo, pix_chave, status_empregado";
            $values = "VALUES (:nome, :login, :email, :senha, :cargo, :cpf, :admissao_data, :salario_base, :pix_tipo, :pix_chave, :status_empregado";
            $params = [
                ':nome' => $nome, ':login' => $login, ':email' => $email, ':senha' => password_hash($senha, PASSWORD_DEFAULT),
                ':cargo' => $cargo, ':cpf' => $cpf, ':admissao_data' => $admissao_data, ':salario_base' => $salario_base,
                ':pix_tipo' => $pix_tipo, ':pix_chave' => $pix_chave, ':status_empregado' => $status_empregado
            ];
            
            foreach ($permissoes as $perm => $value) {
                $sql .= ", $perm";
                $values .= ", :$perm";
                $params[":$perm"] = $value;
            }
            
            $sql .= ") $values)";
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        // Redirecionar para evitar reenvio do formulário
        $redirectUrl = 'index.php?page=usuarios';
        if ($search) {
            $redirectUrl .= '&search=' . urlencode($search);
        }
        $redirectUrl .= '&success=' . urlencode($user_id > 0 ? "Usuário atualizado com sucesso!" : "Usuário criado com sucesso!");
        
        header('Location: ' . $redirectUrl);
        exit;
        
    } catch (Exception $e) {
        $error_message = "Erro: " . $e->getMessage();
    }
}

// Buscar mensagem de sucesso da URL
if (isset($_GET['success'])) {
    $success_message = $_GET['success'];
}

// Buscar usuários
$search = trim($_GET['search'] ?? '');
$where = [];
$params = [];

if ($search) {
    $where[] = "(nome ILIKE :search OR login ILIKE :search OR email ILIKE :search)";
    $params[':search'] = "%$search%";
}

$sql = "SELECT * FROM usuarios";
if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY nome ASC LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar usuário para edição
$usuario_edit = null;
if ($user_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $usuario_edit = $stmt->fetch(PDO::FETCH_ASSOC);
}

// INICIAR OUTPUT BUFFER para capturar todo o conteúdo
ob_start();
?>

<style>
        /* Controle de overflow sem quebrar o layout */
        html, body {
            overflow-x: hidden;
        }
        
        #pageContent,
        .main-content {
            overflow-x: hidden;
            box-sizing: border-box;
        }
        
        /* FORÇAR que pageContent e users-container respeitem a sidebar */
        #pageContent {
            position: relative !important;
            left: 0 !important;
            margin-left: 0 !important;
            padding-left: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
            z-index: 1 !important;
        }
        
        /* SOBRESCREVER sidebar_unified.php que tem left: 0 !important */
        /* Garantir que main-content está correto e acima da sidebar */
        .main-content#mainContent {
            position: relative !important;
            left: auto !important;
            margin-left: 280px !important;
            width: calc(100% - 280px) !important;
            z-index: 1 !important;
        }
        
        /* Também para classe genérica */
        .main-content {
            position: relative !important;
            left: auto !important;
            margin-left: 280px !important;
            width: calc(100% - 280px) !important;
            z-index: 1 !important;
        }
        
        @media (max-width: 768px) {
            .main-content#mainContent,
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
            }
        }
        
        /* Sidebar deve ter z-index maior para ficar acima */
        .sidebar {
            z-index: 1000 !important;
        }
        
        .users-container {
            max-width: 100%;
            margin: 0 auto;
            padding: 1.5rem;
            box-sizing: border-box;
            overflow-x: hidden;
            width: 100%;
            position: relative !important;
            left: 0 !important;
            margin-left: 0 !important;
            z-index: 1 !important;
        }
        
        @media (max-width: 1400px) {
            .users-container {
                padding: 1rem;
            }
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            overflow: hidden;
        }
        
        .page-title {
            font-size: 2rem;
            font-weight: 700;
            color: #1e3a8a;
            margin: 0;
        }
        
        .btn-primary {
            background: #1e3a8a;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background: #2563eb;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(30, 58, 138, 0.2);
        }
        
        .search-bar {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            align-items: center;
            background: white;
            padding: 1rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border: 1px solid #e5e7eb;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            overflow: hidden;
        }
        
        .search-input {
            flex: 1;
            padding: 0.75rem 1rem;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 0.875rem;
            transition: border-color 0.2s;
            min-width: 0;
            max-width: 100%;
            box-sizing: border-box;
        }
        
        .search-input:focus {
            outline: none;
            border-color: #1e3a8a;
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
        }
        
        .users-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.5rem;
            width: 100%;
            box-sizing: border-box;
            overflow: hidden;
        }
        
        @media (max-width: 1024px) {
            .users-grid {
                grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            }
        }
        
        @media (max-width: 768px) {
            .users-grid {
                grid-template-columns: 1fr;
            }
            
            .users-container {
                padding: 1rem;
            }
        }
        
        /* CARD SIMPLIFICADO */
        .user-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.25rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
            width: 100%;
            position: relative;
        }
        
        .user-card:hover {
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
            border-color: #1e3a8a;
        }
        
        .user-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .user-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #1e3a8a;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.125rem;
            flex-shrink: 0;
        }
        
        .user-info {
            flex: 1;
            min-width: 0;
        }
        
        .user-info h3 {
            margin: 0 0 0.25rem 0;
            font-size: 1rem;
            font-weight: 600;
            color: #1e293b;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .user-info p {
            margin: 0;
            color: #64748b;
            font-size: 0.8125rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .user-details {
            margin-bottom: 1rem;
            font-size: 0.8125rem;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            gap: 0.5rem;
        }
        
        .detail-label {
            color: #64748b;
            font-weight: 500;
            white-space: nowrap;
        }
        
        .detail-value {
            color: #1e293b;
            text-align: right;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 60%;
        }
        
        .permissions-section {
            margin-bottom: 1rem;
            padding-top: 0.75rem;
            border-top: 1px solid #e5e7eb;
        }
        
        .permissions-title {
            font-size: 0.6875rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            margin-bottom: 0.5rem;
        }
        
        .permissions-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.375rem;
        }
        
        .permission-item {
            display: flex;
            align-items: center;
            gap: 0.375rem;
            font-size: 0.6875rem;
            overflow: hidden;
        }
        
        .permission-item span:not(.permission-badge) {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .permission-badge {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #059669;
            flex-shrink: 0;
        }
        
        /* AÇÕES - BOTÕES NA PARTE INFERIOR DO CARD */
        .user-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: auto;
            padding-top: 1rem;
            border-top: 1px solid #e5e7eb;
            width: 100%;
            box-sizing: border-box;
            align-items: stretch;
        }
        
        .btn-edit {
            flex: 1;
            background: #1e3a8a;
            color: white;
            border: none;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s;
            white-space: nowrap;
            min-width: 0;
        }
        
        .btn-edit:hover {
            background: #2563eb;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(30, 58, 138, 0.25);
        }
        
        .btn-delete {
            background: #dc2626;
            color: white;
            border: none;
            padding: 0.75rem 1.25rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s;
            white-space: nowrap;
            flex-shrink: 0;
        }
        
        .btn-delete:hover {
            background: #b91c1c;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(220, 38, 38, 0.25);
        }
        
        .btn-edit span,
        .btn-delete span {
            display: inline-block;
            line-height: 1;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        
        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 1;
            visibility: visible;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 30px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-title {
            font-size: 24px;
            font-weight: 700;
            color: #1e3a8a;
            margin: 0;
        }
        
        .close-btn {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #6b7280;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        .form-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 5px;
        }
        
        .form-input {
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 16px;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .permissions-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }
        
        .permissions-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e3a8a;
            margin-bottom: 15px;
        }
        
        .permissions-grid-form {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }
        
        .permission-checkbox {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .permission-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #3b82f6;
        }
        
        .permission-checkbox label {
            font-size: 14px;
            color: #374151;
            cursor: pointer;
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }
        
        .btn-cancel {
            background: #6b7280;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
        }
        
        .btn-save {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
    </style>

<style>
        /* Remover regra que estava escondendo conteúdo */
        .users-container {
            visibility: visible !important;
            opacity: 1 !important;
        }
    </style>

<script>
        // Executar correção IMEDIATAMENTE
        (function() {
            // Executar logo que o DOM estiver disponível
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initLayout);
            } else {
                initLayout();
            }
            
            function initLayout() {
                // Executar múltiplas vezes para garantir
                forceOverflowFix();
                setTimeout(forceOverflowFix, 10);
                setTimeout(forceOverflowFix, 50);
                setTimeout(forceOverflowFix, 100);
                setTimeout(forceOverflowFix, 200);
                
                document.body.classList.add('sidebar-ready');
            }
        })();
        
        // Função para verificar e corrigir layout
        function forceOverflowFix() {
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            const pageContent = document.querySelector('#pageContent');
            const usersContainer = document.querySelector('.users-container');
            
            // DEBUG: Log informações
            console.log('=== DEBUG LAYOUT ===');
            console.log('Sidebar encontrada:', !!sidebar);
            console.log('Main-content encontrado:', !!mainContent);
            console.log('PageContent encontrado:', !!pageContent);
            console.log('Users-container encontrado:', !!usersContainer);
            
            if (sidebar) {
                const sidebarWidth = sidebar.offsetWidth;
                const sidebarRect = sidebar.getBoundingClientRect();
                const sidebarStyle = window.getComputedStyle(sidebar);
                console.log('Sidebar width:', sidebarWidth);
                console.log('Sidebar position:', sidebarStyle.position);
                console.log('Sidebar left:', sidebarRect.left);
                console.log('Sidebar right:', sidebarRect.right);
            }
            
            if (mainContent) {
                const mainRect = mainContent.getBoundingClientRect();
                const mainStyle = window.getComputedStyle(mainContent);
                console.log('Main-content margin-left:', mainStyle.marginLeft);
                console.log('Main-content width:', mainStyle.width);
                console.log('Main-content left:', mainRect.left);
                console.log('Main-content right:', mainRect.right);
                console.log('Main-content position:', mainStyle.position);
                
                // FORÇAR correção se necessário
                if (sidebar && parseFloat(mainStyle.marginLeft) < 250) {
                    console.warn('⚠️ margin-left muito pequeno, corrigindo...');
                    mainContent.style.setProperty('margin-left', '280px', 'important');
                    mainContent.style.setProperty('width', 'calc(100% - 280px)', 'important');
                }
                
                // Verificar se main-content está por baixo da sidebar
                if (sidebar) {
                    const sidebarRect = sidebar.getBoundingClientRect();
                    if (mainRect.left < sidebarRect.right) {
                        console.error('❌ Main-content está por baixo da sidebar!');
                        mainContent.style.setProperty('margin-left', '280px', 'important');
                        mainContent.style.setProperty('width', 'calc(100% - 280px)', 'important');
                        mainContent.style.setProperty('position', 'relative', 'important');
                        mainContent.style.setProperty('left', 'auto', 'important');
                    }
                }
            }
            
            if (pageContent) {
                const pageRect = pageContent.getBoundingClientRect();
                const pageStyle = window.getComputedStyle(pageContent);
                console.log('PageContent left:', pageRect.left);
                console.log('PageContent position:', pageStyle.position);
                console.log('PageContent margin:', pageStyle.margin);
                console.log('PageContent padding:', pageStyle.padding);
                
                // Forçar position relative se não estiver
                if (pageStyle.position === 'absolute' || pageStyle.position === 'fixed') {
                    console.warn('⚠️ PageContent com position absoluta/fixa, corrigindo...');
                    pageContent.style.setProperty('position', 'relative', 'important');
                    pageContent.style.setProperty('left', 'auto', 'important');
                    pageContent.style.setProperty('top', 'auto', 'important');
                }
                
                // Verificar se pageContent está por baixo da sidebar
                if (sidebar && pageRect.left < 280) {
                    console.error('❌ PageContent está por baixo da sidebar!');
                    console.log('PageContent parent:', pageContent.parentElement?.className);
                    
                    // Verificar se o main-content está correto
                    const mainContentParent = pageContent.closest('.main-content');
                    if (mainContentParent) {
                        const mainRect = mainContentParent.getBoundingClientRect();
                        console.log('Main-content parent rect:', mainRect);
                        if (mainRect.left < 280) {
                            console.error('❌ Main-content também está por baixo da sidebar!');
                            mainContentParent.style.setProperty('margin-left', '280px', 'important');
                            mainContentParent.style.setProperty('width', 'calc(100% - 280px)', 'important');
                            mainContentParent.style.setProperty('position', 'relative', 'important');
                            mainContentParent.style.setProperty('left', 'auto', 'important');
                        }
                    }
                    
                    pageContent.style.setProperty('position', 'relative', 'important');
                    pageContent.style.setProperty('left', 'auto', 'important');
                    pageContent.style.setProperty('margin-left', '0', 'important');
                    pageContent.style.setProperty('padding-left', '0', 'important');
                }
            }
            
            if (usersContainer) {
                const containerRect = usersContainer.getBoundingClientRect();
                const containerStyle = window.getComputedStyle(usersContainer);
                const sidebarRect = sidebar ? sidebar.getBoundingClientRect() : null;
                
                console.log('Container left:', containerRect.left);
                console.log('Container width:', containerRect.width);
                console.log('Container position:', containerStyle.position);
                
                if (sidebarRect) {
                    console.log('Sidebar right:', sidebarRect.right);
                    if (containerRect.left < sidebarRect.right) {
                        console.error('❌ Container está por baixo da sidebar!');
                        console.error('Container left:', containerRect.left, 'Sidebar right:', sidebarRect.right);
                        
                        // CORRIGIR FORÇANDO posicionamento relativo
                        usersContainer.style.setProperty('position', 'relative', 'important');
                        usersContainer.style.setProperty('left', 'auto', 'important');
                        usersContainer.style.setProperty('margin-left', 'auto', 'important');
                        
                        // Verificar o elemento pai e TODOS os ancestrais
                        let parent = usersContainer.parentElement;
                        let level = 0;
                        while (parent && level < 5) {
                            const parentRect = parent.getBoundingClientRect();
                            const parentStyle = window.getComputedStyle(parent);
                            console.log(`Parent level ${level}:`, parent.className || parent.id || parent.tagName);
                            console.log(`Parent ${level} left:`, parentRect.left);
                            console.log(`Parent ${level} position:`, parentStyle.position);
                            console.log(`Parent ${level} margin-left:`, parentStyle.marginLeft);
                            
                            if (sidebarRect && parentRect.left < sidebarRect.right) {
                                console.error(`❌ Parent level ${level} está por baixo da sidebar!`);
                                parent.style.setProperty('position', 'relative', 'important');
                                parent.style.setProperty('left', 'auto', 'important');
                                parent.style.setProperty('margin-left', '0', 'important');
                                parent.style.setProperty('padding-left', '0', 'important');
                                
                                // Se for pageContent, garantir que está dentro do main-content
                                if (parent.id === 'pageContent') {
                                    const mainContent = parent.parentElement;
                                    if (mainContent && mainContent.classList.contains('main-content')) {
                                        const mainRect = mainContent.getBoundingClientRect();
                                        console.log('Main-content rect:', mainRect);
                                        if (mainRect.left >= 280) {
                                            // Main-content está correto, pageContent deve herdar
                                            console.log('Main-content está correto, pageContent deve seguir');
                                        }
                                    }
                                }
                            }
                            
                            parent = parent.parentElement;
                            level++;
                        }
                    }
                }
            }
            
            // Garantir overflow-x
            if (mainContent) {
                mainContent.style.overflowX = 'hidden';
            }
            
            if (usersContainer) {
                usersContainer.style.overflowX = 'hidden';
            }
            
            document.body.style.overflowX = 'hidden';
            
            console.log('=== FIM DEBUG ===');
        }
        
        // Executar também após pequeno delay adicional
        window.addEventListener('load', function() {
            setTimeout(forceOverflowFix, 100);
        });
    </script>

        <div class="users-container">
            <!-- Header -->
            <div class="page-header">
            <div>
                <h1 class="page-title">👥 Usuários e Colaboradores</h1>
                <p style="color: #64748b; margin: 0.5rem 0 0 0; font-size: 0.875rem;">Gerencie usuários, permissões e colaboradores do sistema</p>
            </div>
            <button class="btn-primary" type="button" onclick="openModal(0)">
                    <span>➕</span>
                    Novo Usuário
                </button>
            </div>
            
            <!-- Mensagens -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    ✅ <?= h($success_message) ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="alert alert-error">
                    ❌ <?= h($error_message) ?>
                </div>
            <?php endif; ?>
            
            <!-- Barra de Pesquisa -->
            <div class="search-bar">
            <input type="text" class="search-input" placeholder="🔍 Pesquisar por nome, login ou email..." 
                       value="<?= h($search) ?>" onkeyup="searchUsers(this.value)">
            <button class="btn-primary" onclick="searchUsers()">Buscar</button>
            </div>
            
            <!-- Grid de Usuários -->
            <div class="users-grid">
                <?php foreach ($usuarios as $user): ?>
                    <div class="user-card">
                        <div class="user-header">
                            <div class="user-avatar">
                                <?= strtoupper(substr($user['nome'] ?? 'U', 0, 1)) ?>
                            </div>
                            <div class="user-info">
                                <h3><?= h($user['nome'] ?? 'Sem nome') ?></h3>
                                <p><?= h($user['login'] ?? 'Sem login') ?></p>
                            </div>
                        </div>
                        
                        <div class="user-details">
                            <div class="detail-row">
                                <span class="detail-label">📧 Email:</span>
                                <span class="detail-value"><?= h($user['email'] ?? '—') ?></span>
                            </div>
                            
                            <?php if (!empty($user['cargo'])): ?>
                            <div class="detail-row">
                                <span class="detail-label">💼 Cargo:</span>
                                <span class="detail-value"><?= h($user['cargo']) ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($user['admissao_data'])): ?>
                            <div class="detail-row">
                                <span class="detail-label">📅 Admissão:</span>
                                <span class="detail-value"><?= date('d/m/Y', strtotime($user['admissao_data'])) ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($user['salario_base'])): ?>
                            <div class="detail-row">
                                <span class="detail-label">💰 Salário:</span>
                                <span class="detail-value">R$ <?= number_format($user['salario_base'], 2, ',', '.') ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($user['cpf'])): ?>
                            <div class="detail-row">
                                <span class="detail-label">🆔 CPF:</span>
                                <span class="detail-value"><?= h($user['cpf']) ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($user['pix_chave'])): ?>
                            <div class="detail-row">
                                <span class="detail-label">📱 PIX:</span>
                                <span class="detail-value"><?= h($user['pix_chave']) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                            <?php
                            // Verificar se há permissões ativas
                            $permissoes_ativas = [];
                            $permissoes_nomes = [
                                // Módulos da sidebar
                                'perm_agenda' => 'Agenda',
                                'perm_comercial' => 'Comercial',
                                'perm_logistico' => 'Logístico',
                                'perm_configuracoes' => 'Configurações',
                                'perm_cadastros' => 'Cadastros',
                                'perm_financeiro' => 'Financeiro',
                                'perm_administrativo' => 'Administrativo',
                                'perm_rh' => 'RH',
                                'perm_banco_smile' => 'Banco Smile',
                                'perm_banco_smile_admin' => 'Banco Admin',
                                // Permissões específicas
                                'perm_usuarios' => 'Usuários',
                                'perm_pagamentos' => 'Pagamentos',
                                'perm_tarefas' => 'Tarefas',
                                'perm_demandas' => 'Demandas',
                                'perm_portao' => 'Portão',
                                'perm_notas_fiscais' => 'Notas Fiscais',
                                'perm_estoque_logistico' => 'Estoque',
                                'perm_dados_contrato' => 'Contratos',
                                'perm_uso_fiorino' => 'Fiorino'
                            ];
                            
                            foreach ($permissoes_nomes as $perm => $nome) {
                                if (!empty($user[$perm])) {
                                    $permissoes_ativas[] = $nome;
                                }
                            }
                            ?>
                            
                            <?php if (!empty($permissoes_ativas)): ?>
                            <div class="permissions-section">
                                <div class="permissions-title">Permissões Ativas</div>
                                <div class="permissions-grid">
                                    <?php foreach ($permissoes_ativas as $perm): ?>
                                <div class="permission-item">
                                            <span class="permission-badge"></span>
                                            <span><?= h($perm) ?></span>
                                </div>
                            <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="user-actions">
                            <button class="btn-edit" type="button" onclick="openModal(<?= $user['id'] ?>, event)">
                                <span>✏️</span>
                                <span>Editar</span>
                            </button>
                            <button class="btn-delete" type="button" onclick="deleteUser(<?= $user['id'] ?>)">
                                <span>🗑️</span>
                                <span>Excluir</span>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal -->
    <div class="modal" id="userModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="modalTitle">Novo Usuário</h2>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            
            <form method="POST" id="userForm">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="user_id" id="userId" value="0">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Nome Completo *</label>
                        <input type="text" name="nome" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Login *</label>
                        <input type="text" name="login" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Senha <?= $user_id > 0 ? '(deixe em branco para manter)' : '*' ?></label>
                        <input type="password" name="senha" class="form-input" <?= $user_id > 0 ? '' : 'required' ?>>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Cargo</label>
                        <input type="text" name="cargo" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">CPF</label>
                        <input type="text" name="cpf" class="form-input" placeholder="000.000.000-00">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Data de Admissão</label>
                        <input type="date" name="admissao_data" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Salário Base</label>
                        <input type="number" name="salario_base" class="form-input" step="0.01" min="0">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Tipo PIX</label>
                        <select name="pix_tipo" class="form-input">
                            <option value="">Selecione...</option>
                            <option value="CPF">CPF</option>
                            <option value="CNPJ">CNPJ</option>
                            <option value="EMAIL">Email</option>
                            <option value="TELEFONE">Telefone</option>
                            <option value="ALEATORIA">Aleatória</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Chave PIX</label>
                        <input type="text" name="pix_chave" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Status do Empregado</label>
                        <select name="status_empregado" class="form-input">
                            <option value="ativo">Ativo</option>
                            <option value="inativo">Inativo</option>
                        </select>
                    </div>
                </div>
                
                <div class="permissions-section">
                    <h3 class="permissions-title">🔐 Permissões do Sistema</h3>
                    <p style="color: #64748b; font-size: 0.875rem; margin-bottom: 1rem;">Módulos da Sidebar (correspondem aos itens do menu)</p>
                    <div class="permissions-grid-form">
                        <?php
                        $permissions_sidebar = [
                            'perm_agenda' => '📅 Agenda',
                            'perm_comercial' => '📋 Comercial',
                            'perm_logistico' => '📦 Logístico',
                            'perm_configuracoes' => '⚙️ Configurações',
                            'perm_cadastros' => '📝 Cadastros',
                            'perm_financeiro' => '💰 Financeiro',
                            'perm_administrativo' => '👥 Administrativo',
                            'perm_rh' => '👔 RH',
                            'perm_banco_smile' => '🏦 Banco Smile',
                            'perm_banco_smile_admin' => '🏦 Admin Banco Smile'
                        ];
                        
                        foreach ($permissions_sidebar as $perm => $label):
                        ?>
                            <div class="permission-checkbox">
                                <input type="checkbox" name="<?= $perm ?>" id="<?= $perm ?>" value="1">
                                <label for="<?= $perm ?>"><?= $label ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <h3 class="permissions-title" style="margin-top: 2rem;">🔧 Permissões Específicas</h3>
                    <div class="permissions-grid-form">
                        <?php
                        $permissions_especificas = [
                            'perm_usuarios' => '👥 Usuários',
                            'perm_pagamentos' => '💳 Pagamentos',
                            'perm_tarefas' => '📋 Tarefas',
                            'perm_demandas' => '📋 Demandas',
                            'perm_portao' => '🚪 Portão',
                            'perm_notas_fiscais' => '📄 Notas Fiscais',
                            'perm_estoque_logistico' => '📦 Estoque',
                            'perm_dados_contrato' => '📋 Dados Contrato',
                            'perm_uso_fiorino' => '🚐 Uso Fiorino'
                        ];
                        
                        foreach ($permissions_especificas as $perm => $label):
                        ?>
                            <div class="permission-checkbox">
                                <input type="checkbox" name="<?= $perm ?>" id="<?= $perm ?>" value="1">
                                <label for="<?= $perm ?>"><?= $label ?></label>
                            </div>
      <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal()">Cancelar</button>
                    <button type="submit" class="btn-save">💾 Salvar</button>
                </div>
            </form>
        </div>
</div>
    
    <script>
        // Abrir modal
        function openModal(userId = 0, evt = null) {
            // Prevenir navegação padrão se vier de um link
            if (evt) {
                evt.preventDefault();
                evt.stopPropagation();
            } else if (typeof event !== 'undefined') {
                event.preventDefault();
                event.stopPropagation();
            }
            
            const modal = document.getElementById('userModal');
            const form = document.getElementById('userForm');
            const title = document.getElementById('modalTitle');
            const userIdInput = document.getElementById('userId');
            
            // Esconder modal primeiro para evitar flash
            modal.style.display = 'none';
            
            if (userId > 0) {
                title.textContent = 'Editar Usuário';
                userIdInput.value = userId;
                loadUserData(userId);
            } else {
                title.textContent = 'Novo Usuário';
                userIdInput.value = 0;
                form.reset();
                // Limpar todos os checkboxes
                form.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
            }
            
            // Mostrar modal após pequeno delay para evitar flash
            setTimeout(() => {
                modal.style.display = 'flex';
                setTimeout(() => {
            modal.classList.add('active');
                }, 10);
            }, 10);
        }
        
        // Fechar modal
        function closeModal() {
            const modal = document.getElementById('userModal');
            modal.classList.remove('active');
            setTimeout(() => {
                modal.style.display = 'none';
            }, 300); // Esperar transição completar
        }
        
        // Carregar dados do usuário
        function loadUserData(userId) {
            if (userId > 0) {
                // Mostrar loading
                const form = document.getElementById('userForm');
                const originalContent = form.innerHTML;
                form.innerHTML = '<div style="padding: 2rem; text-align: center; color: #64748b;">Carregando dados do usuário...</div>';
                
                // Buscar dados do usuário via AJAX
                fetch('?action=get_user&id=' + userId, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'Cache-Control': 'no-cache'
                    },
                    cache: 'no-cache'
                })
                    .then(response => {
                        if (!response.ok) {
                            return response.text().then(text => {
                                console.error('Erro HTTP:', response.status, text);
                                throw new Error('Erro na resposta do servidor: ' + response.status);
                            });
                        }
                        return response.text().then(text => {
                            try {
                                return JSON.parse(text);
                            } catch (e) {
                                console.error('Erro ao parsear JSON:', text);
                                throw new Error('Resposta não é JSON válido');
                            }
                        });
                    })
                    .then(data => {
                        // Restaurar formulário - simplesmente restaurar o HTML
                        form.innerHTML = originalContent;
                        
                        // Re-attach event listeners se necessário (já estão no global scope)
                        
                        if (data.success && data.user) {
                            // Preencher formulário com dados do usuário
                            const user = data.user;
                            // Usar o formulário restaurado
                            const formToFill = restoredForm || document.getElementById('userForm');
                            
                            const userIdInput = formToFill.querySelector('#userId');
                            if (userIdInput) userIdInput.value = user.id || userId;
                            
                            const nomeInput = formToFill.querySelector('input[name="nome"]');
                            if (nomeInput) nomeInput.value = user.nome || '';
                            
                            const loginInput = formToFill.querySelector('input[name="login"]');
                            if (loginInput) loginInput.value = user.login || '';
                            
                            const emailInput = formToFill.querySelector('input[name="email"]');
                            if (emailInput) emailInput.value = user.email || '';
                            
                            const cargoInput = formToFill.querySelector('input[name="cargo"]');
                            if (cargoInput) cargoInput.value = user.cargo || '';
                            
                            const cpfInput = formToFill.querySelector('input[name="cpf"]');
                            if (cpfInput) cpfInput.value = user.cpf || '';
                            
                            const admissaoInput = formToFill.querySelector('input[name="admissao_data"]');
                            if (admissaoInput && user.admissao_data) {
                                // Converter data para formato YYYY-MM-DD se necessário
                                let dataStr = user.admissao_data;
                                if (dataStr.includes('/')) {
                                    const parts = dataStr.split('/');
                                    if (parts.length === 3) {
                                        dataStr = parts[2] + '-' + parts[1] + '-' + parts[0];
                                    }
                                }
                                admissaoInput.value = dataStr;
                            }
                            
                            const salarioInput = formToFill.querySelector('input[name="salario_base"]');
                            if (salarioInput) salarioInput.value = user.salario_base || '';
                            
                            const pixTipoInput = formToFill.querySelector('select[name="pix_tipo"]');
                            if (pixTipoInput) pixTipoInput.value = user.pix_tipo || '';
                            
                            const pixChaveInput = formToFill.querySelector('input[name="pix_chave"]');
                            if (pixChaveInput) pixChaveInput.value = user.pix_chave || '';
                            
                            const statusInput = formToFill.querySelector('select[name="status_empregado"]');
                            if (statusInput) statusInput.value = user.status_empregado || 'ativo';
                            
                            // Preencher permissões - todas as que começam com perm_
                            Object.keys(user).forEach(key => {
                                if (key.startsWith('perm_')) {
                                    const checkbox = formToFill.querySelector('input[name="' + key + '"]');
                                    if (checkbox) {
                                        checkbox.checked = (user[key] == 1 || user[key] === true || user[key] === '1');
                                    }
                                }
                            });
                            
                            // Garantir que todos os checkboxes de permissão sejam verificados corretamente
                            formToFill.querySelectorAll('input[type="checkbox"][name^="perm_"]').forEach(checkbox => {
                                const key = checkbox.name;
                                if (user[key] !== undefined) {
                                    checkbox.checked = (user[key] == 1 || user[key] === true || user[key] === '1');
                                }
                            });
                        } else {
                            alert('Erro ao carregar usuário: ' + (data.message || 'Usuário não encontrado'));
                            closeModal();
                        }
                    })
                    .catch(error => {
                        console.error('Erro na requisição:', error);
                        alert('Erro ao carregar dados do usuário. Por favor, tente novamente.');
                        closeModal();
                    });
            } else {
                // Limpar formulário para novo usuário
                const form = document.getElementById('userForm');
                if (form) {
                    form.reset();
                    const userIdInput = document.getElementById('userId');
                    if (userIdInput) userIdInput.value = '0';
                    // Limpar todos os checkboxes
                    form.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
                }
            }
        }
        
        // Pesquisar usuários
        function searchUsers(query = '') {
            if (query === '') {
                query = document.querySelector('.search-input').value;
            }
            window.location.href = '?search=' + encodeURIComponent(query);
        }
        
        // Excluir usuário
        function deleteUser(userId) {
            if (!confirm('Tem certeza que deseja excluir este usuário?\n\nEsta ação não pode ser desfeita.')) {
                return;
            }
            
            // Criar formulário para enviar requisição de exclusão
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete';
            form.appendChild(actionInput);
            
            const userIdInput = document.createElement('input');
            userIdInput.type = 'hidden';
            userIdInput.name = 'user_id';
            userIdInput.value = userId;
            form.appendChild(userIdInput);
            
            document.body.appendChild(form);
            form.submit();
        }
        
        // Fechar modal ao clicar fora (apenas no fundo, não no conteúdo)
        document.getElementById('userModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        // Prevenir fechamento do modal ao clicar no conteúdo
        const modalContent = document.querySelector('.modal-content');
        if (modalContent) {
            modalContent.addEventListener('click', function(e) {
            e.stopPropagation();
            });
        }
        
        // Prevenir submit padrão e fazer submit correto
        const userForm = document.getElementById('userForm');
        if (userForm) {
            userForm.addEventListener('submit', function(e) {
                // Permitir submit padrão - o PHP vai processar e redirecionar
                // Não usar preventDefault aqui, pois queremos o submit normal
            });
        }
        
        // Prevenir flash ao carregar página - esconder modal até que JS esteja pronto
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('userModal');
            if (modal) {
                modal.style.display = 'none';
            }
        });
    </script>

<?php
// Restaurar error_reporting antes de incluir sidebar
error_reporting(E_ALL);
@ini_set('display_errors', 0);

// IMPORTANTE: ob_start deve ter sido chamado antes
if (!ob_get_level()) {
    ob_start();
}

$conteudo = ob_get_clean();

includeSidebar('Usuários e Colaboradores');
// O sidebar_unified.php cria: <div class="main-content"><div id="pageContent">
// Então echo $conteudo vai dentro do #pageContent
echo $conteudo;
endSidebar();
?>