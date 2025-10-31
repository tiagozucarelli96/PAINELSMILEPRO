<?php
/**
 * webhook_logs_viewer.php
 * Visualizador de logs do webhook ME Eventos
 */
require_once __DIR__ . '/conexao.php';

header('Content-Type: text/html; charset=utf-8');

// Verificar se está logado
if (empty($_SESSION['logado'])) {
    header('Location: login.php');
    exit;
}

$log_file = __DIR__ . '/logs/webhook_me_eventos.log';
$lines_to_show = isset($_GET['lines']) ? (int)$_GET['lines'] : 100;
$refresh = isset($_GET['refresh']) ? (int)$_GET['refresh'] : 0;

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logs Webhook ME Eventos</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f1b35;
            color: #fff;
            padding: 2rem;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: #1a2540;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
        }
        h1 {
            color: #3b82f6;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .controls {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            align-items: center;
        }
        .btn {
            padding: 0.75rem 1.5rem;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-weight: 500;
            transition: background 0.2s;
        }
        .btn:hover {
            background: #2563eb;
        }
        .btn-secondary {
            background: #6b7280;
        }
        .btn-secondary:hover {
            background: #4b5563;
        }
        .btn-danger {
            background: #ef4444;
        }
        .btn-danger:hover {
            background: #dc2626;
        }
        .info {
            background: #1e3a8a;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            gap: 2rem;
            flex-wrap: wrap;
        }
        .info-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        .info-label {
            font-size: 0.875rem;
            color: #9ca3af;
        }
        .info-value {
            font-size: 1.125rem;
            font-weight: 600;
            color: #fff;
        }
        .log-container {
            background: #0f172a;
            border: 1px solid #1e293b;
            border-radius: 8px;
            padding: 1rem;
            overflow-x: auto;
            max-height: 70vh;
            overflow-y: auto;
        }
        .log-content {
            font-family: 'Monaco', 'Courier New', monospace;
            font-size: 0.875rem;
            line-height: 1.6;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .log-line {
            padding: 0.25rem 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }
        .log-line:last-child {
            border-bottom: none;
        }
        .log-timestamp {
            color: #60a5fa;
        }
        .log-error {
            color: #f87171;
        }
        .log-success {
            color: #34d399;
        }
        .log-warning {
            color: #fbbf24;
        }
        .log-info {
            color: #60a5fa;
        }
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .status-success {
            background: #065f46;
            color: #6ee7b7;
        }
        .status-error {
            background: #991b1b;
            color: #fca5a5;
        }
        .status-warning {
            background: #92400e;
            color: #fcd34d;
        }
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6b7280;
        }
        .empty-state svg {
            width: 64px;
            height: 64px;
            margin: 0 auto 1rem;
            opacity: 0.5;
        }
        input[type="number"] {
            padding: 0.5rem;
            border: 1px solid #374151;
            border-radius: 6px;
            background: #1f2937;
            color: #fff;
            width: 100px;
        }
        label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #9ca3af;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .auto-refresh {
            animation: pulse 2s infinite;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>
            📋 Logs Webhook ME Eventos
        </h1>
        
        <div class="controls">
            <a href="index.php?page=diagnostico_webhook_eventos" class="btn btn-secondary">🔙 Voltar ao Diagnóstico</a>
            <a href="index.php?page=dashboard" class="btn btn-secondary">📊 Dashboard</a>
            <?php if (file_exists($log_file)): ?>
                <a href="?page=webhook_logs&action=download" class="btn">📥 Download Log</a>
                <a href="?page=webhook_logs&action=clear" class="btn btn-danger" onclick="return confirm('Tem certeza que deseja limpar o arquivo de log?')">🗑️ Limpar Log</a>
            <?php endif; ?>
            <label>
                Linhas:
                <input type="number" id="lines" value="<?= $lines_to_show ?>" min="10" max="1000" step="10" onchange="updateLines()">
            </label>
            <?php if ($refresh > 0): ?>
                <span class="auto-refresh">🔄 Auto-refresh: <?= $refresh ?>s</span>
            <?php endif; ?>
        </div>
        
        <?php
        // Ações
        if (isset($_GET['action'])) {
            if ($_GET['action'] === 'download' && file_exists($log_file)) {
                header('Content-Type: text/plain');
                header('Content-Disposition: attachment; filename="webhook_me_eventos_' . date('Y-m-d') . '.log"');
                readfile($log_file);
                exit;
            }
            if ($_GET['action'] === 'clear' && file_exists($log_file)) {
                file_put_contents($log_file, '');
                header('Location: index.php?page=webhook_logs');
                exit;
            }
        }
        
        // Informações do arquivo
        if (file_exists($log_file)) {
            $file_size = filesize($log_file);
            $file_modified = filemtime($log_file);
            $log_content = file_get_contents($log_file);
            $all_lines = explode("\n", $log_content);
            $total_lines = count($all_lines);
            $last_lines = array_slice($all_lines, -$lines_to_show);
        } else {
            $file_size = 0;
            $file_modified = 0;
            $total_lines = 0;
            $last_lines = [];
        }
        ?>
        
        <div class="info">
            <div class="info-item">
                <span class="info-label">Arquivo</span>
                <span class="info-value"><?= file_exists($log_file) ? '✅ Existe' : '❌ Não encontrado' ?></span>
            </div>
            <?php if (file_exists($log_file)): ?>
                <div class="info-item">
                    <span class="info-label">Tamanho</span>
                    <span class="info-value"><?= number_format($file_size / 1024, 2) ?> KB</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Total de Linhas</span>
                    <span class="info-value"><?= number_format($total_lines) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Última Modificação</span>
                    <span class="info-value"><?= date('d/m/Y H:i:s', $file_modified) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Mostrando</span>
                    <span class="info-value">Últimas <?= count($last_lines) ?> linhas</span>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="log-container">
            <?php if (file_exists($log_file) && !empty($last_lines)): ?>
                <div class="log-content">
                    <?php foreach ($last_lines as $line): 
                        $line = trim($line);
                        if (empty($line)) continue;
                        
                        // Detectar tipo de log pela cor
                        $color_class = 'log-info';
                        if (stripos($line, 'erro') !== false || stripos($line, 'error') !== false || stripos($line, '❌') !== false) {
                            $color_class = 'log-error';
                        } elseif (stripos($line, 'sucesso') !== false || stripos($line, 'success') !== false || stripos($line, '✅') !== false) {
                            $color_class = 'log-success';
                        } elseif (stripos($line, 'warning') !== false || stripos($line, 'aviso') !== false || stripos($line, '⚠️') !== false) {
                            $color_class = 'log-warning';
                        }
                        
                        // Extrair timestamp se existir
                        if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
                            $timestamp = $matches[1];
                            $content = preg_replace('/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]\s*/', '', $line);
                            echo '<div class="log-line"><span class="log-timestamp">[' . htmlspecialchars($timestamp) . ']</span> <span class="' . $color_class . '">' . htmlspecialchars($content) . '</span></div>';
                        } else {
                            echo '<div class="log-line"><span class="' . $color_class . '">' . htmlspecialchars($line) . '</span></div>';
                        }
                    endforeach; ?>
                </div>
            <?php elseif (file_exists($log_file)): ?>
                <div class="empty-state">
                    <p>📝 Arquivo de log existe mas está vazio.</p>
                    <p style="margin-top: 0.5rem; font-size: 0.875rem; color: #6b7280;">Aguarde webhooks serem recebidos para ver logs aqui.</p>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <p>📭 Arquivo de log não encontrado.</p>
                    <p style="margin-top: 0.5rem; font-size: 0.875rem; color: #6b7280;">O arquivo será criado automaticamente quando o primeiro webhook for recebido.</p>
                    <p style="margin-top: 0.5rem; font-size: 0.875rem; color: #6b7280;">Caminho esperado: <code><?= htmlspecialchars($log_file) ?></code></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if ($refresh > 0): ?>
        <script>
            setTimeout(function() {
                window.location.reload();
            }, <?= $refresh * 1000 ?>);
        </script>
    <?php endif; ?>
    
    <script>
        function updateLines() {
            const lines = document.getElementById('lines').value;
            window.location.href = '?page=webhook_logs&lines=' + lines;
        }
        
        // Auto-scroll para o final
        const logContainer = document.querySelector('.log-container');
        if (logContainer) {
            logContainer.scrollTop = logContainer.scrollHeight;
        }
    </script>
</body>
</html>

