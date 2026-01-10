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
require_once __DIR__ . '/core/email_global_helper.php';
require_once __DIR__ . '/sidebar_integration.php';

$mensagem = '';
$erro = '';
$teste_resultado = null;

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
        // Verificar se Resend está configurado antes de salvar
        $resend_api_key = getenv('RESEND_API_KEY') ?: ($_ENV['RESEND_API_KEY'] ?? null);
        if (!$resend_api_key) {
            throw new Exception('RESEND_API_KEY não configurada. Configure no Railway antes de salvar as configurações.');
        }
        
        $email_remetente = trim($_POST['email_remetente'] ?? 'painelsmilenotifica@smileeventos.com.br');
        $email_administrador = trim($_POST['email_administrador'] ?? '');
        
        // Garantir que valores boolean sejam boolean verdadeiros (não strings vazias)
        $pref_contabilidade = isset($_POST['preferencia_notif_contabilidade']) ? (bool)true : (bool)false;
        $pref_sistema = isset($_POST['preferencia_notif_sistema']) ? (bool)true : (bool)false;
        $pref_financeiro = isset($_POST['preferencia_notif_financeiro']) ? (bool)true : (bool)false;
        $tempo_inatividade = (int)($_POST['tempo_inatividade_minutos'] ?? 10);
        
        if (empty($email_administrador)) {
            throw new Exception('E-mail do administrador é obrigatório');
        }
        
        if (empty($email_remetente)) {
            throw new Exception('E-mail remetente é obrigatório');
        }
        
        // Valores padrão para SMTP (mantidos para compatibilidade com banco, mas não são mais usados)
        $smtp_host = 'mail.smileeventos.com.br';
        $smtp_port = 465;
        $smtp_username = $email_remetente;
        $smtp_password = ''; // Não é mais usado
        $smtp_encryption = 'ssl';
        
        if ($config) {
            // Atualizar
            $stmt = $pdo->prepare("
                UPDATE sistema_email_config SET
                    smtp_host = :smtp_host,
                    smtp_port = :smtp_port,
                    smtp_username = :smtp_username,
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
            $stmt->bindValue(':smtp_host', $smtp_host);
            $stmt->bindValue(':smtp_port', $smtp_port, PDO::PARAM_INT);
            $stmt->bindValue(':smtp_username', $smtp_username);
            $stmt->bindValue(':smtp_encryption', $smtp_encryption);
            $stmt->bindValue(':email_remetente', $email_remetente);
            $stmt->bindValue(':email_administrador', $email_administrador);
            $stmt->bindValue(':pref_contabilidade', $pref_contabilidade, PDO::PARAM_BOOL);
            $stmt->bindValue(':pref_sistema', $pref_sistema, PDO::PARAM_BOOL);
            $stmt->bindValue(':pref_financeiro', $pref_financeiro, PDO::PARAM_BOOL);
            $stmt->bindValue(':tempo_inatividade', $tempo_inatividade, PDO::PARAM_INT);
            $stmt->bindValue(':id', $config['id'], PDO::PARAM_INT);
            $stmt->execute();
        } else {
            // Criar
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
            $stmt->bindValue(':smtp_host', $smtp_host);
            $stmt->bindValue(':smtp_port', $smtp_port, PDO::PARAM_INT);
            $stmt->bindValue(':smtp_username', $smtp_username);
            $stmt->bindValue(':smtp_password', $smtp_password);
            $stmt->bindValue(':smtp_encryption', $smtp_encryption);
            $stmt->bindValue(':email_remetente', $email_remetente);
            $stmt->bindValue(':email_administrador', $email_administrador);
            $stmt->bindValue(':pref_contabilidade', $pref_contabilidade, PDO::PARAM_BOOL);
            $stmt->bindValue(':pref_sistema', $pref_sistema, PDO::PARAM_BOOL);
            $stmt->bindValue(':pref_financeiro', $pref_financeiro, PDO::PARAM_BOOL);
            $stmt->bindValue(':tempo_inatividade', $tempo_inatividade, PDO::PARAM_INT);
            $stmt->execute();
        }
        
        $mensagem = 'Configuração salva com sucesso!';
        
        // Recarregar configuração
        $stmt = $pdo->query("SELECT * FROM sistema_email_config ORDER BY id DESC LIMIT 1");
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

// Processar teste de e-mail
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'testar_email') {
    try {
        $email_teste = trim($_POST['email_teste'] ?? '');
        
        if (empty($email_teste)) {
            throw new Exception('E-mail de teste é obrigatório');
        }
        
        if (!filter_var($email_teste, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('E-mail inválido');
        }
        
        // Carregar configuração atual
        $stmt = $pdo->query("SELECT * FROM sistema_email_config ORDER BY id DESC LIMIT 1");
        $config_teste = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$config_teste) {
            throw new Exception('Configuração de e-mail não encontrada. Salve as configurações primeiro.');
        }
        
        // Criar helper e enviar e-mail de teste
        $emailHelper = new EmailGlobalHelper();
        
        $assunto = 'Teste de E-mail - Portal Grupo Smile';
        $corpo = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { background: #f9fafb; padding: 30px; border-radius: 0 0 8px 8px; }
                .success-box { background: #d1fae5; border: 2px solid #10b981; padding: 20px; border-radius: 8px; margin: 20px 0; }
                .info-box { background: #f0f9ff; border-left: 4px solid #1e40af; padding: 15px; margin: 15px 0; }
                .footer { text-align: center; margin-top: 30px; color: #6b7280; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>✅ E-mail de Teste</h1>
                    <p>Portal Grupo Smile</p>
                </div>
                <div class="content">
                    <div class="success-box">
                        <h2 style="color: #065f46; margin: 0 0 10px 0;">🎉 E-mail Enviado com Sucesso!</h2>
                        <p style="color: #047857; margin: 0;">Se você recebeu este e-mail, a configuração do Resend está funcionando corretamente.</p>
                    </div>
                    
                    <div class="info-box">
                        <h3 style="color: #1e40af; margin: 0 0 10px 0;">📋 Informações da Configuração:</h3>
                        <p style="margin: 5px 0;"><strong>Serviço:</strong> Resend (API)</p>
                        <p style="margin: 5px 0;"><strong>Remetente:</strong> ' . htmlspecialchars($config_teste['email_remetente']) . '</p>
                        <p style="margin: 5px 0;"><strong>Data/Hora:</strong> ' . date('d/m/Y H:i:s') . '</p>
                    </div>
                    
                    <p>Este é um e-mail de teste enviado pelo sistema de configuração global de e-mail do Portal Grupo Smile.</p>
                    
                    <div class="footer">
                        <p>Portal Grupo Smile - Sistema de Gestão</p>
                        <p>Este é um e-mail automático, por favor não responda.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>';
        
        $enviado = $emailHelper->enviarEmail($email_teste, $assunto, $corpo, true);
        
        if ($enviado) {
            $teste_resultado = [
                'sucesso' => true,
                'mensagem' => 'E-mail de teste enviado com sucesso para: ' . htmlspecialchars($email_teste)
            ];
        } else {
            $teste_resultado = [
                'sucesso' => false,
                'mensagem' => 'Erro ao enviar e-mail de teste. Verifique se o Resend está configurado corretamente e os logs do sistema.'
            ];
        }
        
    } catch (Exception $e) {
        $teste_resultado = [
            'sucesso' => false,
            'mensagem' => 'Erro: ' . $e->getMessage()
        ];
    }
}

// Verificar se Resend está configurado
$resend_api_key = getenv('RESEND_API_KEY') ?: ($_ENV['RESEND_API_KEY'] ?? null);
$resend_configurado = !empty($resend_api_key);
$resend_sdk_disponivel = class_exists('\Resend\Resend');

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
        <h1>📧 E-mail Global - Configuração</h1>
    </div>
    
    <?php if ($mensagem): ?>
    <div class="alert alert-success">✅ <?= htmlspecialchars($mensagem) ?></div>
    <?php endif; ?>
    
    <?php if ($erro): ?>
    <div class="alert alert-error">❌ <?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>
    
    <!-- Status do Resend -->
    <div class="form-section" style="background: <?= $resend_configurado && $resend_sdk_disponivel ? '#d1fae5' : ($resend_configurado ? '#fef3c7' : '#fee2e2') ?>; border-left: 4px solid <?= $resend_configurado && $resend_sdk_disponivel ? '#059669' : ($resend_configurado ? '#f59e0b' : '#dc2626') ?>;">
        <h2 class="section-title">🚀 Resend (Sistema de E-mail)</h2>
        
        <?php if ($resend_configurado && $resend_sdk_disponivel): ?>
        <div style="padding: 1rem; background: white; border-radius: 8px; margin-bottom: 1rem;">
            <p style="color: #059669; font-weight: 600; margin-bottom: 0.5rem; font-size: 1.1rem;">✅ Resend configurado e pronto para uso!</p>
            <p style="color: #374151; font-size: 0.875rem;">O sistema está usando <strong>APENAS Resend</strong> para enviar e-mails. SMTP não é mais usado.</p>
        </div>
        <?php elseif ($resend_configurado && !$resend_sdk_disponivel): ?>
        <div style="padding: 1rem; background: white; border-radius: 8px; margin-bottom: 1rem;">
            <p style="color: #f59e0b; font-weight: 600; margin-bottom: 0.5rem;">⚠️ RESEND_API_KEY configurada, mas SDK não instalado</p>
            <p style="color: #374151; font-size: 0.875rem;">Execute no servidor: <code style="background: #f3f4f6; padding: 0.25rem 0.5rem; border-radius: 4px;">composer install</code></p>
        </div>
        <?php else: ?>
        <div style="padding: 1rem; background: white; border-radius: 8px; margin-bottom: 1rem;">
            <p style="color: #dc2626; font-weight: 600; margin-bottom: 0.5rem; font-size: 1.1rem;">❌ Resend NÃO configurado</p>
            <p style="color: #374151; font-size: 0.875rem; margin-bottom: 1rem;">
                <strong>O sistema usa APENAS Resend.</strong> É obrigatório configurar a variável de ambiente <code style="background: #f3f4f6; padding: 0.25rem 0.5rem; border-radius: 4px;">RESEND_API_KEY</code> no Railway.
            </p>
            <div style="background: #eff6ff; padding: 1rem; border-radius: 8px; border-left: 4px solid #3b82f6;">
                <p style="font-weight: 600; color: #1e40af; margin-bottom: 0.5rem;">📋 Como configurar:</p>
                <ol style="margin-left: 1.5rem; color: #374151; font-size: 0.875rem;">
                    <li>Acesse o painel do Railway</li>
                    <li>Vá em <strong>Variables</strong> (Variáveis de Ambiente)</li>
                    <li>Adicione: <code style="background: #f3f4f6; padding: 0.25rem 0.5rem; border-radius: 4px;">RESEND_API_KEY</code> = sua API key do Resend</li>
                    <li>Faça um novo deploy</li>
                </ol>
                <p style="margin-top: 1rem; color: #374151; font-size: 0.875rem;">
                    Veja instruções completas em: <code>CONFIGURAR_RESEND_RAILWAY.md</code>
                </p>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <form method="POST" class="form-section">
        <h2 class="section-title">📧 Configurações de E-mail</h2>
        
        <div class="form-group">
            <label class="form-label">E-mail Remetente</label>
            <input type="email" name="email_remetente" class="form-input" 
                   value="<?= htmlspecialchars($config['email_remetente'] ?? 'painelsmilenotifica@smileeventos.com.br') ?>" required>
            <div class="info-text">E-mail que aparecerá como remetente em todos os e-mails enviados</div>
        </div>
        
        <div class="form-group" style="margin-top: 2rem;">
            <label class="form-label">E-mail Principal do Administrador</label>
            <input type="email" name="email_administrador" class="form-input" 
                   value="<?= htmlspecialchars($config['email_administrador'] ?? '') ?>" required>
            <div class="info-text">E-mail que receberá as notificações do sistema</div>
        </div>
        
        <h2 class="section-title" style="margin-top: 2rem;">🔔 Preferências de Notificação</h2>
        
        <div class="form-group">
            <label class="form-label">E-mail Principal do Administrador</label>
            <input type="email" name="email_administrador" class="form-input" 
                   value="<?= htmlspecialchars($config['email_administrador'] ?? '') ?>" required>
            <div class="info-text">E-mail que receberá as notificações do sistema</div>
        </div>
        
        <div style="display: flex; flex-direction: column; gap: 0.75rem; margin-bottom: 1.5rem;">
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
        
        <div class="form-group">
            <label class="form-label">Tempo de Inatividade para Envio (minutos)</label>
            <input type="number" name="tempo_inatividade_minutos" class="form-input" 
                   value="<?= htmlspecialchars($config['tempo_inatividade_minutos'] ?? '10') ?>" 
                   min="1" max="60" required>
            <div class="info-text">Após este tempo sem novas ações, as notificações serão enviadas consolidadas</div>
        </div>
        
        <div style="margin-top: 2rem; display: flex; gap: 1rem;">
            <button type="submit" class="btn-primary">💾 Salvar Configurações</button>
        </div>
    </form>
    
    <!-- Seção de Teste de E-mail -->
    <?php if ($config): ?>
    <div class="form-section">
        <h2 class="section-title">🧪 Testar Configuração de E-mail</h2>
        
        <?php if ($teste_resultado): ?>
        <div class="alert <?= $teste_resultado['sucesso'] ? 'alert-success' : 'alert-error' ?>">
            <?= $teste_resultado['sucesso'] ? '✅' : '❌' ?> <?= htmlspecialchars($teste_resultado['mensagem']) ?>
        </div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="acao" value="testar_email">
            
            <div class="form-group">
                <label class="form-label">E-mail para Teste</label>
                <input type="email" name="email_teste" class="form-input" 
                       value="<?= htmlspecialchars($config['email_administrador'] ?? '') ?>" 
                       placeholder="Digite o e-mail para receber o teste" required>
                <div class="info-text">Um e-mail de teste será enviado para verificar se o Resend está funcionando corretamente.</div>
            </div>
            
            <div style="margin-top: 1.5rem;">
                <button type="submit" class="btn-primary" style="background: #10b981;">
                    📧 Enviar E-mail de Teste
                </button>
            </div>
        </form>
    </div>
    <?php else: ?>
    <div class="form-section">
        <div class="alert" style="background: #fef3c7; color: #92400e; border-left: 4px solid #f59e0b;">
            ⚠️ <strong>Salve as configurações primeiro</strong> para poder testar o envio de e-mail.
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
$conteudo = ob_get_clean();
includeSidebar('Configurações - E-mail Global');
echo $conteudo;
endSidebar();
?>
