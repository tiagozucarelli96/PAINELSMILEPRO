<?php
/**
 * demandas_fixas_ui.php
 * Interface para gerenciar demandas fixas
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';

if (!isset($_SESSION['logado']) || $_SESSION['logado'] != 1) {
    header('Location: login.php');
    exit;
}

$pdo = $GLOBALS['pdo'];
$usuario_id = (int)($_SESSION['user_id'] ?? 1);

// Buscar quadros e listas
$stmt_boards = $pdo->query("SELECT id, nome FROM demandas_boards WHERE ativo = TRUE ORDER BY nome");
$boards = $stmt_boards->fetchAll(PDO::FETCH_ASSOC);

// Buscar demandas fixas
$stmt = $pdo->query("
    SELECT df.*, 
           db.nome as board_nome,
           dl.nome as lista_nome
    FROM demandas_fixas df
    JOIN demandas_boards db ON db.id = df.board_id
    JOIN demandas_listas dl ON dl.id = df.lista_id
    ORDER BY df.criado_em DESC
");
$fixas = $stmt->fetchAll(PDO::FETCH_ASSOC);

includeSidebar('Demandas Fixas');
?>

<style>
    .page-container {
        padding: 2rem;
        max-width: 1200px;
        margin: 0 auto;
    }
    
    .header-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
    }
    
    .table-container {
        background: white;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
    }
    
    th, td {
        padding: 1rem;
        text-align: left;
        border-bottom: 1px solid #e5e7eb;
    }
    
    th {
        background: #f9fafb;
        font-weight: 600;
        color: #374151;
    }
    
    .badge-ativo {
        background: #10b981;
        color: white;
        padding: 0.25rem 0.75rem;
        border-radius: 12px;
        font-size: 0.75rem;
    }
    
    .badge-inativo {
        background: #6b7280;
        color: white;
        padding: 0.25rem 0.75rem;
        border-radius: 12px;
        font-size: 0.75rem;
    }
</style>

<div class="page-container">
    <div class="header-actions">
        <h1>📅 Demandas Fixas</h1>
        <button class="btn btn-primary" onclick="abrirModalNovaFixa()">➕ Nova Demanda Fixa</button>
    </div>
    
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Título</th>
                    <th>Quadro</th>
                    <th>Lista</th>
                    <th>Periodicidade</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody id="fixas-table">
                <?php if (empty($fixas)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 2rem; color: #6b7280;">
                            Nenhuma demanda fixa configurada
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($fixas as $fixa): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($fixa['titulo']) ?></strong></td>
                            <td><?= htmlspecialchars($fixa['board_nome']) ?></td>
                            <td><?= htmlspecialchars($fixa['lista_nome']) ?></td>
                            <td>
                                <?php
                                $periodo = $fixa['periodicidade'];
                                if ($periodo === 'diaria') echo 'Diária';
                                elseif ($periodo === 'semanal') {
                                    $dias = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
                                    echo 'Semanal - ' . ($dias[$fixa['dia_semana']] ?? '');
                                } elseif ($periodo === 'mensal') {
                                    echo 'Mensal - Dia ' . $fixa['dia_mes'];
                                }
                                ?>
                            </td>
                            <td>
                                <span class="badge-<?= $fixa['ativo'] ? 'ativo' : 'inativo' ?>">
                                    <?= $fixa['ativo'] ? 'Ativo' : 'Inativo' ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-outline" onclick="editarFixa(<?= $fixa['id'] ?>)">✏️ Editar</button>
                                <button class="btn" onclick="toggleFixa(<?= $fixa['id'] ?>, <?= $fixa['ativo'] ? 'false' : 'true' ?>)" style="background: <?= $fixa['ativo'] ? '#f59e0b' : '#10b981' ?>; color: white;">
                                    <?= $fixa['ativo'] ? '⏸️ Pausar' : '▶️ Ativar' ?>
                                </button>
                                <button class="btn" onclick="deletarFixa(<?= $fixa['id'] ?>)" style="background: #ef4444; color: white;">🗑️</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Nova/Editar Demanda Fixa -->
<div id="modal-fixa" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modal-fixa-titulo">Nova Demanda Fixa</h2>
            <span class="close" onclick="fecharModal('modal-fixa')">&times;</span>
        </div>
        <form id="form-fixa">
            <input type="hidden" id="fixa-id">
            <div class="form-group">
                <label for="fixa-titulo">Título *</label>
                <input type="text" id="fixa-titulo" required>
            </div>
            <div class="form-group">
                <label for="fixa-descricao">Descrição</label>
                <textarea id="fixa-descricao" rows="3"></textarea>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label for="fixa-board">Quadro *</label>
                    <select id="fixa-board" required onchange="carregarListasFixa()">
                        <option value="">Selecione...</option>
                        <?php foreach ($boards as $board): ?>
                            <option value="<?= $board['id'] ?>"><?= htmlspecialchars($board['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="fixa-lista">Lista *</label>
                    <select id="fixa-lista" required>
                        <option value="">Selecione um quadro primeiro</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="fixa-periodicidade">Periodicidade *</label>
                <select id="fixa-periodicidade" required onchange="atualizarCamposPeriodicidade()">
                    <option value="diaria">Diária</option>
                    <option value="semanal">Semanal</option>
                    <option value="mensal">Mensal</option>
                </select>
            </div>
            <div id="fixa-dia-semana-container" style="display: none;">
                <div class="form-group">
                    <label for="fixa-dia-semana">Dia da Semana *</label>
                    <select id="fixa-dia-semana">
                        <option value="0">Domingo</option>
                        <option value="1">Segunda-feira</option>
                        <option value="2">Terça-feira</option>
                        <option value="3">Quarta-feira</option>
                        <option value="4">Quinta-feira</option>
                        <option value="5">Sexta-feira</option>
                        <option value="6">Sábado</option>
                    </select>
                </div>
            </div>
            <div id="fixa-dia-mes-container" style="display: none;">
                <div class="form-group">
                    <label for="fixa-dia-mes">Dia do Mês *</label>
                    <input type="number" id="fixa-dia-mes" min="1" max="31">
                </div>
            </div>
            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-outline" onclick="fecharModal('modal-fixa')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
let boardsData = <?= json_encode($boards) ?>;
let listsData = {};

async function carregarListasFixa() {
    const boardId = parseInt(document.getElementById('fixa-board').value);
    const selectLista = document.getElementById('fixa-lista');
    
    if (!boardId) {
        selectLista.innerHTML = '<option value="">Selecione um quadro primeiro</option>';
        return;
    }
    
    try {
        const response = await fetch(`demandas_trello_api.php?action=listas&id=${boardId}`);
        const data = await response.json();
        
        if (data.success) {
            listsData[boardId] = data.data;
            selectLista.innerHTML = '<option value="">Selecione...</option>' +
                data.data.map(l => `<option value="${l.id}">${l.nome}</option>`).join('');
        }
    } catch (error) {
        console.error('Erro ao carregar listas:', error);
    }
}

function atualizarCamposPeriodicidade() {
    const periodicidade = document.getElementById('fixa-periodicidade').value;
    const diaSemanaContainer = document.getElementById('fixa-dia-semana-container');
    const diaMesContainer = document.getElementById('fixa-dia-mes-container');
    
    diaSemanaContainer.style.display = periodicidade === 'semanal' ? 'block' : 'none';
    diaMesContainer.style.display = periodicidade === 'mensal' ? 'block' : 'none';
    
    document.getElementById('fixa-dia-semana').required = periodicidade === 'semanal';
    document.getElementById('fixa-dia-mes').required = periodicidade === 'mensal';
}

function abrirModalNovaFixa() {
    document.getElementById('modal-fixa-titulo').textContent = 'Nova Demanda Fixa';
    document.getElementById('form-fixa').reset();
    document.getElementById('fixa-id').value = '';
    document.getElementById('fixa-lista').innerHTML = '<option value="">Selecione um quadro primeiro</option>';
    atualizarCamposPeriodicidade();
    document.getElementById('modal-fixa').style.display = 'block';
}

async function editarFixa(id) {
    try {
        // Buscar dados da fixa
        const response = await fetch(`demandas_trello_api.php?action=quadros`);
        const boardsData = await response.json();
        
        // Buscar fixa específica (precisamos buscar todas do backend)
        // Por enquanto, vamos carregar a página com parâmetro
        const fixa = document.querySelector(`tr:has(button[onclick="editarFixa(${id})"])`);
        if (!fixa) {
            alert('Demanda fixa não encontrada');
            return;
        }
        
        // Preencher modal com dados (simplificado - em produção, buscar via API)
        document.getElementById('modal-fixa-titulo').textContent = 'Editar Demanda Fixa';
        document.getElementById('fixa-id').value = id;
        
        // TODO: Buscar dados completos via API para preencher o formulário
        alert('Funcionalidade de edição será implementada na próxima versão. Por enquanto, delete e crie novamente.');
    } catch (error) {
        console.error('Erro:', error);
        alert('Erro ao carregar dados para edição');
    }
}

async function toggleFixa(id, novoStatus) {
    try {
        const response = await fetch(`demandas_fixas_api.php?id=${id}`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ativo: novoStatus === 'true' })
        });
        
        const data = await response.json();
        
        if (data.success) {
            location.reload();
        } else {
            alert('Erro: ' + (data.error || 'Erro desconhecido'));
        }
    } catch (error) {
        console.error('Erro:', error);
        alert('Erro ao atualizar demanda fixa');
    }
}

async function deletarFixa(id) {
    if (!confirm('Tem certeza que deseja deletar esta demanda fixa? Esta ação não pode ser desfeita.')) {
        return;
    }
    
    try {
        const response = await fetch(`demandas_fixas_api.php?id=${id}`, {
            method: 'DELETE'
        });
        
        const data = await response.json();
        
        if (data.success) {
            location.reload();
        } else {
            alert('Erro: ' + (data.error || 'Erro desconhecido'));
        }
    } catch (error) {
        console.error('Erro:', error);
        alert('Erro ao deletar demanda fixa');
    }
}

document.getElementById('form-fixa').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const fixaId = document.getElementById('fixa-id').value;
    const periodicidade = document.getElementById('fixa-periodicidade').value;
    const dados = {
        titulo: document.getElementById('fixa-titulo').value,
        descricao: document.getElementById('fixa-descricao').value,
        board_id: parseInt(document.getElementById('fixa-board').value),
        lista_id: parseInt(document.getElementById('fixa-lista').value),
        periodicidade: periodicidade,
        dia_semana: periodicidade === 'semanal' ? parseInt(document.getElementById('fixa-dia-semana').value) : null,
        dia_mes: periodicidade === 'mensal' ? parseInt(document.getElementById('fixa-dia-mes').value) : null
    };
    
    const method = fixaId ? 'PATCH' : 'POST';
    const url = fixaId ? `demandas_fixas_api.php?id=${fixaId}` : 'demandas_fixas_api.php';
    
    fetch(url, {
        method: method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(dados)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Erro: ' + (data.error || 'Erro desconhecido'));
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        alert('Erro ao salvar');
    });
});

function fecharModal(id) {
    document.getElementById(id).style.display = 'none';
}
</script>

<?php endSidebar(); ?>

