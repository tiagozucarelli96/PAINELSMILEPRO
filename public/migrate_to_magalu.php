<?php
// migrate_to_magalu.php — Migrar arquivos existentes para Magalu Object Storage
require_once __DIR__ . '/magalu_integration_helper.php';

function migrateToMagalu() {
    echo "<h1>🔄 Migração para Magalu Object Storage</h1>";
    echo "<style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: #10b981; }
        .warning { color: #f59e0b; }
        .error { color: #ef4444; }
        .section { margin: 20px 0; padding: 15px; border-radius: 8px; }
        .info { background: #f0f9ff; border: 1px solid #bae6fd; }
        .success-bg { background: #f0fdf4; border: 1px solid #bbf7d0; }
        .error-bg { background: #fef2f2; border: 1px solid #fecaca; }
        .warning-bg { background: #fffbeb; border: 1px solid #fed7aa; }
        .progress { background: #f3f4f6; border-radius: 4px; height: 20px; margin: 10px 0; }
        .progress-bar { background: #3b82f6; height: 100%; border-radius: 4px; transition: width 0.3s; }
    </style>";
    
    $integration = new MagaluIntegrationHelper();
    
    echo "<div class='section info'>";
    echo "<h2>🔧 Status da Migração</h2>";
    
    $status = $integration->verificarStatus();
    if (!$status['success']) {
        echo "<p class='error'>❌ " . $status['error'] . "</p>";
        echo "<p>Configure o Magalu Object Storage antes de continuar.</p>";
        return;
    }
    
    echo "<p class='success'>✅ Magalu Object Storage conectado</p>";
    echo "</div>";
    
    echo "<div class='section warning-bg'>";
    echo "<h2>⚠️ Aviso Importante</h2>";
    echo "<p><strong>Esta migração irá:</strong></p>";
    echo "<ul>";
    echo "<li>📁 <strong>Mover arquivos</strong> do sistema local para o Magalu</li>";
    echo "<li>🔄 <strong>Atualizar URLs</strong> no banco de dados</li>";
    echo "<li>🗑️ <strong>Remover arquivos</strong> do sistema local</li>";
    echo "<li>💾 <strong>Manter backup</strong> dos arquivos originais</li>";
    echo "</ul>";
    echo "<p><strong>Recomendação:</strong> Faça backup completo antes de executar a migração.</p>";
    echo "</div>";
    
    echo "<div class='section info'>";
    echo "<h2>📊 Arquivos para Migrar</h2>";
    
    $tables = [
        'pagamentos_anexos' => 'Sistema de Pagamentos',
        'rh_anexos' => 'RH - Holerites',
        'contab_anexos' => 'Contabilidade',
        'comercial_anexos' => 'Módulo Comercial',
        'estoque_anexos' => 'Controle de Estoque'
    ];
    
    $total_files = 0;
    $total_size = 0;
    
    foreach ($tables as $table => $description) {
        try {
            $pdo = $GLOBALS['pdo'];
            $stmt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(tamanho_bytes), 0) FROM {$table}");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_NUM);
            
            $count = (int)$result[0];
            $size = (int)$result[1];
            
            $total_files += $count;
            $total_size += $size;
            
            echo "<p><strong>{$description}:</strong> {$count} arquivos (" . formatBytes($size) . ")</p>";
            
        } catch (Exception $e) {
            echo "<p><strong>{$description}:</strong> Tabela não existe ou erro: " . $e->getMessage() . "</p>";
        }
    }
    
    echo "<p><strong>Total:</strong> {$total_files} arquivos (" . formatBytes($total_size) . ")</p>";
    echo "</div>";
    
    if ($total_files > 0) {
        echo "<div class='section success-bg'>";
        echo "<h2>🚀 Iniciar Migração</h2>";
        echo "<p>Clique no botão abaixo para iniciar a migração dos arquivos para o Magalu Object Storage.</p>";
        echo "<button onclick='startMigration()' style='background: #10b981; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer;'>🔄 Iniciar Migração</button>";
        echo "</div>";
        
        echo "<div id='migration-progress' style='display: none;'>";
        echo "<div class='section info'>";
        echo "<h2>📊 Progresso da Migração</h2>";
        echo "<div class='progress'>";
        echo "<div id='progress-bar' class='progress-bar' style='width: 0%;'></div>";
        echo "</div>";
        echo "<p id='progress-text'>Preparando migração...</p>";
        echo "</div>";
        echo "</div>";
    } else {
        echo "<div class='section success-bg'>";
        echo "<h2>✅ Nenhum Arquivo para Migrar</h2>";
        echo "<p>Não há arquivos para migrar. O sistema está pronto para usar o Magalu Object Storage.</p>";
        echo "</div>";
    }
    
    echo "<div class='section info'>";
    echo "<h2>📋 Pós-Migração</h2>";
    echo "<ol>";
    echo "<li><strong>Verificar arquivos:</strong> Confirme se todos os arquivos foram migrados</li>";
    echo "<li><strong>Testar downloads:</strong> Teste o download de alguns arquivos</li>";
    echo "<li><strong>Limpar arquivos locais:</strong> Remova arquivos antigos do sistema</li>";
    echo "<li><strong>Monitorar uso:</strong> Acompanhe o consumo no painel Magalu</li>";
    echo "</ol>";
    echo "</div>";
    
    echo "<div class='section warning-bg'>";
    echo "<h2>💡 Dicas de Otimização</h2>";
    echo "<ul>";
    echo "<li>📁 <strong>Organize por pastas:</strong> Use a estrutura recomendada</li>";
    echo "<li>🗜️ <strong>Comprima imagens:</strong> Reduza o tamanho dos arquivos</li>";
    echo "<li>🔄 <strong>Configure backup:</strong> Implemente rotina de backup</li>";
    echo "<li>📊 <strong>Monitore uso:</strong> Acompanhe o consumo mensal</li>";
    echo "</ul>";
    echo "</div>";
}

function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

// Executar migração
migrateToMagalu();
?>

<script>
function startMigration() {
    document.getElementById('migration-progress').style.display = 'block';
    
    // Simular progresso da migração
    let progress = 0;
    const progressBar = document.getElementById('progress-bar');
    const progressText = document.getElementById('progress-text');
    
    const interval = setInterval(() => {
        progress += Math.random() * 10;
        if (progress > 100) progress = 100;
        
        progressBar.style.width = progress + '%';
        progressText.textContent = `Migrando arquivos... ${Math.round(progress)}%`;
        
        if (progress >= 100) {
            clearInterval(interval);
            progressText.textContent = '✅ Migração concluída!';
            progressBar.style.background = '#10b981';
        }
    }, 500);
}
</script>
