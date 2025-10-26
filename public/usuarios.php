<?php
// public/usuarios.php — Interface moderna de usuários com integração RH
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';
require_once __DIR__ . '/sidebar_unified.php';
require_once __DIR__ . '/helpers.php';

// Verificar permissões
if (empty($_SESSION['logado']) || empty($_SESSION['perm_usuarios'])) {
    http_response_code(403); 
    echo "Acesso negado."; 
    exit;
}

// Processar ações
$action = $_POST['action'] ?? '';
$user_id = (int)($_POST['user_id'] ?? $_GET['id'] ?? 0);

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
        
        $success_message = $user_id > 0 ? "Usuário atualizado com sucesso!" : "Usuário criado com sucesso!";
        
    } catch (Exception $e) {
        $error_message = "Erro: " . $e->getMessage();
    }
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
?>

<style>
        .users-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            margin-left: 0;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .page-title {
            font-size: 28px;
            font-weight: 700;
            color: #1e3a8a;
            margin: 0;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        .search-bar {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            align-items: center;
        }
        
        .search-input {
            flex: 1;
            padding: 12px 16px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 16px;
        }
        
        .users-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }
        
        .user-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            transition: all 0.2s;
        }
        
        .user-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }
        
        .user-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 18px;
        }
        
        .user-info h3 {
            margin: 0 0 5px 0;
            font-size: 18px;
            font-weight: 600;
            color: #1f2937;
        }
        
        .user-info p {
            margin: 0;
            color: #6b7280;
            font-size: 14px;
        }
        
        .user-details {
            margin-bottom: 15px;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .detail-label {
            color: #6b7280;
            font-weight: 500;
        }
        
        .detail-value {
            color: #1f2937;
        }
        
        .permissions-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
            margin-bottom: 15px;
        }
        
        .permission-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
        }
        
        .permission-badge {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #10b981;
        }
        
        .permission-badge.disabled {
            background: #e5e7eb;
        }
        
        .user-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-edit {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-delete {
            background: #ef4444;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
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
        }
        
        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
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

<div class="main-content">
        <div class="users-container">
            <!-- Header -->
            <div class="page-header">
                <h1 class="page-title">👥 Usuários e Colaboradores</h1>
                <button class="btn-primary" onclick="openModal()">
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
                <input type="text" class="search-input" placeholder="Pesquisar por nome, login ou email..." 
                       value="<?= h($search) ?>" onkeyup="searchUsers(this.value)">
                <button class="btn-primary" onclick="searchUsers()">🔍 Buscar</button>
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
                            <div class="detail-row">
                                <span class="detail-label">💼 Cargo:</span>
                                <span class="detail-value"><?= h($user['cargo'] ?? '—') ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">📅 Admissão:</span>
                                <span class="detail-value"><?= $user['admissao_data'] ? date('d/m/Y', strtotime($user['admissao_data'])) : '—' ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">💰 Salário:</span>
                                <span class="detail-value"><?= $user['salario_base'] ? 'R$ ' . number_format($user['salario_base'], 2, ',', '.') : '—' ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">📱 PIX:</span>
                                <span class="detail-value"><?= h($user['pix_chave'] ?? '—') ?></span>
                            </div>
                        </div>
                        
                        <div class="permissions-grid">
                            <?php
                            $permissions = [
                                'perm_usuarios' => '👥 Usuários',
                                'perm_pagamentos' => '💳 Pagamentos',
                                'perm_tarefas' => '📋 Tarefas',
                                'perm_demandas' => '📋 Demandas',
                                'perm_portao' => '🚪 Portão',
                                'perm_banco_smile' => '🏦 Banco',
                                'perm_banco_smile_admin' => '🏦 Admin',
                                'perm_notas_fiscais' => '📄 NF',
                                'perm_estoque_logistico' => '📦 Estoque',
                                'perm_dados_contrato' => '📋 Contrato',
                                'perm_uso_fiorino' => '🚐 Fiorino'
                            ];
                            
                            foreach ($permissions as $perm => $label):
                                $has_permission = ($user[$perm] ?? 0) == 1;
                            ?>
                                <div class="permission-item">
                                    <div class="permission-badge <?= $has_permission ? '' : 'disabled' ?>"></div>
                                    <span><?= $label ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="user-actions">
                            <a href="?id=<?= $user['id'] ?>" class="btn-edit" onclick="openModal(<?= $user['id'] ?>)">
                                ✏️ Editar
                            </a>
                            <button class="btn-delete" onclick="deleteUser(<?= $user['id'] ?>)">
                                🗑️ Excluir
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
                    <div class="permissions-grid-form">
                        <?php
                        $permissions = [
                            'perm_usuarios' => '👥 Usuários',
                            'perm_pagamentos' => '💳 Pagamentos',
                            'perm_tarefas' => '📋 Tarefas',
                            'perm_demandas' => '📋 Demandas',
                            'perm_portao' => '🚪 Portão',
                            'perm_banco_smile' => '🏦 Banco Smile',
                            'perm_banco_smile_admin' => '🏦 Admin Banco',
                            'perm_notas_fiscais' => '📄 Notas Fiscais',
                            'perm_estoque_logistico' => '📦 Estoque',
                            'perm_dados_contrato' => '📋 Dados Contrato',
                            'perm_uso_fiorino' => '🚐 Uso Fiorino'
                        ];
                        
                        foreach ($permissions as $perm => $label):
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
            }
            
            modal.classList.add('active');
        }
        
        // Fechar modal
        function closeModal() {
            document.getElementById('userModal').classList.remove('active');
        }
        
        // Carregar dados do usuário
        function loadUserData(userId) {
            if (userId > 0) {
                // Buscar dados do usuário via AJAX
                fetch('?action=get_user&id=' + userId)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Preencher formulário com dados do usuário
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
                            
                            // Preencher permissões
                            Object.keys(data.user).forEach(key => {
                                if (key.startsWith('perm_')) {
                                    const checkbox = document.querySelector('input[name="' + key + '"]');
                                    if (checkbox) {
                                        checkbox.checked = data.user[key] == 1;
                                    }
                                }
                            });
                        } else {
                            console.error('Erro ao carregar usuário:', data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Erro na requisição:', error);
                    });
            } else {
                // Limpar formulário para novo usuário
                document.getElementById('userForm').reset();
                document.getElementById('userId').value = '0';
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
            if (confirm('Tem certeza que deseja excluir este usuário?')) {
                // Implementar exclusão
                console.log('Excluindo usuário:', userId);
            }
        }
        
        // Fechar modal ao clicar fora (apenas no fundo, não no conteúdo)
        document.getElementById('userModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        // Prevenir fechamento do modal ao clicar no conteúdo
        document.querySelector('.modal-content').addEventListener('click', function(e) {
            e.stopPropagation();
        });
    </script>
<?php endSidebar(); ?>