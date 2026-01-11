<?php
// debug_email_send.php — Diagnóstico completo de envio de e-mail (somente admin)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['logado']) || empty($_SESSION['perm_administrativo'])) {
    die('Acesso negado. Apenas administradores podem acessar este diagnóstico.');
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
// email_global_helper.php já carrega o autoload, não precisa carregar novamente
require_once __DIR__ . '/core/email_global_helper.php';
require_once __DIR__ . '/sidebar_integration.php';

header('Content-Type: text/html; charset=UTF-8');

// Função para registrar log no banco
function registrarLogEmail($pdo, $tipo, $mensagem, $detalhes = null) {
    try {
        // Verificar se tabela de logs existe
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sistema_email_logs (
                id SERIAL PRIMARY KEY,
                tipo VARCHAR(50) NOT NULL,
                mensagem TEXT NOT NULL,
                detalhes JSONB,
                criado_em TIMESTAMP DEFAULT NOW()
            )
        ");
        
        $stmt = $pdo->prepare("
            INSERT INTO sistema_email_logs (tipo, mensagem, detalhes)
            VALUES (:tipo, :mensagem, :detalhes)
        ");
        
        $stmt->execute([
            ':tipo' => $tipo,
            ':mensagem' => $mensagem,
            ':detalhes' => $detalhes ? json_encode($detalhes) : null
        ]);
    } catch (Exception $e) {
        error_log("Erro ao registrar log de email: " . $e->getMessage());
    }
}

$resultado_teste = null;
$config = null;
$validacao = [];

// Carregar configuração
try {
    $stmt = $pdo->query("SELECT * FROM sistema_email_config ORDER BY id DESC LIMIT 1");
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $validacao[] = ['tipo' => 'erro', 'mensagem' => 'Erro ao carregar configuração: ' . $e->getMessage()];
}

// Validar configuração
if ($config) {
    $campos_obrigatorios = [
        'email_remetente' => 'E-mail remetente',
        'email_administrador' => 'E-mail do administrador'
    ];
    
    foreach ($campos_obrigatorios as $campo => $nome) {
        if (empty($config[$campo])) {
            $validacao[] = ['tipo' => 'erro', 'mensagem' => "Campo obrigatório ausente: $nome ($campo)"];
        } else {
            $validacao[] = ['tipo' => 'ok', 'mensagem' => "Campo $nome: OK"];
        }
    }
    
    // Validar formato de email
    if (!empty($config['email_administrador']) && !filter_var($config['email_administrador'], FILTER_VALIDATE_EMAIL)) {
        $validacao[] = ['tipo' => 'erro', 'mensagem' => 'E-mail do administrador inválido'];
    }
    
    // Resend não requer validação de porta/host.
}

// Processar teste de envio
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'testar_envio') {
    // Limitar tempo de execução para não travar
    set_time_limit(30); // Máximo de 30 segundos
    
    try {
        if (!$config) {
            throw new Exception('Configuração não encontrada');
        }
        
        if (empty($config['email_administrador'])) {
            throw new Exception('E-mail do administrador não configurado');
        }
        
        // Verificar se Resend está configurado (prioridade 1) - mesma lógica do helper
        $resend_api_key = null;
        $env_getenv = getenv('RESEND_API_KEY');
        if ($env_getenv && !empty($env_getenv)) {
            $resend_api_key = $env_getenv;
        } elseif (isset($_ENV['RESEND_API_KEY']) && !empty($_ENV['RESEND_API_KEY'])) {
            $resend_api_key = $_ENV['RESEND_API_KEY'];
        } elseif (isset($_SERVER['RESEND_API_KEY']) && !empty($_SERVER['RESEND_API_KEY'])) {
            $resend_api_key = $_SERVER['RESEND_API_KEY'];
        } elseif (function_exists('apache_getenv')) {
            $env_apache = apache_getenv('RESEND_API_KEY');
            if ($env_apache && !empty($env_apache)) {
                $resend_api_key = $env_apache;
            }
        }
        
        // Usar EmailGlobalHelper que já tem a lógica correta (Resend)
        $email_helper = new EmailGlobalHelper();
        
        $assunto = 'Teste de Diagnóstico - Portal Grupo Smile';
        $corpo = '<html><body><h1>Teste de E-mail</h1><p>Este é um e-mail de teste enviado pelo sistema de diagnóstico.</p><p>Data/Hora: ' . date('d/m/Y H:i:s') . '</p><p>Método usado: Resend (API)</p></body></html>';
        
        $sucesso = $email_helper->enviarEmail($config['email_administrador'], $assunto, $corpo, true);
        
        if ($sucesso) {
            $resultado_teste = [
                'sucesso' => true,
                'mensagem' => 'E-mail enviado com sucesso!',
                'detalhes' => [
                    'para' => $config['email_administrador'],
                    'metodo' => 'Resend (API)',
                    'resend_configurado' => !empty($resend_api_key)
                ]
            ];
            
            registrarLogEmail($pdo, 'sucesso', 'E-mail de teste enviado com sucesso', [
                'para' => $config['email_administrador'],
                'metodo' => 'Resend'
            ]);
        } else {
            // Verificar se Resend está configurado para dar mensagem mais específica
            $resend_api_key = getenv('RESEND_API_KEY') 
                ?: ($_ENV['RESEND_API_KEY'] ?? null)
                ?: ($_SERVER['RESEND_API_KEY'] ?? null);
            
            if (!$resend_api_key) {
                throw new Exception('RESEND_API_KEY não configurada. O sistema requer Resend para enviar e-mails no Railway. Configure a variável de ambiente RESEND_API_KEY no Railway (Variables → + New Variable). Veja instruções em: CONFIGURAR_RESEND_RAILWAY.md');
            } else {
                throw new Exception('Falha ao enviar e-mail com Resend. Verifique os logs do sistema para mais detalhes. A API key foi detectada, mas o envio falhou. Verifique se a API key está correta e se o domínio remetente está verificado no Resend.');
            }
        }
        
    } catch (Exception $e) {
        $erro_detalhado = $e->getMessage();
        
        $resultado_teste = [
            'sucesso' => false,
            'mensagem' => 'Erro ao enviar e-mail',
            'erro' => $erro_detalhado
        ];
        
        // Registrar erro no log
        $detalhes_erro = [
            'erro' => $erro_detalhado,
            'resend_configurado' => !empty($resend_api_key ?? null)
        ];
        
        registrarLogEmail($pdo, 'erro', 'Falha ao enviar e-mail de teste', $detalhes_erro);
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnóstico de E-mail</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            padding: 2rem;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #1e3a8a;
            margin-bottom: 1rem;
        }
        .section {
            margin: 2rem 0;
            padding: 1.5rem;
            background: #f9fafb;
            border-radius: 8px;
        }
        .section h2 {
            color: #374151;
            margin-bottom: 1rem;
            font-size: 1.25rem;
        }
        .ok { color: #059669; }
        .erro { color: #dc2626; }
        .warning { color: #f59e0b; }
        .info { color: #3b82f6; }
        .validacao-item {
            padding: 0.5rem;
            margin: 0.25rem 0;
            border-left: 4px solid;
        }
        .validacao-item.ok { border-color: #059669; background: #d1fae5; }
        .validacao-item.erro { border-color: #dc2626; background: #fee2e2; }
        .resultado-box {
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
        }
        .resultado-box.sucesso {
            background: #d1fae5;
            border-left: 4px solid #059669;
        }
        .resultado-box.erro {
            background: #fee2e2;
            border-left: 4px solid #dc2626;
        }
        .config-table {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
        }
        .config-table th,
        .config-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        .config-table th {
            background: #f3f4f6;
            font-weight: 600;
            color: #374151;
        }
        .config-table .valor-sensivel {
            font-family: monospace;
            color: #6b7280;
        }
        button {
            background: #1e3a8a;
            color: white;
            padding: 0.75rem 2rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            margin-top: 1rem;
        }
        button:hover {
            background: #2563eb;
        }
        .code-block {
            background: #1f2937;
            color: #f9fafb;
            padding: 1rem;
            border-radius: 8px;
            font-family: monospace;
            font-size: 0.875rem;
            overflow-x: auto;
            margin: 1rem 0;
        }
        .info-box {
            background: #eff6ff;
            border-left: 4px solid #3b82f6;
            padding: 1rem;
            border-radius: 4px;
            margin: 1rem 0;
        }
        button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .loading {
            display: inline-block;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Diagnóstico de E-mail</h1>
        
        <?php if (!$config): ?>
        <div class="resultado-box erro">
            <h2>❌ Configuração não encontrada</h2>
            <p>Nenhuma configuração de e-mail foi encontrada no banco de dados.</p>
            <p><a href="index.php?page=config_email_global">Configurar E-mail Global</a></p>
        </div>
        <?php else: ?>
        
        <!-- Configuração Atual -->
        <div class="section">
            <h2>📋 Configuração Atual</h2>
            <table class="config-table">
                <tr>
                    <th>Campo</th>
                    <th>Valor</th>
                </tr>
                <tr>
                    <td>E-mail Remetente</td>
                    <td><?= htmlspecialchars($config['email_remetente'] ?? 'N/A') ?></td>
                </tr>
                <tr>
                    <td>E-mail Administrador</td>
                    <td><?= htmlspecialchars($config['email_administrador'] ?? 'N/A') ?></td>
                </tr>
            </table>
        </div>
        
        <!-- Validação -->
        <div class="section">
            <h2>✅ Validação de Campos</h2>
            <?php foreach ($validacao as $item): ?>
            <div class="validacao-item <?= $item['tipo'] ?>">
                <?= $item['tipo'] === 'ok' ? '✅' : '❌' ?> <?= htmlspecialchars($item['mensagem']) ?>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Verificação de Dependências -->
        <div class="section">
            <h2>📦 Verificação de Dependências</h2>
            <?php
            $autoload_existe = file_exists(__DIR__ . '/../vendor/autoload.php');
            
            // Tentar carregar autoload se ainda não foi carregado
            if ($autoload_existe && !isset($GLOBALS['autoload_carregado'])) {
                $autoload_path = __DIR__ . '/../vendor/autoload.php';
                if (file_exists($autoload_path)) {
                    require_once $autoload_path;
                    $GLOBALS['autoload_carregado'] = true;
                }
            }
            
            // Verificar classes (agora com autoload carregado, mas sem forçar autoload automático)
            $resend_disponivel = class_exists('Resend', false) || class_exists('\Resend\Resend', false);
            
            // Verificar RESEND_API_KEY em múltiplas fontes (Railway pode usar diferentes métodos)
            $resend_api_key = null;
            $fonte_detectada = null;
            
            // Método 1: getenv
            $env_getenv = getenv('RESEND_API_KEY');
            if ($env_getenv && !empty($env_getenv)) {
                $resend_api_key = $env_getenv;
                $fonte_detectada = 'getenv';
            }
            
            // Método 2: $_ENV
            if (!$resend_api_key && isset($_ENV['RESEND_API_KEY']) && !empty($_ENV['RESEND_API_KEY'])) {
                $resend_api_key = $_ENV['RESEND_API_KEY'];
                $fonte_detectada = '_ENV';
            }
            
            // Método 3: $_SERVER
            if (!$resend_api_key && isset($_SERVER['RESEND_API_KEY']) && !empty($_SERVER['RESEND_API_KEY'])) {
                $resend_api_key = $_SERVER['RESEND_API_KEY'];
                $fonte_detectada = '_SERVER';
            }
            
            // Método 4: apache_getenv (se Apache)
            if (!$resend_api_key && function_exists('apache_getenv')) {
                $env_apache = apache_getenv('RESEND_API_KEY');
                if ($env_apache && !empty($env_apache)) {
                    $resend_api_key = $env_apache;
                    $fonte_detectada = 'apache_getenv';
                }
            }
            
            // Debug: verificar se autoload existe mas classes não estão carregadas
            $vendor_path = __DIR__ . '/../vendor';
            $resend_path = $vendor_path . '/resend/resend-php';
            $resend_instalado = is_dir($resend_path);
            ?>
            <div class="validacao-item <?= $autoload_existe ? 'ok' : 'erro' ?>">
                <?= $autoload_existe ? '✅' : '❌' ?> vendor/autoload.php: <?= $autoload_existe ? 'Existe' : 'Não encontrado' ?>
            </div>
            <div class="validacao-item <?= $resend_disponivel ? 'ok' : 'erro' ?>">
                <?= $resend_disponivel ? '✅' : '❌' ?> Resend SDK: <?= $resend_disponivel ? 'Disponível' : 'Não disponível' ?>
            </div>
            <div class="validacao-item <?= $resend_api_key ? 'ok' : 'warning' ?>" style="border-color: <?= $resend_api_key ? '#059669' : '#f59e0b' ?>; background: <?= $resend_api_key ? '#d1fae5' : '#fef3c7' ?>;">
                <?= $resend_api_key ? '✅' : '⚠️' ?> RESEND_API_KEY: <?= $resend_api_key ? 'Configurada' : 'Não configurada (recomendado para Railway)' ?>
            </div>
            
            <?php if (!$resend_api_key): ?>
            <div class="info-box" style="background: #fee2e2; border-color: #dc2626; margin-top: 1rem;">
                <p><strong>🔍 Debug Detalhado - RESEND_API_KEY:</strong></p>
                <ul style="margin-left: 1.5rem; margin-top: 0.5rem; font-family: monospace; font-size: 0.875rem;">
                    <li>getenv('RESEND_API_KEY'): <?= getenv('RESEND_API_KEY') ? '✅ ' . substr(getenv('RESEND_API_KEY'), 0, 10) . '... (' . strlen(getenv('RESEND_API_KEY')) . ' chars)' : '❌ NULL' ?></li>
                    <li>$_ENV['RESEND_API_KEY']: <?= isset($_ENV['RESEND_API_KEY']) && !empty($_ENV['RESEND_API_KEY']) ? '✅ ' . substr($_ENV['RESEND_API_KEY'], 0, 10) . '... (' . strlen($_ENV['RESEND_API_KEY']) . ' chars)' : '❌ Não definido' ?></li>
                    <li>$_SERVER['RESEND_API_KEY']: <?= isset($_SERVER['RESEND_API_KEY']) && !empty($_SERVER['RESEND_API_KEY']) ? '✅ ' . substr($_SERVER['RESEND_API_KEY'], 0, 10) . '... (' . strlen($_SERVER['RESEND_API_KEY']) . ' chars)' : '❌ Não definido' ?></li>
                    <li>apache_getenv('RESEND_API_KEY'): <?= function_exists('apache_getenv') ? (apache_getenv('RESEND_API_KEY') ? '✅ ' . substr(apache_getenv('RESEND_API_KEY'), 0, 10) . '...' : '❌ NULL') : 'N/A (função não disponível)' ?></li>
                </ul>
                <p style="margin-top: 0.75rem; padding: 0.75rem; background: #fef3c7; border-radius: 4px;">
                    <strong>💡 Solução:</strong> Se a variável não está sendo detectada, faça um <strong>Redeploy</strong> no Railway após adicionar a variável. 
                    Às vezes o Railway precisa reiniciar o serviço para carregar novas variáveis de ambiente.
                </p>
                <p style="margin-top: 0.5rem; font-size: 0.875rem;">
                    <strong>Passos:</strong><br>
                    1. Railway → Seu Serviço → <strong>Settings</strong> → <strong>Restart</strong><br>
                    2. Ou: Railway → <strong>Deployments</strong> → <strong>Redeploy</strong>
                </p>
            </div>
            <?php else: ?>
            <div class="info-box" style="background: #d1fae5; border-color: #059669; margin-top: 0.5rem;">
                <p><strong>✅ RESEND_API_KEY detectada!</strong></p>
                <p style="font-family: monospace; font-size: 0.875rem; margin-top: 0.5rem;">
                    Fonte: <strong><?= $fonte_detectada ?? 'desconhecida' ?></strong><br>
                    Preview: <?= substr($resend_api_key, 0, 10) ?>...<?= substr($resend_api_key, -5) ?> (<?= strlen($resend_api_key) ?> caracteres)
                </p>
            </div>
            <?php endif; ?>
            
            <?php if ($autoload_existe && !$resend_disponivel): ?>
            <div class="info-box" style="background: #fef3c7; border-color: #f59e0b; margin-top: 1rem;">
                <p><strong>🔍 Diagnóstico de Instalação:</strong></p>
                <ul style="margin-left: 1.5rem; margin-top: 0.5rem;">
                    <li>vendor/autoload.php: <?= $autoload_existe ? '✅ Existe' : '❌ Não encontrado' ?></li>
                    <li>Resend SDK instalado: <?= $resend_instalado ? '✅ Sim' : '❌ Não' ?> (<?= $resend_path ?>)</li>
                    <li>Resend SDK carregado: <?= $resend_disponivel ? '✅ Sim' : '❌ Não (pode precisar recarregar autoload)' ?></li>
                </ul>
                <?php if ($resend_instalado && !$resend_disponivel): ?>
                <p style="margin-top: 0.75rem; padding: 0.75rem; background: #fee2e2; border-radius: 4px;">
                    <strong>⚠️ Problema detectado:</strong> As bibliotecas estão instaladas mas não estão sendo carregadas pelo autoload. 
                    Isso pode indicar que o autoload precisa ser regenerado. Execute no Railway:
                </p>
                <div class="code-block" style="margin-top: 0.5rem;">
composer dump-autoload --optimize
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <?php if ($resend_api_key && $resend_disponivel): ?>
            <div class="info-box" style="background: #d1fae5; border-color: #059669;">
                <p><strong>✅ Resend configurado!</strong></p>
                <p>O sistema usará Resend para enviar e-mails.</p>
            </div>
            <?php elseif ($resend_api_key && !$resend_disponivel): ?>
            <div class="info-box">
                <p><strong>⚠️ RESEND_API_KEY configurada, mas SDK não instalado</p>
                <p>Execute no servidor:</p>
                <div class="code-block">
composer install --no-dev --optimize-autoloader
                </div>
            </div>
            <?php elseif (!$resend_api_key): ?>
            <div class="info-box">
                <p><strong>💡 Recomendação para Railway:</strong></p>
                <p>Configure <code>RESEND_API_KEY</code> como variável de ambiente no Railway para usar Resend (funciona perfeitamente, sem bloqueio de portas).</p>
                <p style="margin-top: 0.5rem;">Veja instruções em: <code>CONFIGURAR_RESEND_RAILWAY.md</code></p>
            </div>
            <?php endif; ?>
            
            <?php if (!$autoload_existe || !$resend_disponivel): ?>
            <div class="info-box">
                <p><strong>⚠️ Ação necessária:</strong></p>
                <p>Execute no servidor:</p>
                <div class="code-block">
composer install --no-dev --optimize-autoloader
                </div>
                <p>Ou verifique se o Railway está executando o composer install no deploy.</p>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Resultado do Teste de Envio -->
        <?php if ($resultado_teste): ?>
        <div class="section">
            <h2>📧 Resultado do Teste de Envio</h2>
            <?php if ($resultado_teste['sucesso']): ?>
            <div class="resultado-box sucesso">
                <h3>✅ E-mail Enviado com Sucesso!</h3>
                <p><?= htmlspecialchars($resultado_teste['mensagem']) ?></p>
                <?php if (isset($resultado_teste['detalhes'])): ?>
                <ul style="margin-top: 1rem; margin-left: 1.5rem;">
                    <?php foreach ($resultado_teste['detalhes'] as $chave => $valor): ?>
                    <li><strong><?= htmlspecialchars($chave) ?>:</strong> <?= is_bool($valor) ? ($valor ? 'Sim' : 'Não') : htmlspecialchars($valor) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="resultado-box erro">
                <h3>❌ Erro ao Enviar E-mail</h3>
                <p><strong>Mensagem:</strong> <?= htmlspecialchars($resultado_teste['mensagem']) ?></p>
                <?php if (isset($resultado_teste['erro'])): ?>
                <p><strong>Erro detalhado:</strong></p>
                <div class="code-block" style="white-space: pre-wrap;">
<?= htmlspecialchars($resultado_teste['erro']) ?>
                </div>
                
                <div class="info-box" style="margin-top: 1rem;">
                    <p><strong>💡 Soluções possíveis:</strong></p>
                    <ul style="margin-left: 1.5rem; margin-top: 0.5rem;">
                        <li><strong>Domínio não verificado:</strong> Confirme se o remetente está validado no Resend.</li>
                        <li><strong>RESEND_FROM:</strong> Verifique se o remetente é um e-mail válido.</li>
                        <li><strong>API Key inválida:</strong> Gere uma nova chave e atualize no Railway.</li>
                        <li><strong>Rate limit:</strong> Aguarde alguns minutos e tente novamente.</li>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Formulário de Teste -->
        <div class="section">
            <h2>🧪 Testar Envio de E-mail</h2>
            <p>Este teste tentará enviar um e-mail para o endereço configurado do administrador.</p>
            <form method="POST">
                <input type="hidden" name="acao" value="testar_envio">
                <button type="submit">📧 Enviar E-mail de Teste</button>
            </form>
        </div>
        
        <?php endif; ?>
        
        <div style="margin-top: 2rem;">
            <a href="index.php?page=config_email_global" style="color: #1e3a8a; text-decoration: underline;">← Voltar para Configuração de E-mail</a>
        </div>
    </div>
</body>
</html>
