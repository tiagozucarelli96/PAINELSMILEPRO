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
               vp.email,
               vp.data_evento,
               vp.horario_inicio,
               vp.horario_termino,
               vp.unidade,
               vp.valor_total,
               vp.status as pre_contrato_status,
               vp.observacoes,
               vp.observacoes_internas
        FROM vendas_kanban_cards vk
        LEFT JOIN vendas_pre_contratos vp ON vp.id = vk.pre_contrato_id
        WHERE vk.coluna_id = ?
          AND (vk.pre_contrato_id IS NULL OR vp.id IS NOT NULL)
        ORDER BY vk.posicao ASC, vk.id ASC
    ");
    $stmt->execute([$coluna['id']]);
    $cards_por_coluna[$coluna['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$open_card_id = (int)($_GET['card_id'] ?? 0);

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

/* Upgrade visual dos cards */
.kanban-card {
    border-left-width: 5px;
    border-radius: 12px;
    padding: 0.9rem;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.08);
    transition: transform .15s ease, box-shadow .15s ease;
}
.kanban-card:hover {
    transform: translateY(-1px);
    box-shadow: 0 10px 22px rgba(15, 23, 42, 0.12);
}
.kanban-card.card-highlight {
    animation: cardPulse 1.4s ease;
}
@keyframes cardPulse {
    0% { box-shadow: 0 0 0 0 rgba(37,99,235,.45); }
    100% { box-shadow: 0 0 0 16px rgba(37,99,235,0); }
}
.card-headline {
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:.55rem;
}
.card-tag-id {
    flex-shrink:0;
    background:#e0ecff;
    color:#1d4ed8;
    border:1px solid #bfdbfe;
    border-radius:999px;
    padding:.15rem .5rem;
    font-size:.72rem;
    font-weight:700;
}
.card-titulo {
    margin-bottom: .2rem;
    font-size: .98rem;
    line-height: 1.25;
}
.card-subtitulo {
    color:#475569;
    font-size:.85rem;
    margin-bottom:.6rem;
}
.card-meta-line {
    display:flex;
    align-items:center;
    gap:.35rem;
    flex-wrap:wrap;
    color:#334155;
    font-size:.83rem;
    margin-bottom:.2rem;
}
.card-meta-dot {
    color:#94a3b8;
}
.card-footer-row {
    margin-top:.55rem;
    padding-top:.55rem;
    border-top:1px solid #e2e8f0;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:.5rem;
}
.card-pill {
    display:inline-flex;
    align-items:center;
    gap:.3rem;
    padding:.2rem .52rem;
    border-radius:999px;
    border:1px solid #d1d5db;
    background:#f8fafc;
    color:#334155;
    font-size:.72rem;
    font-weight:700;
}
.card-observacao-hint {
    color:#64748b;
    font-size:.76rem;
    font-weight:600;
}

/* Modal detalhado com observações */
.kanban-modal {
    width: min(980px, calc(100vw - 24px));
    max-height: calc(100vh - 24px);
    display:flex;
    flex-direction:column;
}
.kanban-modal-header-main {
    display:flex;
    flex-direction:column;
    gap:.2rem;
}
.kanban-modal-subtitle {
    font-size:.82rem;
    color:#64748b;
}
.kanban-modal-body {
    overflow:auto;
}
.kanban-modal-links {
    margin-top:.8rem;
    display:flex;
    align-items:center;
    gap:.5rem;
    flex-wrap:wrap;
}
.kanban-modal-link {
    display:inline-flex;
    align-items:center;
    gap:.3rem;
    text-decoration:none;
    color:#1d4ed8;
    background:#eff6ff;
    border:1px solid #bfdbfe;
    border-radius:999px;
    padding:.32rem .72rem;
    font-size:.8rem;
    font-weight:700;
}
.kanban-modal-link:hover { background:#dbeafe; }
.kanban-modal-sections {
    margin-top:1rem;
    display:grid;
    grid-template-columns: 1fr 1fr;
    gap:1rem;
}
.kanban-modal-section {
    border:1px solid #e2e8f0;
    border-radius:12px;
    padding:.85rem;
    background:#fbfdff;
    min-height:280px;
    display:flex;
    flex-direction:column;
}
.kanban-modal-section-header {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:.5rem;
    margin-bottom:.7rem;
}
.kanban-modal-section-title {
    margin:0;
    color:#1e293b;
    font-size:.95rem;
}
.kanban-modal-section-count {
    min-width:22px;
    height:22px;
    padding:0 .45rem;
    border-radius:999px;
    background:#e0ecff;
    color:#1d4ed8;
    font-size:.74rem;
    font-weight:700;
    display:inline-flex;
    align-items:center;
    justify-content:center;
}
.kanban-modal-list {
    display:flex;
    flex-direction:column;
    gap:.6rem;
    overflow:auto;
    max-height:280px;
}
.kanban-list-empty {
    border:1px dashed #cbd5e1;
    border-radius:10px;
    color:#64748b;
    font-size:.85rem;
    text-align:center;
    padding:.9rem .75rem;
    background:#fff;
}
.kanban-observacao-item,
.kanban-historico-item {
    border:1px solid #e2e8f0;
    border-radius:10px;
    background:#fff;
    padding:.62rem .66rem;
}
.kanban-item-meta {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:.45rem;
    margin-bottom:.35rem;
    font-size:.76rem;
    color:#64748b;
}
.kanban-item-author {
    color:#0f172a;
    font-weight:700;
}
.kanban-item-text {
    color:#1e293b;
    font-size:.86rem;
    line-height:1.36;
    white-space:pre-wrap;
    word-break:break-word;
}
.mention-token {
    color:#1d4ed8;
    font-weight:700;
}
.kanban-obs-form {
    margin-top:.8rem;
    border-top:1px solid #e2e8f0;
    padding-top:.75rem;
    position:relative;
}
.kanban-obs-form label {
    display:block;
    color:#334155;
    font-weight:700;
    font-size:.78rem;
    margin-bottom:.4rem;
}
.kanban-obs-textarea {
    width:100%;
    min-height:94px;
    resize:vertical;
    border:1px solid #cbd5e1;
    border-radius:10px;
    padding:.58rem .64rem;
    font-size:.9rem;
    line-height:1.35;
    font-family:inherit;
}
.kanban-obs-textarea:focus {
    outline:none;
    border-color:#60a5fa;
    box-shadow:0 0 0 3px rgba(96,165,250,0.2);
}
.kanban-obs-actions {
    margin-top:.6rem;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:.5rem;
    flex-wrap:wrap;
}
.kanban-obs-help {
    color:#64748b;
    font-size:.76rem;
}
.kanban-obs-feedback {
    margin-top:.4rem;
    min-height:1rem;
    color:#2563eb;
    font-size:.77rem;
    font-weight:600;
}
.kanban-mention-list {
    position:absolute;
    left:0;
    right:0;
    top:calc(100% - 4.6rem);
    background:#fff;
    border:1px solid #cbd5e1;
    box-shadow:0 12px 24px rgba(15,23,42,.14);
    border-radius:10px;
    max-height:180px;
    overflow:auto;
    z-index:20;
    display:none;
}
.kanban-mention-list.open { display:block; }
.kanban-mention-item {
    width:100%;
    border:none;
    background:#fff;
    text-align:left;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:.5rem;
    padding:.52rem .6rem;
    cursor:pointer;
    font-size:.82rem;
}
.kanban-mention-item:hover { background:#f1f5f9; }
.kanban-mention-tag {
    color:#1d4ed8;
    font-weight:700;
}

@media (max-width: 940px) {
    .kanban-modal-sections {
        grid-template-columns: 1fr;
    }
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
                            $titulo_card = (string)($card['titulo'] ?? $card['cliente_nome'] ?? 'Sem título');
                            $descricao_card = (string)($card['descricao'] ?? '');
                            $telefone = (string)($card['telefone'] ?? '');
                            $email_cliente = (string)($card['email'] ?? '');
                            $local_evento = (string)($card['unidade'] ?? '');
                            $horario_inicio = (string)($card['horario_inicio'] ?? '');
                            $horario_termino = (string)($card['horario_termino'] ?? '');
                            $data_evento = (string)($card['data_evento'] ?? '');
                            $status_pre_raw = trim((string)($card['pre_contrato_status'] ?? ''));
                            $status_pre_label = $status_pre_raw !== '' ? ucwords(str_replace('_', ' ', $status_pre_raw)) : 'Sem status';
                            $valor_card = (float)($card['valor_total'] ?? 0);
                            $valor_formatado = $valor_card > 0 ? 'R$ ' . number_format($valor_card, 2, ',', '.') : '';
                        ?>
                        <div class="kanban-card"
                             draggable="true"
                             data-card-id="<?php echo (int)$card['id']; ?>"
                             data-pre-contrato-id="<?php echo (int)($card['pre_contrato_id'] ?? 0); ?>"
                             data-card-titulo="<?php echo htmlspecialchars($titulo_card, ENT_QUOTES); ?>"
                             data-card-descricao="<?php echo htmlspecialchars($descricao_card, ENT_QUOTES); ?>"
                             data-card-status="<?php echo htmlspecialchars($status_pre_label, ENT_QUOTES); ?>"
                             data-card-valor="<?php echo htmlspecialchars((string)$valor_formatado, ENT_QUOTES); ?>"
                             data-coluna-nome="<?php echo htmlspecialchars((string)($coluna['nome'] ?? ''), ENT_QUOTES); ?>"
                             data-cliente="<?php echo htmlspecialchars($cliente_nome, ENT_QUOTES); ?>"
                             data-evento="<?php echo htmlspecialchars($nome_evento, ENT_QUOTES); ?>"
                             data-telefone="<?php echo htmlspecialchars($telefone, ENT_QUOTES); ?>"
                             data-email="<?php echo htmlspecialchars($email_cliente, ENT_QUOTES); ?>"
                             data-local="<?php echo htmlspecialchars($local_evento, ENT_QUOTES); ?>"
                             data-horario-inicio="<?php echo htmlspecialchars($horario_inicio, ENT_QUOTES); ?>"
                             data-horario-termino="<?php echo htmlspecialchars($horario_termino, ENT_QUOTES); ?>"
                             data-data-evento="<?php echo htmlspecialchars($data_evento, ENT_QUOTES); ?>"
                             style="border-left-color: <?php echo htmlspecialchars((string)($coluna['cor'] ?? '#3b82f6')); ?>;">
                            <div class="card-headline">
                                <div class="card-titulo"><?php echo htmlspecialchars($titulo_card); ?></div>
                                <span class="card-tag-id">#<?php echo (int)$card['id']; ?></span>
                            </div>
                            <div class="card-subtitulo"><?php echo htmlspecialchars($cliente_nome !== '' ? $cliente_nome : 'Cliente não informado'); ?></div>

                            <?php if ($data_evento || $horario_inicio || $horario_termino): ?>
                                <div class="card-meta-line">
                                    <?php if ($data_evento): ?>
                                        <span><?php echo date('d/m/Y', strtotime($data_evento)); ?></span>
                                    <?php endif; ?>
                                    <?php if ($horario_inicio): ?>
                                        <span class="card-meta-dot">•</span>
                                        <span><?php echo htmlspecialchars($horario_inicio); ?></span>
                                    <?php endif; ?>
                                    <?php if ($horario_termino): ?>
                                        <span class="card-meta-dot">•</span>
                                        <span>até <?php echo htmlspecialchars($horario_termino); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($local_evento || $telefone): ?>
                                <div class="card-meta-line">
                                    <?php if ($local_evento): ?>
                                        <span><?php echo htmlspecialchars($local_evento); ?></span>
                                    <?php endif; ?>
                                    <?php if ($telefone): ?>
                                        <span class="card-meta-dot">•</span>
                                        <span><?php echo htmlspecialchars($telefone); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <div class="card-footer-row">
                                <span class="card-pill"><?php echo htmlspecialchars($status_pre_label); ?></span>
                                <?php if ($valor_formatado !== ''): ?>
                                    <div class="card-valor"><?php echo htmlspecialchars($valor_formatado); ?></div>
                                <?php else: ?>
                                    <span class="card-observacao-hint">Clique para detalhes</span>
                                <?php endif; ?>
                            </div>

                            <div class="card-acoes">
                                <?php if ($card['pre_contrato_id']): ?>
                                    <?php $page_det = $is_admin ? 'vendas_administracao' : 'vendas_pre_contratos'; ?>
                                    <a href="index.php?page=<?php echo $page_det; ?>&editar=<?php echo $card['pre_contrato_id']; ?>">Abrir pré-contrato</a>
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
            <div class="kanban-modal-header-main">
                <h3 id="kanbanCardModalTitle">Detalhes do Card</h3>
                <div class="kanban-modal-subtitle" id="kanbanCardModalSubtitle">Clique no card para abrir informações completas.</div>
            </div>
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
                    <div class="kanban-modal-field-label">E-mail</div>
                    <div class="kanban-modal-field-value" id="modalEmail">-</div>
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
                <div>
                    <div class="kanban-modal-field-label">Horário de Término</div>
                    <div class="kanban-modal-field-value" id="modalHorarioTermino">-</div>
                </div>
                <div>
                    <div class="kanban-modal-field-label">Status</div>
                    <div class="kanban-modal-field-value" id="modalCardStatus">-</div>
                </div>
                <div>
                    <div class="kanban-modal-field-label">Etapa Atual</div>
                    <div class="kanban-modal-field-value" id="modalColunaAtual">-</div>
                </div>
                <div>
                    <div class="kanban-modal-field-label">Valor do Contrato</div>
                    <div class="kanban-modal-field-value" id="modalValorContrato">-</div>
                </div>
                <div>
                    <div class="kanban-modal-field-label">Descrição do Card</div>
                    <div class="kanban-modal-field-value" id="modalDescricaoCard">-</div>
                </div>
            </div>

            <div class="kanban-modal-links">
                <a href="#" id="modalLinkPreContrato" class="kanban-modal-link" style="display:none;">Abrir pré-contrato</a>
            </div>

            <div class="kanban-modal-sections">
                <section class="kanban-modal-section">
                    <div class="kanban-modal-section-header">
                        <h4 class="kanban-modal-section-title">Observações</h4>
                        <span class="kanban-modal-section-count" id="modalObservacoesCount">0</span>
                    </div>
                    <div class="kanban-modal-list" id="modalObservacoesList">
                        <div class="kanban-list-empty">Nenhuma observação neste card.</div>
                    </div>
                    <div class="kanban-obs-form">
                        <label for="modalObservacaoInput">Nova observação</label>
                        <textarea id="modalObservacaoInput" class="kanban-obs-textarea" placeholder="Escreva uma observação. Use @usuario para mencionar alguém."></textarea>
                        <div class="kanban-mention-list" id="modalMentionList"></div>
                        <div class="kanban-obs-actions">
                            <span class="kanban-obs-help">Use @usuario para gerar notificação no dashboard.</span>
                            <button type="button" class="btn btn-primary" id="modalSalvarObservacaoBtn">Salvar observação</button>
                        </div>
                        <div class="kanban-obs-feedback" id="modalObservacaoFeedback"></div>
                    </div>
                </section>

                <section class="kanban-modal-section">
                    <div class="kanban-modal-section-header">
                        <h4 class="kanban-modal-section-title">Histórico de Movimentações</h4>
                        <span class="kanban-modal-section-count" id="modalHistoricoCount">0</span>
                    </div>
                    <div class="kanban-modal-list" id="modalHistoricoList">
                        <div class="kanban-list-empty">Ainda não há movimentações registradas.</div>
                    </div>
                </section>
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

function formatarDataHoraBr(dataIso) {
    if (!dataIso) return '-';
    const normalized = dataIso.includes('T') ? dataIso : dataIso.replace(' ', 'T');
    const d = new Date(normalized);
    if (Number.isNaN(d.getTime())) return dataIso;
    return d.toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = String(text ?? '');
    return div.innerHTML;
}

function renderTextoComMencoes(texto) {
    const escaped = escapeHtml(texto || '');
    return escaped.replace(/(^|\s)@([A-Za-z0-9._-]{2,60})/g, '$1<span class="mention-token">@$2</span>');
}

function formatarStatusLabel(status) {
    const raw = String(status || '').trim();
    if (!raw) return '-';
    return raw
        .replace(/_/g, ' ')
        .toLowerCase()
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

function setModalField(id, value) {
    const el = document.getElementById(id);
    if (el) {
        const txt = String(value ?? '').trim();
        el.textContent = txt !== '' ? txt : '-';
    }
}

const MODAL_PRE_CONTRATO_PAGE = <?php echo json_encode($is_admin ? 'vendas_administracao' : 'vendas_pre_contratos'); ?>;
const AUTO_OPEN_CARD_ID = <?php echo (int)$open_card_id; ?>;

let modalCardAtual = null;
let modalCardAtualId = 0;
let modalMentionUsers = [];
let modalMentionRange = null;
let modalFetchToken = 0;
let modalSavingObservacao = false;

function atualizarFeedbackObservacao(msg, isError = false) {
    const el = document.getElementById('modalObservacaoFeedback');
    if (!el) return;
    el.textContent = msg || '';
    el.style.color = isError ? '#dc2626' : '#2563eb';
}

function setModalPreContratoLink(preContratoId) {
    const link = document.getElementById('modalLinkPreContrato');
    if (!link) return;
    if (preContratoId > 0) {
        link.href = `index.php?page=${MODAL_PRE_CONTRATO_PAGE}&editar=${preContratoId}`;
        link.style.display = 'inline-flex';
    } else {
        link.style.display = 'none';
        link.removeAttribute('href');
    }
}

function preencherModalBase(cardEl) {
    const cardId = Number(cardEl?.dataset?.cardId || 0);
    const titulo = cardEl?.dataset?.cardTitulo || 'Detalhes do Card';
    const subtitle = cardId > 0 ? `Card #${cardId} · carregando informações...` : 'Carregando informações...';

    document.getElementById('kanbanCardModalTitle').textContent = titulo;
    document.getElementById('kanbanCardModalSubtitle').textContent = subtitle;

    setModalField('modalClienteNome', cardEl?.dataset?.cliente || '-');
    setModalField('modalNomeEvento', cardEl?.dataset?.evento || '-');
    setModalField('modalTelefone', cardEl?.dataset?.telefone || '-');
    setModalField('modalEmail', cardEl?.dataset?.email || '-');
    setModalField('modalLocalEvento', cardEl?.dataset?.local || '-');
    setModalField('modalDataEvento', formatarDataBr(cardEl?.dataset?.dataEvento || ''));
    setModalField('modalHorarioInicio', cardEl?.dataset?.horarioInicio || '-');
    setModalField('modalHorarioTermino', cardEl?.dataset?.horarioTermino || '-');
    setModalField('modalCardStatus', cardEl?.dataset?.cardStatus || '-');
    setModalField('modalColunaAtual', cardEl?.dataset?.colunaNome || '-');
    setModalField('modalValorContrato', cardEl?.dataset?.cardValor || '-');
    setModalField('modalDescricaoCard', cardEl?.dataset?.cardDescricao || '-');

    setModalPreContratoLink(Number(cardEl?.dataset?.preContratoId || 0));
}

function renderListaObservacoes(observacoes) {
    const list = document.getElementById('modalObservacoesList');
    const count = document.getElementById('modalObservacoesCount');
    if (!list || !count) return;

    const items = Array.isArray(observacoes) ? observacoes : [];
    count.textContent = String(items.length);

    if (!items.length) {
        list.innerHTML = '<div class="kanban-list-empty">Nenhuma observação neste card.</div>';
        return;
    }

    list.innerHTML = items.map((obs) => {
        const autor = escapeHtml(obs.autor_nome || 'Usuário');
        const data = formatarDataHoraBr(obs.criado_em || '');
        const texto = renderTextoComMencoes(obs.observacao || '');
        return `
            <div class="kanban-observacao-item">
                <div class="kanban-item-meta">
                    <span class="kanban-item-author">${autor}</span>
                    <span>${escapeHtml(data)}</span>
                </div>
                <div class="kanban-item-text">${texto}</div>
            </div>
        `;
    }).join('');
}

function renderListaHistorico(historico) {
    const list = document.getElementById('modalHistoricoList');
    const count = document.getElementById('modalHistoricoCount');
    if (!list || !count) return;

    const items = Array.isArray(historico) ? historico : [];
    count.textContent = String(items.length);

    if (!items.length) {
        list.innerHTML = '<div class="kanban-list-empty">Ainda não há movimentações registradas.</div>';
        return;
    }

    list.innerHTML = items.map((item) => {
        const origem = escapeHtml(item.coluna_anterior_nome || 'Sem etapa anterior');
        const destino = escapeHtml(item.coluna_nova_nome || 'Sem etapa');
        const autor = escapeHtml(item.movido_por_nome || 'Sistema');
        const data = escapeHtml(formatarDataHoraBr(item.movido_em || ''));
        return `
            <div class="kanban-historico-item">
                <div class="kanban-item-meta">
                    <span class="kanban-item-author">${autor}</span>
                    <span>${data}</span>
                </div>
                <div class="kanban-item-text">Movido de <strong>${origem}</strong> para <strong>${destino}</strong>.</div>
            </div>
        `;
    }).join('');
}

function atualizarSugestoesMencao() {
    const textarea = document.getElementById('modalObservacaoInput');
    const list = document.getElementById('modalMentionList');
    if (!textarea || !list) return;

    const cursor = textarea.selectionStart ?? textarea.value.length;
    const before = textarea.value.slice(0, cursor);
    const match = before.match(/(?:^|\s)@([A-Za-z0-9._-]{0,60})$/);

    if (!match || !modalMentionUsers.length) {
        list.classList.remove('open');
        list.innerHTML = '';
        modalMentionRange = null;
        return;
    }

    const query = String(match[1] || '').toLowerCase();
    const start = before.lastIndexOf('@');
    if (start < 0) {
        list.classList.remove('open');
        list.innerHTML = '';
        modalMentionRange = null;
        return;
    }

    modalMentionRange = { start, end: cursor };

    const sugestoes = modalMentionUsers
        .filter((u) => {
            const tag = String(u.tag || '').toLowerCase();
            const nome = String(u.nome || '').toLowerCase();
            return !query || tag.includes(query) || nome.includes(query);
        })
        .slice(0, 8);

    if (!sugestoes.length) {
        list.classList.remove('open');
        list.innerHTML = '';
        return;
    }

    list.innerHTML = sugestoes.map((u) => {
        const nome = escapeHtml(u.nome || u.tag || 'Usuário');
        const tag = escapeHtml(u.tag || '');
        return `
            <button type="button" class="kanban-mention-item" data-tag="${tag}">
                <span>${nome}</span>
                <span class="kanban-mention-tag">@${tag}</span>
            </button>
        `;
    }).join('');
    list.classList.add('open');

    list.querySelectorAll('.kanban-mention-item').forEach((btn) => {
        btn.addEventListener('mousedown', (event) => {
            event.preventDefault();
            const tag = btn.getAttribute('data-tag') || '';
            inserirMencaoNoTexto(tag);
        });
    });
}

function inserirMencaoNoTexto(tag) {
    const textarea = document.getElementById('modalObservacaoInput');
    const list = document.getElementById('modalMentionList');
    if (!textarea || !list || !modalMentionRange || !tag) return;

    const value = textarea.value;
    const before = value.slice(0, modalMentionRange.start);
    const after = value.slice(modalMentionRange.end);
    const mention = '@' + tag + ' ';
    textarea.value = before + mention + after;

    const nextPos = before.length + mention.length;
    textarea.focus();
    textarea.setSelectionRange(nextPos, nextPos);

    list.classList.remove('open');
    list.innerHTML = '';
    modalMentionRange = null;
}

async function carregarDetalhesCard(cardId) {
    if (!cardId) return;
    const currentToken = ++modalFetchToken;

    const obsList = document.getElementById('modalObservacoesList');
    const histList = document.getElementById('modalHistoricoList');
    if (obsList) obsList.innerHTML = '<div class="kanban-list-empty">Carregando observações...</div>';
    if (histList) histList.innerHTML = '<div class="kanban-list-empty">Carregando histórico...</div>';

    try {
        const response = await fetch(`vendas_kanban_api.php?action=detalhes_card&card_id=${encodeURIComponent(String(cardId))}`, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        });
        const payload = await response.json();

        if (currentToken !== modalFetchToken) return;
        if (!response.ok || !payload?.success) {
            throw new Error(payload?.error || 'Falha ao carregar detalhes do card');
        }

        const data = payload.data || {};
        const card = data.card || {};
        const title = card.titulo || modalCardAtual?.dataset?.cardTitulo || 'Detalhes do Card';
        document.getElementById('kanbanCardModalTitle').textContent = title;
        document.getElementById('kanbanCardModalSubtitle').textContent = `Card #${card.id || cardId} · ${card.coluna_nome || modalCardAtual?.dataset?.colunaNome || 'Sem etapa'}`;

        setModalField('modalClienteNome', card.nome_completo || modalCardAtual?.dataset?.cliente || '-');
        setModalField('modalNomeEvento', card.nome_noivos || modalCardAtual?.dataset?.evento || '-');
        setModalField('modalTelefone', card.telefone || modalCardAtual?.dataset?.telefone || '-');
        setModalField('modalEmail', card.email || modalCardAtual?.dataset?.email || '-');
        setModalField('modalLocalEvento', card.unidade || modalCardAtual?.dataset?.local || '-');
        setModalField('modalDataEvento', formatarDataBr(card.data_evento || modalCardAtual?.dataset?.dataEvento || ''));
        setModalField('modalHorarioInicio', card.horario_inicio || modalCardAtual?.dataset?.horarioInicio || '-');
        setModalField('modalHorarioTermino', card.horario_termino || modalCardAtual?.dataset?.horarioTermino || '-');
        setModalField('modalCardStatus', formatarStatusLabel(card.pre_contrato_status || modalCardAtual?.dataset?.cardStatus || ''));
        setModalField('modalColunaAtual', card.coluna_nome || modalCardAtual?.dataset?.colunaNome || '-');

        const valor = Number(card.valor_total || 0);
        setModalField('modalValorContrato', valor > 0 ? `R$ ${valor.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}` : (modalCardAtual?.dataset?.cardValor || '-'));
        setModalField('modalDescricaoCard', card.descricao || modalCardAtual?.dataset?.cardDescricao || '-');

        setModalPreContratoLink(Number(card.pre_contrato_id || modalCardAtual?.dataset?.preContratoId || 0));

        renderListaObservacoes(data.observacoes || []);
        renderListaHistorico(data.historico || []);

        modalMentionUsers = Array.isArray(payload.usuarios_mencao) ? payload.usuarios_mencao : [];
    } catch (error) {
        if (currentToken !== modalFetchToken) return;
        renderListaObservacoes([]);
        renderListaHistorico([]);
        atualizarFeedbackObservacao('Não foi possível carregar os detalhes completos deste card.', true);
    }
}

async function salvarObservacaoModal() {
    if (modalSavingObservacao) return;
    if (!modalCardAtualId) {
        atualizarFeedbackObservacao('Card inválido para salvar observação.', true);
        return;
    }

    const textarea = document.getElementById('modalObservacaoInput');
    const btnSalvar = document.getElementById('modalSalvarObservacaoBtn');
    const texto = String(textarea?.value || '').trim();

    if (!texto) {
        atualizarFeedbackObservacao('Escreva uma observação antes de salvar.', true);
        return;
    }

    modalSavingObservacao = true;
    if (btnSalvar) btnSalvar.disabled = true;
    atualizarFeedbackObservacao('Salvando observação...');

    try {
        const response = await fetch('vendas_kanban_api.php?action=adicionar_observacao', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                card_id: String(modalCardAtualId),
                observacao: texto
            })
        });
        const payload = await response.json();

        if (!response.ok || !payload?.success) {
            throw new Error(payload?.error || 'Não foi possível salvar a observação');
        }

        if (textarea) {
            textarea.value = '';
            textarea.focus();
        }
        const mentionList = document.getElementById('modalMentionList');
        if (mentionList) {
            mentionList.classList.remove('open');
            mentionList.innerHTML = '';
        }

        const mencoes = Number(payload.mencoes || 0);
        if (mencoes > 0) {
            atualizarFeedbackObservacao(`Observação salva. ${mencoes} notificação(ões) de menção enviada(s).`);
        } else {
            atualizarFeedbackObservacao('Observação salva com sucesso.');
        }

        await carregarDetalhesCard(modalCardAtualId);
    } catch (error) {
        atualizarFeedbackObservacao(error?.message || 'Erro ao salvar observação.', true);
    } finally {
        modalSavingObservacao = false;
        if (btnSalvar) btnSalvar.disabled = false;
    }
}

function abrirModalDetalhesCard(cardEl) {
    const overlay = document.getElementById('kanbanCardModalOverlay');
    if (!overlay || !cardEl) return;

    modalCardAtual = cardEl;
    modalCardAtualId = Number(cardEl.dataset.cardId || 0);
    modalMentionRange = null;
    atualizarFeedbackObservacao('');

    preencherModalBase(cardEl);

    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden', 'false');

    carregarDetalhesCard(modalCardAtualId);
}

function fecharModalDetalhesCard() {
    const overlay = document.getElementById('kanbanCardModalOverlay');
    if (!overlay) return;
    overlay.classList.remove('open');
    overlay.setAttribute('aria-hidden', 'true');

    modalFetchToken++;
    modalMentionRange = null;
    atualizarFeedbackObservacao('');

    const mentionList = document.getElementById('modalMentionList');
    if (mentionList) {
        mentionList.classList.remove('open');
        mentionList.innerHTML = '';
    }
}

document.getElementById('kanbanCardModalClose')?.addEventListener('click', fecharModalDetalhesCard);
document.getElementById('kanbanCardModalOverlay')?.addEventListener('click', function(e) {
    if (e.target === this) {
        fecharModalDetalhesCard();
    }
});
document.getElementById('modalSalvarObservacaoBtn')?.addEventListener('click', salvarObservacaoModal);
document.getElementById('modalObservacaoInput')?.addEventListener('input', atualizarSugestoesMencao);
document.getElementById('modalObservacaoInput')?.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        salvarObservacaoModal();
        return;
    }
    if (e.key === 'Escape') {
        const mentionList = document.getElementById('modalMentionList');
        if (mentionList) {
            mentionList.classList.remove('open');
            mentionList.innerHTML = '';
        }
    }
});
document.addEventListener('click', function(e) {
    const mentionList = document.getElementById('modalMentionList');
    if (!mentionList) return;
    if (!e.target.closest('#modalMentionList') && !e.target.closest('#modalObservacaoInput')) {
        mentionList.classList.remove('open');
        mentionList.innerHTML = '';
    }
});

(function autoOpenCardFromQuery() {
    if (!AUTO_OPEN_CARD_ID) return;
    const selector = `.kanban-card[data-card-id=\"${AUTO_OPEN_CARD_ID}\"]`;
    const target = document.querySelector(selector);
    if (!target) return;
    target.classList.add('card-highlight');
    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    window.setTimeout(() => {
        abrirModalDetalhesCard(target);
    }, 220);
    window.setTimeout(() => {
        target.classList.remove('card-highlight');
    }, 2200);
})();

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
