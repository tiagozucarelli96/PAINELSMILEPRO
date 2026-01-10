<?php
// contabilidade.php — Página principal do módulo Contabilidade (Área Administrativa)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar permissão de administrador
if (empty($_SESSION['logado']) || empty($_SESSION['perm_administrativo'])) {
    header('Location: index.php?page=login');
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/sidebar_integration.php';

// Suprimir warnings durante renderização
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
@ini_set('display_errors', 0);

// Processar ações
$mensagem = '';
$erro = '';

// Salvar/Atualizar configuração de acesso
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'salvar_acesso') {
    try {
        $link_publico = trim($_POST['link_publico'] ?? '');
        $senha = trim($_POST['senha'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $status = trim($_POST['status'] ?? 'ativo');
        
        if (empty($link_publico)) {
            throw new Exception('Link público é obrigatório');
        }
        
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('E-mail válido é obrigatório');
        }
        
        // Verificar se já existe configuração
        $stmt = $pdo->query("SELECT id FROM contabilidade_acesso LIMIT 1");
        $existe = $stmt->fetch();
        
        if ($existe) {
            // Atualizar
            if (!empty($senha)) {
                $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    UPDATE contabilidade_acesso 
                    SET link_publico = :link, senha_hash = :senha, email = :email, 
                        status = :status, atualizado_em = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':link' => $link_publico,
                    ':senha' => $senha_hash,
                    ':email' => $email,
                    ':status' => $status,
                    ':id' => $existe['id']
                ]);
            } else {
                // Não atualizar senha se estiver vazia
                $stmt = $pdo->prepare("
                    UPDATE contabilidade_acesso 
                    SET link_publico = :link, email = :email, 
                        status = :status, atualizado_em = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':link' => $link_publico,
                    ':email' => $email,
                    ':status' => $status,
                    ':id' => $existe['id']
                ]);
            }
            $mensagem = 'Configuração atualizada com sucesso!';
        } else {
            // Criar novo
            if (empty($senha)) {
                throw new Exception('Senha é obrigatória na primeira configuração');
            }
            $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                INSERT INTO contabilidade_acesso (link_publico, senha_hash, email, status)
                VALUES (:link, :senha, :email, :status)
            ");
            $stmt->execute([
                ':link' => $link_publico,
                ':senha' => $senha_hash,
                ':email' => $email,
                ':status' => $status
            ]);
            $mensagem = 'Configuração criada com sucesso!';
        }
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

// Buscar configuração atual
$config_acesso = null;
try {
    $stmt = $pdo->query("SELECT * FROM contabilidade_acesso LIMIT 1");
    $config_acesso = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tabela pode não existir ainda
}

// Criar conteúdo da página usando output buffering
ob_start();
?>

<style>
/* Container Principal */
.contabilidade-container {
    width: 100%;
    max-width: 1400px;
    margin: 0 auto;
    padding: 1.5rem;
}

/* Header */
.contabilidade-header {
    text-align: center;
    margin-bottom: 2rem;
}

.contabilidade-header h1 {
    font-size: 2rem;
    font-weight: 700;
    color: #1e3a8a;
    margin: 0 0 0.5rem 0;
}

.contabilidade-header p {
    font-size: 1.125rem;
    color: #64748b;
    margin: 0;
}

/* Seção de Configuração */
.config-section {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e7eb;
}

.config-section-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: #1e3a8a;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.config-form {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-label {
    font-weight: 500;
    color: #374151;
    margin-bottom: 0.5rem;
}

.form-input {
    padding: 0.75rem;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 1rem;
    transition: all 0.2s;
}

.form-input:focus {
    outline: none;
    border-color: #1e40af;
    box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
}

.form-select {
    padding: 0.75rem;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 1rem;
    background: white;
    cursor: pointer;
}

.form-select:focus {
    outline: none;
    border-color: #1e40af;
    box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
}

.form-actions {
    display: flex;
    gap: 1rem;
    margin-top: 1.5rem;
}

.btn-primary {
    background: #1e40af;
    color: white;
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 6px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-primary:hover {
    background: #1e3a8a;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
}

.alert {
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 1.5rem;
}

.alert-success {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #a7f3d0;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

.info-box {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 8px;
    padding: 1rem;
    margin-top: 1rem;
}

.info-box-title {
    font-weight: 600;
    color: #1e40af;
    margin-bottom: 0.5rem;
}

.info-box-text {
    font-size: 0.875rem;
    color: #374151;
    line-height: 1.5;
}
</style>

<div class="contabilidade-container">
    <!-- Header -->
    <div class="contabilidade-header">
        <h1>📑 Contabilidade</h1>
        <p>Gestão completa do módulo de Contabilidade</p>
    </div>
    
    <!-- Mensagens -->
    <?php if ($mensagem): ?>
    <div class="alert alert-success">
        <strong>✅ Sucesso!</strong> <?= htmlspecialchars($mensagem) ?>
    </div>
    <?php endif; ?>
    
    <?php if ($erro): ?>
    <div class="alert alert-error">
        <strong>❌ Erro!</strong> <?= htmlspecialchars($erro) ?>
    </div>
    <?php endif; ?>
    
    <!-- Seção: Acesso da Contabilidade -->
    <div class="config-section">
        <h2 class="config-section-title">
            🔗 Acesso da Contabilidade
        </h2>
        
        <form method="POST" class="config-form">
            <input type="hidden" name="acao" value="salvar_acesso">
            
            <div class="form-group">
                <label class="form-label">Link Público de Acesso *</label>
                <input type="text" name="link_publico" class="form-input" 
                       value="<?= htmlspecialchars($config_acesso['link_publico'] ?? '/contabilidade') ?>" 
                       placeholder="/contabilidade" required>
                <small style="color: #64748b; font-size: 0.875rem; margin-top: 0.25rem;">
                    Exemplo: /contabilidade ou /contabilidade-externa
                </small>
            </div>
            
            <div class="form-group">
                <label class="form-label">Senha de Acesso *</label>
                <input type="password" name="senha" class="form-input" 
                       placeholder="<?= $config_acesso ? 'Deixe em branco para manter a atual' : 'Digite a senha' ?>">
                <small style="color: #64748b; font-size: 0.875rem; margin-top: 0.25rem;">
                    <?= $config_acesso ? 'Deixe em branco para manter a senha atual' : 'Senha será criptografada' ?>
                </small>
            </div>
            
            <div class="form-group">
                <label class="form-label">E-mail da Contabilidade *</label>
                <input type="email" name="email" class="form-input" 
                       value="<?= htmlspecialchars($config_acesso['email'] ?? '') ?>" 
                       placeholder="contabilidade@exemplo.com" required>
                <small style="color: #64748b; font-size: 0.875rem; margin-top: 0.25rem;">
                    E-mail para receber notificações
                </small>
            </div>
            
            <div class="form-group">
                <label class="form-label">Status do Acesso *</label>
                <select name="status" class="form-select" required>
                    <option value="ativo" <?= ($config_acesso['status'] ?? 'ativo') === 'ativo' ? 'selected' : '' ?>>
                        Ativo
                    </option>
                    <option value="inativo" <?= ($config_acesso['status'] ?? '') === 'inativo' ? 'selected' : '' ?>>
                        Inativo
                    </option>
                </select>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn-primary">
                    💾 Salvar Configuração
                </button>
            </div>
        </form>
        
        <?php if ($config_acesso): ?>
        <div class="info-box">
            <div class="info-box-title">ℹ️ Informações</div>
            <div class="info-box-text">
                <?php
                // Construir URL completa
                $protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'painelsmilepro-production.up.railway.app';
                $link_completo = $protocolo . '://' . $host . $config_acesso['link_publico'];
                ?>
                <div style="margin-bottom: 0.75rem;">
                    <strong>Link de acesso:</strong><br>
                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.5rem; padding: 0.75rem; background: white; border-radius: 6px; border: 1px solid #d1d5db;">
                        <input type="text" id="link_completo" value="<?= htmlspecialchars($link_completo) ?>" 
                               readonly style="flex: 1; border: none; background: transparent; font-family: monospace; font-size: 0.875rem; color: #374151;">
                        <button type="button" onclick="copiarLink()" 
                                style="background: #1e40af; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer; font-weight: 500; font-size: 0.875rem; white-space: nowrap;">
                            📋 Copiar
                        </button>
                    </div>
                </div>
                <div style="margin-bottom: 0.5rem;">
                    <strong>Status:</strong> <?= ucfirst($config_acesso['status']) ?>
                </div>
                <div>
                    <strong>Última atualização:</strong> <?= date('d/m/Y H:i', strtotime($config_acesso['atualizado_em'])) ?>
                </div>
            </div>
        </div>
        
        <script>
        function copiarLink() {
            const input = document.getElementById('link_completo');
            input.select();
            input.setSelectionRange(0, 99999); // Para mobile
            
            try {
                document.execCommand('copy');
                
                // Feedback visual
                const btn = event.target;
                const textoOriginal = btn.textContent;
                btn.textContent = '✅ Copiado!';
                btn.style.background = '#10b981';
                
                setTimeout(() => {
                    btn.textContent = textoOriginal;
                    btn.style.background = '#1e40af';
                }, 2000);
            } catch (err) {
                // Fallback para navegadores modernos
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(input.value).then(() => {
                        const btn = event.target;
                        const textoOriginal = btn.textContent;
                        btn.textContent = '✅ Copiado!';
                        btn.style.background = '#10b981';
                        
                        setTimeout(() => {
                            btn.textContent = textoOriginal;
                            btn.style.background = '#1e40af';
                        }, 2000);
                    });
                } else {
                    alert('Não foi possível copiar. Link: ' + input.value);
                }
            }
        }
        </script>
        <?php endif; ?>
    </div>
    
    <!-- Seção: Gestão Administrativa -->
    <div class="config-section" style="margin-top: 2rem;">
        <h2 class="config-section-title">
            🛠️ Gestão Administrativa
        </h2>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 1.5rem; margin-top: 1.5rem;">
            <!-- Card Guias -->
            <a href="index.php?page=contabilidade_admin_guias" style="text-decoration: none; color: inherit;">
                <div style="background: white; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1.5rem; transition: all 0.2s; cursor: pointer;" 
                     onmouseover="this.style.borderColor='#1e40af'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(0,0,0,0.1)'"
                     onmouseout="this.style.borderColor='#e5e7eb'; this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                    <div style="font-size: 2.5rem; margin-bottom: 0.75rem;">💰</div>
                    <div style="font-weight: 600; color: #1e40af; margin-bottom: 0.25rem;">Guias para Pagamento</div>
                    <div style="font-size: 0.875rem; color: #64748b;">Gerenciar guias</div>
                </div>
            </a>
            
            <!-- Card Holerites -->
            <a href="index.php?page=contabilidade_admin_holerites" style="text-decoration: none; color: inherit;">
                <div style="background: white; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1.5rem; transition: all 0.2s; cursor: pointer;"
                     onmouseover="this.style.borderColor='#1e40af'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(0,0,0,0.1)'"
                     onmouseout="this.style.borderColor='#e5e7eb'; this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                    <div style="font-size: 2.5rem; margin-bottom: 0.75rem;">📄</div>
                    <div style="font-weight: 600; color: #1e40af; margin-bottom: 0.25rem;">Holerites</div>
                    <div style="font-size: 0.875rem; color: #64748b;">Gerenciar holerites</div>
                </div>
            </a>
            
            <!-- Card Honorários -->
            <a href="index.php?page=contabilidade_admin_honorarios" style="text-decoration: none; color: inherit;">
                <div style="background: white; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1.5rem; transition: all 0.2s; cursor: pointer;"
                     onmouseover="this.style.borderColor='#1e40af'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(0,0,0,0.1)'"
                     onmouseout="this.style.borderColor='#e5e7eb'; this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                    <div style="font-size: 2.5rem; margin-bottom: 0.75rem;">💼</div>
                    <div style="font-weight: 600; color: #1e40af; margin-bottom: 0.25rem;">Honorários</div>
                    <div style="font-size: 0.875rem; color: #64748b;">Gerenciar honorários</div>
                </div>
            </a>
            
            <!-- Card Conversas -->
            <a href="index.php?page=contabilidade_admin_conversas" style="text-decoration: none; color: inherit;">
                <div style="background: white; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1.5rem; transition: all 0.2s; cursor: pointer;"
                     onmouseover="this.style.borderColor='#1e40af'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(0,0,0,0.1)'"
                     onmouseout="this.style.borderColor='#e5e7eb'; this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                    <div style="font-size: 2.5rem; margin-bottom: 0.75rem;">💬</div>
                    <div style="font-weight: 600; color: #1e40af; margin-bottom: 0.25rem;">Conversas</div>
                    <div style="font-size: 0.875rem; color: #64748b;">Gerenciar conversas</div>
                </div>
            </a>
            
            <!-- Card Colaboradores -->
            <a href="index.php?page=contabilidade_admin_colaboradores" style="text-decoration: none; color: inherit;">
                <div style="background: white; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1.5rem; transition: all 0.2s; cursor: pointer;"
                     onmouseover="this.style.borderColor='#1e40af'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(0,0,0,0.1)'"
                     onmouseout="this.style.borderColor='#e5e7eb'; this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                    <div style="font-size: 2.5rem; margin-bottom: 0.75rem;">👥</div>
                    <div style="font-weight: 600; color: #1e40af; margin-bottom: 0.25rem;">Colaboradores</div>
                    <div style="font-size: 0.875rem; color: #64748b;">Gerenciar documentos</div>
                </div>
            </a>
        </div>
    </div>
</div>

<?php
// Restaurar error_reporting antes de incluir sidebar
error_reporting(E_ALL);
@ini_set('display_errors', 0);

$conteudo = ob_get_clean();

// Verificar se houve algum erro no buffer
if (ob_get_level() > 0) {
    ob_end_clean();
}

includeSidebar('Contabilidade');
echo $conteudo;
endSidebar();
?>
