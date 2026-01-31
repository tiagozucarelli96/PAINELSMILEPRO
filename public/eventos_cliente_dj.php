<?php
/**
 * eventos_cliente_dj.php
 * Página pública para cliente preencher informações de DJ
 * Acessada via token único (sem login)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$error = '';
$success = false;
$link = null;
$reuniao = null;
$secao = null;
$anexos = [];

// Validar token
if (empty($token)) {
    $error = 'Link inválido';
} else {
    $link = eventos_link_publico_get($pdo, $token);
    
    if (!$link) {
        $error = 'Link inválido ou expirado';
    } elseif (!$link['is_active']) {
        $error = 'Este link foi desativado';
    } elseif ($link['expires_at'] && strtotime($link['expires_at']) < time()) {
        $error = 'Este link expirou';
    } else {
        // Registrar acesso
        eventos_link_publico_registrar_acesso($pdo, $link['id']);
        
        // Buscar reunião e seção
        $reuniao = eventos_reuniao_get($pdo, $link['meeting_id']);
        $secao = eventos_reuniao_get_secao($pdo, $link['meeting_id'], 'dj_protocolo');
        $anexos = eventos_reuniao_get_anexos($pdo, $link['meeting_id'], 'dj_protocolo');
    }
}

// Processar envio do formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $link && !$error) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'salvar') {
        // Verificar se já está travado
        if ($secao && $secao['is_locked']) {
            $error = 'Este formulário já foi enviado e não pode ser alterado.';
        } else {
            $content = $_POST['content_html'] ?? '';
            
            // Salvar conteúdo (como cliente)
            $result = eventos_reuniao_salvar_secao(
                $pdo,
                $link['meeting_id'],
                'dj_protocolo',
                $content,
                0, // user_id = 0 para cliente
                'Envio do cliente',
                'cliente'
            );
            
            if ($result['ok']) {
                // Travar seção
                eventos_reuniao_travar_secao($pdo, $link['meeting_id'], 'dj_protocolo', 0);
                $success = true;
                
                // Recarregar seção
                $secao = eventos_reuniao_get_secao($pdo, $link['meeting_id'], 'dj_protocolo');
            } else {
                $error = $result['error'] ?? 'Erro ao salvar';
            }
        }
    }
}

// Dados do evento
$snapshot = $reuniao ? json_decode($reuniao['me_event_snapshot'], true) : [];
$is_locked = $secao && !empty($secao['is_locked']);
$content = $secao['content_html'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Organização do Evento - DJ/Músicas</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8fafc;
            color: #1e293b;
            line-height: 1.6;
        }
        
        .header {
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .header img {
            max-width: 180px;
            filter: brightness(0) invert(1);
            margin-bottom: 1rem;
        }
        
        .header h1 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 0.95rem;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .event-info {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .event-info h2 {
            font-size: 1.25rem;
            color: #1e3a8a;
            margin-bottom: 1rem;
        }
        
        .event-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
        }
        
        .detail-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .detail-item span:first-child {
            font-size: 1.25rem;
        }
        
        .detail-item strong {
            font-size: 0.875rem;
            color: #64748b;
            display: block;
        }
        
        .detail-item span:last-child {
            font-size: 0.95rem;
        }
        
        .form-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .form-section h3 {
            font-size: 1.125rem;
            color: #374151;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .instructions {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
            color: #1e40af;
        }
        
        .instructions ul {
            margin: 0.5rem 0 0 1.5rem;
        }
        
        .editor-wrapper {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            min-height: 300px;
            background: white;
        }
        
        .editor-toolbar {
            display: flex;
            gap: 0.5rem;
            padding: 0.75rem;
            border-bottom: 1px solid #e5e7eb;
            background: #f8fafc;
            border-radius: 8px 8px 0 0;
            flex-wrap: wrap;
        }
        
        .editor-toolbar button {
            padding: 0.5rem 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            background: white;
            cursor: pointer;
            font-size: 0.875rem;
        }
        
        .editor-toolbar button:hover {
            background: #f1f5f9;
        }
        
        .editor-content {
            padding: 1rem;
            min-height: 250px;
            outline: none;
        }
        
        .editor-content:focus {
            box-shadow: inset 0 0 0 2px rgba(30, 58, 138, 0.2);
        }
        
        .btn {
            padding: 0.875rem 2rem;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
            color: white;
            width: 100%;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(30, 58, 138, 0.3);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }
        
        .locked-notice {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            color: #92400e;
            padding: 1.5rem;
            border-radius: 8px;
            text-align: center;
        }
        
        .locked-notice h3 {
            margin-bottom: 0.5rem;
        }
        
        .success-box {
            text-align: center;
            padding: 3rem;
        }
        
        .success-box .icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #059669 0%, #10b981 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem auto;
            font-size: 2.5rem;
            color: white;
        }
        
        .success-box h2 {
            color: #059669;
            margin-bottom: 0.5rem;
        }
        
        .success-box p {
            color: #64748b;
        }
        
        @media (max-width: 600px) {
            .container {
                padding: 1rem;
            }
            
            .event-details {
                grid-template-columns: 1fr 1fr;
            }
            
            .editor-toolbar {
                gap: 0.25rem;
            }
            
            .editor-toolbar button {
                padding: 0.375rem 0.5rem;
                font-size: 0.75rem;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <img src="logo.png" alt="Grupo Smile" onerror="this.style.display='none'">
        <h1>🎧 Organização - DJ / Músicas</h1>
        <p>Preencha as informações musicais do seu evento</p>
    </div>
    
    <div class="container">
        <?php if ($error): ?>
        <div class="alert alert-error">
            <strong>Erro:</strong> <?= htmlspecialchars($error) ?>
        </div>
        <?php elseif ($success): ?>
        <div class="form-section">
            <div class="success-box">
                <div class="icon">✓</div>
                <h2>Enviado com sucesso!</h2>
                <p>Recebemos suas informações. Nossa equipe entrará em contato se houver dúvidas.</p>
                <p style="margin-top: 1rem; font-size: 0.875rem;">Você pode fechar esta página.</p>
            </div>
        </div>
        <?php elseif ($is_locked): ?>
        <div class="event-info">
            <h2><?= htmlspecialchars($snapshot['nome'] ?? 'Seu Evento') ?></h2>
            <div class="event-details">
                <div class="detail-item">
                    <span>📅</span>
                    <div>
                        <strong>Data</strong>
                        <span><?= date('d/m/Y', strtotime($snapshot['data'] ?? 'now')) ?></span>
                    </div>
                </div>
                <div class="detail-item">
                    <span>⏰</span>
                    <div>
                        <strong>Horário</strong>
                        <span><?= htmlspecialchars($snapshot['hora_inicio'] ?? '-') ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="locked-notice">
            <h3>🔒 Formulário já enviado</h3>
            <p>Você já enviou as informações deste formulário. Se precisar fazer alterações, entre em contato com nossa equipe.</p>
        </div>
        
        <div class="form-section">
            <h3>Suas informações enviadas:</h3>
            <div style="padding: 1rem; background: #f8fafc; border-radius: 8px; margin-top: 1rem;">
                <?= $content ?: '<em>Sem conteúdo</em>' ?>
            </div>
        </div>
        <?php else: ?>
        <!-- Formulário editável -->
        <div class="event-info">
            <h2><?= htmlspecialchars($snapshot['nome'] ?? 'Seu Evento') ?></h2>
            <div class="event-details">
                <div class="detail-item">
                    <span>📅</span>
                    <div>
                        <strong>Data</strong>
                        <span><?= date('d/m/Y', strtotime($snapshot['data'] ?? 'now')) ?></span>
                    </div>
                </div>
                <div class="detail-item">
                    <span>⏰</span>
                    <div>
                        <strong>Horário</strong>
                        <span><?= htmlspecialchars($snapshot['hora_inicio'] ?? '-') ?></span>
                    </div>
                </div>
                <div class="detail-item">
                    <span>📍</span>
                    <div>
                        <strong>Local</strong>
                        <span><?= htmlspecialchars($snapshot['local'] ?? '-') ?></span>
                    </div>
                </div>
                <div class="detail-item">
                    <span>👥</span>
                    <div>
                        <strong>Convidados</strong>
                        <span><?= (int)($snapshot['convidados'] ?? 0) ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <form method="POST" id="djForm">
            <input type="hidden" name="action" value="salvar">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            
            <div class="form-section">
                <h3>🎵 Músicas e Protocolos</h3>
                
                <div class="instructions">
                    <strong>Instruções:</strong>
                    <ul>
                        <li>Para cada música, informe o <strong>link do YouTube</strong> e o <strong>tempo de início</strong></li>
                        <li>Exemplo: <em>Valsa 0:20 - https://youtube.com/...</em></li>
                        <li>Inclua músicas para: entrada, valsas, momentos especiais, abertura de pista</li>
                        <li>Informe também seu gosto musical e ritmos preferidos</li>
                    </ul>
                </div>
                
                <div class="editor-wrapper">
                    <div class="editor-toolbar">
                        <button type="button" onclick="execCmd('bold')"><b>B</b></button>
                        <button type="button" onclick="execCmd('italic')"><i>I</i></button>
                        <button type="button" onclick="execCmd('underline')"><u>U</u></button>
                        <button type="button" onclick="execCmd('insertUnorderedList')">• Lista</button>
                    </div>
                    <div class="editor-content" 
                         id="editor" 
                         contenteditable="true"><?= $content ?></div>
                </div>
                <input type="hidden" name="content_html" id="contentInput">
            </div>
            
            <button type="submit" class="btn btn-primary" id="submitBtn">
                ✓ Enviar Informações
            </button>
            
            <p style="text-align: center; margin-top: 1rem; font-size: 0.875rem; color: #64748b;">
                Após o envio, as informações não poderão ser alteradas.
            </p>
        </form>
        
        <script>
            function execCmd(cmd) {
                document.execCommand(cmd, false, null);
            }
            
            document.getElementById('djForm').addEventListener('submit', function(e) {
                const editor = document.getElementById('editor');
                document.getElementById('contentInput').value = editor.innerHTML;
                
                if (!editor.innerText.trim()) {
                    e.preventDefault();
                    alert('Por favor, preencha as informações antes de enviar.');
                    return false;
                }
                
                if (!confirm('Confirma o envio das informações? Após enviar, não será possível alterar.')) {
                    e.preventDefault();
                    return false;
                }
                
                document.getElementById('submitBtn').disabled = true;
                document.getElementById('submitBtn').innerText = 'Enviando...';
            });
        </script>
        <?php endif; ?>
    </div>
</body>
</html>
