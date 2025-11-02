<?php
/**
 * comercial_realizar_degustacao_ultra_simples.php — VERSÃO ULTRA SIMPLES
 * Bypass TOTAL - sem conexão inicial, sem helpers, sem nada
 */

// CRÍTICO: Verificar se está sendo acessado via router ou diretamente
$debug = [];
$debug[] = "🔍 SCRIPT_NAME: " . ($_SERVER['SCRIPT_NAME'] ?? 'N/A');
$debug[] = "🔍 REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A');
$debug[] = "🔍 PHP_SELF: " . ($_SERVER['PHP_SELF'] ?? 'N/A');
$debug[] = "🔍 QUERY_STRING: " . ($_SERVER['QUERY_STRING'] ?? 'N/A');

// Parsear QUERY_STRING manualmente ANTES de tudo
if (isset($_SERVER['QUERY_STRING']) && !empty($_SERVER['QUERY_STRING'])) {
    parse_str($_SERVER['QUERY_STRING'], $parsed);
    $_GET = array_merge($parsed, $_GET);
    $debug[] = "🔍 QUERY_STRING parseado";
}

// Verificar se passou pelo router (se tiver index.php no path, foi pelo router)
if (strpos($_SERVER['REQUEST_URI'] ?? '', 'index.php') !== false) {
    $debug[] = "⚠️ DETECTADO: Passou pelo router (index.php no REQUEST_URI)";
}

// Tentar carregar conexão apenas se necessário
$pdo = null;
$degustacao_id = (int)($_GET['degustacao_id'] ?? 0);

// Carregar conexão SEMPRE (não apenas se degustacao_id > 0)
// Para buscar lista de degustações também precisamos de conexão
try {
    if (file_exists(__DIR__ . '/conexao.php')) {
        require_once __DIR__ . '/conexao.php';
        $pdo = $GLOBALS['pdo'] ?? null;
        $debug[] = "✅ Conexão carregada";
    } else {
        $debug[] = "❌ conexao.php não encontrado";
    }
} catch (Exception $e) {
    $debug[] = "❌ Erro ao carregar conexão: " . $e->getMessage();
}

// Parsear QUERY_STRING manualmente
if (isset($_SERVER['QUERY_STRING']) && !empty($_SERVER['QUERY_STRING'])) {
    parse_str($_SERVER['QUERY_STRING'], $parsed);
    if (isset($parsed['degustacao_id']) && $degustacao_id === 0) {
        $degustacao_id = (int)$parsed['degustacao_id'];
        $debug[] = "✅ degustacao_id obtido de QUERY_STRING parseado: {$degustacao_id}";
    }
}

$debug[] = "🎯 degustacao_id FINAL: {$degustacao_id}";

// Buscar dados APENAS se tiver conexão e ID válido
$degustacao = null;
$inscritos = [];
$degustacoes = [];

if ($pdo && $degustacao_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM comercial_degustacoes WHERE id = :id");
        $stmt->execute([':id' => $degustacao_id]);
        $degustacao = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($degustacao) {
            // Verificar coluna
            $col_check = $pdo->query("
                SELECT column_name FROM information_schema.columns
                WHERE table_name = 'comercial_inscricoes' 
                AND column_name IN ('degustacao_id', 'event_id') LIMIT 1
            ")->fetch(PDO::FETCH_ASSOC);
            $col = $col_check ? $col_check['column_name'] : 'degustacao_id';
            
            $stmt = $pdo->prepare("
                SELECT id, nome, qtd_pessoas, tipo_festa 
                FROM comercial_inscricoes 
                WHERE {$col} = :id AND status = 'confirmado' 
                ORDER BY nome ASC
            ");
            $stmt->execute([':id' => $degustacao_id]);
            $inscritos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $debug[] = "✅ Inscritos encontrados: " . count($inscritos);
        }
    } catch (Exception $e) {
        $debug[] = "❌ Erro ao buscar dados: " . $e->getMessage();
    }
}

// Buscar lista de degustações
if ($pdo) {
    try {
        $degustacoes = $pdo->query("
            SELECT id, nome, data, hora_inicio, local, capacidade
            FROM comercial_degustacoes
            ORDER BY data DESC, hora_inicio DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $debug[] = "❌ Erro ao buscar degustações: " . $e->getMessage();
    }
}

// CRÍTICO: Enviar headers ANTES de qualquer output
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Realizar Degustação - ULTRA SIMPLES</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: system-ui, -apple-system, sans-serif; 
            max-width: 1400px; 
            margin: 0 auto; 
            padding: 2rem; 
            background: #f5f5f5;
            line-height: 1.6;
        }
        h1 { color: #1e40af; margin-bottom: 1rem; }
        .debug-box { 
            background: #fff3cd; 
            border: 2px solid #ffc107; 
            padding: 1rem; 
            margin: 1rem 0; 
            border-radius: 8px;
            font-size: 0.9rem;
        }
        .form-box { 
            background: white; 
            padding: 2rem; 
            border-radius: 12px; 
            margin: 1rem 0; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1); 
        }
        .form-group { display: flex; gap: 1rem; align-items: flex-end; }
        label { display: block; margin-bottom: 0.5rem; font-weight: 600; }
        select, button { 
            padding: 12px; 
            font-size: 1rem; 
            border-radius: 8px; 
            border: 1px solid #ddd; 
        }
        select { flex: 1; }
        button { 
            background: #3b82f6; 
            color: white; 
            border: none; 
            cursor: pointer; 
            font-weight: 600;
        }
        .relatorio { 
            background: white; 
            padding: 2rem; 
            border-radius: 12px; 
            margin-top: 2rem;
        }
        .mesa-card { 
            background: #f8fafc; 
            padding: 1rem; 
            margin: 0.5rem 0; 
            border-radius: 8px; 
            border: 1px solid #e5e7eb;
        }
    </style>
</head>
<body>
    <h1>🍽️ Realizar Degustação - ULTRA SIMPLES</h1>
    <p><strong style="color: #dc2626;">⚠️ Esta versão bypassa TUDO - router, sidebar, permissões</strong></p>
    
    <div class="debug-box">
        <h3>🔍 Debug Completo</h3>
        <?php foreach ($debug as $log): ?>
            <div><?= htmlspecialchars($log) ?></div>
        <?php endforeach; ?>
    </div>
    
    <div class="form-box">
        <form method="GET" action="comercial_realizar_degustacao_ultra_simples.php">
            <div class="form-group">
                <div style="flex: 1;">
                    <label>Selecione a Degustação:</label>
                    <select name="degustacao_id" required>
                        <option value="">-- Selecione --</option>
                        <?php foreach ($degustacoes as $deg): ?>
                            <option value="<?= $deg['id'] ?>" <?= $degustacao_id == $deg['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($deg['nome']) ?> - <?= date('d/m/Y', strtotime($deg['data'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit">📊 Gerar Relatório</button>
            </div>
        </form>
        
        <?php if ($degustacao_id > 0): ?>
            <div style="margin-top: 1rem; padding: 1rem; background: #e3f2fd; border-radius: 8px;">
                <strong>✅ ID Selecionado: <?= $degustacao_id ?></strong>
                <?php if (!$degustacao): ?>
                    <p style="color: #dc2626; margin-top: 0.5rem;">⚠️ Degustação não encontrada no banco de dados</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <?php if ($degustacao_id > 0 && $degustacao): ?>
        <div class="relatorio">
            <h2><?= htmlspecialchars($degustacao['nome']) ?></h2>
            <p><strong>Data:</strong> <?= date('d/m/Y', strtotime($degustacao['data'])) ?></p>
            <p><strong>Hora:</strong> <?= date('H:i', strtotime($degustacao['hora_inicio'])) ?></p>
            <p><strong>Inscrições:</strong> <?= count($inscritos) ?></p>
            <p><strong>Pessoas:</strong> <?= array_sum(array_column($inscritos, 'qtd_pessoas')) ?></p>
            
            <h3 style="margin-top: 1.5rem;">Mesas:</h3>
            <?php if (empty($inscritos)): ?>
                <p>Nenhum inscrito confirmado.</p>
            <?php else: ?>
                <?php foreach ($inscritos as $index => $inscrito): ?>
                    <div class="mesa-card">
                        <strong>Mesa <?= $index + 1 ?></strong> - 
                        <?= htmlspecialchars($inscrito['nome']) ?> - 
                        <?= $inscrito['qtd_pessoas'] ?> pessoas
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <div style="margin-top: 2rem; padding: 1rem; background: #fef3c7; border-radius: 8px;">
        <h3>🔗 Links para Teste:</h3>
        <ul style="margin-top: 0.5rem;">
            <li><a href="comercial_realizar_degustacao_ultra_simples.php?degustacao_id=17">comercial_realizar_degustacao_ultra_simples.php?degustacao_id=17</a></li>
            <li><a href="test_relatorio_simples.php?degustacao_id=17">test_relatorio_simples.php?degustacao_id=17</a></li>
        </ul>
    </div>
</body>
</html>

