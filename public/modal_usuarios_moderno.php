<?php
// modal_usuarios_moderno.php — Modal moderno para usuários
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';

// Simular sessão de admin
$_SESSION['logado'] = 1;
$_SESSION['user_id'] = 1;
$_SESSION['perfil'] = 'ADM';
$_SESSION['perm_usuarios'] = 1;

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Processar ações AJAX
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user_id = (int)($_POST['user_id'] ?? $_GET['id'] ?? 0);

if ($action === 'get_user' && $user_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = :id");
        $stmt->execute([':id' => $user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            unset($user['senha']);
            echo json_encode(['success' => true, 'user' => $user]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Usuário não encontrado']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erro ao buscar usuário: ' . $e->getMessage()]);
    }
    exit;
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
        
        $permissoes = [
            'perm_usuarios' => isset($_POST['perm_usuarios']) ? 1 : 0,
            'perm_pagamentos' => isset($_POST['perm_pagamentos']) ? 1 : 0,
            'perm_tarefas' => isset($_POST['perm_tarefas']) ? 1 : 0,
            'perm_demandas' => isset($_POST['perm_demandas']) ? 1 : 0,
            'perm_portao' => isset($_POST['perm_portao']) ? 1 : 0,
            'perm_banco_smile' => isset($_POST['perm_banco_smile']) ? 1 : 0,
            'perm_banco_smile_admin' => isset($_POST['perm_banco_smile_admin']) ? 1 : 0,
            'perm_notas_fiscais' => isset($_POST['perm_notas_fiscais']) ? 1 : 0,
            'perm_estoque_logistico' => isset($_POST['perm_estoque_logistico']) ? 1 : 0,
            'perm_dados_contrato' => isset($_POST['perm_dados_contrato']) ? 1 : 0,
            'perm_uso_fiorino' => isset($_POST['perm_uso_fiorino']) ? 1 : 0
        ];
        
        if ($user_id > 0) {
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
            
            foreach ($permissoes as $perm => $value) {
                $sql .= ", $perm = :$perm";
                $params[":$perm"] = $value;
            }
            
            $sql .= " WHERE id = :id";
            $params[':id'] = $user_id;
            
        } else {
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
        
        echo json_encode(['success' => true, 'message' => ($user_id > 0 ? "Usuário atualizado com sucesso!" : "Usuário criado com sucesso!")]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erro ao salvar usuário: ' . $e->getMessage()]);
    }
    exit;
}

// Buscar usuários
$users = [];
try {
    $stmt = $pdo->query("SELECT id, nome, login, email, cargo, status_empregado FROM usuarios ORDER BY nome ASC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Erro ao buscar usuários: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuários - GRUPO Smile EVENTOS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            text-align: center;
        }
        
        .title {
            font-size: 2.5rem;
            font-weight: 800;
            color: #1e3a8a;
            margin-bottom: 10px;
        }
        
        .subtitle {
            font-size: 1.2rem;
            color: #6b7280;
        }
        
        .main-content {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 12px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4);
        }
        
        .users-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 10px;
        }
        
        .users-table th, .users-table td {
            padding: 15px;
            text-align: left;
        }
        
        .users-table th {
            background-color: #f8f9fa;
            color: #555;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 13px;
        }
        
        .users-table td {
            background-color: #ffffff;
            border-bottom: 1px solid #f0f0f0;
            font-size: 14px;
            color: #444;
        }
        
        .users-table tbody tr {
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        
        .users-table tbody tr:hover {
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .user-status {
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: 600;
            text-transform: capitalize;
        }
        
        .status-ativo { background-color: #d1fae5; color: #065f46; }
        .status-inativo { background-color: #fee2e2; color: #991b1b; }
        
        .user-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-edit, .btn-delete {
            padding: 8px 12px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: background-color 0.2s ease;
            border: none;
        }
        
        .btn-edit {
            background-color: #e0f2fe;
            color: #0284c7;
            border: 1px solid #a7e0ff;
        }
        
        .btn-edit:hover {
            background-color: #c7e7fe;
        }
        
        .btn-delete {
            background-color: #fee2e2;
            color: #dc2626;
            border: 1px solid #fca5a5;
        }
        
        .btn-delete:hover {
            background-color: #fecaca;
        }

        /* Modal Styles Modernos */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: all 0.3s ease;
        }

        .modal.active {
            display: flex;
            opacity: 1;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
            width: 90%;
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            transform: scale(0.9) translateY(20px);
            transition: all 0.3s ease;
        }

        .modal.active .modal-content {
            transform: scale(1) translateY(0);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 25px 30px;
            border-bottom: 1px solid #e5e7eb;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border-radius: 20px 20px 0 0;
        }

        .modal-title {
            font-size: 24px;
            font-weight: 700;
        }

        .close-btn {
            background: none;
            border: none;
            color: white;
            font-size: 30px;
            cursor: pointer;
            transition: opacity 0.2s ease;
        }

        .close-btn:hover {
            opacity: 0.7;
        }

        .modal-body {
            padding: 30px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
            font-size: 14px;
        }

        .form-input, .form-select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .permissions-section {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }

        .permissions-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e3a8a;
            margin-bottom: 15px;
        }

        .permissions-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }

        .permission-checkbox {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            border-radius: 8px;
            transition: background-color 0.2s ease;
        }

        .permission-checkbox:hover {
            background-color: #f8fafc;
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
            font-weight: 500;
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
            transition: background-color 0.2s ease;
        }
        
        .btn-cancel:hover {
            background: #4b5563;
        }

        .btn-save {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-save:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
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

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .permissions-grid {
                grid-template-columns: 1fr;
            }
            
            .modal-content {
                width: 95%;
                margin: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 class="title">👥 Usuários e Colaboradores</h1>
            <p class="subtitle">Gerencie usuários e permissões do sistema</p>
        </div>
        
        <div class="main-content">
            <div class="page-header">
                <h2>Lista de Usuários</h2>
                <button class="btn-primary" onclick="openModal()">
                    <span>➕</span>
                    Novo Usuário
                </button>
            </div>
            
            <table class="users-table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Login</th>
                        <th>Email</th>
                        <th>Cargo</th>
                        <th>Status</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 20px;">Nenhum usuário encontrado.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?= h($user['nome']) ?></td>
                                <td><?= h($user['login']) ?></td>
                                <td><?= h($user['email']) ?></td>
                                <td><?= h($user['cargo']) ?></td>
                                <td>
                                    <span class="user-status status-<?= h($user['status_empregado']) ?>">
                                        <?= h($user['status_empregado']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="user-actions">
                                        <button class="btn-edit" onclick="openModal(<?= $user['id'] ?>)">
                                            ✏️ Editar
                                        </button>
                                        <button class="btn-delete" onclick="deleteUser(<?= $user['id'] ?>)">
                                            🗑️ Excluir
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal -->
    <div class="modal" id="userModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="modalTitle">Novo Usuário</h2>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <form method="POST" id="userForm">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="user_id" id="userId" value="0">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="nome">Nome Completo *</label>
                            <input type="text" id="nome" name="nome" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="login">Login *</label>
                            <input type="text" id="login" name="login" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="email">Email</label>
                            <input type="email" id="email" name="email" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="senha">Senha *</label>
                            <input type="password" id="senha" name="senha" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="cargo">Cargo</label>
                            <input type="text" id="cargo" name="cargo" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="cpf">CPF</label>
                            <input type="text" id="cpf" name="cpf" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="admissao_data">Data de Admissão</label>
                            <input type="date" id="admissao_data" name="admissao_data" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="salario_base">Salário Base</label>
                            <input type="number" id="salario_base" name="salario_base" class="form-input" step="0.01">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="pix_tipo">Tipo PIX</label>
                            <input type="text" id="pix_tipo" name="pix_tipo" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="pix_chave">Chave PIX</label>
                            <input type="text" id="pix_chave" name="pix_chave" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="status_empregado">Status do Empregado</label>
                            <select id="status_empregado" name="status_empregado" class="form-select">
                                <option value="ativo">Ativo</option>
                                <option value="inativo">Inativo</option>
                                <option value="ferias">Férias</option>
                                <option value="licenca">Licença</option>
                            </select>
                        </div>
                    </div>

                    <div class="permissions-section">
                        <h3 class="permissions-title">Permissões</h3>
                        <div class="permissions-grid">
                            <div class="permission-checkbox">
                                <input type="checkbox" id="perm_usuarios" name="perm_usuarios" value="1">
                                <label for="perm_usuarios">Gerenciar Usuários</label>
                            </div>
                            <div class="permission-checkbox">
                                <input type="checkbox" id="perm_pagamentos" name="perm_pagamentos" value="1">
                                <label for="perm_pagamentos">Gerenciar Pagamentos</label>
                            </div>
                            <div class="permission-checkbox">
                                <input type="checkbox" id="perm_tarefas" name="perm_tarefas" value="1">
                                <label for="perm_tarefas">Gerenciar Tarefas</label>
                            </div>
                            <div class="permission-checkbox">
                                <input type="checkbox" id="perm_demandas" name="perm_demandas" value="1">
                                <label for="perm_demandas">Gerenciar Demandas</label>
                            </div>
                            <div class="permission-checkbox">
                                <input type="checkbox" id="perm_portao" name="perm_portao" value="1">
                                <label for="perm_portao">Acesso Portão</label>
                            </div>
                            <div class="permission-checkbox">
                                <input type="checkbox" id="perm_banco_smile" name="perm_banco_smile" value="1">
                                <label for="perm_banco_smile">Banco Smile</label>
                            </div>
                            <div class="permission-checkbox">
                                <input type="checkbox" id="perm_banco_smile_admin" name="perm_banco_smile_admin" value="1">
                                <label for="perm_banco_smile_admin">Banco Smile Admin</label>
                            </div>
                            <div class="permission-checkbox">
                                <input type="checkbox" id="perm_notas_fiscais" name="perm_notas_fiscais" value="1">
                                <label for="perm_notas_fiscais">Notas Fiscais</label>
                            </div>
                            <div class="permission-checkbox">
                                <input type="checkbox" id="perm_estoque_logistico" name="perm_estoque_logistico" value="1">
                                <label for="perm_estoque_logistico">Estoque Logístico</label>
                            </div>
                            <div class="permission-checkbox">
                                <input type="checkbox" id="perm_dados_contrato" name="perm_dados_contrato" value="1">
                                <label for="perm_dados_contrato">Dados Contrato</label>
                            </div>
                            <div class="permission-checkbox">
                                <input type="checkbox" id="perm_uso_fiorino" name="perm_uso_fiorino" value="1">
                                <label for="perm_uso_fiorino">Uso Fiorino</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn-cancel" onclick="closeModal()">Cancelar</button>
                        <button type="submit" class="btn-save">💾 Salvar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function openModal(userId = 0) {
            const modal = document.getElementById('userModal');
            const form = document.getElementById('userForm');
            const title = document.getElementById('modalTitle');
            const userIdInput = document.getElementById('userId');
            
            if (userId > 0) {
                title.textContent = 'Editar Usuário';
                userIdInput.value = userId;
                loadUserData(userId);
            } else {
                title.textContent = 'Novo Usuário';
                userIdInput.value = 0;
                form.reset();
                document.querySelectorAll('.permissions-grid input[type="checkbox"]').forEach(checkbox => {
                    checkbox.checked = false;
                });
            }
            
            modal.classList.add('active');
            setTimeout(() => {
                form.querySelector('input, select, textarea').focus();
            }, 300);
        }
        
        function closeModal() {
            const modal = document.getElementById('userModal');
            modal.classList.remove('active');
            const alertDiv = document.getElementById('formAlert');
            if (alertDiv) {
                alertDiv.remove();
            }
        }
        
        function loadUserData(userId) {
            if (userId > 0) {
                fetch('?action=get_user&id=' + userId)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            document.getElementById('userId').value = data.user.id;
                            document.querySelector('input[name="nome"]').value = data.user.nome || '';
                            document.querySelector('input[name="login"]').value = data.user.login || '';
                            document.querySelector('input[name="email"]').value = data.user.email || '';
                            document.querySelector('input[name="cargo"]').value = data.user.cargo || '';
                            document.querySelector('input[name="cpf"]').value = data.user.cpf || '';
                            document.querySelector('input[name="admissao_data"]').value = data.user.admissao_data || '';
                            document.querySelector('input[name="salario_base"]').value = data.user.salario_base || '';
                            document.querySelector('input[name="pix_tipo"]').value = data.user.pix_tipo || '';
                            document.querySelector('input[name="pix_chave"]').value = data.user.pix_chave || '';
                            document.querySelector('select[name="status_empregado"]').value = data.user.status_empregado || 'ativo';
                            
                            Object.keys(data.user).forEach(key => {
                                if (key.startsWith('perm_')) {
                                    const checkbox = document.querySelector('input[name="' + key + '"]');
                                    if (checkbox) {
                                        checkbox.checked = data.user[key] == 1;
                                    }
                                }
                            });
                        } else {
                            showAlert('error', 'Erro ao carregar dados do usuário: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Erro na requisição:', error);
                        showAlert('error', 'Erro de rede ao carregar dados do usuário.');
                    });
            } else {
                document.getElementById('userForm').reset();
                document.getElementById('userId').value = '0';
                document.querySelectorAll('.permissions-grid input[type="checkbox"]').forEach(checkbox => {
                    checkbox.checked = false;
                });
            }
        }

        function showAlert(type, message) {
            let alertDiv = document.getElementById('formAlert');
            if (!alertDiv) {
                alertDiv = document.createElement('div');
                alertDiv.id = 'formAlert';
                document.querySelector('.modal-body').prepend(alertDiv);
            }
            alertDiv.className = 'alert ' + (type === 'success' ? 'alert-success' : 'alert-error');
            alertDiv.textContent = message;
        }

        document.getElementById('userForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            fetch('?action=save', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', data.message);
                    setTimeout(() => {
                        closeModal();
                        location.reload();
                    }, 1500);
                } else {
                    showAlert('error', data.message);
                }
            })
            .catch(error => {
                console.error('Erro na requisição:', error);
                showAlert('error', 'Erro de rede ao salvar usuário.');
            });
        });
        
        document.getElementById('userModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        document.querySelector('.modal-content').addEventListener('click', function(e) {
            e.stopPropagation();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.getElementById('userModal').classList.contains('active')) {
                closeModal();
            }
        });

        function deleteUser(userId) {
            if (confirm('Tem certeza que deseja excluir este usuário?')) {
                console.log('Excluindo usuário:', userId);
                // Implementar exclusão
            }
        }
    </script>
</body>
</html>
