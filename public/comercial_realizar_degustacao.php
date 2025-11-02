<?php
/**
 * comercial_realizar_degustacao.php — Relatório para realização de degustação
 * Versão com logs detalhados para debug
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';
require_once __DIR__ . '/core/helpers.php';

// Inicializar variáveis para debug ANTES de qualquer verificação
$debug_info = [];
$pdo = $GLOBALS['pdo'];
$degustacao = null;
$inscritos = [];
$error_message = '';
$perfil = null;

// CRÍTICO: Parsear QUERY_STRING MANUALMENTE antes de tudo (mesma lógica da versão direta que funciona)
if (isset($_SERVER['QUERY_STRING']) && !empty($_SERVER['QUERY_STRING'])) {
    parse_str($_SERVER['QUERY_STRING'], $parsed_all);
    // Mesclar com $_GET para garantir que temos tudo
    $_GET = array_merge($parsed_all, $_GET);
    $_REQUEST = array_merge($parsed_all, $_REQUEST);
}

// Log inicial - VERIFICAR $_GET ANTES DE PROCESSAR
$debug_info[] = "🔍 DEBUG: Script iniciado";
$debug_info[] = "🔍 DEBUG: REQUEST_URI = " . ($_SERVER['REQUEST_URI'] ?? 'NÃO DEFINIDO');
$debug_info[] = "🔍 DEBUG: QUERY_STRING = " . ($_SERVER['QUERY_STRING'] ?? 'NÃO DEFINIDO');
$debug_info[] = "🔍 DEBUG: \$_GET completo = " . json_encode($_GET, JSON_UNESCAPED_UNICODE);

// Verificar permissões
$tem_permissao = false;
try {
    $tem_permissao = lc_can_access_comercial();
    $perfil = lc_get_user_profile();
    
    $debug_info[] = "🔍 DEBUG: Verificação de permissão - lc_can_access_comercial() = " . ($tem_permissao ? 'true' : 'false');
    $debug_info[] = "🔍 DEBUG: Perfil do usuário = " . ($perfil ?? 'NÃO DEFINIDO');
    $debug_info[] = "🔍 DEBUG: Sessão logado = " . (isset($_SESSION['logado']) ? var_export($_SESSION['logado'], true) : 'NÃO DEFINIDO');
    $debug_info[] = "🔍 DEBUG: Sessão id = " . (isset($_SESSION['id']) ? var_export($_SESSION['id'], true) : 'NÃO DEFINIDO');
    $debug_info[] = "🔍 DEBUG: Sessão perm_usuarios = " . (isset($_SESSION['perm_usuarios']) ? var_export($_SESSION['perm_usuarios'], true) : 'NÃO DEFINIDO');
    $debug_info[] = "🔍 DEBUG: Sessão perm_pagamentos = " . (isset($_SESSION['perm_pagamentos']) ? var_export($_SESSION['perm_pagamentos'], true) : 'NÃO DEFINIDO');
} catch (Exception $e) {
    $debug_info[] = "❌ DEBUG: Erro ao verificar permissão: " . $e->getMessage();
    error_log("❌ Erro ao verificar permissão em comercial_realizar_degustacao.php: " . $e->getMessage());
}

// TEMPORÁRIO: Bypass de permissão para debug - descomente a linha abaixo para testar
// $tem_permissao = true;

if (!$tem_permissao) {
    $debug_info[] = "❌ DEBUG: SEM PERMISSÃO - Mostrando erro na página ao invés de redirecionar";
    $error_message = "Sem permissão para acessar esta página. Perfil: " . ($perfil ?? 'NÃO DEFINIDO');
    error_log("⚠️ comercial_realizar_degustacao.php: Sem permissão para acessar. Perfil: " . ($perfil ?? 'N/A') . ", Sessão logado: " . ($_SESSION['logado'] ?? 'N/A'));
    
    // NÃO redirecionar imediatamente - renderizar página com erro para debug
    // Removido: header('Location: index.php?page=dashboard&error=permission_denied'); exit;
}

// Continuar processamento mesmo sem permissão para mostrar debug
// Mas só processar dados se tiver permissão

// 2. Tentar do REQUEST_URI diretamente (última tentativa)
if (!isset($_GET['degustacao_id']) && isset($_SERVER['REQUEST_URI'])) {
    $request_uri = $_SERVER['REQUEST_URI'];
    // Procurar por degustacao_id= na URI
    if (preg_match('/degustacao_id[=:](\d+)/', $request_uri, $matches)) {
        $_GET['degustacao_id'] = $matches[1];
        $debug_info[] = "✅ DEBUG: degustacao_id recuperado do REQUEST_URI via regex = " . $_GET['degustacao_id'];
    } elseif (strpos($request_uri, 'degustacao_id=') !== false) {
        // Parsear manualmente se houver degustacao_id na URI
        $parts = parse_url($request_uri);
        if (isset($parts['query'])) {
            parse_str($parts['query'], $uri_params);
            if (isset($uri_params['degustacao_id']) && !empty($uri_params['degustacao_id'])) {
                $_GET['degustacao_id'] = $uri_params['degustacao_id'];
                $debug_info[] = "✅ DEBUG: degustacao_id recuperado do REQUEST_URI via parse_url = " . $_GET['degustacao_id'];
            }
        }
    }
}

// Tentar obter degustacao_id de múltiplas formas
$degustacao_id = 0;
if (isset($_GET['degustacao_id']) && $_GET['degustacao_id'] !== '') {
    $degustacao_id = (int)$_GET['degustacao_id'];
    $debug_info[] = "✅ DEBUG: degustacao_id obtido de \$_GET = {$degustacao_id}";
} elseif (isset($_REQUEST['degustacao_id']) && $_REQUEST['degustacao_id'] !== '') {
    $degustacao_id = (int)$_REQUEST['degustacao_id'];
    $debug_info[] = "✅ DEBUG: degustacao_id obtido de \$_REQUEST = {$degustacao_id}";
} else {
    // Tentar parsear da QUERY_STRING
    parse_str($_SERVER['QUERY_STRING'] ?? '', $query_params);
    if (isset($query_params['degustacao_id']) && $query_params['degustacao_id'] !== '') {
        $degustacao_id = (int)$query_params['degustacao_id'];
        $debug_info[] = "✅ DEBUG: degustacao_id obtido de QUERY_STRING = {$degustacao_id}";
    } else {
        $debug_info[] = "⚠️ DEBUG: degustacao_id NÃO encontrado em nenhum lugar";
    }
}

$debug_info[] = "🔍 DEBUG: degustacao_id FINAL = " . ($degustacao_id > 0 ? $degustacao_id : 'VAZIO/0');

// Buscar todas as degustações
try {
    $degustacoes = $pdo->query("
        SELECT id, nome, data, hora_inicio, local, capacidade
        FROM comercial_degustacoes
        ORDER BY data DESC, hora_inicio DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $debug_info[] = "✅ DEBUG: Degustações encontradas = " . count($degustacoes);
} catch (Exception $e) {
    $degustacoes = [];
    $error_message = "Erro ao buscar degustações: " . $e->getMessage();
    $debug_info[] = "❌ DEBUG: Erro ao buscar degustações - " . $e->getMessage();
}

// Se selecionou uma degustação, buscar dados (só se tiver permissão)
if ($degustacao_id > 0 && $tem_permissao) {
    $debug_info[] = "🔍 DEBUG: Processando degustacao_id = {$degustacao_id}";
    
    try {
        // Buscar degustação
        $stmt = $pdo->prepare("SELECT * FROM comercial_degustacoes WHERE id = :id");
        $stmt->execute([':id' => $degustacao_id]);
        $degustacao = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($degustacao) {
            $debug_info[] = "✅ DEBUG: Degustação encontrada - ID: {$degustacao['id']}, Nome: {$degustacao['nome']}";
            
            // Verificar qual coluna usar
            try {
                $check_col = $pdo->query("
                    SELECT column_name 
                    FROM information_schema.columns 
                    WHERE table_name = 'comercial_inscricoes' 
                    AND column_name IN ('degustacao_id', 'event_id')
                    LIMIT 1
                ");
                $col_result = $check_col->fetch(PDO::FETCH_ASSOC);
                
                if ($col_result) {
                    $coluna_id = ($col_result['column_name'] == 'degustacao_id') ? 'degustacao_id' : 'event_id';
                    $debug_info[] = "✅ DEBUG: Coluna usada = {$coluna_id}";
                } else {
                    $coluna_id = 'degustacao_id'; // Padrão
                    $debug_info[] = "⚠️ DEBUG: Nenhuma coluna encontrada, usando padrão degustacao_id";
                }
            } catch (Exception $e) {
                $coluna_id = 'degustacao_id'; // Padrão
                $debug_info[] = "⚠️ DEBUG: Erro ao verificar coluna - {$e->getMessage()}, usando padrão degustacao_id";
            }
            
            // Buscar inscritos confirmados
            try {
                $sql = "SELECT id, nome, qtd_pessoas, tipo_festa 
                        FROM comercial_inscricoes 
                        WHERE {$coluna_id} = :deg_id AND status = 'confirmado' 
                        ORDER BY nome ASC";
                $debug_info[] = "🔍 DEBUG: SQL = {$sql}";
                $debug_info[] = "🔍 DEBUG: Parâmetro deg_id = {$degustacao_id}";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':deg_id' => $degustacao_id]);
                $inscritos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $debug_info[] = "✅ DEBUG: Inscritos encontrados = " . count($inscritos);
                
                if (count($inscritos) > 0) {
                    $debug_info[] = "📋 DEBUG: Primeiros 3 inscritos:";
                    foreach (array_slice($inscritos, 0, 3) as $idx => $insc) {
                        $debug_info[] = "   - {$insc['nome']} ({$insc['qtd_pessoas']} pessoas)";
                    }
                } else {
                    $debug_info[] = "⚠️ DEBUG: Nenhum inscrito confirmado encontrado";
                }
            } catch (Exception $e) {
                $error_message = "Erro ao buscar inscritos: " . $e->getMessage();
                $debug_info[] = "❌ DEBUG: Erro ao buscar inscritos - " . $e->getMessage();
            }
        } else {
            $debug_info[] = "❌ DEBUG: Degustação NÃO encontrada com ID = {$degustacao_id}";
        }
    } catch (Exception $e) {
        $error_message = "Erro ao buscar dados: " . $e->getMessage();
        $debug_info[] = "❌ DEBUG: Erro geral - " . $e->getMessage();
    }
} else {
    $debug_info[] = "ℹ️ DEBUG: Nenhuma degustação selecionada ainda (degustacao_id = 0 ou vazio)";
}

includeSidebar('Comercial');
?>

<style>
.page-realizar-degustacao {
    max-width: 1400px;
    margin: 0 auto;
    padding: 2rem;
}

.page-header {
    margin-bottom: 2rem;
}

.page-title {
    font-size: 2rem;
    font-weight: 700;
    color: #1e3a8a;
    margin: 0 0 0.5rem 0;
}

.page-subtitle {
    color: #6b7280;
    font-size: 1rem;
    margin: 0;
}

.debug-panel {
    background: #fef3c7;
    border: 2px solid #f59e0b;
    border-radius: 8px;
    padding: 1.5rem;
    margin: 1.5rem 0;
    font-family: 'Courier New', monospace;
    font-size: 0.875rem;
    line-height: 1.6;
    max-height: 400px;
    overflow-y: auto;
}

.debug-panel h3 {
    margin: 0 0 1rem 0;
    color: #92400e;
    font-size: 1rem;
}

.debug-item {
    margin: 0.25rem 0;
    padding: 0.25rem 0;
    border-bottom: 1px solid #fde68a;
}

.debug-item:last-child {
    border-bottom: none;
}

.error-panel {
    background: #fee2e2;
    border: 2px solid #fca5a5;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1.5rem;
    color: #991b1b;
}

.selecao-container {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 2rem;
    margin-bottom: 2rem;
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-label {
    display: block;
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.5rem;
    font-size: 1rem;
}

.form-select {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 1rem;
    background: white;
    cursor: pointer;
}

.form-select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.info-box {
    background: #f0f9ff;
    border: 1px solid #bae6fd;
    border-radius: 8px;
    padding: 1.5rem;
    margin-top: 1.5rem;
}

.info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.5rem 0;
    border-bottom: 1px solid #e0f2fe;
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    font-weight: 600;
    color: #0c4a6e;
}

.info-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: #0369a1;
}

.relatorio-container {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 2rem;
    margin-top: 2rem;
}

.relatorio-header {
    border-bottom: 2px solid #e5e7eb;
    padding-bottom: 1.5rem;
    margin-bottom: 2rem;
}

.relatorio-titulo {
    font-size: 1.75rem;
    font-weight: 700;
    color: #1e3a8a;
    margin: 0 0 1rem 0;
}

.relatorio-info {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.relatorio-info-item {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.relatorio-info-label {
    font-size: 0.875rem;
    color: #6b7280;
    font-weight: 500;
}

.relatorio-info-value {
    font-size: 1.125rem;
    font-weight: 600;
    color: #1f2937;
}

.mesas-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.mesa-card {
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 1rem;
    transition: transform 0.2s, box-shadow 0.2s;
}

.mesa-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.mesa-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.75rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid #e5e7eb;
}

.mesa-numero {
    font-size: 1.125rem;
    font-weight: 700;
    color: #1e3a8a;
}

.mesa-pessoas {
    font-size: 0.875rem;
    color: #6b7280;
    background: #e0f2fe;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
}

.inscrito-info {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.inscrito-nome {
    font-weight: 600;
    color: #1f2937;
    font-size: 1rem;
}

.inscrito-tipo {
    font-size: 0.875rem;
    color: #6b7280;
    background: #f3f4f6;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    display: inline-block;
    width: fit-content;
}

.acoes-relatorio {
    display: flex;
    gap: 1rem;
    justify-content: center;
    padding-top: 2rem;
    border-top: 1px solid #e5e7eb;
}

.btn-acao {
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 1rem;
    cursor: pointer;
    transition: opacity 0.2s;
}

.btn-acao:hover {
    opacity: 0.9;
}

.btn-impressao {
    background: #3b82f6;
    color: white;
}

.btn-pdf {
    background: #10b981;
    color: white;
}

@media print {
    .selecao-container,
    .acoes-relatorio,
    .debug-panel {
        display: none;
    }
    
    .relatorio-container {
        border: none;
        padding: 0;
    }
}
</style>

<div class="page-realizar-degustacao">
    <div class="page-header">
        <h1 class="page-title">🍽️ Realizar Degustação</h1>
        <p class="page-subtitle">Selecione uma degustação para gerar o relatório de mesas e inscritos</p>
    </div>
    
    <!-- Painel de Debug - SEMPRE VISÍVEL para ajudar -->
    <div class="debug-panel">
        <h3>🔍 Log de Debug</h3>
        <?php if (!empty($debug_info)): ?>
            <?php foreach ($debug_info as $log): ?>
                <div class="debug-item"><?= h($log) ?></div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="debug-item">Nenhuma informação de debug disponível.</div>
        <?php endif; ?>
        
        <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #fde68a;">
            <strong>URL atual:</strong> <code style="background: #fef3c7; padding: 2px 6px; border-radius: 4px;"><?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'N/A') ?></code>
            <br>
            <strong>degustacao_id na URL:</strong> <code style="background: #fef3c7; padding: 2px 6px; border-radius: 4px;"><?= $degustacao_id > 0 ? $degustacao_id : 'NÃO ENCONTRADO' ?></code>
            <br>
            <strong>Formulário:</strong> Se você selecionou uma degustação e clicou em "Gerar Relatório", o valor deve aparecer acima.
        </div>
    </div>
    
    <?php if ($error_message): ?>
        <div class="error-panel">
            ❌ <strong>Erro:</strong> <?= h($error_message) ?>
        </div>
    <?php endif; ?>
    
    <!-- Seleção de Degustação - SOLUÇÃO SIMPLIFICADA E ROBUSTA -->
    <div class="selecao-container">
        <!-- SOLUÇÃO: Usar action="index.php" e enviar page via campo oculto
             Isso garante que tanto page quanto degustacao_id cheguem juntos no GET -->
        <form method="GET" action="index.php" id="formSelecaoDegustacao" style="margin-bottom: 2rem;">
            <input type="hidden" name="page" value="comercial_realizar_degustacao">
            <div class="form-group" style="display: flex; gap: 1rem; align-items: flex-end;">
                <div style="flex: 1;">
                    <label class="form-label">Selecione a Degustação</label>
                    <select name="degustacao_id" id="selectDegustacao" class="form-select" required style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 1rem;">
                        <option value="">-- Selecione uma degustação --</option>
                        <?php foreach ($degustacoes as $deg): ?>
                            <option value="<?= $deg['id'] ?>" <?= $degustacao_id == $deg['id'] ? 'selected' : '' ?>>
                                <?= h($deg['nome']) ?> - <?= date('d/m/Y', strtotime($deg['data'])) ?> - <?= date('H:i', strtotime($deg['hora_inicio'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-primary" style="padding: 12px 24px; background: #3b82f6; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; white-space: nowrap;">
                    📊 Gerar Relatório
                </button>
            </div>
        </form>
        
        <?php if ($degustacao_id > 0): ?>
        <div style="margin-top: 1rem; padding: 1rem; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; color: #0369a1;">
            <strong>✅ Degustação selecionada (ID: <?= $degustacao_id ?>)</strong>
            <?php if (isset($degustacao)): ?>
                <p style="margin: 0.5rem 0 0 0;"><?= h($degustacao['nome']) ?></p>
            <?php else: ?>
                <p style="margin: 0.5rem 0 0 0; color: #dc2626;">⚠️ Degustação não encontrada no banco de dados</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Info Box e Relatório (renderizado pelo PHP quando há degustacao_id) -->
    <?php if ($degustacao_id > 0 && isset($degustacao)): ?>
        <!-- Info Box -->
        <div class="info-box" style="display: block;">
            <div class="info-row">
                <span class="info-label">Inscrições Confirmadas:</span>
                <span class="info-value"><?= count($inscritos) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Total de Mesas:</span>
                <span class="info-value"><?= count($inscritos) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Total de Pessoas:</span>
                <span class="info-value"><?= array_sum(array_column($inscritos, 'qtd_pessoas')) ?></span>
            </div>
        </div>
        
        <!-- Relatório -->
        <div class="relatorio-container">
            <div class="relatorio-header">
                <h2 class="relatorio-titulo"><?= h($degustacao['nome']) ?></h2>
                <div class="relatorio-info">
                    <div class="relatorio-info-item">
                        <span class="relatorio-info-label">📅 Data</span>
                        <span class="relatorio-info-value"><?= date('d/m/Y', strtotime($degustacao['data'])) ?></span>
                    </div>
                    <div class="relatorio-info-item">
                        <span class="relatorio-info-label">🕐 Horário de Início</span>
                        <span class="relatorio-info-value"><?= date('H:i', strtotime($degustacao['hora_inicio'])) ?></span>
                    </div>
                    <?php if (!empty($degustacao['local'])): ?>
                    <div class="relatorio-info-item">
                        <span class="relatorio-info-label">📍 Local</span>
                        <span class="relatorio-info-value"><?= h($degustacao['local']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="relatorio-info-item">
                        <span class="relatorio-info-label">👥 Total de Pessoas</span>
                        <span class="relatorio-info-value"><?= array_sum(array_column($inscritos, 'qtd_pessoas')) ?></span>
                    </div>
                </div>
            </div>
            
            <div class="mesas-grid">
                <?php if (empty($inscritos)): ?>
                    <div style="grid-column: 1 / -1; text-align: center; padding: 3rem; color: #6b7280;">
                        <p style="font-size: 1.125rem;">Nenhum inscrito confirmado encontrado para esta degustação.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($inscritos as $index => $inscrito): ?>
                        <?php $qtdPessoas = (int)($inscrito['qtd_pessoas'] ?? 1); ?>
                        <div class="mesa-card">
                            <div class="mesa-header">
                                <span class="mesa-numero">Mesa <?= $index + 1 ?></span>
                                <span class="mesa-pessoas"><?= $qtdPessoas ?> <?= $qtdPessoas === 1 ? 'pessoa' : 'pessoas' ?></span>
                            </div>
                            <div class="inscrito-info">
                                <div class="inscrito-nome"><?= h($inscrito['nome']) ?></div>
                                <?php if (!empty($inscrito['tipo_festa'])): ?>
                                <span class="inscrito-tipo"><?= h(ucfirst($inscrito['tipo_festa'])) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div class="acoes-relatorio">
                <button type="button" class="btn-acao btn-impressao" onclick="window.print()">
                    🖨️ Imprimir
                </button>
                <button type="button" class="btn-acao btn-pdf" onclick="gerarPDF()">
                    📄 Gerar PDF
                </button>
            </div>
        </div>
    <?php elseif ($degustacao_id > 0 && !isset($degustacao)): ?>
        <div class="error-panel">
            ⚠️ <strong>Atenção:</strong> Degustação selecionada (ID: <?= $degustacao_id ?>) mas dados não encontrados no banco de dados.
            <br><br>
            Verifique o painel de debug acima para mais detalhes.
        </div>
    <?php else: ?>
        <div style="text-align: center; padding: 3rem; color: #6b7280; background: white; border: 1px solid #e5e7eb; border-radius: 12px; margin-top: 2rem;">
            <p style="font-size: 1.125rem; margin: 0; font-weight: 600;">📋 Instruções:</p>
            <p style="font-size: 1rem; margin: 1rem 0 0 0;">Selecione uma degustação no dropdown acima e clique em <strong>"📊 Gerar Relatório"</strong> para visualizar os dados.</p>
        </div>
    <?php endif; ?>
</div>

<script>
// SOLUÇÃO 100% SERVER-SIDE: Formulário tradicional GET, relatório renderizado pelo PHP
// Sem AJAX, sem eventos change complexos, sem clonagem - funciona sempre
(function() {
    'use strict';
    
    // Função simples para gerar PDF (placeholder)
    function gerarPDF() {
        alert('Funcionalidade de PDF será implementada em breve. Use a opção de Imprimir e salve como PDF no navegador.');
    }
    
    // Tornar função global
    window.gerarPDF = gerarPDF;
    
    // Log quando formulário for submetido (apenas para debug, não interferir no submit)
    const form = document.getElementById('formSelecaoDegustacao');
    if (form) {
        form.addEventListener('submit', function(e) {
            const degustacaoId = this.querySelector('[name="degustacao_id"]').value;
            const page = this.querySelector('[name="page"]').value;
            console.log('📤 Formulário sendo submetido:');
            console.log('   - page:', page);
            console.log('   - degustacao_id:', degustacaoId);
            console.log('   - URL será: index.php?page=' + page + '&degustacao_id=' + degustacaoId);
            // NÃO prevenir o submit - deixar formulário funcionar normalmente
        });
        
        console.log('✅ Formulário "Realizar Degustação" configurado. Método GET tradicional.');
    }
    
    console.log('✅ Página "Realizar Degustação" carregada. Formulário tradicional GET - funciona sempre.');
})();
</script>
