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
require_once __DIR__ . '/core/email_global_helper.php';
require_once __DIR__ . '/sidebar_integration.php';

// Carregar autoload do Composer
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

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

// Função para testar conexão TCP/SSL com múltiplas tentativas
function testarConexaoTCP($host, $port, $timeout = 5) {
    $inicio = microtime(true);
    $resultado = [
        'sucesso' => false,
        'erro' => null,
        'tempo' => 0,
        'metodo' => null,
        'tentativas' => []
    ];
    
    // Tentar diferentes métodos de conexão
    $metodos = [];
    
    if ($port == 465) {
        // Porta 465: SSL implícito
        $metodos[] = [
            'nome' => 'SSL Implícito (ssl://)',
            'url' => "ssl://{$host}:{$port}",
            'context' => stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
                ],
                'socket' => [
                    'bindto' => '0:0' // Não forçar interface específica
                ]
            ])
        ];
        
        // Tentar também com TLS explícito
        $metodos[] = [
            'nome' => 'TLS Explícito (tls://)',
            'url' => "tls://{$host}:{$port}",
            'context' => stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ])
        ];
    } else {
        // Outras portas: TCP primeiro, depois TLS se necessário
        $metodos[] = [
            'nome' => 'TCP Simples',
            'url' => "tcp://{$host}:{$port}",
            'context' => null
        ];
    }
    
    foreach ($metodos as $metodo) {
        $tentativa_inicio = microtime(true);
        $socket = null;
        
        try {
            if ($metodo['context']) {
                $socket = @stream_socket_client(
                    $metodo['url'],
                    $errno,
                    $errstr,
                    $timeout,
                    STREAM_CLIENT_CONNECT,
                    $metodo['context']
                );
            } else {
                $socket = @stream_socket_client(
                    $metodo['url'],
                    $errno,
                    $errstr,
                    $timeout
                );
            }
            
            $tentativa_tempo = round((microtime(true) - $tentativa_inicio) * 1000, 2);
            
            if ($socket) {
                fclose($socket);
                $resultado['sucesso'] = true;
                $resultado['metodo'] = $metodo['nome'];
                $resultado['tempo'] = round((microtime(true) - $inicio) * 1000, 2);
                $resultado['tentativas'][] = [
                    'metodo' => $metodo['nome'],
                    'sucesso' => true,
                    'tempo' => $tentativa_tempo
                ];
                return $resultado; // Sucesso, parar tentativas
            } else {
                $resultado['tentativas'][] = [
                    'metodo' => $metodo['nome'],
                    'sucesso' => false,
                    'erro' => "Erro $errno: $errstr",
                    'tempo' => $tentativa_tempo
                ];
            }
        } catch (Exception $e) {
            $tentativa_tempo = round((microtime(true) - $tentativa_inicio) * 1000, 2);
            $resultado['tentativas'][] = [
                'metodo' => $metodo['nome'],
                'sucesso' => false,
                'erro' => $e->getMessage(),
                'tempo' => $tentativa_tempo
            ];
        }
    }
    
    // Se chegou aqui, todas as tentativas falharam
    $resultado['tempo'] = round((microtime(true) - $inicio) * 1000, 2);
    
    // Pegar o último erro como principal
    if (!empty($resultado['tentativas'])) {
        $ultima_tentativa = end($resultado['tentativas']);
        $resultado['erro'] = $ultima_tentativa['erro'] ?? 'Todas as tentativas falharam';
    } else {
        $resultado['erro'] = 'Nenhuma tentativa foi realizada';
    }
    
    return $resultado;
}

$resultado_teste = null;
$resultado_conexao = null;
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
        'smtp_host' => 'Host SMTP',
        'smtp_port' => 'Porta SMTP',
        'smtp_username' => 'Usuário SMTP',
        'smtp_password' => 'Senha SMTP',
        'smtp_encryption' => 'Tipo de segurança',
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
    
    // Validar porta
    $port = (int)$config['smtp_port'];
    if ($port <= 0 || $port > 65535) {
        $validacao[] = ['tipo' => 'erro', 'mensagem' => 'Porta SMTP inválida'];
    }
    
    // Testar conexão TCP/SSL
    if (!empty($config['smtp_host']) && !empty($config['smtp_port'])) {
        $resultado_conexao = testarConexaoTCP($config['smtp_host'], (int)$config['smtp_port']);
    }
}

// Processar teste de envio
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'testar_envio') {
    try {
        if (!$config) {
            throw new Exception('Configuração não encontrada');
        }
        
        if (empty($config['email_administrador'])) {
            throw new Exception('E-mail do administrador não configurado');
        }
        
        // Verificar se PHPMailer está disponível
        $phpmailer_disponivel = class_exists('PHPMailer\PHPMailer\PHPMailer');
        $autoload_existe = file_exists(__DIR__ . '/../vendor/autoload.php');
        
        if (!$phpmailer_disponivel) {
            throw new Exception('PHPMailer não está disponível. Verifique se o Composer foi executado (composer install).');
        }
        
        // Tentar enviar e-mail
        $emailHelper = new EmailGlobalHelper();
        
        $assunto = 'Teste de Diagnóstico - Portal Grupo Smile';
        $corpo = '<html><body><h1>Teste de E-mail</h1><p>Este é um e-mail de teste enviado pelo sistema de diagnóstico.</p><p>Data/Hora: ' . date('d/m/Y H:i:s') . '</p></body></html>';
        
        $enviado = $emailHelper->enviarEmail($config['email_administrador'], $assunto, $corpo, true);
        
        if ($enviado) {
            $resultado_teste = [
                'sucesso' => true,
                'mensagem' => 'E-mail enviado com sucesso!',
                'detalhes' => [
                    'para' => $config['email_administrador'],
                    'phpmailer_disponivel' => $phpmailer_disponivel,
                    'autoload_existe' => $autoload_existe
                ]
            ];
            
            registrarLogEmail($pdo, 'sucesso', 'E-mail de teste enviado com sucesso', [
                'para' => $config['email_administrador'],
                'host' => $config['smtp_host'],
                'porta' => $config['smtp_port']
            ]);
        } else {
            throw new Exception('Falha ao enviar e-mail (retornou false)');
        }
        
    } catch (Exception $e) {
        $erro_detalhado = $e->getMessage();
        
        // Tentar capturar erro do PHPMailer se disponível
        if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            try {
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = $config['smtp_host'];
                $mail->SMTPAuth = true;
                $mail->Username = $config['smtp_username'];
                $mail->Password = $config['smtp_password'];
                
                $port = (int)$config['smtp_port'];
                if ($port === 465) {
                    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                    $mail->SMTPAutoTLS = false;
                } else {
                    $mail->SMTPSecure = $config['smtp_encryption'] === 'tls' 
                        ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS 
                        : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                }
                $mail->Port = $port;
                $mail->SMTPDebug = 2; // Ativar debug para capturar erro
                $mail->Debugoutput = function($str, $level) use (&$erro_detalhado) {
                    $erro_detalhado .= "\n[SMTP Debug] $str";
                };
                
                $mail->setFrom($config['email_remetente'], 'Portal Grupo Smile');
                $mail->addAddress($config['email_administrador']);
                $mail->isHTML(true);
                $mail->Subject = 'Teste';
                $mail->Body = '<p>Teste</p>';
                
                $mail->send();
            } catch (PHPMailer\PHPMailer\Exception $e) {
                $erro_detalhado = $e->getMessage();
            }
        }
        
        $resultado_teste = [
            'sucesso' => false,
            'mensagem' => 'Erro ao enviar e-mail',
            'erro' => $erro_detalhado
        ];
        
        // Registrar erro no log (sem senha)
        $detalhes_erro = [
            'host' => $config['smtp_host'] ?? 'N/A',
            'porta' => $config['smtp_port'] ?? 'N/A',
            'usuario' => $config['smtp_username'] ?? 'N/A',
            'encryption' => $config['smtp_encryption'] ?? 'N/A',
            'erro' => $erro_detalhado
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
                    <td>Host SMTP</td>
                    <td><?= htmlspecialchars($config['smtp_host'] ?? 'N/A') ?></td>
                </tr>
                <tr>
                    <td>Porta</td>
                    <td><?= htmlspecialchars($config['smtp_port'] ?? 'N/A') ?></td>
                </tr>
                <tr>
                    <td>Usuário SMTP</td>
                    <td><?= htmlspecialchars($config['smtp_username'] ?? 'N/A') ?></td>
                </tr>
                <tr>
                    <td>Senha SMTP</td>
                    <td class="valor-sensivel"><?= !empty($config['smtp_password']) ? '••••••••' : 'N/A' ?></td>
                </tr>
                <tr>
                    <td>Encriptação</td>
                    <td><?= strtoupper(htmlspecialchars($config['smtp_encryption'] ?? 'N/A')) ?></td>
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
        
        <!-- Teste de Conexão TCP/SSL -->
        <?php if ($resultado_conexao): ?>
        <div class="section">
            <h2>🔌 Teste de Conexão TCP/SSL</h2>
            <?php if ($resultado_conexao['sucesso']): ?>
            <div class="resultado-box sucesso">
                <p><strong>✅ Sucesso!</strong></p>
                <p>Conexão estabelecida com <?= htmlspecialchars($config['smtp_host']) ?>:<?= htmlspecialchars($config['smtp_port']) ?></p>
                <p>Método usado: <?= htmlspecialchars($resultado_conexao['metodo']) ?></p>
                <p>Tempo de resposta: <?= $resultado_conexao['tempo'] ?>ms</p>
            </div>
            <?php else: ?>
            <div class="resultado-box erro">
                <p><strong>❌ Falha na conexão</strong></p>
                <p>Erro: <?= htmlspecialchars($resultado_conexao['erro']) ?></p>
                <p>Tempo total de tentativas: <?= $resultado_conexao['tempo'] ?>ms</p>
                
                <?php if (!empty($resultado_conexao['tentativas'])): ?>
                <p style="margin-top: 1rem;"><strong>Tentativas realizadas:</strong></p>
                <ul style="margin-left: 1.5rem; margin-top: 0.5rem;">
                    <?php foreach ($resultado_conexao['tentativas'] as $tentativa): ?>
                    <li>
                        <strong><?= htmlspecialchars($tentativa['metodo']) ?>:</strong>
                        <?= $tentativa['sucesso'] ? '✅' : '❌' ?>
                        <?= $tentativa['sucesso'] ? 'Sucesso' : htmlspecialchars($tentativa['erro'] ?? 'Falhou') ?>
                        (<?= $tentativa['tempo'] ?>ms)
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                
                <div class="info-box" style="margin-top: 1rem;">
                    <p><strong>💡 Possíveis causas:</strong></p>
                    <ul style="margin-left: 1.5rem; margin-top: 0.5rem;">
                        <li>Firewall bloqueando a porta <?= htmlspecialchars($config['smtp_port']) ?></li>
                        <li>Servidor SMTP não acessível do Railway</li>
                        <li>Host ou porta incorretos</li>
                        <li>Problema de rede temporário</li>
                    </ul>
                    <p style="margin-top: 0.5rem;"><strong>Sugestão:</strong> Tente usar a porta 587 com STARTTLS se a 465 não funcionar.</p>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Verificação de Dependências -->
        <div class="section">
            <h2>📦 Verificação de Dependências</h2>
            <?php
            $autoload_existe = file_exists(__DIR__ . '/../vendor/autoload.php');
            $phpmailer_disponivel = class_exists('PHPMailer\PHPMailer\PHPMailer');
            ?>
            <div class="validacao-item <?= $autoload_existe ? 'ok' : 'erro' ?>">
                <?= $autoload_existe ? '✅' : '❌' ?> vendor/autoload.php: <?= $autoload_existe ? 'Existe' : 'Não encontrado' ?>
            </div>
            <div class="validacao-item <?= $phpmailer_disponivel ? 'ok' : 'erro' ?>">
                <?= $phpmailer_disponivel ? '✅' : '❌' ?> PHPMailer: <?= $phpmailer_disponivel ? 'Disponível' : 'Não disponível' ?>
            </div>
            
            <?php if (!$autoload_existe || !$phpmailer_disponivel): ?>
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
                <div class="code-block">
<?= htmlspecialchars($resultado_teste['erro']) ?>
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
