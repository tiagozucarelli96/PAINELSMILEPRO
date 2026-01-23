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

if (empty($_SESSION['logado']) || empty($_SESSION['perm_comercial'])) {
    header('Location: index.php?page=login');
    exit;
}

$pdo = $GLOBALS['pdo'];

// Buscar board padrão
$stmt = $pdo->prepare("SELECT * FROM vendas_kanban_boards WHERE ativo = TRUE LIMIT 1");
$stmt->execute();
$board = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$board) {
    // Criar board padrão se não existir
    $stmt = $pdo->prepare("INSERT INTO vendas_kanban_boards (nome, descricao, ativo) VALUES ('Acompanhamento de Contratos', 'Kanban para acompanhamento de contratos', TRUE) RETURNING id");
    $stmt->execute();
    $board_id = $pdo->lastInsertId();
} else {
    $board_id = $board['id'];
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

.kanban-header {
    margin-bottom: 1.5rem;
}

.kanban-header h1 {
    font-size: 1.75rem;
    color: #1e3a8a;
    margin-bottom: 0.5rem;
}

.kanban-board {
    display: flex;
    gap: 1rem;
    overflow-x: auto;
    padding-bottom: 1rem;
}

.kanban-coluna {
    min-width: 300px;
    background: #f1f2f6;
    border-radius: 8px;
    padding: 1rem;
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
    <div class="kanban-header">
        <h1>Acompanhamento de Contratos</h1>
        <p>Gerencie o fluxo de contratos através do Kanban</p>
    </div>
    
    <div class="kanban-board" id="kanbanBoard">
        <?php foreach ($colunas as $coluna): ?>
            <div class="kanban-coluna" data-coluna-id="<?php echo $coluna['id']; ?>">
                <div class="coluna-header">
                    <span><?php echo htmlspecialchars($coluna['nome']); ?></span>
                    <span class="coluna-count"><?php echo count($cards_por_coluna[$coluna['id']] ?? []); ?></span>
                </div>
                
                <div class="coluna-cards" data-coluna-id="<?php echo $coluna['id']; ?>">
                    <?php foreach ($cards_por_coluna[$coluna['id']] ?? [] as $card): ?>
                        <div class="kanban-card" draggable="true" data-card-id="<?php echo $card['id']; ?>">
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
                                    <a href="index.php?page=vendas_pre_contratos&editar=<?php echo $card['pre_contrato_id']; ?>">Ver detalhes</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
// Drag and Drop básico
let draggedCard = null;

document.querySelectorAll('.kanban-card').forEach(card => {
    card.addEventListener('dragstart', function(e) {
        draggedCard = this;
        this.style.opacity = '0.5';
    });
    
    card.addEventListener('dragend', function(e) {
        this.style.opacity = '1';
    });
});

document.querySelectorAll('.kanban-coluna').forEach(coluna => {
    coluna.addEventListener('dragover', function(e) {
        e.preventDefault();
        this.style.backgroundColor = '#e1e8ed';
    });
    
    coluna.addEventListener('dragleave', function(e) {
        this.style.backgroundColor = '#f1f2f6';
    });
    
    coluna.addEventListener('drop', function(e) {
        e.preventDefault();
        this.style.backgroundColor = '#f1f2f6';
        
        if (draggedCard) {
            const colunaId = this.dataset.colunaId;
            const cardId = draggedCard.dataset.cardId;
            const colunaCards = this.querySelector('.coluna-cards');
            
            // Mover card visualmente
            colunaCards.appendChild(draggedCard);
            
            // Salvar no servidor
            fetch('vendas_kanban_api.php?action=mover_card', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    card_id: cardId,
                    coluna_id: colunaId,
                    posicao: 0
                })
            }).then(response => response.json())
              .then(data => {
                  if (!data.success) {
                      console.error('Erro ao mover card:', data.error);
                      // Reverter movimento visual se necessário
                  } else {
                      // Atualizar contador
                      const countEl = this.querySelector('.coluna-count');
                      const oldColuna = draggedCard.closest('.kanban-coluna');
                      const oldCountEl = oldColuna.querySelector('.coluna-count');
                      
                      countEl.textContent = parseInt(countEl.textContent) + 1;
                      oldCountEl.textContent = parseInt(oldCountEl.textContent) - 1;
                  }
              });
        }
    });
});
</script>

<?php
$conteudo = ob_get_clean();
includeSidebar('Comercial');
echo $conteudo;
endSidebar();
