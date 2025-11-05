<?php
/**
 * Sistema de Usuários - Versão Nova e Limpa
 * Refatorado completamente do zero
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';

// Garantir que $pdo está disponível
if (!isset($pdo)) {
    global $pdo;
}

// ============================================
// PROCESSAMENTO DE AÇÕES (ANTES DE QUALQUER OUTPUT)
// ============================================

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user_id = (int)($_POST['user_id'] ?? $_GET['id'] ?? 0);

// AJAX: Retornar dados do usuário
if ($action === 'get_user' && $user_id > 0) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    
    if (empty($_SESSION['logado']) || empty($_SESSION['perm_configuracoes'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Sessão expirada. Recarregue a página.']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = :id");
        $stmt->execute([':id' => $user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // Converter booleanos para true/false
            foreach ($user as $key => $value) {
                if (strpos($key, 'perm_') === 0) {
                    $user[$key] = (bool)($value ?? false);
                }
            }
            
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'user' => $user], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Usuário não encontrado']);
        }
    } catch (Exception $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
    }
    exit;
}

// Salvar usuário
if ($action === 'save') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    
    try {
        require_once __DIR__ . '/usuarios_save_robust.php';
        $manager = new UsuarioSaveManager($pdo);
        
        $data = $_POST;
        if (empty($data['login']) && !empty($data['email'])) {
            $data['login'] = $data['email'];
        }
        
        $result = $manager->save($data, $user_id);
        
        if ($result['success']) {
            $redirectUrl = 'index.php?page=usuarios&success=' . urlencode($user_id > 0 ? 'Usuário atualizado!' : 'Usuário criado!');
            header('Location: ' . $redirectUrl);
        } else {
            header('Location: index.php?page=usuarios&error=' . urlencode($result['message'] ?? 'Erro ao salvar'));
        }
    } catch (Exception $e) {
        header('Location: index.php?page=usuarios&error=' . urlencode('Erro: ' . $e->getMessage()));
    }
    exit;
}

// Excluir usuário
if ($action === 'delete' && $user_id > 0) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    
    try {
        if ($user_id == ($_SESSION['usuario_id'] ?? 0)) {
            header('Location: index.php?page=usuarios&error=' . urlencode('Você não pode excluir seu próprio usuário!'));
            exit;
        }
        
        $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = :id");
        $stmt->execute([':id' => $user_id]);
        
        header('Location: index.php?page=usuarios&success=' . urlencode('Usuário excluído com sucesso!'));
    } catch (Exception $e) {
        header('Location: index.php?page=usuarios&error=' . urlencode('Erro: ' . $e->getMessage()));
    }
    exit;
}

// ============================================
// VERIFICAÇÃO DE PERMISSÕES
// ============================================

if (empty($_SESSION['logado']) || empty($_SESSION['perm_configuracoes'])) {
    includeSidebar('Configurações');
    echo '<div style="padding: 2rem; text-align: center;">
            <h2 style="color: #dc2626;">Acesso Negado</h2>
            <p>Você não tem permissão para acessar esta página.</p>
            <a href="index.php?page=dashboard" style="color: #1e3a8a;">Voltar ao Dashboard</a>
          </div>';
    endSidebar();
    exit;
}

// ============================================
// BUSCAR USUÁRIOS
// ============================================

$search = trim($_GET['search'] ?? '');
$sql = "SELECT id, nome, login, email, cargo, ativo, created_at";
$params = [];

// Adicionar colunas de permissões se existirem
$perm_cols = ['perm_agenda', 'perm_comercial', 'perm_logistico', 'perm_configuracoes', 
              'perm_cadastros', 'perm_financeiro', 'perm_administrativo', 'perm_rh',
              'perm_banco_smile', 'perm_banco_smile_admin', 'perm_usuarios', 'perm_pagamentos',
              'perm_tarefas', 'perm_demandas', 'perm_portao', 'perm_notas_fiscais',
              'perm_estoque_logistico', 'perm_dados_contrato', 'perm_uso_fiorino'];

try {
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns 
                         WHERE table_schema = 'public' AND table_name = 'usuarios' 
                         AND column_name LIKE 'perm_%'");
    $existing_perms = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $existing_perms = array_flip($existing_perms);
    
    foreach ($perm_cols as $perm) {
        if (isset($existing_perms[$perm])) {
            $sql .= ", $perm";
        }
    }
} catch (Exception $e) {
    error_log("Erro ao verificar permissões: " . $e->getMessage());
}

$sql .= " FROM usuarios WHERE 1=1";

if ($search) {
    $sql .= " AND (nome ILIKE :search OR login ILIKE :search OR email ILIKE :search)";
    $params[':search'] = "%$search%";
}

$sql .= " ORDER BY nome ASC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $usuarios = [];
    $error_msg = "Erro ao buscar usuários: " . $e->getMessage();
}

// ============================================
// INICIAR OUTPUT
// ============================================

ob_start();
?>

<style>
    * {
        box-sizing: border-box;
    }
    
    .usuarios-page {
        padding: 2rem;
        max-width: 1400px;
        margin: 0 auto;
    }
    
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
        flex-wrap: wrap;
        gap: 1rem;
    }
    
    .page-title {
        font-size: 1.875rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
    }
    
    .page-subtitle {
        color: #64748b;
        font-size: 0.875rem;
        margin-top: 0.25rem;
    }
    
    .btn-primary {
        background: #1e3a8a;
        color: white;
        border: none;
        padding: 0.75rem 1.5rem;
        border-radius: 8px;
        font-size: 0.875rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .btn-primary:hover {
        background: #2563eb;
        transform: translateY(-1px);
    }
    
    .search-bar {
        display: flex;
        gap: 0.75rem;
        margin-bottom: 2rem;
    }
    
    .search-input {
        flex: 1;
        padding: 0.75rem 1rem;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 0.875rem;
    }
    
    .search-input:focus {
        outline: none;
        border-color: #1e3a8a;
        box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
    }
    
    .btn-search {
        background: #1e3a8a;
        color: white;
        border: none;
        padding: 0.75rem 1.5rem;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
    }
    
    .alert {
        padding: 1rem;
        border-radius: 8px;
        margin-bottom: 1.5rem;
    }
    
    .alert-success {
        background: #d1fae5;
        color: #065f46;
        border: 1px solid #6ee7b7;
    }
    
    .alert-error {
        background: #fee2e2;
        color: #991b1b;
        border: 1px solid #fca5a5;
    }
    
    .users-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 1.5rem;
    }
    
    .user-card {
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        transition: all 0.2s;
        display: flex;
        flex-direction: column;
    }
    
    .user-card:hover {
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        border-color: #1e3a8a;
    }
    
    .user-header {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid #e5e7eb;
    }
    
    .user-avatar {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        font-weight: 700;
        flex-shrink: 0;
    }
    
    .user-info h3 {
        margin: 0 0 0.25rem 0;
        font-size: 1.125rem;
        font-weight: 600;
        color: #1e293b;
    }
    
    .user-info p {
        margin: 0;
        color: #64748b;
        font-size: 0.875rem;
    }
    
    .user-details {
        flex: 1;
        margin-bottom: 1rem;
    }
    
    .detail-item {
        display: flex;
        justify-content: space-between;
        padding: 0.5rem 0;
        font-size: 0.875rem;
        border-bottom: 1px solid #f1f5f9;
    }
    
    .detail-item:last-child {
        border-bottom: none;
    }
    
    .detail-label {
        color: #64748b;
        font-weight: 500;
    }
    
    .detail-value {
        color: #1e293b;
        font-weight: 600;
    }
    
    .user-actions {
        display: flex;
        gap: 0.75rem;
        margin-top: auto;
        padding-top: 1rem;
        border-top: 1px solid #e5e7eb;
    }
    
    .btn-action {
        flex: 1;
        padding: 0.625rem 1rem;
        border: none;
        border-radius: 6px;
        font-size: 0.875rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }
    
    .btn-edit {
        background: #1e3a8a;
        color: white;
    }
    
    .btn-edit:hover {
        background: #2563eb;
    }
    
    .btn-delete {
        background: #dc2626;
        color: white;
    }
    
    .btn-delete:hover {
        background: #b91c1c;
    }
    
    /* Modal */
    .modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1000;
        align-items: center;
        justify-content: center;
        padding: 2rem;
    }
    
    .modal-overlay.active {
        display: flex;
    }
    
    .modal-content {
        background: white;
        border-radius: 12px;
        width: 100%;
        max-width: 600px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
    }
    
    .modal-header {
        padding: 1.5rem;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .modal-title {
        font-size: 1.25rem;
        font-weight: 600;
        color: #1e293b;
        margin: 0;
    }
    
    .modal-close {
        background: none;
        border: none;
        font-size: 1.5rem;
        color: #64748b;
        cursor: pointer;
        padding: 0;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 6px;
    }
    
    .modal-close:hover {
        background: #f1f5f9;
        color: #1e293b;
    }
    
    .modal-body {
        padding: 1.5rem;
    }
    
    .form-group {
        margin-bottom: 1.25rem;
    }
    
    .form-label {
        display: block;
        font-weight: 600;
        color: #374151;
        margin-bottom: 0.5rem;
        font-size: 0.875rem;
    }
    
    .form-input {
        width: 100%;
        padding: 0.75rem;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 0.875rem;
    }
    
    .form-input:focus {
        outline: none;
        border-color: #1e3a8a;
        box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
    }
    
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }
    
    .permissions-section {
        margin-top: 2rem;
        padding-top: 1.5rem;
        border-top: 1px solid #e5e7eb;
    }
    
    .permissions-title {
        font-size: 1rem;
        font-weight: 600;
        color: #1e293b;
        margin-bottom: 1rem;
    }
    
    .permissions-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 0.75rem;
    }
    
    .permission-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .permission-item input[type="checkbox"] {
        width: 18px;
        height: 18px;
        cursor: pointer;
    }
    
    .modal-footer {
        padding: 1.5rem;
        border-top: 1px solid #e5e7eb;
        display: flex;
        justify-content: flex-end;
        gap: 0.75rem;
    }
    
    .btn-secondary {
        background: #f1f5f9;
        color: #475569;
        border: none;
        padding: 0.75rem 1.5rem;
        border-radius: 6px;
        font-weight: 500;
        cursor: pointer;
    }
    
    .btn-secondary:hover {
        background: #e2e8f0;
    }
</style>

<div class="usuarios-page">
    <div class="page-header">
        <div>
            <h1 class="page-title">Usuários e Colaboradores</h1>
            <p class="page-subtitle">Gerencie usuários, permissões e colaboradores do sistema</p>
        </div>
        <button class="btn-primary" onclick="openModal(0)">
            <span>+</span>
            <span>Novo Usuário</span>
        </button>
    </div>
    
    <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success">
        <?= htmlspecialchars($_GET['success']) ?>
    </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-error">
        <?= htmlspecialchars($_GET['error']) ?>
    </div>
    <?php endif; ?>
    
    <form method="GET" action="index.php" class="search-bar">
        <input type="hidden" name="page" value="usuarios">
        <input type="text" name="search" class="search-input" 
               placeholder="Pesquisar por nome, login ou email..." 
               value="<?= htmlspecialchars($search) ?>">
        <button type="submit" class="btn-search">Buscar</button>
    </form>
    
    <div class="users-grid">
        <?php foreach ($usuarios as $user): 
            $permissoes_ativas = [];
            foreach ($perm_cols as $perm) {
                if (isset($existing_perms[$perm]) && !empty($user[$perm])) {
                    $permissoes_ativas[] = $perm;
                }
            }
        ?>
        <div class="user-card">
            <div class="user-header">
                <div class="user-avatar">
                    <?= strtoupper(substr($user['nome'] ?? 'U', 0, 1)) ?>
                </div>
                <div class="user-info">
                    <h3><?= h($user['nome'] ?? 'Sem nome') ?></h3>
                    <p><?= h($user['login'] ?? $user['email'] ?? 'Sem login') ?></p>
                </div>
            </div>
            
            <div class="user-details">
                <?php if (!empty($user['email'])): ?>
                <div class="detail-item">
                    <span class="detail-label">Email</span>
                    <span class="detail-value"><?= h($user['email']) ?></span>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($user['cargo'])): ?>
                <div class="detail-item">
                    <span class="detail-label">Cargo</span>
                    <span class="detail-value"><?= h($user['cargo']) ?></span>
                </div>
                <?php endif; ?>
                
                <div class="detail-item">
                    <span class="detail-label">Status</span>
                    <span class="detail-value" style="color: <?= ($user['ativo'] ?? true) ? '#059669' : '#dc2626' ?>">
                        <?= ($user['ativo'] ?? true) ? 'Ativo' : 'Inativo' ?>
                    </span>
                </div>
                
                <?php if (count($permissoes_ativas) > 0): ?>
                <div class="detail-item">
                    <span class="detail-label">Permissões</span>
                    <span class="detail-value"><?= count($permissoes_ativas) ?> ativas</span>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="user-actions">
                <button class="btn-action btn-edit" onclick="openModal(<?= $user['id'] ?>)">
                    <span>✏️</span>
                    <span>Editar</span>
                </button>
                <button class="btn-action btn-delete" onclick="deleteUser(<?= $user['id'] ?>)">
                    <span>🗑️</span>
                    <span>Excluir</span>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Modal -->
<div id="userModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title" id="modalTitle">Novo Usuário</h2>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form id="userForm" method="POST" action="index.php?page=usuarios">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="user_id" id="userId" value="0">
            
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Nome *</label>
                    <input type="text" name="nome" class="form-input" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Login *</label>
                        <input type="text" name="login" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email *</label>
                        <input type="email" name="email" class="form-input" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Senha <?= $user_id > 0 ? '(deixe em branco para não alterar)' : '*' ?></label>
                    <input type="password" name="senha" class="form-input" <?= $user_id > 0 ? '' : 'required' ?>>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Cargo</label>
                    <input type="text" name="cargo" class="form-input">
                </div>
                
                <?php
                // Verificar permissões existentes
                $all_perms = [
                    'perm_agenda' => '📅 Agenda',
                    'perm_comercial' => '📋 Comercial',
                    'perm_logistico' => '📦 Logístico',
                    'perm_configuracoes' => '⚙️ Configurações',
                    'perm_cadastros' => '📝 Cadastros',
                    'perm_financeiro' => '💰 Financeiro',
                    'perm_administrativo' => '👥 Administrativo',
                    'perm_rh' => '👔 RH',
                    'perm_banco_smile' => '🏦 Banco Smile',
                    'perm_banco_smile_admin' => '🏦 Admin Banco Smile',
                    'perm_usuarios' => '👥 Usuários',
                    'perm_pagamentos' => '💳 Pagamentos',
                    'perm_tarefas' => '📋 Tarefas',
                    'perm_demandas' => '📋 Demandas',
                    'perm_portao' => '🚪 Portão',
                    'perm_notas_fiscais' => '📄 Notas Fiscais',
                    'perm_estoque_logistico' => '📦 Estoque',
                    'perm_dados_contrato' => '📋 Contratos',
                    'perm_uso_fiorino' => '🚐 Fiorino'
                ];
                
                $available_perms = [];
                foreach ($all_perms as $perm => $label) {
                    if (isset($existing_perms[$perm])) {
                        $available_perms[$perm] = $label;
                    }
                }
                ?>
                
                <?php if (!empty($available_perms)): ?>
                <div class="permissions-section">
                    <h3 class="permissions-title">Permissões</h3>
                    <div class="permissions-grid">
                        <?php foreach ($available_perms as $perm => $label): ?>
                        <div class="permission-item">
                            <input type="checkbox" name="<?= $perm ?>" id="<?= $perm ?>" value="1">
                            <label for="<?= $perm ?>"><?= $label ?></label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal()">Cancelar</button>
                <button type="submit" class="btn-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(userId = 0) {
    const modal = document.getElementById('userModal');
    const form = document.getElementById('userForm');
    const title = document.getElementById('modalTitle');
    const userIdInput = document.getElementById('userId');
    
    userId = parseInt(userId) || 0;
    
    if (userId > 0) {
        title.textContent = 'Editar Usuário';
        userIdInput.value = userId;
        loadUserData(userId);
    } else {
        title.textContent = 'Novo Usuário';
        userIdInput.value = 0;
        form.reset();
        form.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
        modal.classList.add('active');
    }
}

function closeModal() {
    document.getElementById('userModal').classList.remove('active');
}

function loadUserData(userId) {
    fetch('index.php?page=usuarios&action=get_user&id=' + userId, {
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        },
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.user) {
            const user = data.user;
            const form = document.getElementById('userForm');
            
            form.querySelector('[name="nome"]').value = user.nome || '';
            form.querySelector('[name="login"]').value = user.login || '';
            form.querySelector('[name="email"]').value = user.email || '';
            form.querySelector('[name="cargo"]').value = user.cargo || '';
            
            // Permissões
            form.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                const name = cb.name;
                if (user[name]) {
                    cb.checked = true;
                }
            });
            
            document.getElementById('userModal').classList.add('active');
        } else {
            alert('Erro ao carregar usuário: ' + (data.message || 'Usuário não encontrado'));
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        alert('Erro ao carregar dados do usuário');
    });
}

function deleteUser(userId) {
    if (!confirm('Tem certeza que deseja excluir este usuário?\n\nEsta ação não pode ser desfeita.')) {
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'index.php?page=usuarios';
    
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

// Fechar modal ao clicar fora
document.getElementById('userModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});
</script>

<?php
$conteudo = ob_get_clean();
includeSidebar('Configurações');
echo $conteudo;
endSidebar();
?>

