<?php
/**
 * vendas_kanban.php
 * Kanban de Acompanhamento de Contratos
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/vendas_helper.php';

if (empty($_SESSION['logado']) || empty($_SESSION['perm_comercial'])) {
    header('Location: index.php?page=login');
    exit;
}

$pdo = $GLOBALS['pdo'];
$is_admin = vendas_is_admin();

$messages = [];
$errors = [];
if (!vendas_ensure_schema($pdo, $errors, $messages)) {
    includeSidebar('Comercial');
    echo '<div style="padding:2rem;max-width:1100px;margin:0 auto;">';
    foreach ($errors as $e) {
        echo '<div class="alert alert-error">' . htmlspecialchars((string)$e) . '</div>';
    }
    echo '<div class="alert alert-error">Base de Vendas ausente/desatualizada. Execute os SQLs <code>sql/041_modulo_vendas.sql</code> e <code>sql/042_vendas_ajustes.sql</code>.</div>';
    echo '</div>';
    endSidebar();
    exit;
}

// Buscar board padrão
$stmt = $pdo->prepare("SELECT * FROM vendas_kanban_boards WHERE ativo = TRUE LIMIT 1");
$stmt->execute();
$board = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$board) {
    // Criar board padrão se não existir
    $stmt = $pdo->prepare("INSERT INTO vendas_kanban_boards (nome, descricao, ativo) VALUES ('Acompanhamento de Contratos', 'Kanban para acompanhamento de contratos', TRUE) RETURNING id");
    $stmt->execute();
    $board_id = (int)$stmt->fetchColumn();
    if ($board_id <= 0) {
        $stmt = $pdo->prepare("SELECT id FROM vendas_kanban_boards WHERE ativo = TRUE ORDER BY id ASC LIMIT 1");
        $stmt->execute();
        $board_id = (int)$stmt->fetchColumn();
    }
} else {
    $board_id = $board['id'];
}

// Ações de administração de colunas (somente admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!$is_admin) {
        // silencioso (não quebrar UX), apenas ignora
    } else {
        try {
            if ($action === 'add_coluna') {
                $nome = trim((string)($_POST['nome'] ?? ''));
                $cor = trim((string)($_POST['cor'] ?? '#6b7280'));
                if ($nome === '') throw new Exception('Nome da coluna é obrigatório.');
                if (mb_strtolower($nome) === 'criado na me') throw new Exception('A coluna "Criado na ME" já é obrigatória e será mantida.');

                $stmt = $pdo->prepare("SELECT COALESCE(MAX(posicao), 0) FROM vendas_kanban_colunas WHERE board_id = ?");
                $stmt->execute([$board_id]);
                $max_pos = (int)$stmt->fetchColumn();

                $stmt = $pdo->prepare("INSERT INTO vendas_kanban_colunas (board_id, nome, posicao, cor) VALUES (?, ?, ?, ?)");
                $stmt->execute([$board_id, $nome, $max_pos + 1, $cor ?: '#6b7280']);
            }

            if ($action === 'salvar_colunas') {
                $colunasPost = $_POST['colunas'] ?? [];
                if (is_array($colunasPost)) {
                    $pdo->beginTransaction();
                    $stmtUpd = $pdo->prepare("UPDATE vendas_kanban_colunas SET nome = ?, posicao = ?, cor = ? WHERE id = ? AND board_id = ?");
                    foreach ($colunasPost as $id => $c) {
                        $id = (int)$id;
                        if ($id <= 0 || !is_array($c)) continue;
                        $nome = trim((string)($c['nome'] ?? ''));
                        $pos = (int)($c['posicao'] ?? 0);
                        $cor = trim((string)($c['cor'] ?? '#6b7280'));

                        // bloquear renomear "Criado na ME"
                        $stmt = $pdo->prepare("SELECT nome FROM vendas_kanban_colunas WHERE id = ? AND board_id = ? LIMIT 1");
                        $stmt->execute([$id, $board_id]);
                        $nomeAtual = (string)$stmt->fetchColumn();
                        if (mb_strtolower(trim($nomeAtual)) === 'criado na me') {
                            $nome = 'Criado na ME';
                        } elseif ($nome === '') {
                            $nome = $nomeAtual ?: 'Coluna';
                        }

                        $stmtUpd->execute([$nome, $pos, ($cor !== '' ? $cor : '#6b7280'), $id, $board_id]);
                    }
                    $pdo->commit();
                }
            }

            if ($action === 'deletar_coluna') {
                $coluna_id = (int)($_POST['coluna_id'] ?? 0);
                if ($coluna_id > 0) {
                    $stmt = $pdo->prepare("SELECT nome FROM vendas_kanban_colunas WHERE id = ? AND board_id = ? LIMIT 1");
                    $stmt->execute([$coluna_id, $board_id]);
                    $nome = (string)$stmt->fetchColumn();
                    if (mb_strtolower(trim($nome)) === 'criado na me') {
                        throw new Exception('A coluna "Criado na ME" é obrigatória e não pode ser removida.');
                    }

                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM vendas_kanban_cards WHERE coluna_id = ?");
                    $stmt->execute([$coluna_id]);
                    if ((int)$stmt->fetchColumn() > 0) {
                        throw new Exception('Não é possível excluir uma coluna com cards. Mova os cards antes.');
                    }

                    $stmt = $pdo->prepare("DELETE FROM vendas_kanban_colunas WHERE id = ? AND board_id = ?");
                    $stmt->execute([$coluna_id, $board_id]);
                }
            }
        } catch (Throwable $e) {
            $_SESSION['vendas_kanban_admin_error'] = $e->getMessage();
        }
    }

    header('Location: index.php?page=vendas_kanban');
    exit;
}

// Garantir que a coluna "Criado na ME" sempre exista
$stmt = $pdo->prepare("SELECT id FROM vendas_kanban_colunas WHERE board_id = ? AND nome = 'Criado na ME' LIMIT 1");
$stmt->execute([$board_id]);
$coluna_criado_me = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$coluna_criado_me) {
    // Buscar maior posição atual
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(posicao), 0) as max_pos FROM vendas_kanban_colunas WHERE board_id = ?");
    $stmt->execute([$board_id]);
    $max_pos = (int)$stmt->fetchColumn();
    
    // Criar coluna "Criado na ME" na primeira posição
    $stmt = $pdo->prepare("INSERT INTO vendas_kanban_colunas (board_id, nome, posicao) VALUES (?, 'Criado na ME', 0)");
    $stmt->execute([$board_id]);
    
    // Ajustar posições das outras colunas
    $stmt = $pdo->prepare("UPDATE vendas_kanban_colunas SET posicao = posicao + 1 WHERE board_id = ? AND nome != 'Criado na ME'");
    $stmt->execute([$board_id]);
}

// Buscar colunas
$stmt = $pdo->prepare("
    SELECT vc.*, COUNT(vk.id) as total_cards
    FROM vendas_kanban_colunas vc
    LEFT JOIN vendas_kanban_cards vk ON vk.coluna_id = vc.id
    WHERE vc.board_id = ?
    GROUP BY vc.id
    ORDER BY vc.posicao ASC
");
$stmt->execute([$board_id]);
$colunas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar cards por coluna
$cards_por_coluna = [];
foreach ($colunas as $coluna) {
    $stmt = $pdo->prepare("
        SELECT vk.*, vp.nome_completo, vp.data_evento, vp.unidade, vp.valor_total, vp.status as pre_contrato_status
        FROM vendas_kanban_cards vk
        LEFT JOIN vendas_pre_contratos vp ON vp.id = vk.pre_contrato_id
        WHERE vk.coluna_id = ?
        ORDER BY vk.posicao ASC, vk.id ASC
    ");
    $stmt->execute([$coluna['id']]);
    $cards_por_coluna[$coluna['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

ob_start();
?>

<style>
.kanban-container {
    padding: 1.5rem;
    background: #f4f5f7;
    min-height: calc(100vh - 60px);
}

.kanban-topbar{
    max-width: 1400px;
    margin: 0 auto 1rem;
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap: 1rem;
    flex-wrap: wrap;
}

.kanban-header {
    margin-bottom: 1.5rem;
}

.kanban-header h1 {
    font-size: 1.75rem;
    color: #1e3a8a;
    margin-bottom: 0.5rem;
}

.kanban-actions{
    display:flex;
    gap:.75rem;
    align-items:center;
}

.btn-outline{
    background:#ffffff;
    color:#1e3a8a;
    border:1px solid #93c5fd;
}
.btn-outline:hover{
    background:#eff6ff;
}

.kanban-board {
    display: flex;
    gap: 1rem;
    overflow-x: auto;
    padding-bottom: 1rem;
    max-width: 1400px;
    margin: 0 auto;
}

.kanban-coluna {
    min-width: 300px;
    background: #ebecf0;
    border-radius: 8px;
    padding: 1rem;
    border: 1px solid rgba(0,0,0,0.06);
}

.coluna-header {
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #ddd;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.coluna-count {
    background: #ddd;
    padding: 0.25rem 0.5rem;
    border-radius: 12px;
    font-size: 0.875rem;
}

.kanban-card {
    background: white;
    border-radius: 6px;
    padding: 1rem;
    margin-bottom: 0.75rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    cursor: move;
    transition: box-shadow 0.2s;
    border-left: 4px solid #3b82f6;
}

.kanban-card:hover {
    box-shadow: 0 4px 6px rgba(0,0,0,0.15);
}

.card-titulo {
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 0.5rem;
}

.card-info {
    font-size: 0.875rem;
    color: #7f8c8d;
    margin-bottom: 0.25rem;
}

.card-valor {
    font-weight: 600;
    color: #16a34a;
    margin-top: 0.5rem;
}

.coluna-cards {
    min-height: 40px;
}

.coluna-empty {
    color: #64748b;
    font-size: 0.9rem;
    padding: .5rem .25rem;
}

.admin-panel {
    max-width: 1400px;
    margin: 1rem auto 0;
}

.admin-panel.hidden {
    display: none;
}

.drop-highlight {
    outline: 2px dashed #60a5fa;
    outline-offset: 2px;
    background: #e8f1ff !important;
}

.card-acoes {
    margin-top: 0.75rem;
    padding-top: 0.75rem;
    border-top: 1px solid #eee;
}

.card-acoes a {
    font-size: 0.875rem;
    color: #2563eb;
    text-decoration: none;
}

.card-acoes a:hover {
    text-decoration: underline;
}
</style>

<div class="kanban-container">
    <div class="kanban-topbar">
        <div class="kanban-header">
            <h1>Acompanhamento de Contratos</h1>
            <p>Gerencie o fluxo de contratos através do Kanban</p>
        </div>
        <div class="kanban-actions">
            <?php if ($is_admin): ?>
                <button type="button" class="btn btn-outline" id="btnToggleAdmin">Gerenciar colunas</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($_SESSION['vendas_kanban_admin_error'])): ?>
        <div class="alert alert-error" style="max-width: 1400px; margin: 0 auto 1rem;">
            <?php echo htmlspecialchars((string)$_SESSION['vendas_kanban_admin_error']); ?>
        </div>
        <?php unset($_SESSION['vendas_kanban_admin_error']); ?>
    <?php endif; ?>
    
    <div class="kanban-board" id="kanbanBoard">
        <?php foreach ($colunas as $coluna): ?>
            <div class="kanban-coluna" data-coluna-id="<?php echo $coluna['id']; ?>" style="border-top:4px solid <?php echo htmlspecialchars((string)($coluna['cor'] ?? '#3b82f6')); ?>;">
                <div class="coluna-header" style="border-bottom-color: <?php echo htmlspecialchars((string)($coluna['cor'] ?? '#ddd')); ?>;">
                    <span><?php echo htmlspecialchars($coluna['nome']); ?></span>
                    <span class="coluna-count"><?php echo count($cards_por_coluna[$coluna['id']] ?? []); ?></span>
                </div>
                
                <div class="coluna-cards" data-coluna-id="<?php echo $coluna['id']; ?>">
                    <?php $cardsAtual = $cards_por_coluna[$coluna['id']] ?? []; ?>
                    <?php if (empty($cardsAtual)): ?>
                        <div class="coluna-empty">Sem cards nesta etapa.</div>
                    <?php endif; ?>
                    <?php foreach ($cardsAtual as $card): ?>
                        <div class="kanban-card" draggable="true" data-card-id="<?php echo $card['id']; ?>" style="border-left-color: <?php echo htmlspecialchars((string)($coluna['cor'] ?? '#3b82f6')); ?>;">
                            <div class="card-titulo"><?php echo htmlspecialchars($card['titulo'] ?? $card['cliente_nome'] ?? 'Sem título'); ?></div>
                            <?php if ($card['data_evento']): ?>
                                <div class="card-info">📅 <?php echo date('d/m/Y', strtotime($card['data_evento'])); ?></div>
                            <?php endif; ?>
                            <?php if ($card['unidade']): ?>
                                <div class="card-info">📍 <?php echo htmlspecialchars($card['unidade']); ?></div>
                            <?php endif; ?>
                            <?php if ($card['valor_total']): ?>
                                <div class="card-valor">R$ <?php echo number_format($card['valor_total'], 2, ',', '.'); ?></div>
                            <?php endif; ?>
                            <div class="card-acoes">
                                <?php if ($card['pre_contrato_id']): ?>
                                    <?php $page_det = $is_admin ? 'vendas_administracao' : 'vendas_pre_contratos'; ?>
                                    <a href="index.php?page=<?php echo $page_det; ?>&editar=<?php echo $card['pre_contrato_id']; ?>">Ver detalhes</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($is_admin): ?>
        <div class="admin-panel hidden" id="adminPanel">
            <div class="vendas-card">
                <h3 style="margin-bottom: .75rem; color:#1e3a8a;">Gerenciar colunas</h3>

                <form method="POST" style="display:flex; gap:.75rem; flex-wrap:wrap; align-items:flex-end; margin-bottom: 1rem;">
                    <input type="hidden" name="action" value="add_coluna">
                    <div style="flex:1; min-width: 220px;">
                        <label style="display:block; font-weight:600; margin-bottom:.35rem;">Nova coluna</label>
                        <input name="nome" placeholder="Nome da coluna" style="width:100%; padding:.6rem; border:1px solid #d1d5db; border-radius:8px;">
                    </div>
                    <div style="min-width: 140px;">
                        <label style="display:block; font-weight:600; margin-bottom:.35rem;">Cor</label>
                        <input name="cor" value="#6b7280" style="width:100%; padding:.6rem; border:1px solid #d1d5db; border-radius:8px;">
                    </div>
                    <button class="btn btn-primary" type="submit">Adicionar</button>
                </form>

                <form method="POST">
                    <input type="hidden" name="action" value="salvar_colunas">
                    <div style="overflow:auto;">
                        <table class="vendas-table" style="width:100%; border-collapse:collapse;">
                            <thead>
                                <tr>
                                    <th style="text-align:left; padding:.6rem; border-bottom:1px solid #e5e7eb;">Nome</th>
                                    <th style="text-align:left; padding:.6rem; border-bottom:1px solid #e5e7eb; width:120px;">Posição</th>
                                    <th style="text-align:left; padding:.6rem; border-bottom:1px solid #e5e7eb; width:160px;">Cor</th>
                                    <th style="text-align:left; padding:.6rem; border-bottom:1px solid #e5e7eb; width:140px;">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($colunas as $c): ?>
                                    <tr>
                                        <td style="padding:.6rem; border-bottom:1px solid #e5e7eb;">
                                            <input name="colunas[<?php echo (int)$c['id']; ?>][nome]" value="<?php echo htmlspecialchars((string)$c['nome']); ?>" style="width:100%; padding:.5rem; border:1px solid #d1d5db; border-radius:8px;">
                                        </td>
                                        <td style="padding:.6rem; border-bottom:1px solid #e5e7eb;">
                                            <input type="number" name="colunas[<?php echo (int)$c['id']; ?>][posicao]" value="<?php echo (int)$c['posicao']; ?>" style="width:100%; padding:.5rem; border:1px solid #d1d5db; border-radius:8px;">
                                        </td>
                                        <td style="padding:.6rem; border-bottom:1px solid #e5e7eb;">
                                            <input name="colunas[<?php echo (int)$c['id']; ?>][cor]" value="<?php echo htmlspecialchars((string)($c['cor'] ?? '#6b7280')); ?>" style="width:100%; padding:.5rem; border:1px solid #d1d5db; border-radius:8px;">
                                        </td>
                                        <td style="padding:.6rem; border-bottom:1px solid #e5e7eb;">
                                            <button type="submit" class="btn btn-danger" style="padding:.4rem .7rem;"
                                                    form="del_col_<?php echo (int)$c['id']; ?>">
                                                Excluir
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php foreach ($colunas as $c): ?>
                        <form id="del_col_<?php echo (int)$c['id']; ?>" method="POST" style="display:none;">
                            <input type="hidden" name="action" value="deletar_coluna">
                            <input type="hidden" name="coluna_id" value="<?php echo (int)$c['id']; ?>">
                        </form>
                    <?php endforeach; ?>
                    <div style="margin-top: .75rem; display:flex; gap:.75rem; justify-content:flex-end;">
                        <button class="btn btn-primary" type="submit">Salvar colunas</button>
                    </div>
                    <small style="display:block; margin-top:.5rem; color:#64748b;">
                        A coluna <strong>Criado na ME</strong> é obrigatória (não pode ser removida).
                    </small>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
// Drag and Drop básico
let draggedCard = null;
let draggedFromColuna = null;

document.querySelectorAll('.kanban-card').forEach(card => {
    card.addEventListener('dragstart', function(e) {
        draggedCard = this;
        draggedFromColuna = this.closest('.kanban-coluna')?.dataset?.colunaId || null;
        this.style.opacity = '0.5';
    });
    
    card.addEventListener('dragend', function(e) {
        this.style.opacity = '1';
    });
});

document.querySelectorAll('.kanban-coluna').forEach(coluna => {
    coluna.addEventListener('dragover', function(e) {
        e.preventDefault();
        this.classList.add('drop-highlight');
    });
    
    coluna.addEventListener('dragleave', function(e) {
        this.classList.remove('drop-highlight');
    });
    
    coluna.addEventListener('drop', function(e) {
        e.preventDefault();
        this.classList.remove('drop-highlight');
        
        if (draggedCard) {
            const colunaId = this.dataset.colunaId;
            const cardId = draggedCard.dataset.cardId;
            const colunaCards = this.querySelector('.coluna-cards');
            const oldColunaId = draggedFromColuna;
            const oldColunaEl = oldColunaId ? document.querySelector(`.kanban-coluna[data-coluna-id="${oldColunaId}"]`) : null;
            const oldParent = draggedCard.parentElement;
            
            // Mover card visualmente
            // remover placeholder "Sem cards"
            colunaCards.querySelectorAll('.coluna-empty').forEach(el => el.remove());
            colunaCards.appendChild(draggedCard);
            
            // Salvar no servidor
            const newPos = colunaCards.querySelectorAll('.kanban-card').length - 1;
            fetch('vendas_kanban_api.php?action=mover_card', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    card_id: cardId,
                    coluna_id: colunaId,
                    posicao: newPos
                })
            }).then(response => response.json())
              .then(data => {
                  if (!data.success) {
                      console.error('Erro ao mover card:', data.error);
                      // Reverter movimento visual
                      if (oldParent) oldParent.appendChild(draggedCard);
                  } else {
                      // Atualizar contadores
                      const countEl = this.querySelector('.coluna-count');
                      const oldCountEl = oldColunaEl ? oldColunaEl.querySelector('.coluna-count') : null;
                      if (oldColunaId && oldColunaId !== colunaId && oldCountEl) {
                          countEl.textContent = String(parseInt(countEl.textContent || '0', 10) + 1);
                          oldCountEl.textContent = String(Math.max(0, parseInt(oldCountEl.textContent || '0', 10) - 1));

                          // se coluna antiga ficou vazia, mostrar placeholder
                          const oldCardsWrap = oldColunaEl.querySelector('.coluna-cards');
                          if (oldCardsWrap && oldCardsWrap.querySelectorAll('.kanban-card').length === 0) {
                              const empty = document.createElement('div');
                              empty.className = 'coluna-empty';
                              empty.textContent = 'Sem cards nesta etapa.';
                              oldCardsWrap.appendChild(empty);
                          }
                      }
                  }
              });

            draggedCard = null;
            draggedFromColuna = null;
        }
    });
});

// Admin: esconder/mostrar gerenciador (Kanban é o padrão)
(function(){
    const btn = document.getElementById('btnToggleAdmin');
    const panel = document.getElementById('adminPanel');
    if (!btn || !panel) return;
    const key = 'vendas_kanban_admin_open';
    const saved = localStorage.getItem(key);
    if (saved === '1') {
        panel.classList.remove('hidden');
    }
    btn.addEventListener('click', function(){
        const isHidden = panel.classList.contains('hidden');
        panel.classList.toggle('hidden');
        localStorage.setItem(key, isHidden ? '1' : '0');
        if (isHidden) {
            panel.scrollIntoView({behavior:'smooth', block:'start'});
        }
    });
})();
</script>

<?php
$conteudo = ob_get_clean();
includeSidebar('Comercial');
echo $conteudo;
endSidebar();
