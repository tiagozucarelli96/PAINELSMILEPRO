<?php
// asaas_webhook_logs_viewer.php — Visualizador de logs do webhook Asaas
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

// Verificar autenticação - usar padrão do sistema (logado)
if (empty($_SESSION['logado']) || ($_SESSION['logado'] ?? 0) != 1) {
    header('Location: index.php?page=login');
    exit;
}

// Verificar permissão (opcional - pode remover se quiser que todos vejam)
// require_once __DIR__ . '/core/permissions.php';
// if (!lc_can_view_logs()) {
//     die('Acesso negado');
// }

$log_file = __DIR__ . '/logs/asaas_webhook.log';
$lines = [];
$filter = $_GET['filter'] ?? '';
$limit = (int)($_GET['limit'] ?? 500);

if (file_exists($log_file)) {
    $file_lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $file_lines = array_reverse($file_lines); // Mais recentes primeiro
    
    foreach ($file_lines as $line) {
        if (empty($filter) || stripos($line, $filter) !== false) {
            $lines[] = $line;
            if (count($lines) >= $limit) break;
        }
    }
} else {
    $log_file_exists = false;
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logs Webhook Asaas - GRUPO Smile EVENTOS</title>
    <link rel="stylesheet" href="estilo.css">
    <style>
        .logs-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .header h1 {
            margin: 0 0 10px 0;
        }
        
        .controls {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .controls input, .controls select {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .controls button {
            padding: 8px 16px;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
        }
        
        .controls button:hover {
            background: #2563eb;
        }
        
        .logs-content {
            background: #1e293b;
            color: #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.6;
            max-height: 70vh;
            overflow-y: auto;
        }
        
        .log-line {
            padding: 4px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .log-line:hover {
            background: rgba(255, 255, 255, 0.05);
        }
        
        .log-line.error {
            color: #fca5a5;
        }
        
        .log-line.success {
            color: #86efac;
        }
        
        .log-line.warning {
            color: #fde047;
        }
        
        .log-time {
            color: #94a3b8;
            margin-right: 10px;
        }
        
        .no-logs {
            text-align: center;
            padding: 40px;
            color: #94a3b8;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
        }
        
        .stat-label {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 5px;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #1e3a8a;
        }
    </style>
</head>
<body>
    <div class="logs-container">
        <div class="header">
            <h1>📋 Logs do Webhook Asaas</h1>
            <p style="margin: 0; opacity: 0.9;">Visualização de eventos recebidos do Asaas Checkout e Pagamentos</p>
        </div>
        
        <?php if (!file_exists($log_file)): ?>
            <div class="no-logs">
                <h2>📁 Arquivo de log não encontrado</h2>
                <p>O arquivo <code>logs/asaas_webhook.log</code> ainda não foi criado.</p>
                <p>Isso significa que nenhum webhook do Asaas foi recebido ainda.</p>
            </div>
        <?php else: ?>
            <?php
            $total_lines = count(file($log_file));
            $filtered_count = count($lines);
            ?>
            
            <div class="stats">
                <div class="stat-card">
                    <div class="stat-label">Total de Linhas</div>
                    <div class="stat-value"><?= number_format($total_lines) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Linhas Exibidas</div>
                    <div class="stat-value"><?= number_format($filtered_count) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Tamanho do Arquivo</div>
                    <div class="stat-value"><?= number_format(filesize($log_file) / 1024, 2) ?> KB</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Última Modificação</div>
                    <div class="stat-value" style="font-size: 14px;"><?= date('d/m/Y H:i:s', filemtime($log_file)) ?></div>
                </div>
            </div>
            
            <div class="controls">
                <input type="text" 
                       id="filterInput" 
                       placeholder="Filtrar logs (evento, checkout_id, etc)" 
                       value="<?= h($filter) ?>"
                       style="flex: 1; min-width: 250px;">
                
                <select id="limitSelect" style="width: 150px;">
                    <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>Últimas 100</option>
                    <option value="500" <?= $limit === 500 ? 'selected' : '' ?>>Últimas 500</option>
                    <option value="1000" <?= $limit === 1000 ? 'selected' : '' ?>>Últimas 1000</option>
                    <option value="5000" <?= $limit === 5000 ? 'selected' : '' ?>>Últimas 5000</option>
                </select>
                
                <button onclick="applyFilter()">🔍 Filtrar</button>
                <button onclick="downloadLog()">📥 Download</button>
                <button onclick="clearLog()" style="background: #dc2626;">🗑️ Limpar Logs</button>
                <button onclick="location.reload()" style="background: #6b7280;">🔄 Atualizar</button>
            </div>
            
            <div class="logs-content">
                <?php if (empty($lines)): ?>
                    <div class="no-logs">
                        <p>Nenhum log encontrado com o filtro aplicado.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($lines as $line): ?>
                        <?php
                        $line_class = '';
                        if (stripos($line, 'erro') !== false || stripos($line, 'error') !== false || stripos($line, '❌') !== false) {
                            $line_class = 'error';
                        } elseif (stripos($line, 'sucesso') !== false || stripos($line, 'success') !== false || stripos($line, '✅') !== false) {
                            $line_class = 'success';
                        } elseif (stripos($line, 'warning') !== false || stripos($line, '⚠️') !== false) {
                            $line_class = 'warning';
                        }
                        
                        // Extrair timestamp se existir
                        $timestamp = '';
                        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $matches)) {
                            $timestamp = $matches[1];
                            $line_content = substr($line, strlen($timestamp) + 3); // +3 para " - "
                        } else {
                            $line_content = $line;
                        }
                        ?>
                        <div class="log-line <?= $line_class ?>">
                            <?php if ($timestamp): ?>
                                <span class="log-time"><?= h($timestamp) ?></span>
                            <?php endif; ?>
                            <span><?= h($line_content) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        function applyFilter() {
            const filter = document.getElementById('filterInput').value;
            const limit = document.getElementById('limitSelect').value;
            const url = new URL(window.location);
            url.searchParams.set('filter', filter);
            url.searchParams.set('limit', limit);
            window.location = url.toString();
        }
        
        document.getElementById('filterInput')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                applyFilter();
            }
        });
        
        function downloadLog() {
            window.location.href = '?download=1';
        }
        
        function clearLog() {
            if (confirm('Tem certeza que deseja limpar todos os logs? Esta ação não pode ser desfeita.')) {
                window.location.href = '?clear=1';
            }
        }
        
        // Auto-refresh a cada 30 segundos se não houver filtro
        <?php if (empty($filter)): ?>
        setTimeout(() => location.reload(), 30000);
        <?php endif; ?>
    </script>
</script>

<?php
// Download do log
if (isset($_GET['download']) && file_exists($log_file)) {
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="asaas_webhook_' . date('Y-m-d_H-i-s') . '.log"');
    readfile($log_file);
    exit;
}

// Limpar log
if (isset($_GET['clear']) && file_exists($log_file)) {
    file_put_contents($log_file, '');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
?>

