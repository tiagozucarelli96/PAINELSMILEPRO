<?php
/**
 * eventos_fornecedores.php
 * Gerenciamento de fornecedores (DJ e Decoração)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';

// Verificar permissão
if (empty($_SESSION['perm_eventos']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

$user_id = $_SESSION['id'] ?? $_SESSION['user_id'] ?? 0;
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

$error = '';
$success = '';

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'salvar') {
        $tipo = $_POST['tipo'] ?? '';
        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telefone = trim($_POST['telefone'] ?? '');
        $login = trim($_POST['login'] ?? '');
        $senha = $_POST['senha'] ?? '';
        $ativo = isset($_POST['ativo']);
        
        if (!in_array($tipo, ['dj', 'decoracao'])) {
            $error = 'Tipo inválido';
        } elseif (!$nome) {
            $error = 'Nome é obrigatório';
        } elseif (!$login) {
            $error = 'Login é obrigatório';
        } else {
            try {
                if ($id > 0) {
                    // Atualizar
                    $sql = "UPDATE eventos_fornecedores SET 
                            tipo = :tipo, nome = :nome, email = :email, telefone = :telefone,
                            login = :login, ativo = :ativo, updated_at = NOW()";
                    $params = [
                        ':tipo' => $tipo,
                        ':nome' => $nome,
                        ':email' => $email,
                        ':telefone' => $telefone,
                        ':login' => $login,
                        ':ativo' => $ativo,
                        ':id' => $id
                    ];
                    
                    if ($senha) {
                        $sql .= ", senha_hash = :senha";
                        $params[':senha'] = password_hash($senha, PASSWORD_DEFAULT);
                    }
                    
                    $sql .= " WHERE id = :id";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $success = 'Fornecedor atualizado!';
                } else {
                    // Criar
                    if (!$senha) {
                        $error = 'Senha é obrigatória para novo fornecedor';
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO eventos_fornecedores (tipo, nome, email, telefone, login, senha_hash, ativo, created_by, created_at, updated_at)
                            VALUES (:tipo, :nome, :email, :telefone, :login, :senha, :ativo, :user_id, NOW(), NOW())
                        ");
                        $stmt->execute([
                            ':tipo' => $tipo,
                            ':nome' => $nome,
                            ':email' => $email,
                            ':telefone' => $telefone,
                            ':login' => $login,
                            ':senha' => password_hash($senha, PASSWORD_DEFAULT),
                            ':ativo' => $ativo,
                            ':user_id' => $user_id
                        ]);
                        $success = 'Fornecedor criado!';
                    }
                }
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'login') !== false) {
                    $error = 'Login já existe. Escolha outro.';
                } else {
                    $error = 'Erro ao salvar: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'excluir' && $id > 0) {
        try {
            $stmt = $pdo->prepare("DELETE FROM eventos_fornecedores WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $success = 'Fornecedor excluído!';
        } catch (Exception $e) {
            $error = 'Erro ao excluir: ' . $e->getMessage();
        }
    }
}

// Buscar fornecedores
$fornecedores = $pdo->query("
    SELECT * FROM eventos_fornecedores ORDER BY tipo, nome
")->fetchAll(PDO::FETCH_ASSOC);

// Agrupar por tipo
$djs = array_filter($fornecedores, fn($f) => $f['tipo'] === 'dj');
$decoradores = array_filter($fornecedores, fn($f) => $f['tipo'] === 'decoracao');
?>

<style>
    .fornecedores-container {
        padding: 2rem;
        max-width: 1200px;
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
        font-size: 1.5rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
    }
    
    .btn {
        padding: 0.625rem 1.25rem;
        border-radius: 8px;
        font-size: 0.875rem;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        text-decoration: none;
    }
    
    .btn-primary { background: #1e3a8a; color: white; }
    .btn-secondary { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
    .btn-danger { background: #dc2626; color: white; }
    .btn-sm { padding: 0.375rem 0.75rem; font-size: 0.75rem; }
    
    .alert {
        padding: 1rem;
        border-radius: 8px;
        margin-bottom: 1.5rem;
    }
    
    .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
    .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
    
    .section {
        background: white;
        border-radius: 12px;
        padding: 1.5rem;
        margin-bottom: 2rem;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }
    
    .section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid #e5e7eb;
    }
    
    .section-title {
        font-size: 1.125rem;
        font-weight: 600;
        color: #374151;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .fornecedor-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 1rem;
    }
    
    .fornecedor-card {
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 1rem;
        transition: all 0.2s;
    }
    
    .fornecedor-card:hover {
        border-color: #1e3a8a;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }
    
    .fornecedor-card.inactive {
        opacity: 0.6;
        background: #f8fafc;
    }
    
    .fornecedor-name {
        font-weight: 600;
        color: #1e293b;
        margin-bottom: 0.25rem;
    }
    
    .fornecedor-meta {
        font-size: 0.8rem;
        color: #64748b;
    }
    
    .fornecedor-login {
        font-size: 0.8rem;
        color: #1e3a8a;
        font-family: monospace;
        margin-top: 0.5rem;
    }
    
    .status-badge {
        display: inline-block;
        padding: 0.125rem 0.5rem;
        border-radius: 9999px;
        font-size: 0.7rem;
        font-weight: 600;
    }
    
    .status-ativo { background: #d1fae5; color: #065f46; }
    .status-inativo { background: #fee2e2; color: #991b1b; }
    
    .fornecedor-actions {
        display: flex;
        gap: 0.5rem;
        margin-top: 1rem;
    }
    
    /* Modal */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s;
    }
    
    .modal-overlay.show {
        opacity: 1;
        visibility: visible;
    }
    
    .modal-content {
        background: white;
        border-radius: 12px;
        width: 90%;
        max-width: 500px;
        max-height: 90vh;
        overflow-y: auto;
    }
    
    .modal-header {
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .modal-header h3 { margin: 0; font-size: 1.125rem; }
    .modal-close { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #64748b; }
    
    .modal-body { padding: 1.5rem; }
    
    .form-group { margin-bottom: 1rem; }
    .form-label { display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.375rem; color: #374151; }
    .form-input, .form-select {
        width: 100%;
        padding: 0.625rem;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 0.875rem;
    }
    .form-input:focus, .form-select:focus {
        outline: none;
        border-color: #1e3a8a;
        box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
    }
    
    .checkbox-group {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .portal-link {
        background: #eff6ff;
        border: 1px solid #bfdbfe;
        border-radius: 8px;
        padding: 1rem;
        margin-top: 1rem;
    }
    
    .portal-link h4 { margin: 0 0 0.5rem 0; font-size: 0.875rem; color: #1e40af; }
    .portal-link code {
        font-size: 0.8rem;
        background: white;
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        display: block;
        word-break: break-all;
    }
    
    @media (max-width: 768px) {
        .fornecedores-container { padding: 1rem; }
    }
</style>

<div class="fornecedores-container">
    <div class="page-header">
        <h1 class="page-title">👥 Fornecedores</h1>
        <div>
            <button type="button" class="btn btn-primary" onclick="abrirModal()">+ Novo Fornecedor</button>
            <a href="index.php?page=eventos" class="btn btn-secondary">← Voltar</a>
        </div>
    </div>
    
    <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    
    <!-- Links dos Portais -->
    <div class="portal-link">
        <h4>🔗 Links dos Portais Externos</h4>
        <p style="font-size: 0.875rem; color: #475569; margin-bottom: 0.5rem;">Compartilhe estes links com os fornecedores:</p>
        <code>Portal DJ: <?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] ?>/index.php?page=portal_dj_login</code>
        <code style="margin-top: 0.5rem;">Portal Decoração: <?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] ?>/index.php?page=portal_decoracao_login</code>
    </div>
    
    <!-- DJs -->
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">🎧 DJs</h2>
        </div>
        <?php if (empty($djs)): ?>
        <p style="color: #64748b;">Nenhum DJ cadastrado.</p>
        <?php else: ?>
        <div class="fornecedor-grid">
            <?php foreach ($djs as $f): ?>
            <div class="fornecedor-card <?= $f['ativo'] ? '' : 'inactive' ?>">
                <div class="fornecedor-name"><?= htmlspecialchars($f['nome']) ?></div>
                <div class="fornecedor-meta">
                    <?= htmlspecialchars($f['email'] ?: '-') ?> • <?= htmlspecialchars($f['telefone'] ?: '-') ?>
                </div>
                <div class="fornecedor-login">Login: <?= htmlspecialchars($f['login']) ?></div>
                <div style="margin-top: 0.5rem;">
                    <span class="status-badge <?= $f['ativo'] ? 'status-ativo' : 'status-inativo' ?>">
                        <?= $f['ativo'] ? 'Ativo' : 'Inativo' ?>
                    </span>
                </div>
                <div class="fornecedor-actions">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="editarFornecedor(<?= htmlspecialchars(json_encode($f)) ?>)">Editar</button>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Excluir este fornecedor?')">
                        <input type="hidden" name="action" value="excluir">
                        <input type="hidden" name="id" value="<?= $f['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Decoradores -->
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">🎨 Decoradores</h2>
        </div>
        <?php if (empty($decoradores)): ?>
        <p style="color: #64748b;">Nenhum decorador cadastrado.</p>
        <?php else: ?>
        <div class="fornecedor-grid">
            <?php foreach ($decoradores as $f): ?>
            <div class="fornecedor-card <?= $f['ativo'] ? '' : 'inactive' ?>">
                <div class="fornecedor-name"><?= htmlspecialchars($f['nome']) ?></div>
                <div class="fornecedor-meta">
                    <?= htmlspecialchars($f['email'] ?: '-') ?> • <?= htmlspecialchars($f['telefone'] ?: '-') ?>
                </div>
                <div class="fornecedor-login">Login: <?= htmlspecialchars($f['login']) ?></div>
                <div style="margin-top: 0.5rem;">
                    <span class="status-badge <?= $f['ativo'] ? 'status-ativo' : 'status-inativo' ?>">
                        <?= $f['ativo'] ? 'Ativo' : 'Inativo' ?>
                    </span>
                </div>
                <div class="fornecedor-actions">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="editarFornecedor(<?= htmlspecialchars(json_encode($f)) ?>)">Editar</button>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Excluir este fornecedor?')">
                        <input type="hidden" name="action" value="excluir">
                        <input type="hidden" name="id" value="<?= $f['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal -->
<div class="modal-overlay" id="modalFornecedor">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Novo Fornecedor</h3>
            <button type="button" class="modal-close" onclick="fecharModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" id="formFornecedor">
                <input type="hidden" name="action" value="salvar">
                <input type="hidden" name="id" id="fornecedorId" value="">
                
                <div class="form-group">
                    <label class="form-label">Tipo *</label>
                    <select name="tipo" id="fornecedorTipo" class="form-select" required>
                        <option value="">Selecione...</option>
                        <option value="dj">DJ</option>
                        <option value="decoracao">Decoração</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Nome *</label>
                    <input type="text" name="nome" id="fornecedorNome" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">E-mail</label>
                    <input type="email" name="email" id="fornecedorEmail" class="form-input">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Telefone</label>
                    <input type="text" name="telefone" id="fornecedorTelefone" class="form-input">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Login * (usado para acessar o portal)</label>
                    <input type="text" name="login" id="fornecedorLogin" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Senha <span id="senhaObrigatorio">*</span></label>
                    <input type="password" name="senha" id="fornecedorSenha" class="form-input">
                    <small id="senhaHint" style="color: #64748b; display: none;">Deixe em branco para manter a senha atual</small>
                </div>
                
                <div class="form-group">
                    <label class="checkbox-group">
                        <input type="checkbox" name="ativo" id="fornecedorAtivo" checked>
                        <span>Ativo</span>
                    </label>
                </div>
                
                <div style="display: flex; gap: 0.75rem; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="fecharModal()">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function abrirModal() {
    document.getElementById('modalTitle').textContent = 'Novo Fornecedor';
    document.getElementById('formFornecedor').reset();
    document.getElementById('fornecedorId').value = '';
    document.getElementById('fornecedorSenha').required = true;
    document.getElementById('senhaObrigatorio').style.display = 'inline';
    document.getElementById('senhaHint').style.display = 'none';
    document.getElementById('modalFornecedor').classList.add('show');
}

function editarFornecedor(f) {
    document.getElementById('modalTitle').textContent = 'Editar Fornecedor';
    document.getElementById('fornecedorId').value = f.id;
    document.getElementById('fornecedorTipo').value = f.tipo;
    document.getElementById('fornecedorNome').value = f.nome;
    document.getElementById('fornecedorEmail').value = f.email || '';
    document.getElementById('fornecedorTelefone').value = f.telefone || '';
    document.getElementById('fornecedorLogin').value = f.login;
    document.getElementById('fornecedorSenha').value = '';
    document.getElementById('fornecedorSenha').required = false;
    document.getElementById('fornecedorAtivo').checked = f.ativo;
    document.getElementById('senhaObrigatorio').style.display = 'none';
    document.getElementById('senhaHint').style.display = 'block';
    document.getElementById('modalFornecedor').classList.add('show');
}

function fecharModal() {
    document.getElementById('modalFornecedor').classList.remove('show');
}
</script>
