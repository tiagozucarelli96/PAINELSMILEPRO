<?php
/**
 * comercial_realizar_degustacao.php — Relatório para realização de degustação
 * Permite selecionar uma degustação e gerar relatório com mesas e inscritos
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

// Buscar todas as degustações cadastradas
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

$degustacao_selecionada = null;
$inscritos = [];
$total_pessoas = 0;
$total_inscritos = 0;
$total_mesas = 0;
$mostrar_relatorio = false;

// Processar seleção de degustação
if (isset($_GET['degustacao_id']) && $_GET['degustacao_id'] > 0) {
    $degustacao_id = (int)$_GET['degustacao_id'];
    
    try {
        // Buscar dados da degustação
        $stmt = $pdo->prepare("
            SELECT id, nome, data, hora_inicio, hora_fim, local, capacidade
            FROM comercial_degustacoes
            WHERE id = :id
        ");
        $stmt->execute([':id' => $degustacao_id]);
        $degustacao_selecionada = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($degustacao_selecionada) {
            // Verificar qual coluna existe na tabela comercial_inscricoes
            $check_col = $pdo->query("
                SELECT column_name 
                FROM information_schema.columns 
                WHERE table_name = 'comercial_inscricoes' 
                AND column_name IN ('degustacao_id', 'event_id')
            ");
            $colunas = $check_col->fetchAll(PDO::FETCH_COLUMN);
            $coluna_id = in_array('degustacao_id', $colunas) ? 'degustacao_id' : 'event_id';
            
            error_log("Buscando inscritos - Degustação ID: {$degustacao_id}, Coluna usada: {$coluna_id}");
            
            // Buscar inscritos confirmados
            $stmt = $pdo->prepare("
                SELECT 
                    id,
                    nome,
                    email,
                    qtd_pessoas,
                    tipo_festa,
                    status
                FROM comercial_inscricoes
                WHERE {$coluna_id} = :deg_id
                AND status = 'confirmado'
                ORDER BY nome ASC
            ");
            $stmt->execute([':deg_id' => $degustacao_id]);
            $inscritos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("Inscritos encontrados: " . count($inscritos));
            
            $total_inscritos = count($inscritos);
            $total_mesas = $total_inscritos; // Cada inscrição = uma mesa
            $total_pessoas = array_sum(array_column($inscritos, 'qtd_pessoas'));
            
            error_log("Total inscritos: {$total_inscritos}, Total mesas: {$total_mesas}, Total pessoas: {$total_pessoas}");
            
            // Mostrar relatório automaticamente quando selecionar degustação
            $mostrar_relatorio = true;
        }
    } catch (Exception $e) {
        $error_message = "Erro ao buscar dados: " . $e->getMessage();
    }
}

includeSidebar('Comercial');
?>

<style>
.page-realizar-degustacao {
    padding: 2rem;
    max-width: 1400px;
    margin: 0 auto;
}

.page-header {
    margin-bottom: 2rem;
}

.page-title {
    font-size: 2rem;
    font-weight: 700;
    color: #1e3a8a;
    margin-bottom: 0.5rem;
}

.page-subtitle {
    color: #64748b;
    font-size: 1rem;
}

.selecao-container {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e7eb;
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
    padding: 0.75rem 1rem;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 1rem;
    background: white;
    color: #1f2937;
    transition: border-color 0.2s;
}

.form-select:focus {
    outline: none;
    border-color: #3b82f6;
}

.btn-gerar {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    border: none;
    padding: 0.875rem 2rem;
    border-radius: 8px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: transform 0.2s, box-shadow 0.2s;
}

.btn-gerar:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
}

.info-box {
    background: #f0f9ff;
    border: 2px solid #0ea5e9;
    border-radius: 8px;
    padding: 1rem;
    margin-top: 1rem;
}

.info-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 0.5rem;
}

.info-label {
    font-weight: 600;
    color: #0c4a6e;
}

.info-value {
    color: #075985;
    font-weight: 500;
}

.relatorio-container {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    border: 1px solid #e5e7eb;
}

.relatorio-header {
    text-align: center;
    margin-bottom: 2rem;
    padding-bottom: 1.5rem;
    border-bottom: 3px solid #3b82f6;
}

.relatorio-titulo {
    font-size: 1.75rem;
    font-weight: 700;
    color: #1e3a8a;
    margin-bottom: 0.5rem;
}

.relatorio-info {
    display: flex;
    justify-content: center;
    gap: 2rem;
    margin-top: 1rem;
    flex-wrap: wrap;
}

.relatorio-info-item {
    display: flex;
    flex-direction: column;
    align-items: center;
}

.relatorio-info-label {
    font-size: 0.875rem;
    color: #64748b;
    margin-bottom: 0.25rem;
}

.relatorio-info-value {
    font-size: 1.125rem;
    font-weight: 600;
    color: #1e3a8a;
}

.mesas-grid {
    display: grid;
    gap: 1.5rem;
    margin-top: 2rem;
}

.mesa-card {
    background: #f9fafb;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 1.5rem;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.mesa-card:hover {
    border-color: #3b82f6;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.1);
}

.mesa-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #e5e7eb;
}

.mesa-numero {
    font-size: 1.25rem;
    font-weight: 700;
    color: #3b82f6;
}

.mesa-pessoas {
    background: #dbeafe;
    color: #1e40af;
    padding: 0.375rem 0.75rem;
    border-radius: 6px;
    font-weight: 600;
    font-size: 0.875rem;
}

.inscrito-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 0;
}

.inscrito-nome {
    font-weight: 600;
    color: #1f2937;
}

.inscrito-tipo {
    background: #f3f4f6;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    color: #4b5563;
    text-transform: capitalize;
}

.acoes-relatorio {
    display: flex;
    gap: 1rem;
    justify-content: center;
    margin-top: 2rem;
    padding-top: 2rem;
    border-top: 2px solid #e5e7eb;
}

.btn-acao {
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 8px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: transform 0.2s, box-shadow 0.2s;
}

.btn-impressao {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
}

.btn-pdf {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
}

.btn-acao:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}

@media print {
    .selecao-container,
    .acoes-relatorio,
    .page-header {
        display: none;
    }
    
    .relatorio-container {
        box-shadow: none;
        border: none;
    }
    
    .mesa-card {
        page-break-inside: avoid;
    }
}
</style>

<div class="page-realizar-degustacao">
    <div class="page-header">
        <h1 class="page-title">🍽️ Realizar Degustação</h1>
        <p class="page-subtitle">Selecione uma degustação e gere o relatório de mesas e inscritos</p>
    </div>
    
    <!-- Seleção de Degustação -->
    <div class="selecao-container">
        <form method="GET" action="">
            <input type="hidden" name="page" value="comercial_realizar_degustacao">
            
            <div class="form-group">
                <label class="form-label">Selecione a Degustação</label>
                <select name="degustacao_id" class="form-select" id="selectDegustacao" onchange="this.form.submit()">
                    <option value="">-- Selecione uma degustação --</option>
                    <?php foreach ($degustacoes as $deg): ?>
                        <option value="<?= $deg['id'] ?>" 
                                <?= (isset($_GET['degustacao_id']) && $_GET['degustacao_id'] == $deg['id']) ? 'selected' : '' ?>>
                            <?= h($deg['nome']) ?> - <?= date('d/m/Y', strtotime($deg['data'])) ?> - <?= date('H:i', strtotime($deg['hora_inicio'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <?php if ($degustacao_selecionada): ?>
                <div class="info-box">
                    <div class="info-row">
                        <span class="info-label">Quantidade de Inscrições:</span>
                        <span class="info-value"><?= $total_inscritos ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Quantidade de Mesas:</span>
                        <span class="info-value"><?= $total_mesas ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Total de Pessoas:</span>
                        <span class="info-value"><?= $total_pessoas ?></span>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($degustacao_selecionada && !$mostrar_relatorio): ?>
                <button type="submit" name="gerar" value="1" class="btn-gerar" onclick="this.closest('form').submit()">
                    📊 Gerar Informações
                </button>
            <?php endif; ?>
        </form>
    </div>
    
    <!-- Debug Info (temporário para identificar problema) -->
    <?php if (isset($_GET['degustacao_id'])): ?>
        <div style="background: #fef3c7; padding: 1rem; margin: 1rem 0; border-radius: 8px; font-size: 0.875rem; border-left: 4px solid #f59e0b;">
            <strong>🔍 Debug:</strong><br>
            Degustação ID na URL: <code><?= htmlspecialchars($_GET['degustacao_id'] ?? 'não definido') ?></code><br>
            Degustação encontrada: <?= $degustacao_selecionada ? '✅ SIM' : '❌ NÃO' ?><br>
            Mostrar relatório: <?= $mostrar_relatorio ? '✅ SIM' : '❌ NÃO' ?><br>
            Total inscritos: <?= $total_inscritos ?><br>
            Total pessoas: <?= $total_pessoas ?><br>
            Total mesas: <?= $total_mesas ?><br>
            <?php if ($degustacao_selecionada): ?>
                <br><strong>Dados da degustação:</strong><br>
                Nome: <?= h($degustacao_selecionada['nome']) ?><br>
                Data: <?= $degustacao_selecionada['data'] ?><br>
                Hora: <?= $degustacao_selecionada['hora_inicio'] ?><br>
            <?php endif; ?>
            <?php if (!empty($inscritos)): ?>
                <br><strong>Primeiros 3 inscritos:</strong><br>
                <?php foreach (array_slice($inscritos, 0, 3) as $i): ?>
                    - <?= h($i['nome']) ?> (<?= $i['qtd_pessoas'] ?> pessoas)<br>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <!-- Relatório -->
    <?php if ($mostrar_relatorio && $degustacao_selecionada): ?>
        <div class="relatorio-container">
            <div class="relatorio-header">
                <h2 class="relatorio-titulo"><?= h($degustacao_selecionada['nome']) ?></h2>
                <div class="relatorio-info">
                    <div class="relatorio-info-item">
                        <span class="relatorio-info-label">📅 Data</span>
                        <span class="relatorio-info-value"><?= date('d/m/Y', strtotime($degustacao_selecionada['data'])) ?></span>
                    </div>
                    <div class="relatorio-info-item">
                        <span class="relatorio-info-label">🕐 Horário de Início</span>
                        <span class="relatorio-info-value"><?= date('H:i', strtotime($degustacao_selecionada['hora_inicio'])) ?></span>
                    </div>
                    <div class="relatorio-info-item">
                        <span class="relatorio-info-label">📍 Local</span>
                        <span class="relatorio-info-value"><?= h($degustacao_selecionada['local']) ?></span>
                    </div>
                    <div class="relatorio-info-item">
                        <span class="relatorio-info-label">👥 Total de Pessoas</span>
                        <span class="relatorio-info-value"><?= $total_pessoas ?></span>
                    </div>
                </div>
            </div>
            
            <div class="mesas-grid">
                <?php foreach ($inscritos as $index => $inscrito): ?>
                    <div class="mesa-card">
                        <div class="mesa-header">
                            <span class="mesa-numero">Mesa <?= $index + 1 ?></span>
                            <span class="mesa-pessoas"><?= $inscrito['qtd_pessoas'] ?> <?= $inscrito['qtd_pessoas'] == 1 ? 'pessoa' : 'pessoas' ?></span>
                        </div>
                        <div class="inscrito-info">
                            <div>
                                <div class="inscrito-nome"><?= h($inscrito['nome']) ?></div>
                            </div>
                            <?php if ($inscrito['tipo_festa']): ?>
                                <span class="inscrito-tipo"><?= ucfirst($inscrito['tipo_festa']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php if (empty($inscritos)): ?>
                <div style="text-align: center; padding: 3rem; color: #6b7280;">
                    <p style="font-size: 1.125rem;">Nenhum inscrito confirmado encontrado para esta degustação.</p>
                </div>
            <?php endif; ?>
            
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
    // Implementar geração de PDF no futuro
    alert('Funcionalidade de PDF será implementada em breve. Use a opção de Imprimir e salve como PDF no navegador.');
}

// Auto-submit quando selecionar degustação
const selectDegustacao = document.getElementById('selectDegustacao');
if (selectDegustacao) {
    selectDegustacao.addEventListener('change', function() {
        console.log('Select mudou para:', this.value);
        if (this.value) {
            const form = this.closest('form');
            if (form) {
                console.log('Submetendo formulário...');
                form.submit();
            } else {
                console.error('Formulário não encontrado');
            }
        }
    });
}
</script>

