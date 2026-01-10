<?php
// config_email_global.php — Configuração Global de E-mail (ETAPA 12)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['logado']) || empty($_SESSION['perm_administrativo'])) {
    header('Location: index.php?page=login');
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/sidebar_integration.php';

$mensagem = '';
$erro = '';

// Carregar configuração existente
$config = null;
try {
    $stmt = $pdo->query("SELECT * FROM sistema_email_config ORDER BY id DESC LIMIT 1");
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tabela pode não existir ainda
}

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $smtp_host = trim($_POST['smtp_host'] ?? 'mail.smileeventos.com.br');
        $smtp_port = (int)($_POST['smtp_port'] ?? 465);
        $smtp_username = trim($_POST['smtp_username'] ?? 'painelsmilenotifica@smileeventos.com.br');
        $smtp_password = trim($_POST['smtp_password'] ?? '');
        $smtp_encryption = trim($_POST['smtp_encryption'] ?? 'ssl');
        $email_remetente = trim($_POST['email_remetente'] ?? 'painelsmilenotifica@smileeventos.com.br');
        $email_administrador = trim($_POST['email_administrador'] ?? '');
        
        $pref_contabilidade = isset($_POST['preferencia_notif_contabilidade']) ? true : false;
        $pref_sistema = isset($_POST['preferencia_notif_sistema']) ? true : false;
        $pref_financeiro = isset($_POST['preferencia_notif_financeiro']) ? true : false;
        $tempo_inatividade = (int)($_POST['tempo_inatividade_minutos'] ?? 10);
        
        if (empty($email_administrador)) {
            throw new Exception('E-mail do administrador é obrigatório');
        }
        
        if ($config) {
            // Atualizar
            $stmt = $pdo->prepare("
                UPDATE sistema_email_config SET
                    smtp_host = :smtp_host,
                    smtp_port = :smtp_port,
                    smtp_username = :smtp_username,
                    smtp_password = COALESCE(NULLIF(:smtp_password, ''), smtp_password),
                    smtp_encryption = :smtp_encryption,
                    email_remetente = :email_remetente,
                    email_administrador = :email_administrador,
                    preferencia_notif_contabilidade = :pref_contabilidade,
                    preferencia_notif_sistema = :pref_sistema,
                    preferencia_notif_financeiro = :pref_financeiro,
                    tempo_inatividade_minutos = :tempo_inatividade,
                    atualizado_em = NOW()
                WHERE id = :id
            ");
            $params = [
                ':smtp_host' => $smtp_host,
                ':smtp_port' => $smtp_port,
                ':smtp_username' => $smtp_username,
                ':smtp_password' => $smtp_password,
                ':smtp_encryption' => $smtp_encryption,
                ':email_remetente' => $email_remetente,
                ':email_administrador' => $email_administrador,
                ':pref_contabilidade' => $pref_contabilidade,
                ':pref_sistema' => $pref_sistema,
                ':pref_financeiro' => $pref_financeiro,
                ':tempo_inatividade' => $tempo_inatividade,
                ':id' => $config['id']
            ];
            $stmt->execute($params);
        } else {
            // Criar
            if (empty($smtp_password)) {
                throw new Exception('Senha SMTP é obrigatória na primeira configuração');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO sistema_email_config (
                    smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption,
                    email_remetente, email_administrador,
                    preferencia_notif_contabilidade, preferencia_notif_sistema, preferencia_notif_financeiro,
                    tempo_inatividade_minutos
                ) VALUES (
                    :smtp_host, :smtp_port, :smtp_username, :smtp_password, :smtp_encryption,
                    :email_remetente, :email_administrador,
                    :pref_contabilidade, :pref_sistema, :pref_financeiro,
                    :tempo_inatividade
                )
            ");
            $stmt->execute([
                ':smtp_host' => $smtp_host,
                ':smtp_port' => $smtp_port,
                ':smtp_username' => $smtp_username,
                ':smtp_password' => $smtp_password,
                ':smtp_encryption' => $smtp_encryption,
                ':email_remetente' => $email_remetente,
                ':email_administrador' => $email_administrador,
                ':pref_contabilidade' => $pref_contabilidade,
                ':pref_sistema' => $pref_sistema,
                ':pref_financeiro' => $pref_financeiro,
                ':tempo_inatividade' => $tempo_inatividade
            ]);
        }
        
        $mensagem = 'Configuração salva com sucesso!';
        
        // Recarregar configuração
        $stmt = $pdo->query("SELECT * FROM sistema_email_config ORDER BY id DESC LIMIT 1");
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

ob_start();
?>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; }
.container { max-width: 900px; margin: 0 auto; padding: 2rem; }
.header {
    background: linear-gradient(135deg, #ec4899 0%, #db2777 100%);
    color: white;
    padding: 1.5rem 2rem;
    margin-bottom: 2rem;
    border-radius: 12px;
}
.header h1 { font-size: 1.5rem; font-weight: 700; }
.alert { padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; }
.alert-success { background: #d1fae5; color: #065f46; }
.alert-error { background: #fee2e2; color: #991b1b; }
.form-section {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.section-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #1e40af;
    margin-bottom: 1.5rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #e5e7eb;
}
.form-group {
    margin-bottom: 1.5rem;
}
.form-label {
    display: block;
    font-weight: 500;
    color: #374151;
    margin-bottom: 0.5rem;
}
.form-input, .form-select {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 1rem;
}
.form-input:focus, .form-select:focus {
    outline: none;
    border-color: #1e40af;
    box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
}
.checkbox-group {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.checkbox-group input[type="checkbox"] {
    width: auto;
}
.btn-primary {
    background: #1e40af;
    color: white;
    padding: 0.75rem 2rem;
    border: none;
    border-radius: 6px;
    font-weight: 500;
    cursor: pointer;
    font-size: 1rem;
}
.btn-primary:hover {
    background: #1e3a8a;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
}
.info-text {
    font-size: 0.875rem;
    color: #64748b;
    margin-top: 0.25rem;
}
</style>

<div class="container">
    <div class="header">
        <h1>📧 E-mail Global - Configuração SMTP</h1>
    </div>
    
    <?php if ($mensagem): ?>
    <div class="alert alert-success">✅ <?= htmlspecialchars($mensagem) ?></div>
    <?php endif; ?>
    
    <?php if ($erro): ?>
    <div class="alert alert-error">❌ <?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>
    
    <form method="POST" class="form-section">
        <!-- Configurações SMTP -->
        <h2 class="section-title">⚙️ Configurações SMTP</h2>
        
        <div class="form-group">
            <label class="form-label">E-mail Remetente</label>
            <input type="email" name="email_remetente" class="form-input" 
                   value="<?= htmlspecialchars($config['email_remetente'] ?? 'painelsmilenotifica@smileeventos.com.br') ?>" required>
        </div>
        
        <div class="form-group">
            <label class="form-label">Usuário SMTP</label>
            <input type="text" name="smtp_username" class="form-input" 
                   value="<?= htmlspecialchars($config['smtp_username'] ?? 'painelsmilenotifica@smileeventos.com.br') ?>" required>
        </div>
        
        <div class="form-group">
            <label class="form-label">Senha SMTP</label>
            <input type="password" name="smtp_password" class="form-input" 
                   placeholder="<?= $config ? 'Deixe em branco para não alterar' : 'Obrigatório' ?>" 
                   <?= $config ? '' : 'required' ?>>
        </div>
        
        <div class="form-group">
            <label class="form-label">Servidor SMTP</label>
            <input type="text" name="smtp_host" class="form-input" 
                   value="<?= htmlspecialchars($config['smtp_host'] ?? 'mail.smileeventos.com.br') ?>" required>
        </div>
        
        <div class="form-group">
            <label class="form-label">Porta SMTP</label>
            <input type="number" name="smtp_port" class="form-input" 
                   value="<?= htmlspecialchars($config['smtp_port'] ?? '465') ?>" required>
        </div>
        
        <div class="form-group">
            <label class="form-label">Tipo de Segurança</label>
            <select name="smtp_encryption" class="form-select" required>
                <option value="ssl" <?= ($config['smtp_encryption'] ?? 'ssl') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                <option value="tls" <?= ($config['smtp_encryption'] ?? '') === 'tls' ? 'selected' : '' ?>>TLS</option>
                <option value="none" <?= ($config['smtp_encryption'] ?? '') === 'none' ? 'selected' : '' ?>>Nenhuma</option>
            </select>
        </div>
        
        <!-- E-mail e Preferências -->
        <h2 class="section-title" style="margin-top: 2rem;">👤 E-mail e Preferências do Administrador</h2>
        
        <div class="form-group">
            <label class="form-label">E-mail Principal do Administrador</label>
            <input type="email" name="email_administrador" class="form-input" 
                   value="<?= htmlspecialchars($config['email_administrador'] ?? '') ?>" required>
            <div class="info-text">E-mail que receberá as notificações do sistema</div>
        </div>
        
        <div class="form-group">
            <label class="form-label">Preferências de Notificação</label>
            <div style="display: flex; flex-direction: column; gap: 0.75rem; margin-top: 0.5rem;">
                <div class="checkbox-group">
                    <input type="checkbox" name="preferencia_notif_contabilidade" id="pref_contabilidade" 
                           <?= ($config['preferencia_notif_contabilidade'] ?? true) ? 'checked' : '' ?>>
                    <label for="pref_contabilidade">Receber notificações da Contabilidade</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" name="preferencia_notif_sistema" id="pref_sistema" 
                           <?= ($config['preferencia_notif_sistema'] ?? true) ? 'checked' : '' ?>>
                    <label for="pref_sistema">Receber notificações do Sistema</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" name="preferencia_notif_financeiro" id="pref_financeiro" 
                           <?= ($config['preferencia_notif_financeiro'] ?? true) ? 'checked' : '' ?>>
                    <label for="pref_financeiro">Receber notificações Financeiras</label>
                </div>
            </div>
        </div>
        
        <div class="form-group">
            <label class="form-label">Tempo de Inatividade para Envio (minutos)</label>
            <input type="number" name="tempo_inatividade_minutos" class="form-input" 
                   value="<?= htmlspecialchars($config['tempo_inatividade_minutos'] ?? '10') ?>" 
                   min="1" max="60" required>
            <div class="info-text">Após este tempo sem novas ações, as notificações serão enviadas consolidadas</div>
        </div>
        
        <div style="margin-top: 2rem;">
            <button type="submit" class="btn-primary">💾 Salvar Configurações</button>
        </div>
    </form>
</div>

<?php
$conteudo = ob_get_clean();
includeSidebar('Configurações - E-mail Global');
echo $conteudo;
endSidebar();
?>
