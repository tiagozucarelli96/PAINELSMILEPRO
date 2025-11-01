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
$debug_info[] = "🔍 DEBUG: \$_GET completo = " . json_encode($_GET);
$debug_info[] = "🔍 DEBUG: \$_GET['degustacao_id'] = " . (isset($_GET['degustacao_id']) ? $_GET['degustacao_id'] : 'NÃO EXISTE');
$debug_info[] = "🔍 DEBUG: \$_GET['page'] = " . (isset($_GET['page']) ? $_GET['page'] : 'NÃO EXISTE');
$debug_info[] = "🔍 DEBUG: \$_REQUEST['degustacao_id'] = " . (isset($_REQUEST['degustacao_id']) ? $_REQUEST['degustacao_id'] : 'NÃO EXISTE');
$debug_info[] = "🔍 DEBUG: REQUEST_URI = " . ($_SERVER['REQUEST_URI'] ?? 'NÃO DEFINIDO');
$debug_info[] = "🔍 DEBUG: QUERY_STRING = " . ($_SERVER['QUERY_STRING'] ?? 'NÃO DEFINIDO');

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
        <form method="GET" action="" id="formDegustacao" name="formDegustacao">
            <input type="hidden" name="page" value="comercial_realizar_degustacao">
            
            <div class="form-group">
                <label class="form-label">Selecione a Degustação</label>
                <select name="degustacao_id" class="form-select" id="selectDegustacao">
                    <option value="">-- Selecione uma degustação --</option>
                    <?php foreach ($degustacoes as $deg): ?>
                        <option value="<?= $deg['id'] ?>" <?= $degustacao_id == $deg['id'] ? 'selected' : '' ?>>
                            <?= h($deg['nome']) ?> - <?= date('d/m/Y', strtotime($deg['data'])) ?> - <?= date('H:i', strtotime($deg['hora_inicio'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <?php if ($degustacao): ?>
                <div class="info-box">
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
            <?php endif; ?>
        </form>
    </div>
    
    <!-- Relatório -->
    <?php 
    // Debug: verificar condições ANTES do if
    $debug_info[] = "🔍 DEBUG: ===== VERIFICAÇÃO FINAL PARA MOSTRAR RELATÓRIO =====";
    $debug_info[] = "🔍 DEBUG: degustacao_id na variável = {$degustacao_id}";
    $debug_info[] = "🔍 DEBUG: degustacao é null? " . (is_null($degustacao) ? 'SIM' : 'NÃO');
    $debug_info[] = "🔍 DEBUG: degustacao é false? " . ($degustacao === false ? 'SIM' : 'NÃO');
    $debug_info[] = "🔍 DEBUG: degustacao existe? " . ($degustacao ? 'SIM (tem dados)' : 'NÃO (null ou false)');
    $debug_info[] = "🔍 DEBUG: degustacao_id > 0? " . ($degustacao_id > 0 ? 'SIM (' . $degustacao_id . ')' : 'NÃO');
    $condicao_final = ($degustacao && $degustacao_id > 0);
    $debug_info[] = "🔍 DEBUG: Condição (\$degustacao && \$degustacao_id > 0) = " . ($condicao_final ? 'VERDADEIRO ✅' : 'FALSO ❌');
    if ($degustacao) {
        $debug_info[] = "📋 DEBUG: Dados da degustação encontrada:";
        $debug_info[] = "   - ID: " . ($degustacao['id'] ?? 'NÃO TEM');
        $debug_info[] = "   - Nome: " . ($degustacao['nome'] ?? 'NÃO TEM');
    }
    ?>
    
    <?php if ($condicao_final): ?>
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
console.log('🔍 Script JavaScript carregado');
console.log('🔍 degustacao_id na URL:', new URLSearchParams(window.location.search).get('degustacao_id'));

function gerarPDF() {
    alert('Funcionalidade de PDF será implementada em breve. Use a opção de Imprimir e salve como PDF no navegador.');
}

// Função para configurar o select - pode ser chamada múltiplas vezes
function configurarSelectDegustacao() {
    const selectDegustacao = document.getElementById('selectDegustacao');
    const formDegustacao = document.getElementById('formDegustacao');
    
    if (!selectDegustacao) {
        console.error('❌ Select não encontrado! Tentando novamente em 100ms...');
        setTimeout(configurarSelectDegustacao, 100);
        return;
    }
    
    console.log('✅ Select encontrado!');
    
    // Remover listener anterior se existir
    const newSelect = selectDegustacao.cloneNode(true);
    selectDegustacao.parentNode.replaceChild(newSelect, selectDegustacao);
    
    // Adicionar novo listener
    const selectAtual = document.getElementById('selectDegustacao');
    
    selectAtual.addEventListener('change', function() {
        const selectedValue = this.value;
        console.log('🔍 Select mudou para:', selectedValue);
        
        if (selectedValue && selectedValue !== '') {
            const form = this.closest('form') || document.getElementById('formDegustacao');
            if (form) {
                console.log('✅ Formulário encontrado, submetendo...');
                form.submit();
            } else {
                console.error('❌ Formulário não encontrado!');
                alert('Erro: Formulário não encontrado. Recarregue a página.');
            }
        } else {
            console.log('⚠️ Valor vazio selecionado');
        }
    });
    
    // Log do valor inicial
    if (selectAtual.value && selectAtual.value !== '') {
        console.log('✅ Degustação já selecionada no carregamento:', selectAtual.value);
    } else {
        console.log('ℹ️ Nenhuma degustação selecionada inicialmente');
    }
}

// Tentar configurar quando DOM estiver pronto
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        console.log('🔍 DOM carregado (DOMContentLoaded)');
        configurarSelectDegustacao();
        verificarRelatorio();
    });
} else {
    // DOM já está pronto
    console.log('🔍 DOM já estava pronto');
    configurarSelectDegustacao();
    verificarRelatorio();
}

// Função para verificar se relatório apareceu
function verificarRelatorio() {
    setTimeout(function() {
        const relatorioContainer = document.querySelector('.relatorio-container');
        if (relatorioContainer) {
            console.log('✅ Relatório encontrado no DOM');
        } else {
            console.log('⚠️ Relatório NÃO encontrado no DOM');
            const degustacaoId = new URLSearchParams(window.location.search).get('degustacao_id');
            if (degustacaoId && parseInt(degustacaoId) > 0) {
                console.error('❌ ERRO: degustacao_id existe mas relatório não aparece!');
                console.error('❌ degustacao_id =', degustacaoId);
                console.error('❌ Possíveis causas:');
                console.error('   1. Degustação não encontrada no banco');
                console.error('   2. Nenhum inscrito confirmado');
                console.error('   3. Erro na query SQL');
                console.error('   4. Condição PHP não foi satisfeita');
                console.error('   5. Verifique o painel de debug amarelo na página');
            }
        }
    }, 500); // Aguardar 500ms para garantir que tudo foi renderizado
}
</script>
