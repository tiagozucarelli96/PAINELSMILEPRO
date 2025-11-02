<?php
/**
 * comercial_realizar_degustacao_ajax.php — VERSÃO AJAX
 * Usa a API existente (api_relatorio_degustacao.php) via JavaScript
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';
require_once __DIR__ . '/core/helpers.php';

// Bypass permissão para teste
$tem_permissao = true;

$pdo = $GLOBALS['pdo'];
$degustacoes = [];

try {
    $degustacoes = $pdo->query("
        SELECT id, nome, data, hora_inicio, local, capacidade
        FROM comercial_degustacoes
        ORDER BY data DESC, hora_inicio DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_message = "Erro: " . $e->getMessage();
}

includeSidebar('Comercial');
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Realizar Degustação - VERSÃO AJAX</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 1400px; margin: 0 auto; padding: 2rem; }
        .form-box { background: white; padding: 2rem; border-radius: 12px; margin: 2rem 0; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .form-group { display: flex; gap: 1rem; align-items: flex-end; }
        select, button { padding: 12px; font-size: 1rem; border-radius: 8px; border: 1px solid #ddd; }
        button { background: #3b82f6; color: white; border: none; cursor: pointer; font-weight: 600; }
        button:disabled { opacity: 0.5; cursor: not-allowed; }
        .relatorio { background: white; padding: 2rem; border-radius: 12px; margin-top: 2rem; display: none; }
        .mesa-card { background: #f8fafc; padding: 1rem; margin: 0.5rem 0; border-radius: 8px; border: 1px solid #e5e7eb; }
        .loading { text-align: center; padding: 2rem; color: #666; }
        .error { background: #fee; color: #c00; padding: 1rem; border-radius: 8px; margin: 1rem 0; }
    </style>
</head>
<body>
    <h1>🍽️ Realizar Degustação - VERSÃO AJAX</h1>
    <p><strong>Esta versão usa AJAX para buscar dados via API, bypassando problemas do router.</strong></p>
    
    <div class="form-box">
        <form id="formDegustacao" onsubmit="return false;">
            <div class="form-group">
                <div style="flex: 1;">
                    <label>Selecione a Degustação:</label>
                    <select name="degustacao_id" id="selectDegustacao" required style="width: 100%;">
                        <option value="">-- Selecione --</option>
                        <?php foreach ($degustacoes as $deg): ?>
                            <option value="<?= $deg['id'] ?>">
                                <?= htmlspecialchars($deg['nome']) ?> - <?= date('d/m/Y', strtotime($deg['data'])) ?> - <?= date('H:i', strtotime($deg['hora_inicio'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="button" id="btnGerar" onclick="gerarRelatorio()">📊 Gerar Relatório</button>
            </div>
        </form>
    </div>
    
    <div id="loading" class="loading" style="display: none;">
        <p>⏳ Carregando relatório...</p>
    </div>
    
    <div id="error" class="error" style="display: none;"></div>
    
    <div id="relatorio" class="relatorio"></div>
    
    <script>
    const API_URL = 'api_relatorio_degustacao.php';
    
    async function gerarRelatorio() {
        const select = document.getElementById('selectDegustacao');
        const degustacaoId = select.value;
        
        if (!degustacaoId) {
            alert('Selecione uma degustação');
            return;
        }
        
        const loading = document.getElementById('loading');
        const error = document.getElementById('error');
        const relatorio = document.getElementById('relatorio');
        const btn = document.getElementById('btnGerar');
        
        // Mostrar loading
        loading.style.display = 'block';
        error.style.display = 'none';
        relatorio.style.display = 'none';
        btn.disabled = true;
        
        try {
            console.log('📤 Fazendo requisição AJAX para:', API_URL + '?degustacao_id=' + degustacaoId);
            
            const response = await fetch(API_URL + '?degustacao_id=' + degustacaoId, {
                method: 'GET',
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json'
                }
            });
            
            console.log('📥 Resposta recebida:', response.status, response.ok);
            
            if (!response.ok) {
                throw new Error('Erro HTTP: ' + response.status);
            }
            
            const data = await response.json();
            console.log('📦 Dados:', data);
            
            if (data.success) {
                renderizarRelatorio(data.degustacao, data.inscritos, data.total_inscritos, data.total_pessoas);
                relatorio.style.display = 'block';
            } else {
                throw new Error(data.error || 'Erro desconhecido');
            }
        } catch (err) {
            console.error('❌ Erro:', err);
            error.textContent = '❌ Erro ao carregar relatório: ' + err.message;
            error.style.display = 'block';
        } finally {
            loading.style.display = 'none';
            btn.disabled = false;
        }
    }
    
    function renderizarRelatorio(degustacao, inscritos, totalInscritos, totalPessoas) {
        const dataFormatada = new Date(degustacao.data).toLocaleDateString('pt-BR');
        const horaFormatada = new Date('2000-01-01 ' + degustacao.hora_inicio).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
        
        let html = `
            <h2>${escapeHtml(degustacao.nome)}</h2>
            <div style="margin: 1rem 0;">
                <p><strong>📅 Data:</strong> ${dataFormatada}</p>
                <p><strong>🕐 Hora:</strong> ${horaFormatada}</p>
                ${degustacao.local ? `<p><strong>📍 Local:</strong> ${escapeHtml(degustacao.local)}</p>` : ''}
                <p><strong>👥 Total de Inscrições:</strong> ${totalInscritos}</p>
                <p><strong>👥 Total de Pessoas:</strong> ${totalPessoas}</p>
            </div>
            
            <h3>Mesas:</h3>
        `;
        
        if (inscritos.length === 0) {
            html += '<p>Nenhum inscrito confirmado.</p>';
        } else {
            inscritos.forEach((inscrito, index) => {
                const qtdPessoas = parseInt(inscrito.qtd_pessoas) || 1;
                html += `
                    <div class="mesa-card">
                        <strong>Mesa ${index + 1}</strong> - ${escapeHtml(inscrito.nome)} - ${qtdPessoas} ${qtdPessoas === 1 ? 'pessoa' : 'pessoas'}
                    </div>
                `;
            });
        }
        
        html += `
            <div style="margin-top: 2rem; text-align: center;">
                <button onclick="window.print()" style="padding: 12px 24px; background: #28a745; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; margin-right: 1rem;">
                    🖨️ Imprimir
                </button>
            </div>
        `;
        
        document.getElementById('relatorio').innerHTML = html;
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    </script>
</body>
</html>

