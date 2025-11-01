<?php
/**
 * comercial_realizar_degustacao.php — Relatório para realização de degustação
 * Versão simplificada e funcional
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
$degustacao_id = isset($_GET['degustacao_id']) ? (int)$_GET['degustacao_id'] : 0;
$degustacao = null;
$inscritos = [];
$error_message = '';

// Buscar todas as degustações
try {
    $degustacoes = $pdo->query("
        SELECT id, nome, data, hora_inicio, local, capacidade
        FROM comercial_degustacoes
        ORDER BY data DESC, hora_inicio DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $degustacoes = [];
    $error_message = "Erro ao buscar degustações: " . $e->getMessage();
}

// Se selecionou uma degustação, buscar dados
if ($degustacao_id > 0) {
    try {
        // Buscar degustação
        $stmt = $pdo->prepare("SELECT * FROM comercial_degustacoes WHERE id = :id");
        $stmt->execute([':id' => $degustacao_id]);
        $degustacao = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($degustacao) {
            // Verificar qual coluna usar
            $check_col = $pdo->query("
                SELECT column_name 
                FROM information_schema.columns 
                WHERE table_name = 'comercial_inscricoes' 
                AND column_name IN ('degustacao_id', 'event_id')
                LIMIT 1
            ");
            $col_result = $check_col->fetch(PDO::FETCH_ASSOC);
            $coluna_id = ($col_result && $col_result['column_name'] == 'degustacao_id') ? 'degustacao_id' : 'event_id';
            
            // Buscar inscritos confirmados
            $sql = "SELECT id, nome, qtd_pessoas, tipo_festa 
                    FROM comercial_inscricoes 
                    WHERE {$coluna_id} = :deg_id AND status = 'confirmado' 
                    ORDER BY nome ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':deg_id' => $degustacao_id]);
            $inscritos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        $error_message = "Erro ao buscar dados: " . $e->getMessage();
    }
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
    .acoes-relatorio {
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
    
    <?php if ($error_message): ?>
        <div style="background: #fee2e2; border: 1px solid #fca5a5; border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem; color: #991b1b;">
            ❌ <?= h($error_message) ?>
        </div>
    <?php endif; ?>
    
    <!-- Seleção de Degustação -->
    <div class="selecao-container">
        <form method="GET" action="">
            <input type="hidden" name="page" value="comercial_realizar_degustacao">
            
            <div class="form-group">
                <label class="form-label">Selecione a Degustação</label>
                <select name="degustacao_id" class="form-select" id="selectDegustacao" onchange="this.form.submit()">
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
    <?php if ($degustacao && $degustacao_id > 0): ?>
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
    <?php endif; ?>
</div>

<script>
function gerarPDF() {
    alert('Funcionalidade de PDF será implementada em breve. Use a opção de Imprimir e salve como PDF no navegador.');
}
</script>
