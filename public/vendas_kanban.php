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

// Limpeza defensiva: remove cards órfãos de pré-contratos apagados
$pdo->exec("
    DELETE FROM vendas_kanban_cards vk
    WHERE vk.pre_contrato_id IS NOT NULL
      AND NOT EXISTS (
          SELECT 1
          FROM vendas_pre_contratos vp
          WHERE vp.id = vk.pre_contrato_id
      )
");

// Buscar colunas
$stmt = $pdo->prepare("
    SELECT vc.*,
           COUNT(
               CASE
                   WHEN vk.pre_contrato_id IS NULL OR vp.id IS NOT NULL THEN 1
                   ELSE NULL
               END
           ) as total_cards
    FROM vendas_kanban_colunas vc
    LEFT JOIN vendas_kanban_cards vk ON vk.coluna_id = vc.id
    LEFT JOIN vendas_pre_contratos vp ON vp.id = vk.pre_contrato_id
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
        SELECT vk.*,
               vp.nome_completo,
               vp.nome_noivos,
               vp.telefone,
               vp.data_evento,
               vp.horario_inicio,
               vp.unidade,
               vp.valor_total,
               vp.status as pre_contrato_status
        FROM vendas_kanban_cards vk
        LEFT JOIN vendas_pre_contratos vp ON vp.id = vk.pre_contrato_id
        WHERE vk.coluna_id = ?
          AND (vk.pre_contrato_id IS NULL OR vp.id IS NOT NULL)
        ORDER BY vk.posicao ASC, vk.id ASC
    ");
    $stmt->execute([$coluna['id']]);
    $cards_por_coluna[$coluna['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

ob_start();
?>

<style>
.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:.4rem;
    padding:.55rem .9rem;
    border-radius:8px;
    border:1px solid transparent;
    cursor:pointer;
    font-weight:600;
    font-size:.9rem;
    text-decoration:none;
    user-select:none;
    transition: all .15s ease;
}
.btn:disabled{ opacity:.55; cursor:not-allowed; }
.btn-primary{ background:#2563eb; color:#fff; border-color:#2563eb; }
.btn-primary:hover{ background:#1d4ed8; border-color:#1d4ed8; }
.btn-danger{ background:#ef4444; color:#fff; border-color:#ef4444; }
.btn-danger:hover{ background:#dc2626; border-color:#dc2626; }
.btn-secondary{ background:#6b7280; color:#fff; border-color:#6b7280; }
.btn-secondary:hover{ background:#4b5563; border-color:#4b5563; }
.btn-outline{
    background:#ffffff;
    color:#1e3a8a;
    border:1px solid #93c5fd;
}
.btn-outline:hover{ background:#eff6ff; }

.vendas-card{
    background:#ffffff;
    border:1px solid #e5e7eb;
    border-radius:14px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
    padding: 1.25rem;
}

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

.kanban-hint{
    color:#64748b;
    font-size:.9rem;
    margin-top:.25rem;
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

.kanban-card.dragging{
    opacity: .5;
    transform: rotate(1deg);
}

.kanban-coluna .coluna-cards{
    border-radius: 8px;
    padding: .15rem;
}

.coluna-cards.drop-target{
    outline: 2px dashed #60a5fa;
    outline-offset: 2px;
    background: #e8f1ff !important;
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

.drop-highlight { /* mantido por compatibilidade */
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

/* Admin: editor de colunas (menos engessado) */
.colunas-editor{
    display:flex;
    flex-direction:column;
    gap:.6rem;
    margin-top:.75rem;
}
.coluna-editor-item{
    display:flex;
    align-items:center;
    gap:.6rem;
    background:#f8fafc;
    border:1px solid #e5e7eb;
    border-radius:12px;
    padding:.6rem;
}
.coluna-editor-item.dragging{ opacity:.6; }
.coluna-editor-handle{
    width:34px;
    height:34px;
    border-radius:10px;
    background:#e5e7eb;
    color:#334155;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:800;
    cursor:grab;
    user-select:none;
}
.coluna-editor-fields{
    flex:1;
    display:grid;
    grid-template-columns: 1fr 120px;
    gap:.6rem;
    align-items:center;
}
.coluna-editor-fields input[type="text"]{
    width:100%;
    padding:.55rem .6rem;
    border:1px solid #d1d5db;
    border-radius:10px;
    background:#fff;
}
.coluna-editor-fields input[type="color"]{
    width:100%;
    height:40px;
    border:1px solid #d1d5db;
    border-radius:10px;
    background:#fff;
    padding:.2rem;
}
.coluna-editor-meta{
    font-size:.8rem;
    color:#64748b;
    margin-left:.15rem;
}
.coluna-editor-item.is-required{
    background:#eef2ff;
    border-color:#c7d2fe;
}
.coluna-editor-item.is-required .coluna-editor-handle{
    background:#c7d2fe;
    color:#1e3a8a;
}

.kanban-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 6000;
}

.kanban-modal-overlay.open {
    display: flex;
}

.kanban-modal {
    width: min(640px, calc(100vw - 32px));
    background: #fff;
    border-radius: 14px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 16px 40px rgba(0,0,0,.2);
    overflow: hidden;
}

.kanban-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: .75rem;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #e5e7eb;
}

.kanban-modal-header h3 {
    margin: 0;
    color: #1e3a8a;
    font-size: 1.1rem;
}

.kanban-modal-close {
    border: none;
    background: transparent;
    font-size: 1.5rem;
    line-height: 1;
    cursor: pointer;
    color: #64748b;
}

.kanban-modal-body {
    padding: 1rem 1.25rem 1.25rem;
}

.kanban-modal-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .85rem 1rem;
}

.kanban-modal-field-label {
    font-size: .78rem;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: .04em;
    margin-bottom: .2rem;
}

.kanban-modal-field-value {
    color: #0f172a;
    font-size: .98rem;
    word-break: break-word;
}
</style>

<div class="kanban-container">
    <div class="kanban-topbar">
        <div class="kanban-header">
            <h1>Acompanhamento de Contratos</h1>
            <p>Gerencie o fluxo de contratos através do Kanban</p>
            <div class="kanban-hint">Dica: arraste os cards entre colunas para mover.</div>
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
                        <?php
                            $cliente_nome = (string)($card['nome_completo'] ?? $card['cliente_nome'] ?? '');
                            $nome_evento = trim((string)($card['nome_noivos'] ?? ''));
                            if ($nome_evento === '') {
                                $nome_evento = (string)($card['titulo'] ?? $cliente_nome ?? '');
                            }
                            $telefone = (string)($card['telefone'] ?? '');
                            $local_evento = (string)($card['unidade'] ?? '');
                            $horario_inicio = (string)($card['horario_inicio'] ?? '');
                            $data_evento = (string)($card['data_evento'] ?? '');
                        ?>
                        <div class="kanban-card"
                             draggable="true"
                             data-card-id="<?php echo (int)$card['id']; ?>"
                             data-pre-contrato-id="<?php echo (int)($card['pre_contrato_id'] ?? 0); ?>"
                             data-cliente="<?php echo htmlspecialchars($cliente_nome, ENT_QUOTES); ?>"
                             data-evento="<?php echo htmlspecialchars($nome_evento, ENT_QUOTES); ?>"
                             data-telefone="<?php echo htmlspecialchars($telefone, ENT_QUOTES); ?>"
                             data-local="<?php echo htmlspecialchars($local_evento, ENT_QUOTES); ?>"
                             data-horario-inicio="<?php echo htmlspecialchars($horario_inicio, ENT_QUOTES); ?>"
                             data-data-evento="<?php echo htmlspecialchars($data_evento, ENT_QUOTES); ?>"
                             style="border-left-color: <?php echo htmlspecialchars((string)($coluna['cor'] ?? '#3b82f6')); ?>;">
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
                <div style="color:#64748b; font-size:.9rem; margin-bottom: .75rem;">
                    Arraste as colunas abaixo para reordenar. Você também pode renomear, trocar a cor e excluir (exceto <strong>Criado na ME</strong>).
                </div>

                <form method="POST" style="display:flex; gap:.75rem; flex-wrap:wrap; align-items:flex-end; margin-bottom: 1rem;">
                    <input type="hidden" name="action" value="add_coluna">
                    <div style="flex:1; min-width: 220px;">
                        <label style="display:block; font-weight:600; margin-bottom:.35rem;">Nova coluna</label>
                        <input name="nome" placeholder="Nome da coluna" style="width:100%; padding:.6rem; border:1px solid #d1d5db; border-radius:8px;">
                    </div>
                    <div style="min-width: 140px;">
                        <label style="display:block; font-weight:600; margin-bottom:.35rem;">Cor</label>
                        <input name="cor" type="color" value="#6b7280" style="width:100%; height:42px; padding:.2rem; border:1px solid #d1d5db; border-radius:8px;">
                    </div>
                    <button class="btn btn-primary" type="submit">Adicionar</button>
                </form>

                <form method="POST">
                    <input type="hidden" name="action" value="salvar_colunas">
                    <div class="colunas-editor" id="colunasEditor">
                        <?php foreach ($colunas as $c): ?>
                            <?php
                                $nomeCol = (string)($c['nome'] ?? '');
                                $isRequired = (mb_strtolower(trim($nomeCol)) === 'criado na me');
                                $corCol = (string)($c['cor'] ?? '#3b82f6');
                                if ($corCol === '') $corCol = '#3b82f6';
                            ?>
                            <div class="coluna-editor-item <?php echo $isRequired ? 'is-required' : ''; ?>" draggable="true" data-coluna-id="<?php echo (int)$c['id']; ?>">
                                <div class="coluna-editor-handle" title="Arrastar para reordenar">⋮⋮</div>
                                <div class="coluna-editor-fields">
                                    <div>
                                        <input
                                            type="text"
                                            name="colunas[<?php echo (int)$c['id']; ?>][nome]"
                                            value="<?php echo htmlspecialchars((string)$nomeCol); ?>"
                                            <?php echo $isRequired ? 'readonly' : ''; ?>
                                        >
                                        <div class="coluna-editor-meta">
                                            <?php if ($isRequired): ?>
                                                Obrigatória
                                            <?php else: ?>
                                                Coluna editável
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div>
                                        <input type="color" name="colunas[<?php echo (int)$c['id']; ?>][cor]" value="<?php echo htmlspecialchars($corCol); ?>">
                                        <input type="hidden" class="coluna-posicao-input" name="colunas[<?php echo (int)$c['id']; ?>][posicao]" value="<?php echo (int)$c['posicao']; ?>">
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="btn btn-danger"
                                    style="padding:.5rem .75rem;"
                                    <?php echo $isRequired ? 'disabled' : ''; ?>
                                    data-delete-coluna-id="<?php echo (int)$c['id']; ?>"
                                >
                                    Excluir
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="margin-top: .75rem; display:flex; gap:.75rem; justify-content:flex-end;">
                        <button class="btn btn-primary" type="submit">Salvar colunas</button>
                    </div>
                    <small style="display:block; margin-top:.5rem; color:#64748b;">
                        A coluna <strong>Criado na ME</strong> é obrigatória (não pode ser removida).
                    </small>
                </form>

                <!-- Form único (fora do form de salvar) para exclusão de coluna -->
                <form id="formDeleteColuna" method="POST" style="display:none;">
                    <input type="hidden" name="action" value="deletar_coluna">
                    <input type="hidden" name="coluna_id" id="deleteColunaId" value="">
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="kanban-modal-overlay" id="kanbanCardModalOverlay" aria-hidden="true">
    <div class="kanban-modal" role="dialog" aria-modal="true" aria-labelledby="kanbanCardModalTitle">
        <div class="kanban-modal-header">
            <h3 id="kanbanCardModalTitle">Detalhes do Card</h3>
            <button type="button" class="kanban-modal-close" id="kanbanCardModalClose" aria-label="Fechar">×</button>
        </div>
        <div class="kanban-modal-body">
            <div class="kanban-modal-grid">
                <div>
                    <div class="kanban-modal-field-label">Cliente</div>
                    <div class="kanban-modal-field-value" id="modalClienteNome">-</div>
                </div>
                <div>
                    <div class="kanban-modal-field-label">Nome do Evento</div>
                    <div class="kanban-modal-field-value" id="modalNomeEvento">-</div>
                </div>
                <div>
                    <div class="kanban-modal-field-label">Telefone</div>
                    <div class="kanban-modal-field-value" id="modalTelefone">-</div>
                </div>
                <div>
                    <div class="kanban-modal-field-label">Local do Evento</div>
                    <div class="kanban-modal-field-value" id="modalLocalEvento">-</div>
                </div>
                <div>
                    <div class="kanban-modal-field-label">Data do Evento</div>
                    <div class="kanban-modal-field-value" id="modalDataEvento">-</div>
                </div>
                <div>
                    <div class="kanban-modal-field-label">Horário de Início</div>
                    <div class="kanban-modal-field-value" id="modalHorarioInicio">-</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Drag and Drop (cards) — corrigido para Safari/Chrome: precisa usar dataTransfer e drop no container correto
let draggedCard = null;
let draggedFromColunaId = null;
let isDraggingCard = false;

function getColunaIdFromEl(el) {
    const col = el?.closest?.('.kanban-coluna');
    return col?.dataset?.colunaId || null;
}

function ensureEmptyPlaceholder(colunaEl) {
    if (!colunaEl) return;
    const wrap = colunaEl.querySelector('.coluna-cards');
    if (!wrap) return;
    const hasCards = wrap.querySelectorAll('.kanban-card').length > 0;
    const empty = wrap.querySelector('.coluna-empty');
    if (!hasCards && !empty) {
        const e = document.createElement('div');
        e.className = 'coluna-empty';
        e.textContent = 'Sem cards nesta etapa.';
        wrap.appendChild(e);
    }
    if (hasCards && empty) empty.remove();
}

function updateCountsByDom() {
    document.querySelectorAll('.kanban-coluna').forEach(col => {
        const count = col.querySelectorAll('.kanban-card').length;
        const countEl = col.querySelector('.coluna-count');
        if (countEl) countEl.textContent = String(count);
        ensureEmptyPlaceholder(col);
    });
}

document.querySelectorAll('.kanban-card').forEach(card => {
    card.addEventListener('dragstart', function(e) {
        isDraggingCard = true;
        draggedCard = this;
        draggedFromColunaId = getColunaIdFromEl(this);
        this.classList.add('dragging');
        try {
            e.dataTransfer.effectAllowed = 'move';
            // Necessário para Safari/Chrome dispararem corretamente o drop
            e.dataTransfer.setData('text/plain', this.dataset.cardId || '');
        } catch (err) {}
    });

    card.addEventListener('dragend', function() {
        this.classList.remove('dragging');
        draggedCard = null;
        draggedFromColunaId = null;
        window.setTimeout(() => { isDraggingCard = false; }, 60);
        document.querySelectorAll('.coluna-cards').forEach(w => w.classList.remove('drop-target'));
    });

    card.addEventListener('click', function(e) {
        if (isDraggingCard) return;
        if (e.target.closest('.card-acoes')) return;
        abrirModalDetalhesCard(this);
    });
});

document.querySelectorAll('.card-acoes a').forEach(link => {
    link.addEventListener('click', function(e) {
        e.stopPropagation();
    });
});

function formatarDataBr(dataIso) {
    if (!dataIso) return '-';
    const normalized = (dataIso.includes('T') || dataIso.includes(' '))
        ? dataIso.replace(' ', 'T')
        : (dataIso + 'T00:00:00');
    const d = new Date(normalized);
    if (Number.isNaN(d.getTime())) return dataIso;
    return d.toLocaleDateString('pt-BR');
}

function abrirModalDetalhesCard(cardEl) {
    const overlay = document.getElementById('kanbanCardModalOverlay');
    if (!overlay || !cardEl) return;

    document.getElementById('modalClienteNome').textContent = cardEl.dataset.cliente || '-';
    document.getElementById('modalNomeEvento').textContent = cardEl.dataset.evento || '-';
    document.getElementById('modalTelefone').textContent = cardEl.dataset.telefone || '-';
    document.getElementById('modalLocalEvento').textContent = cardEl.dataset.local || '-';
    document.getElementById('modalDataEvento').textContent = formatarDataBr(cardEl.dataset.dataEvento || '');
    document.getElementById('modalHorarioInicio').textContent = cardEl.dataset.horarioInicio || '-';

    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden', 'false');
}

function fecharModalDetalhesCard() {
    const overlay = document.getElementById('kanbanCardModalOverlay');
    if (!overlay) return;
    overlay.classList.remove('open');
    overlay.setAttribute('aria-hidden', 'true');
}

document.getElementById('kanbanCardModalClose')?.addEventListener('click', fecharModalDetalhesCard);
document.getElementById('kanbanCardModalOverlay')?.addEventListener('click', function(e) {
    if (e.target === this) {
        fecharModalDetalhesCard();
    }
});

document.querySelectorAll('.coluna-cards').forEach(wrap => {
    wrap.addEventListener('dragover', function(e) {
        e.preventDefault();
        this.classList.add('drop-target');
    });
    wrap.addEventListener('dragleave', function() {
        this.classList.remove('drop-target');
    });
    wrap.addEventListener('drop', function(e) {
        e.preventDefault();
        this.classList.remove('drop-target');

        // Recuperar card (fallback via dataTransfer)
        let cardEl = draggedCard;
        if (!cardEl) {
            const id = (function() {
                try { return e.dataTransfer.getData('text/plain'); } catch (err) { return ''; }
            })();
            if (id) cardEl = document.querySelector(`.kanban-card[data-card-id="${CSS.escape(id)}"]`);
        }
        if (!cardEl) return;

        const colunaCards = this;
        const colunaId = this.dataset.colunaId || getColunaIdFromEl(this);
        const cardId = cardEl.dataset.cardId;
        const oldParent = cardEl.parentElement;
        const oldColunaId = draggedFromColunaId || getColunaIdFromEl(cardEl);

        // Mover visualmente (por enquanto: para o fim da coluna)
        colunaCards.querySelectorAll('.coluna-empty').forEach(el => el.remove());
        colunaCards.appendChild(cardEl);
        updateCountsByDom();

        const newPos = colunaCards.querySelectorAll('.kanban-card').length - 1;

        fetch('vendas_kanban_api.php?action=mover_card', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                card_id: cardId,
                coluna_id: colunaId,
                posicao: String(newPos)
            })
        })
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success) {
                console.error('Erro ao mover card:', data?.error);
                // Reverter movimento visual
                if (oldParent) oldParent.appendChild(cardEl);
                updateCountsByDom();
            }
        })
        .catch(err => {
            console.error('Erro ao mover card (network):', err);
            if (oldParent) oldParent.appendChild(cardEl);
            updateCountsByDom();
        });
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

// Admin: reordenar colunas (drag) + deletar com confirmação
(function(){
    const editor = document.getElementById('colunasEditor');
    if (!editor) return;

    let draggingItem = null;

    function syncPositions() {
        const items = Array.from(editor.querySelectorAll('.coluna-editor-item'));
        items.forEach((it, idx) => {
            const posInput = it.querySelector('.coluna-posicao-input');
            if (posInput) posInput.value = String(idx);
        });
    }

    syncPositions();

    editor.querySelectorAll('.coluna-editor-item').forEach(item => {
        item.addEventListener('dragstart', function(e){
            draggingItem = this;
            this.classList.add('dragging');
            try {
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', this.dataset.colunaId || '');
            } catch (err) {}
        });
        item.addEventListener('dragend', function(){
            this.classList.remove('dragging');
            draggingItem = null;
            syncPositions();
        });
        item.addEventListener('dragover', function(e){
            e.preventDefault();
            if (!draggingItem || draggingItem === this) return;
            const rect = this.getBoundingClientRect();
            const before = (e.clientY - rect.top) < rect.height / 2;
            if (before) {
                this.parentElement.insertBefore(draggingItem, this);
            } else {
                this.parentElement.insertBefore(draggingItem, this.nextSibling);
            }
        });
    });

    editor.querySelectorAll('[data-delete-coluna-id]').forEach(btn => {
        btn.addEventListener('click', function(){
            const id = this.getAttribute('data-delete-coluna-id');
            if (!id) return;
            if (!confirm('Tem certeza que deseja excluir esta coluna? (só é permitido se estiver vazia)')) return;
            const form = document.getElementById('formDeleteColuna');
            const input = document.getElementById('deleteColunaId');
            if (!form || !input) return;
            input.value = String(id);
            form.submit();
        });
    });
})();
</script>

<?php
$conteudo = ob_get_clean();
includeSidebar('Comercial');
echo $conteudo;
endSidebar();
