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

// Verificar permissões
if (!lc_can_access_comercial()) {
    header('Location: index.php?page=dashboard&error=permission_denied');
    exit;
}

$pdo = $GLOBALS['pdo'];
$degustacao = null;
$inscritos = [];
$error_message = '';
$debug_info = [];

// Log inicial - VERIFICAR $_GET ANTES DE PROCESSAR
$debug_info[] = "🔍 DEBUG: Script iniciado";
$debug_info[] = "🔍 DEBUG: REQUEST_URI = " . ($_SERVER['REQUEST_URI'] ?? 'NÃO DEFINIDO');
$debug_info[] = "🔍 DEBUG: QUERY_STRING = " . ($_SERVER['QUERY_STRING'] ?? 'NÃO DEFINIDO');
$debug_info[] = "🔍 DEBUG: \$_GET completo = " . json_encode($_GET, JSON_UNESCAPED_UNICODE);
$debug_info[] = "🔍 DEBUG: \$_REQUEST completo = " . json_encode($_REQUEST, JSON_UNESCAPED_UNICODE);
$debug_info[] = "🔍 DEBUG: \$_GET['degustacao_id'] = " . (isset($_GET['degustacao_id']) ? var_export($_GET['degustacao_id'], true) : 'NÃO EXISTE');
$debug_info[] = "🔍 DEBUG: \$_GET['page'] = " . (isset($_GET['page']) ? var_export($_GET['page'], true) : 'NÃO EXISTE');
$debug_info[] = "🔍 DEBUG: \$_REQUEST['degustacao_id'] = " . (isset($_REQUEST['degustacao_id']) ? var_export($_REQUEST['degustacao_id'], true) : 'NÃO EXISTE');

// CRÍTICO: Tentar recuperar degustacao_id de múltiplas fontes ANTES de processar
// 1. Tentar da QUERY_STRING
if (!isset($_GET['degustacao_id']) && isset($_SERVER['QUERY_STRING'])) {
    parse_str($_SERVER['QUERY_STRING'], $parsed_query);
    if (isset($parsed_query['degustacao_id']) && !empty($parsed_query['degustacao_id'])) {
        $_GET['degustacao_id'] = $parsed_query['degustacao_id'];
        $debug_info[] = "✅ DEBUG: degustacao_id recuperado da QUERY_STRING via parse_str = " . $_GET['degustacao_id'];
    }
}

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

// Se selecionou uma degustação, buscar dados
if ($degustacao_id > 0) {
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
    
    <!-- Painel de Debug -->
    <?php if (!empty($debug_info)): ?>
        <div class="debug-panel">
            <h3>🔍 Log de Debug (desenvolvimento)</h3>
            <?php foreach ($debug_info as $log): ?>
                <div class="debug-item"><?= h($log) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error_message): ?>
        <div class="error-panel">
            ❌ <strong>Erro:</strong> <?= h($error_message) ?>
        </div>
    <?php endif; ?>
    
    <!-- Seleção de Degustação -->
    <div class="selecao-container">
        <div class="form-group">
            <label class="form-label">Selecione a Degustação</label>
            <select class="form-select" id="selectDegustacao">
                <option value="">-- Selecione uma degustação --</option>
                <?php foreach ($degustacoes as $deg): ?>
                    <option value="<?= $deg['id'] ?>" <?= $degustacao_id == $deg['id'] ? 'selected' : '' ?>>
                        <?= h($deg['nome']) ?> - <?= date('d/m/Y', strtotime($deg['data'])) ?> - <?= date('H:i', strtotime($deg['hora_inicio'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <!-- Info Box (será atualizado via AJAX) -->
        <div class="info-box" id="infoBox" style="display: none;">
            <div class="info-row">
                <span class="info-label">Inscrições Confirmadas:</span>
                <span class="info-value" id="totalInscritos">0</span>
            </div>
            <div class="info-row">
                <span class="info-label">Total de Mesas:</span>
                <span class="info-value" id="totalMesas">0</span>
            </div>
            <div class="info-row">
                <span class="info-label">Total de Pessoas:</span>
                <span class="info-value" id="totalPessoas">0</span>
            </div>
        </div>
        
        <!-- Loading indicator -->
        <div id="loadingIndicator" style="display: none; text-align: center; padding: 2rem; color: #6b7280;">
            <div class="spinner" style="border: 4px solid #f3f3f3; border-top: 4px solid #3b82f6; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto 1rem;"></div>
            <p>Carregando relatório...</p>
        </div>
        
        <style>
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        </style>
    </div>
    
    <!-- Relatório (será renderizado via JavaScript) -->
    <div id="relatorioContainer"></div>
    
    <!-- Versão PHP removida - agora tudo é AJAX -->
    <?php if (false): // Desabilitado - usando AJAX ?>
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
                        <div class="mesa-card">
                            <div class="mesa-header">
                                <span class="mesa-numero">Mesa <?= $index + 1 ?></span>
                                <span class="mesa-pessoas"><?= $inscrito['qtd_pessoas'] ?> <?= $inscrito['qtd_pessoas'] == 1 ? 'pessoa' : 'pessoas' ?></span>
                            </div>
                            <div class="inscrito-info">
                                <div class="inscrito-nome"><?= h($inscrito['nome']) ?></div>
                                <?php if (!empty($inscrito['tipo_festa'])): ?>
                                    <span class="inscrito-tipo"><?= ucfirst($inscrito['tipo_festa']) ?></span>
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
    <?php else: ?>
        <?php if ($degustacao_id > 0): ?>
            <div class="error-panel">
                ⚠️ <strong>Atenção:</strong> Degustação selecionada mas dados não encontrados. Verifique o painel de debug acima.
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
// Solução definitiva: AJAX independente do router
(function() {
    'use strict';
    
    console.log('🚀 Script JavaScript iniciado');
    
    // Construir caminho absoluto para a API baseado na URL atual
    const currentPath = window.location.pathname;
    const basePath = currentPath.substring(0, currentPath.lastIndexOf('/') + 1);
    const API_ENDPOINT = basePath + 'api_relatorio_degustacao.php';
    console.log('📡 API Endpoint:', API_ENDPOINT);
    console.log('📁 Base Path:', basePath);
    console.log('📍 Current Path:', currentPath);
    
    const selectDegustacao = document.getElementById('selectDegustacao');
    const relatorioContainer = document.getElementById('relatorioContainer');
    const infoBox = document.getElementById('infoBox');
    const loadingIndicator = document.getElementById('loadingIndicator');
    
    console.log('🔍 Elementos DOM:', {
        selectDegustacao: !!selectDegustacao,
        relatorioContainer: !!relatorioContainer,
        infoBox: !!infoBox,
        loadingIndicator: !!loadingIndicator
    });
    
    // Função para carregar relatório via AJAX
    async function carregarRelatorio(degustacaoId) {
        console.log('📥 carregarRelatorio chamado com degustacaoId:', degustacaoId);
        
        if (!degustacaoId || degustacaoId === '') {
            console.log('⚠️ degustacaoId vazio, limpando relatório');
            relatorioContainer.innerHTML = '';
            infoBox.style.display = 'none';
            return;
        }
        
        console.log('🔄 Iniciando carregamento do relatório...');
        loadingIndicator.style.display = 'block';
        relatorioContainer.innerHTML = '';
        infoBox.style.display = 'none';
        
        const url = `${API_ENDPOINT}?degustacao_id=${degustacaoId}`;
        console.log('🌐 Fazendo requisição AJAX para:', url);
        
        try {
            const response = await fetch(url, {
                method: 'GET',
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json'
                }
            });
            
            if (!response.ok) {
                throw new Error(`Erro HTTP: ${response.status}`);
            }
            
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                throw new Error('Resposta não é JSON: ' + text.substring(0, 100));
            }
            
            const data = await response.json();
            console.log('✅ Resposta recebida:', data);
            
            if (!data.success) {
                console.error('❌ API retornou success=false:', data.error);
                throw new Error(data.error || 'Erro desconhecido');
            }
            
            console.log('✅ Dados recebidos:', {
                degustacao: data.degustacao?.nome,
                total_inscritos: data.total_inscritos,
                total_pessoas: data.total_pessoas
            });
            
            // Atualizar info box
            document.getElementById('totalInscritos').textContent = data.total_inscritos;
            document.getElementById('totalMesas').textContent = data.total_inscritos;
            document.getElementById('totalPessoas').textContent = data.total_pessoas;
            infoBox.style.display = 'block';
            
            // Renderizar relatório
            console.log('🎨 Renderizando relatório...');
            renderizarRelatorio(data.degustacao, data.inscritos);
            console.log('✅ Relatório renderizado com sucesso');
            
            // Atualizar URL sem recarregar página
            const url = new URL(window.location);
            url.searchParams.set('degustacao_id', degustacaoId);
            window.history.pushState({degustacao_id: degustacaoId}, '', url);
            console.log('🔗 URL atualizada:', url.toString());
            
        } catch (error) {
            console.error('❌ Erro ao carregar relatório:', error);
            console.error('❌ Stack trace:', error.stack);
            relatorioContainer.innerHTML = `
                <div class="error-panel" style="padding: 2rem; text-align: center; background: #fef2f2; border: 2px solid #ef4444; border-radius: 8px; color: #991b1b;">
                    <p><strong>❌ Erro ao carregar relatório:</strong></p>
                    <p>${error.message}</p>
                </div>
            `;
        } finally {
            loadingIndicator.style.display = 'none';
        }
    }
    
    // Função para renderizar o relatório
    function renderizarRelatorio(degustacao, inscritos) {
        const dataFormatada = new Date(degustacao.data).toLocaleDateString('pt-BR');
        const horaFormatada = new Date('2000-01-01 ' + degustacao.hora_inicio).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
        const totalPessoas = inscritos.reduce((sum, insc) => sum + (parseInt(insc.qtd_pessoas) || 1), 0);
        
        let html = `
            <div class="relatorio-container">
                <div class="relatorio-header">
                    <h2 class="relatorio-titulo">${escapeHtml(degustacao.nome)}</h2>
                    <div class="relatorio-info">
                        <div class="relatorio-info-item">
                            <span class="relatorio-info-label">📅 Data</span>
                            <span class="relatorio-info-value">${dataFormatada}</span>
                        </div>
                        <div class="relatorio-info-item">
                            <span class="relatorio-info-label">🕐 Horário de Início</span>
                            <span class="relatorio-info-value">${horaFormatada}</span>
                        </div>
                        ${degustacao.local ? `
                        <div class="relatorio-info-item">
                            <span class="relatorio-info-label">📍 Local</span>
                            <span class="relatorio-info-value">${escapeHtml(degustacao.local)}</span>
                        </div>
                        ` : ''}
                        <div class="relatorio-info-item">
                            <span class="relatorio-info-label">👥 Total de Pessoas</span>
                            <span class="relatorio-info-value">${totalPessoas}</span>
                        </div>
                    </div>
                </div>
                
                <div class="mesas-grid">
        `;
        
        if (inscritos.length === 0) {
            html += `
                    <div style="grid-column: 1 / -1; text-align: center; padding: 3rem; color: #6b7280;">
                        <p style="font-size: 1.125rem;">Nenhum inscrito confirmado encontrado para esta degustação.</p>
                    </div>
            `;
        } else {
            inscritos.forEach((inscrito, index) => {
                const qtdPessoas = parseInt(inscrito.qtd_pessoas) || 1;
                html += `
                    <div class="mesa-card">
                        <div class="mesa-header">
                            <span class="mesa-numero">Mesa ${index + 1}</span>
                            <span class="mesa-pessoas">${qtdPessoas} ${qtdPessoas === 1 ? 'pessoa' : 'pessoas'}</span>
                        </div>
                        <div class="inscrito-info">
                            <div class="inscrito-nome">${escapeHtml(inscrito.nome)}</div>
                            ${inscrito.tipo_festa ? `<span class="inscrito-tipo">${escapeHtml(inscrito.tipo_festa.charAt(0).toUpperCase() + inscrito.tipo_festa.slice(1))}</span>` : ''}
                        </div>
                    </div>
                `;
            });
        }
        
        html += `
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
        `;
        
        relatorioContainer.innerHTML = html;
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function gerarPDF() {
        alert('Funcionalidade de PDF será implementada em breve. Use a opção de Imprimir e salve como PDF no navegador.');
    }
    
    // Configurar select quando DOM estiver pronto
    function init() {
        console.log('🔧 Função init() chamada');
        console.log('🔍 Estado do DOM:', document.readyState);
        
        if (!selectDegustacao) {
            console.warn('⚠️ Select não encontrado, tentando novamente em 100ms...');
            setTimeout(init, 100);
            return;
        }
        
        console.log('✅ Select encontrado! Valor atual:', selectDegustacao.value);
        
        // Listener para mudança no select
        selectDegustacao.addEventListener('change', function() {
            console.log('🔄 Select mudou! Novo valor:', this.value);
            carregarRelatorio(this.value);
        });
        
        // Verificar se há degustacao_id na URL ou no select
        const urlParams = new URLSearchParams(window.location.search);
        const degustacaoIdUrl = urlParams.get('degustacao_id');
        const degustacaoIdSelect = selectDegustacao.value;
        
        console.log('🔍 Verificando degustacao_id:', {
            naURL: degustacaoIdUrl,
            noSelect: degustacaoIdSelect,
            urlCompleta: window.location.href
        });
        
        if (degustacaoIdUrl && degustacaoIdUrl !== '') {
            console.log('✅ degustacao_id encontrado na URL:', degustacaoIdUrl);
            selectDegustacao.value = degustacaoIdUrl;
            carregarRelatorio(degustacaoIdUrl);
        } else if (degustacaoIdSelect && degustacaoIdSelect !== '') {
            console.log('✅ degustacao_id encontrado no select:', degustacaoIdSelect);
            carregarRelatorio(degustacaoIdSelect);
        } else {
            console.log('ℹ️ Nenhum degustacao_id encontrado, aguardando seleção do usuário');
        }
    }
    
    console.log('🔧 Configurando inicialização...');
    if (document.readyState === 'loading') {
        console.log('⏳ DOM ainda carregando, aguardando DOMContentLoaded...');
        document.addEventListener('DOMContentLoaded', function() {
            console.log('✅ DOMContentLoaded disparado');
            init();
        });
    } else {
        console.log('✅ DOM já pronto, iniciando imediatamente');
        init();
    }
})();
</script>
