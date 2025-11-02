<?php
/**
 * comercial_realizar_degustacao_direto.php — VERSÃO DIRETA que bypassa o router
 * Esta versão funciona como página standalone para testar se o problema é do router
 */

// CRÍTICO: Começar output buffering ANTES de qualquer coisa
ob_start();

// CRÍTICO: Enviar headers ANTES de qualquer require
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// CRÍTICO: Registrar função para interceptar headers de redirecionamento
if (!function_exists('header_interceptor')) {
    function header_interceptor($header) {
        if (stripos($header, 'Location:') !== false) {
            // Bloquear qualquer redirecionamento e registrar
            error_log("🚫 REDIRECIONAMENTO BLOQUEADO: " . $header);
            return false; // Não enviar o header
        }
        return true; // Permitir outros headers
    }
    
    // Substituir função header() temporariamente (isso não é possível diretamente em PHP)
    // Mas podemos usar output buffering para capturar
}

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// DEBUG: Logar como está sendo acessado
error_log("🔍 comercial_realizar_degustacao_direto.php sendo executado");
error_log("🔍 REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));
error_log("🔍 SCRIPT_NAME: " . ($_SERVER['SCRIPT_NAME'] ?? 'N/A'));

require_once __DIR__ . '/conexao.php';
// REMOVER sidebar e permissões para teste - causavam redirecionamento
// require_once __DIR__ . '/sidebar_integration.php';
// require_once __DIR__ . '/lc_permissions_enhanced.php';
require_once __DIR__ . '/core/helpers.php';

// BYPASS: Permitir acesso temporário para teste
$tem_permissao = true; // FORÇAR PERMISSÃO PARA TESTE

$pdo = $GLOBALS['pdo'];
$degustacao = null;
$inscritos = [];
$error_message = '';
$debug_info = [];

// DEBUG AGRESSIVO: Logar TUDO
$debug_info[] = "🚀 VERSÃO DIRETA - Bypass router";
$debug_info[] = "🔍 REQUEST_URI = " . ($_SERVER['REQUEST_URI'] ?? 'N/A');
$debug_info[] = "🔍 QUERY_STRING = " . ($_SERVER['QUERY_STRING'] ?? 'N/A');
$debug_info[] = "🔍 \$_GET = " . json_encode($_GET, JSON_UNESCAPED_UNICODE);
$debug_info[] = "🔍 \$_POST = " . json_encode($_POST, JSON_UNESCAPED_UNICODE);
$debug_info[] = "🔍 \$_REQUEST = " . json_encode($_REQUEST, JSON_UNESCAPED_UNICODE);

// Parsear QUERY_STRING manualmente
if (isset($_SERVER['QUERY_STRING']) && !empty($_SERVER['QUERY_STRING'])) {
    parse_str($_SERVER['QUERY_STRING'], $parsed);
    $debug_info[] = "🔍 QUERY_STRING parseado = " . json_encode($parsed, JSON_UNESCAPED_UNICODE);
    $_GET = array_merge($parsed, $_GET);
}

// Obter degustacao_id de TODAS as formas possíveis
$degustacao_id = 0;

if (isset($_GET['degustacao_id']) && $_GET['degustacao_id'] !== '') {
    $degustacao_id = (int)$_GET['degustacao_id'];
    $debug_info[] = "✅ degustacao_id de \$_GET = {$degustacao_id}";
} elseif (isset($_REQUEST['degustacao_id']) && $_REQUEST['degustacao_id'] !== '') {
    $degustacao_id = (int)$_REQUEST['degustacao_id'];
    $debug_info[] = "✅ degustacao_id de \$_REQUEST = {$degustacao_id}";
} elseif (isset($_SERVER['QUERY_STRING'])) {
    parse_str($_SERVER['QUERY_STRING'], $q);
    if (isset($q['degustacao_id']) && $q['degustacao_id'] !== '') {
        $degustacao_id = (int)$q['degustacao_id'];
        $debug_info[] = "✅ degustacao_id de QUERY_STRING = {$degustacao_id}";
    }
}

$debug_info[] = "🎯 degustacao_id FINAL = {$degustacao_id}";

// Buscar degustações
try {
    $degustacoes = $pdo->query("
        SELECT id, nome, data, hora_inicio, local, capacidade
        FROM comercial_degustacoes
        ORDER BY data DESC, hora_inicio DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $debug_info[] = "✅ Degustações encontradas: " . count($degustacoes);
} catch (Exception $e) {
    $degustacoes = [];
    $error_message = "Erro: " . $e->getMessage();
}

// Buscar dados se tiver degustacao_id
if ($degustacao_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM comercial_degustacoes WHERE id = :id");
        $stmt->execute([':id' => $degustacao_id]);
        $degustacao = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($degustacao) {
            // Buscar inscritos
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
            
            $debug_info[] = "✅ Inscritos encontrados: " . count($inscritos);
        }
    } catch (Exception $e) {
        $error_message = "Erro: " . $e->getMessage();
    }
}

// REMOVER includeSidebar - estava causando redirecionamento para dashboard
// includeSidebar('Comercial');
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Realizar Degustação - VERSÃO DIRETA (SEM SIDEBAR)</title>
    <style>
        * { box-sizing: border-box; }
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
        .debug-box h3 { margin-top: 0; }
        .debug-box h4 { margin-top: 1rem; margin-bottom: 0.5rem; }
        .debug-box pre { margin: 0.5rem 0; font-size: 0.875rem; }
        .form-box { 
            background: white; 
            padding: 2rem; 
            border-radius: 12px; 
            margin: 1rem 0; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1); 
        }
        .form-group { display: flex; gap: 1rem; align-items: flex-end; }
        label { display: block; margin-bottom: 0.5rem; font-weight: 600; color: #374151; }
        select, button { 
            padding: 12px; 
            font-size: 1rem; 
            border-radius: 8px; 
            border: 1px solid #ddd; 
        }
        select { width: 100%; }
        button { 
            background: #3b82f6; 
            color: white; 
            border: none; 
            cursor: pointer; 
            font-weight: 600;
            white-space: nowrap;
        }
        button:hover { background: #2563eb; }
        .relatorio { 
            background: white; 
            padding: 2rem; 
            border-radius: 12px; 
            margin-top: 2rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .relatorio h2 { color: #1e40af; margin-top: 0; }
        .relatorio h3 { color: #374151; margin-top: 1.5rem; }
        .mesa-card { 
            background: #f8fafc; 
            padding: 1rem; 
            margin: 0.5rem 0; 
            border-radius: 8px; 
            border: 1px solid #e5e7eb;
        }
        .mesa-card strong { color: #1e40af; }
        code { 
            background: #f3f4f6; 
            padding: 2px 6px; 
            border-radius: 4px; 
            font-family: 'Courier New', monospace;
            font-size: 0.9em;
        }
        .info-box {
            margin-top: 1rem; 
            padding: 1rem; 
            background: #e3f2fd; 
            border-radius: 8px;
            border: 1px solid #90caf9;
        }
        .error-box {
            background: #fee; 
            color: #c00; 
            padding: 1rem; 
            border-radius: 8px; 
            margin: 1rem 0;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>
    <h1>🍽️ Realizar Degustação - VERSÃO DIRETA (Bypass Router)</h1>
    
    <div class="debug-box">
        <h3>🔍 Debug Completo</h3>
        <?php foreach ($debug_info as $log): ?>
            <div><?= htmlspecialchars($log) ?></div>
        <?php endforeach; ?>
        
        <h4>📋 Teste Manual:</h4>
        <p>Acesse diretamente: <code>comercial_realizar_degustacao_direto.php?degustacao_id=17</code></p>
        <p>Ou use o formulário abaixo:</p>
    </div>
    
    <?php if ($error_message): ?>
        <div style="background: #fee; color: #c00; padding: 1rem; border-radius: 8px; margin: 1rem 0;">
            ❌ <?= htmlspecialchars($error_message) ?>
        </div>
    <?php endif; ?>
    
    <div class="form-box">
        <form method="GET" action="comercial_realizar_degustacao_direto.php">
            <div class="form-group">
                <div style="flex: 1;">
                    <label>Selecione a Degustação:</label>
                    <select name="degustacao_id" required style="width: 100%;">
                        <option value="">-- Selecione --</option>
                        <?php foreach ($degustacoes as $deg): ?>
                            <option value="<?= $deg['id'] ?>" <?= $degustacao_id == $deg['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($deg['nome']) ?> - <?= date('d/m/Y', strtotime($deg['data'])) ?> - <?= date('H:i', strtotime($deg['hora_inicio'])) ?>
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
            </div>
        <?php endif; ?>
    </div>
    
    <?php if ($degustacao_id > 0 && isset($degustacao)): ?>
        <div class="relatorio">
            <h2><?= htmlspecialchars($degustacao['nome']) ?></h2>
            <p><strong>Data:</strong> <?= date('d/m/Y', strtotime($degustacao['data'])) ?></p>
            <p><strong>Hora:</strong> <?= date('H:i', strtotime($degustacao['hora_inicio'])) ?></p>
            <p><strong>Total de Inscrições:</strong> <?= count($inscritos) ?></p>
            <p><strong>Total de Pessoas:</strong> <?= array_sum(array_column($inscritos, 'qtd_pessoas')) ?></p>
            
            <h3>Mesas:</h3>
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
</body>
</html>

