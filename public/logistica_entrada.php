<?php
require_once __DIR__ . '/logistica_tz.php';
// logistica_entrada.php — Entrada de mercadoria
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/logistica_insumo_base_helper.php';

if (empty($_SESSION['perm_superadmin']) && empty($_SESSION['perm_logistico'])) {
    http_response_code(403);
    echo '<div class="alert-error">Acesso negado.</div>';
    exit;
}

function parse_decimal_local(string $value): float {
    $raw = trim($value);
    if ($raw === '') return 0.0;
    $normalized = preg_replace('/[^0-9,\\.]/', '', $raw);
    if (strpos($normalized, ',') !== false && strpos($normalized, '.') !== false) {
        $normalized = str_replace('.', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);
    } else {
        $normalized = str_replace(',', '.', $normalized);
    }
    return $normalized === '' ? 0.0 : (float)$normalized;
}

$errors = [];
$messages = [];

try {
    logistica_ensure_insumo_base_schema($pdo);
} catch (Throwable $e) {
    $errors[] = 'Falha ao preparar estrutura de insumo base: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $unidade_id = (int)($_POST['unidade_id'] ?? 0);
        $insumos = $_POST['insumo_id'] ?? [];
        $quantidades = $_POST['quantidade'] ?? [];
        $unidades = $_POST['unidade_medida_id'] ?? [];
        $obs = trim((string)($_POST['observacao'] ?? ''));

        if ($unidade_id <= 0) {
            $errors[] = 'Selecione a unidade.';
        } else {
            $linhas_validas = 0;
            try {
                $pdo->beginTransaction();
                foreach ($insumos as $idx => $insumo_id_raw) {
                    $insumo_id = (int)$insumo_id_raw;
                    if ($insumo_id <= 0) { continue; }
                    $quantidade = parse_decimal_local((string)($quantidades[$idx] ?? ''));
                    if ($quantidade <= 0) { continue; }
                    $unidade_medida_id = (int)($unidades[$idx] ?? 0);

                    $stmt = $pdo->prepare("
                        INSERT INTO logistica_estoque_saldos (unidade_id, insumo_id, quantidade_atual, unidade_medida_id, updated_at)
                        VALUES (:unidade_id, :insumo_id, :quantidade, :unidade_medida_id, NOW())
                        ON CONFLICT (unidade_id, insumo_id)
                        DO UPDATE SET quantidade_atual = logistica_estoque_saldos.quantidade_atual + EXCLUDED.quantidade_atual,
                                     unidade_medida_id = COALESCE(EXCLUDED.unidade_medida_id, logistica_estoque_saldos.unidade_medida_id),
                                     updated_at = NOW()
                    ");
                    $stmt->execute([
                        ':unidade_id' => $unidade_id,
                        ':insumo_id' => $insumo_id,
                        ':quantidade' => $quantidade,
                        ':unidade_medida_id' => $unidade_medida_id ?: null
                    ]);

                    $mov = $pdo->prepare("
                        INSERT INTO logistica_estoque_movimentos
                            (unidade_id_origem, unidade_id_destino, insumo_id, tipo, quantidade, referencia_tipo, referencia_id, criado_por, criado_em, observacao)
                        VALUES
                            (NULL, :destino, :insumo_id, 'entrada', :quantidade, 'entrada_manual', NULL, :criado_por, NOW(), :observacao)
                    ");
                    $mov->execute([
                        ':destino' => $unidade_id,
                        ':insumo_id' => $insumo_id,
                        ':quantidade' => $quantidade,
                        ':criado_por' => (int)($_SESSION['id'] ?? 0),
                        ':observacao' => $obs !== '' ? $obs : null
                    ]);
                    $linhas_validas++;
                }
                $pdo->commit();
                if ($linhas_validas > 0) {
                    $messages[] = 'Entrada registrada com sucesso.';
                } else {
                    $errors[] = 'Informe pelo menos um item com quantidade.';
                }
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'Erro ao salvar entrada: ' . $e->getMessage();
            }
        }
    }
}

$unidades = $pdo->query("SELECT id, nome FROM logistica_unidades WHERE ativo IS TRUE ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
$insumos = $pdo->query("
    SELECT i.id,
           i.nome_oficial,
           COALESCE(b.nome_base, i.nome_oficial) AS insumo_base_nome,
           CASE
               WHEN COALESCE(b.nome_base, '') <> ''
                    AND LOWER(TRIM(b.nome_base)) <> LOWER(TRIM(i.nome_oficial))
               THEN b.nome_base || ' (' || i.nome_oficial || ')'
               ELSE i.nome_oficial
           END AS nome_exibicao,
           i.unidade_medida_padrao_id,
           i.barcode,
           u.nome AS unidade_nome
    FROM logistica_insumos i
    LEFT JOIN logistica_insumos_base b ON b.id = i.insumo_base_id
    LEFT JOIN logistica_unidades_medida u ON u.id = i.unidade_medida_padrao_id
    WHERE i.ativo IS TRUE
    ORDER BY COALESCE(b.nome_base, i.nome_oficial), i.nome_oficial
")->fetchAll(PDO::FETCH_ASSOC);

includeSidebar('Entrada de Mercadoria - Logística');
?>

<style>
/* Container principal */
.entrada-container {
    max-width: 1000px;
    margin: 0 auto;
    padding: 1.5rem;
}

.entrada-container h1 {
    font-size: 1.5rem;
    font-weight: 600;
    color: #1e3a5f;
    margin-bottom: 1.25rem;
}

/* Card */
.entrada-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

/* Cabeçalho do formulário */
.entrada-header {
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 1rem;
    margin-bottom: 1.5rem;
    padding-bottom: 1.25rem;
    border-bottom: 1px solid #e5e7eb;
}

.entrada-header .form-group {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.entrada-header label {
    font-size: 0.8rem;
    font-weight: 600;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.025em;
}

.entrada-header select,
.entrada-header input[type="text"] {
    padding: 0.6rem 0.75rem;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 0.95rem;
    background: #fff;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.entrada-header select:focus,
.entrada-header input[type="text"]:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}

/* Área de itens */
.entrada-items-wrapper {
    margin-top: 0.5rem;
}

.entrada-items-label {
    font-size: 0.8rem;
    font-weight: 600;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.025em;
    margin-bottom: 0.75rem;
}

/* Grid de cabeçalho de itens */
.entrada-items-header {
    display: grid;
    grid-template-columns: 1fr 120px 130px 44px;
    gap: 0.75rem;
    padding: 0.5rem 0;
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 0.5rem;
}

.entrada-items-header span {
    font-size: 0.75rem;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

/* Linha de item */
.entrada-item-row {
    display: grid;
    grid-template-columns: 1fr 120px 130px 44px;
    gap: 0.75rem;
    padding: 0.65rem 0;
    border-bottom: 1px solid #f1f5f9;
    align-items: center;
}

.entrada-item-row:last-child {
    border-bottom: none;
}

/* Campo de seleção de insumo */
.entrada-insumo-field {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}

.entrada-insumo-field input[type="text"] {
    flex: 1;
    padding: 0.55rem 0.7rem;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 0.9rem;
    background: #f8fafc;
    color: #334155;
    min-width: 0;
}

.entrada-insumo-field input[type="text"]:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}

/* Botão de busca */
.btn-buscar {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 38px;
    padding: 0;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    background: #f1f5f9;
    color: #475569;
    cursor: pointer;
    transition: all 0.2s;
    flex-shrink: 0;
}

.btn-buscar:hover {
    background: #e2e8f0;
    border-color: #94a3b8;
}

.btn-buscar svg {
    width: 18px;
    height: 18px;
}

/* Campos de quantidade e unidade */
.entrada-item-row input.entrada-quantidade {
    width: 100%;
    padding: 0.55rem 0.7rem;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 0.9rem;
    text-align: right;
    background: #fff;
}

.entrada-item-row input.entrada-quantidade:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}

.entrada-item-row .entrada-unidade-text {
    font-size: 0.875rem;
    color: #64748b;
    padding: 0.55rem 0.5rem;
    background: #f8fafc;
    border-radius: 6px;
    text-align: center;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Botão remover */
.btn-remover {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    padding: 0;
    border: none;
    border-radius: 8px;
    background: #fee2e2;
    color: #dc2626;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-remover:hover {
    background: #fecaca;
}

.btn-remover svg {
    width: 18px;
    height: 18px;
}

/* Botões de ação */
.entrada-actions {
    display: flex;
    gap: 0.75rem;
    margin-top: 1.25rem;
    padding-top: 1.25rem;
    border-top: 1px solid #e5e7eb;
    flex-wrap: wrap;
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.65rem 1.1rem;
    border-radius: 8px;
    border: none;
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-primary {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: #fff;
    box-shadow: 0 2px 4px rgba(37,99,235,0.3);
}

.btn-primary:hover {
    background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
    box-shadow: 0 4px 8px rgba(37,99,235,0.35);
}

.btn-secondary {
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #e2e8f0;
}

.btn-secondary:hover {
    background: #e2e8f0;
}

/* Alertas */
.alert-error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #b91c1c;
    padding: 0.75rem 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
    font-size: 0.9rem;
}

.alert-success {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    color: #15803d;
    padding: 0.75rem 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
    font-size: 0.9rem;
}

/* Modal */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15,23,42,0.5);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    backdrop-filter: blur(2px);
}

.modal {
    background: #fff;
    border-radius: 12px;
    padding: 1.25rem;
    width: min(560px, 92vw);
    max-height: 80vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 20px 40px rgba(0,0,0,0.2);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid #e5e7eb;
}

.modal-header strong {
    font-size: 1.1rem;
    color: #1e3a5f;
}

.modal-close {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border: none;
    border-radius: 6px;
    background: #f1f5f9;
    color: #64748b;
    cursor: pointer;
    transition: all 0.2s;
}

.modal-close:hover {
    background: #e2e8f0;
    color: #334155;
}

.modal-search {
    width: 100%;
    padding: 0.65rem 0.85rem;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 0.95rem;
    margin-bottom: 0.75rem;
}

.modal-search:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}

.modal-list {
    flex: 1;
    overflow-y: auto;
    max-height: 320px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
}

.modal-list-item {
    padding: 0.7rem 0.85rem;
    border-bottom: 1px solid #f1f5f9;
    cursor: pointer;
    font-size: 0.9rem;
    color: #334155;
    transition: background 0.15s;
}

.modal-list-item:last-child {
    border-bottom: none;
}

.modal-list-item:hover {
    background: #f8fafc;
}

.modal-list-item.selected {
    background: #eff6ff;
    color: #1d4ed8;
    font-weight: 500;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
    margin-top: 1rem;
    padding-top: 0.75rem;
    border-top: 1px solid #e5e7eb;
}

/* Scanner de código de barras */
.btn-scanner {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    color: #fff;
    box-shadow: 0 2px 4px rgba(5,150,105,0.3);
}

.btn-scanner:hover {
    background: linear-gradient(135deg, #047857 0%, #065f46 100%);
    box-shadow: 0 4px 8px rgba(5,150,105,0.35);
}

.scanner-modal {
    width: min(480px, 94vw);
}

.scanner-preview {
    width: 100%;
    aspect-ratio: 4/3;
    background: #0f172a;
    border-radius: 8px;
    overflow: hidden;
    position: relative;
    margin-bottom: 1rem;
}

.scanner-preview video {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.scanner-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
}

.scanner-line {
    position: absolute;
    left: 10%;
    right: 10%;
    height: 3px;
    background: linear-gradient(90deg, transparent, #22c55e, transparent);
    animation: scanLine 2s ease-in-out infinite;
    box-shadow: 0 0 10px #22c55e;
}

@keyframes scanLine {
    0%, 100% { top: 25%; }
    50% { top: 75%; }
}

.scanner-frame {
    width: 80%;
    height: 60%;
    border: 2px solid rgba(34,197,94,0.6);
    border-radius: 8px;
    position: relative;
}

.scanner-frame::before,
.scanner-frame::after {
    content: '';
    position: absolute;
    width: 20px;
    height: 20px;
    border-color: #22c55e;
    border-style: solid;
}

.scanner-frame::before {
    top: -2px;
    left: -2px;
    border-width: 3px 0 0 3px;
    border-radius: 4px 0 0 0;
}

.scanner-frame::after {
    top: -2px;
    right: -2px;
    border-width: 3px 3px 0 0;
    border-radius: 0 4px 0 0;
}

.scanner-manual {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.75rem;
}

.scanner-manual input {
    flex: 1;
    padding: 0.65rem 0.85rem;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 0.95rem;
}

.scanner-manual input:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}

.scanner-status {
    text-align: center;
    padding: 0.5rem;
    font-size: 0.85rem;
    color: #64748b;
}

.scanner-status.success {
    color: #15803d;
    background: #f0fdf4;
    border-radius: 6px;
}

.scanner-status.error {
    color: #b91c1c;
    background: #fef2f2;
    border-radius: 6px;
}

.scanner-status.scanning {
    color: #0369a1;
    background: #f0f9ff;
    border-radius: 6px;
}

.scanner-register-prompt {
    display: none;
    margin-top: 0.55rem;
    padding: 0.5rem 0.65rem;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #f8fafc;
}

.scanner-register-text {
    font-size: 0.82rem;
    color: #475569;
}

.scanner-register-actions {
    display: flex;
    gap: 0.45rem;
    margin-top: 0.45rem;
}

.btn-quiet {
    padding: 0.4rem 0.65rem;
    font-size: 0.8rem;
    border-radius: 7px;
}

.modal-cadastro {
    width: min(1320px, 96vw);
    height: 92vh;
    max-height: 92vh;
    padding: 0;
}

.modal-cadastro .modal-header {
    margin: 0;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid #e5e7eb;
}

.modal-cadastro-body {
    flex: 1;
    min-height: 0;
}

.modal-cadastro-iframe {
    width: 100%;
    height: 100%;
    border: none;
    background: #fff;
}

.barcode-indicator {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    font-size: 0.7rem;
    color: #059669;
    background: #ecfdf5;
    padding: 0.15rem 0.4rem;
    border-radius: 4px;
    margin-left: 0.5rem;
}

/* Responsivo */
@media (max-width: 768px) {
    .entrada-container {
        padding: 1rem;
    }
    
    .entrada-header {
        grid-template-columns: 1fr;
    }
    
    .entrada-items-header {
        display: none;
    }
    
    .entrada-item-row {
        grid-template-columns: 1fr;
        gap: 0.5rem;
        padding: 0.85rem 0;
        border-bottom: 1px solid #e2e8f0;
    }
    
    .entrada-item-row::before {
        content: none;
    }
    
    .entrada-insumo-field {
        width: 100%;
    }
    
    .entrada-item-row .entrada-quantidade-wrapper,
    .entrada-item-row .entrada-unidade-wrapper {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .entrada-item-row .entrada-quantidade-wrapper::before {
        content: 'Qtd:';
        font-size: 0.75rem;
        color: #64748b;
        font-weight: 600;
        min-width: 35px;
    }
    
    .entrada-item-row .entrada-unidade-wrapper::before {
        content: 'Un:';
        font-size: 0.75rem;
        color: #64748b;
        font-weight: 600;
        min-width: 35px;
    }
    
    .entrada-mobile-actions {
        display: flex;
        justify-content: flex-end;
    }
    
    .entrada-actions {
        flex-direction: column;
    }
    
    .entrada-actions .btn {
        width: 100%;
        justify-content: center;
    }
}
</style>

<div class="entrada-container">
    <h1>Entrada de mercadoria</h1>
    
    <?php foreach ($errors as $e): ?>
        <div class="alert-error"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
    <?php foreach ($messages as $m): ?>
        <div class="alert-success"><?= htmlspecialchars($m) ?></div>
    <?php endforeach; ?>

    <form method="post" class="entrada-card">
        <input type="hidden" name="action" value="save">
        
        <!-- Cabeçalho: Unidade e Observação -->
        <div class="entrada-header">
            <div class="form-group">
                <label for="unidade_id">Unidade destino</label>
                <select name="unidade_id" id="unidade_id" required>
                    <option value="">Selecione a unidade...</option>
                    <?php foreach ($unidades as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="observacao">Observação</label>
                <input type="text" name="observacao" id="observacao" placeholder="Ex: Compra mercado, fornecedor X...">
            </div>
        </div>

        <!-- Lista de Itens -->
        <div class="entrada-items-wrapper">
            <div class="entrada-items-label">Itens da entrada</div>
            
            <div class="entrada-items-header">
                <span>Item / Insumo</span>
                <span>Quantidade</span>
                <span>Unidade</span>
                <span></span>
            </div>
            
            <div id="entrada-body">
                <div class="entrada-item-row">
                    <div class="entrada-insumo-field">
                        <input type="text" class="item-nome" readonly placeholder="Clique para selecionar...">
                        <input type="hidden" name="insumo_id[]" class="item-id">
                        <button class="btn-buscar open-modal" type="button" title="Buscar insumo">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                        </button>
                    </div>
                    <div class="entrada-quantidade-wrapper">
                        <input type="text" name="quantidade[]" class="entrada-quantidade" placeholder="0,00">
                    </div>
                    <div class="entrada-unidade-wrapper">
                        <span class="entrada-unidade-text item-unidade">-</span>
                        <input type="hidden" name="unidade_medida_id[]" class="item-unidade-id">
                    </div>
                    <div class="entrada-mobile-actions">
                        <button class="btn-remover remove-row" type="button" title="Remover item">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ações -->
        <div class="entrada-actions">
            <button class="btn btn-scanner" type="button" id="open-scanner">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h2M4 12h2m14-4h2M4 8h2m2-4h2m8 0h2M6 20h2m-2-4h2"/>
                </svg>
                Ler código de barras
            </button>
            <button class="btn btn-secondary" type="button" id="add-row">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                Adicionar item
            </button>
            <button class="btn btn-primary" type="submit">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                </svg>
                Salvar entrada
            </button>
        </div>
    </form>
</div>

<div class="modal-overlay" id="modal-insumos">
    <div class="modal">
        <div class="modal-header">
            <strong>Selecionar insumo</strong>
            <button class="modal-close" type="button" id="close-modal" title="Fechar">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <input type="text" id="search-insumo" class="modal-search" placeholder="Digite para buscar...">
        <div id="insumo-list" class="modal-list"></div>
        <div class="modal-footer">
            <button class="btn btn-secondary" type="button" id="cancel-modal">Cancelar</button>
            <button class="btn btn-primary" type="button" id="select-insumo">Selecionar</button>
        </div>
    </div>
</div>

<!-- Modal Scanner de Código de Barras -->
<div class="modal-overlay" id="modal-scanner">
    <div class="modal scanner-modal">
        <div class="modal-header">
            <strong>Leitor de Código de Barras</strong>
            <button class="modal-close" type="button" id="close-scanner" title="Fechar">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        
        <div class="scanner-preview" id="scanner-preview">
            <video id="scanner-video" autoplay playsinline muted></video>
            <div class="scanner-overlay">
                <div class="scanner-frame">
                    <div class="scanner-line"></div>
                </div>
            </div>
        </div>
        
        <div class="scanner-status" id="scanner-status">
            Posicione o código de barras na área destacada
        </div>

        <div class="scanner-register-prompt" id="scanner-register-prompt">
            <div class="scanner-register-text" id="scanner-register-text">Produto não cadastrado. Deseja cadastrar?</div>
            <div class="scanner-register-actions">
                <button class="btn btn-secondary btn-quiet" type="button" id="scanner-register-yes">Cadastrar</button>
                <button class="btn btn-secondary btn-quiet" type="button" id="scanner-register-no">Agora não</button>
            </div>
        </div>
        
        <div class="scanner-manual">
            <input type="text" id="barcode-manual" placeholder="Ou digite o código manualmente..." autocomplete="off">
            <button class="btn btn-primary" type="button" id="barcode-search">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
            </button>
        </div>
        
        <div class="modal-footer">
            <button class="btn btn-secondary" type="button" id="cancel-scanner">Fechar</button>
        </div>
    </div>
</div>

<!-- Modal Cadastro de Insumo -->
<div class="modal-overlay" id="modal-cadastro-insumo">
    <div class="modal modal-cadastro">
        <div class="modal-header">
            <strong>Cadastrar insumo</strong>
            <button class="modal-close" type="button" id="close-cadastro-insumo" title="Fechar">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="modal-cadastro-body">
            <iframe id="cadastro-insumo-iframe" class="modal-cadastro-iframe" src="about:blank"></iframe>
        </div>
    </div>
</div>

<!-- Biblioteca QuaggaJS para leitura de código de barras -->
<script src="https://cdn.jsdelivr.net/npm/@ericblade/quagga2@1.8.4/dist/quagga.min.js"></script>

<script>
const INSUMOS = <?= json_encode($insumos, JSON_UNESCAPED_UNICODE) ?>;
let currentRow = null;
let selectedInsumo = null;
let scannerActive = false;
let lastScannedCode = '';
let scanDebounceTimer = null;
let pendingBarcodeCadastro = '';

// ========== SCANNER DE CÓDIGO DE BARRAS ==========

function buildInsumoDisplayName(insumo) {
    if (!insumo) return '';
    const nomeBase = (insumo.insumo_base_nome || '').trim();
    const nomeOficial = (insumo.nome_oficial || '').trim();
    const nomeExibicao = (insumo.nome_exibicao || '').trim();
    if (nomeExibicao) return nomeExibicao;
    if (!nomeBase) return nomeOficial;
    if (!nomeOficial) return nomeBase;
    if (nomeBase.toLowerCase() === nomeOficial.toLowerCase()) return nomeOficial;
    return `${nomeBase} (${nomeOficial})`;
}

function findInsumoByBarcode(code) {
    const normalizedCode = code.trim();
    return INSUMOS.find(i => i.barcode && i.barcode.trim() === normalizedCode);
}

function hideRegisterPrompt() {
    const promptEl = document.getElementById('scanner-register-prompt');
    if (promptEl) {
        promptEl.style.display = 'none';
    }
    pendingBarcodeCadastro = '';
}

function showRegisterPrompt(code) {
    pendingBarcodeCadastro = code;
    const promptEl = document.getElementById('scanner-register-prompt');
    const textEl = document.getElementById('scanner-register-text');
    if (textEl) {
        textEl.textContent = `Produto não cadastrado. Deseja cadastrar? (código: ${code})`;
    }
    if (promptEl) {
        promptEl.style.display = 'block';
    }
}

function openCadastroInsumoFromBarcode() {
    if (!pendingBarcodeCadastro) return;
    const barcode = pendingBarcodeCadastro;
    stopScanner();
    document.getElementById('modal-scanner').style.display = 'none';
    hideRegisterPrompt();
    openCadastroInsumoModal(barcode);
}

function openCadastroInsumoModal(barcode = '') {
    const modal = document.getElementById('modal-cadastro-insumo');
    const iframe = document.getElementById('cadastro-insumo-iframe');
    let url = 'index.php?page=logistica_insumos&modal=1&nochecks=1';
    if (barcode) {
        url += `&barcode=${encodeURIComponent(barcode)}`;
    }
    iframe.src = url;
    modal.style.display = 'flex';
}

function closeCadastroInsumoModal() {
    const modal = document.getElementById('modal-cadastro-insumo');
    const iframe = document.getElementById('cadastro-insumo-iframe');
    if (iframe) {
        iframe.src = 'about:blank';
    }
    if (modal) {
        modal.style.display = 'none';
    }
}

window.onCadastroInsumoSalvo = function(insumo) {
    if (insumo && Number(insumo.id) > 0) {
        const insumoBase = insumo.insumo_base_nome || insumo.nome_oficial || '';
        const normalized = {
            id: Number(insumo.id),
            nome_oficial: insumo.nome_oficial || '',
            insumo_base_nome: insumoBase,
            nome_exibicao: buildInsumoDisplayName({
                nome_oficial: insumo.nome_oficial || '',
                insumo_base_nome: insumoBase,
                nome_exibicao: insumo.nome_exibicao || ''
            }),
            unidade_medida_padrao_id: insumo.unidade_medida_padrao_id || '',
            unidade_nome: insumo.unidade_nome || '-',
            barcode: insumo.barcode || ''
        };
        const idx = INSUMOS.findIndex(i => Number(i.id) === normalized.id);
        if (idx >= 0) {
            INSUMOS[idx] = { ...INSUMOS[idx], ...normalized };
        } else {
            INSUMOS.push(normalized);
        }
        addInsumoFromBarcode(normalized);
    }
    closeCadastroInsumoModal();
};

function addInsumoFromBarcode(insumo) {
    // Verifica se já existe uma linha vazia para usar
    let targetRow = null;
    const rows = document.querySelectorAll('#entrada-body .entrada-item-row');
    
    for (const row of rows) {
        const idInput = row.querySelector('.item-id');
        if (!idInput.value) {
            targetRow = row;
            break;
        }
    }
    
    // Se não houver linha vazia, cria uma nova
    if (!targetRow) {
        const container = document.getElementById('entrada-body');
        targetRow = document.createElement('div');
        targetRow.className = 'entrada-item-row';
        targetRow.innerHTML = `
            <div class="entrada-insumo-field">
                <input type="text" class="item-nome" readonly placeholder="Clique para selecionar...">
                <input type="hidden" name="insumo_id[]" class="item-id">
                <button class="btn-buscar open-modal" type="button" title="Buscar insumo">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </button>
            </div>
            <div class="entrada-quantidade-wrapper">
                <input type="text" name="quantidade[]" class="entrada-quantidade" placeholder="0,00">
            </div>
            <div class="entrada-unidade-wrapper">
                <span class="entrada-unidade-text item-unidade">-</span>
                <input type="hidden" name="unidade_medida_id[]" class="item-unidade-id">
            </div>
            <div class="entrada-mobile-actions">
                <button class="btn-remover remove-row" type="button" title="Remover item">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        `;
        container.appendChild(targetRow);
    }
    
    // Preenche a linha com os dados do insumo
    targetRow.querySelector('.item-nome').value = buildInsumoDisplayName(insumo);
    targetRow.querySelector('.item-id').value = insumo.id;
    targetRow.querySelector('.item-unidade').textContent = insumo.unidade_nome || '-';
    targetRow.querySelector('.item-unidade-id').value = insumo.unidade_medida_padrao_id || '';
    
    // Adiciona indicador de código de barras
    const nomeInput = targetRow.querySelector('.item-nome');
    const existingIndicator = targetRow.querySelector('.barcode-indicator');
    if (!existingIndicator) {
        const indicator = document.createElement('span');
        indicator.className = 'barcode-indicator';
        indicator.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:12px;height:12px;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h2M4 12h2m14-4h2M4 8h2m2-4h2m8 0h2M6 20h2m-2-4h2"/>
            </svg>
        `;
        nomeInput.parentNode.insertBefore(indicator, nomeInput.nextSibling);
    }
    
    // Foco no campo de quantidade
    const qtdInput = targetRow.querySelector('.entrada-quantidade');
    if (qtdInput) {
        qtdInput.focus();
        qtdInput.select();
    }
    
    return true;
}

function handleBarcodeResult(code) {
    // Debounce para evitar leituras duplicadas
    if (code === lastScannedCode) return;
    
    clearTimeout(scanDebounceTimer);
    scanDebounceTimer = setTimeout(() => {
        lastScannedCode = '';
    }, 2000);
    
    lastScannedCode = code;
    
    const statusEl = document.getElementById('scanner-status');
    const insumo = findInsumoByBarcode(code);
    
    if (insumo) {
        hideRegisterPrompt();
        statusEl.className = 'scanner-status success';
        statusEl.textContent = `Encontrado: ${buildInsumoDisplayName(insumo)}`;
        addInsumoFromBarcode(insumo);
        
        // Som de sucesso (beep)
        try {
            const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioCtx.createOscillator();
            const gainNode = audioCtx.createGain();
            oscillator.connect(gainNode);
            gainNode.connect(audioCtx.destination);
            oscillator.frequency.value = 800;
            gainNode.gain.value = 0.1;
            oscillator.start();
            setTimeout(() => oscillator.stop(), 100);
        } catch (e) {}
        
        // Reseta após 1.5s para permitir nova leitura
        setTimeout(() => {
            statusEl.className = 'scanner-status scanning';
            statusEl.textContent = 'Pronto para próxima leitura...';
        }, 1500);
    } else {
        statusEl.className = 'scanner-status error';
        statusEl.textContent = `Código "${code}" não encontrado no cadastro`;
        showRegisterPrompt(code);
        
        setTimeout(() => {
            statusEl.className = 'scanner-status';
            statusEl.textContent = 'Posicione o código de barras na área destacada';
        }, 2500);
    }
}

function startScanner() {
    const previewEl = document.getElementById('scanner-preview');
    const statusEl = document.getElementById('scanner-status');
    
    statusEl.className = 'scanner-status scanning';
    statusEl.textContent = 'Iniciando câmera...';
    
    Quagga.init({
        inputStream: {
            name: "Live",
            type: "LiveStream",
            target: previewEl,
            constraints: {
                facingMode: "environment",
                width: { ideal: 1280 },
                height: { ideal: 720 }
            }
        },
        decoder: {
            readers: [
                "ean_reader",
                "ean_8_reader",
                "code_128_reader",
                "code_39_reader",
                "upc_reader",
                "upc_e_reader"
            ]
        },
        locate: true,
        locator: {
            patchSize: "medium",
            halfSample: true
        }
    }, function(err) {
        if (err) {
            console.error('Erro ao iniciar scanner:', err);
            statusEl.className = 'scanner-status error';
            statusEl.textContent = 'Erro ao acessar câmera. Use o campo manual abaixo.';
            return;
        }
        
        scannerActive = true;
        Quagga.start();
        statusEl.className = 'scanner-status';
        statusEl.textContent = 'Posicione o código de barras na área destacada';
    });
    
    Quagga.onDetected(function(result) {
        if (result && result.codeResult && result.codeResult.code) {
            handleBarcodeResult(result.codeResult.code);
        }
    });
}

function stopScanner() {
    if (scannerActive) {
        Quagga.stop();
        scannerActive = false;
    }
    lastScannedCode = '';
}

function openScannerModal() {
    document.getElementById('modal-scanner').style.display = 'flex';
    document.getElementById('barcode-manual').value = '';
    document.getElementById('scanner-status').className = 'scanner-status';
    document.getElementById('scanner-status').textContent = 'Posicione o código de barras na área destacada';
    hideRegisterPrompt();
    startScanner();
}

function closeScannerModal() {
    stopScanner();
    hideRegisterPrompt();
    document.getElementById('modal-scanner').style.display = 'none';
}

// Event listeners do scanner
document.getElementById('open-scanner').addEventListener('click', openScannerModal);
document.getElementById('close-scanner').addEventListener('click', closeScannerModal);
document.getElementById('cancel-scanner').addEventListener('click', closeScannerModal);
document.getElementById('modal-scanner').addEventListener('click', (e) => {
    if (e.target.id === 'modal-scanner') closeScannerModal();
});

// Busca manual por código de barras
document.getElementById('barcode-search').addEventListener('click', () => {
    const code = document.getElementById('barcode-manual').value.trim();
    if (code) handleBarcodeResult(code);
});

document.getElementById('barcode-manual').addEventListener('keypress', (e) => {
    if (e.key === 'Enter') {
        e.preventDefault();
        const code = e.target.value.trim();
        if (code) {
            handleBarcodeResult(code);
            e.target.value = '';
        }
    }
});

document.getElementById('scanner-register-yes').addEventListener('click', () => {
    openCadastroInsumoFromBarcode();
});

document.getElementById('scanner-register-no').addEventListener('click', () => {
    hideRegisterPrompt();
});

document.getElementById('close-cadastro-insumo').addEventListener('click', closeCadastroInsumoModal);
document.getElementById('modal-cadastro-insumo').addEventListener('click', (e) => {
    if (e.target.id === 'modal-cadastro-insumo') {
        closeCadastroInsumoModal();
    }
});

// ========== SUPORTE A LEITOR USB (funciona como teclado) ==========
let barcodeBuffer = '';
let barcodeTimeout = null;

document.addEventListener('keypress', (e) => {
    // Ignora se estiver em input de texto (exceto o campo manual do scanner)
    const activeEl = document.activeElement;
    const isInput = activeEl && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA');
    const isScannerModal = document.getElementById('modal-scanner').style.display === 'flex';
    
    // Se o modal do scanner não está aberto e estamos em um input, ignora
    if (!isScannerModal && isInput) return;
    
    // Leitores USB geralmente enviam caracteres rapidamente seguidos de Enter
    clearTimeout(barcodeTimeout);
    
    if (e.key === 'Enter') {
        if (barcodeBuffer.length >= 8) { // EAN-8 mínimo
            handleBarcodeResult(barcodeBuffer);
        }
        barcodeBuffer = '';
        return;
    }
    
    // Apenas números e letras
    if (/^[0-9a-zA-Z]$/.test(e.key)) {
        barcodeBuffer += e.key;
    }
    
    // Limpa o buffer após 100ms de inatividade
    barcodeTimeout = setTimeout(() => {
        barcodeBuffer = '';
    }, 100);
});

function renderInsumos() {
    const term = document.getElementById('search-insumo').value.toLowerCase();
    const list = document.getElementById('insumo-list');
    list.innerHTML = '';
    const filtered = INSUMOS.filter(i => {
        const nomeOficial = (i.nome_oficial || '').toLowerCase();
        const nomeBase = (i.insumo_base_nome || '').toLowerCase();
        const nomeExibicao = buildInsumoDisplayName(i).toLowerCase();
        return nomeOficial.includes(term) || nomeBase.includes(term) || nomeExibicao.includes(term);
    });
    
    if (filtered.length === 0) {
        list.innerHTML = '<div style="padding: 1rem; color: #64748b; text-align: center;">Nenhum insumo encontrado</div>';
        return;
    }
    
    filtered.forEach(item => {
        const div = document.createElement('div');
        div.className = 'modal-list-item';
        div.textContent = buildInsumoDisplayName(item);
        div.onclick = () => {
            selectedInsumo = item;
            [...list.querySelectorAll('.selected')].forEach(n => n.classList.remove('selected'));
            div.classList.add('selected');
        };
        // Double-click para selecionar direto
        div.ondblclick = () => {
            selectedInsumo = item;
            applySelection();
        };
        list.appendChild(div);
    });
}

function openModal(row) {
    currentRow = row;
    selectedInsumo = null;
    document.getElementById('search-insumo').value = '';
    renderInsumos();
    document.getElementById('modal-insumos').style.display = 'flex';
    // Foco no campo de busca
    setTimeout(() => document.getElementById('search-insumo').focus(), 100);
}

function closeModal() {
    document.getElementById('modal-insumos').style.display = 'none';
    currentRow = null;
    selectedInsumo = null;
}

function applySelection() {
    if (!currentRow || !selectedInsumo) return;
    currentRow.querySelector('.item-nome').value = buildInsumoDisplayName(selectedInsumo);
    currentRow.querySelector('.item-id').value = selectedInsumo.id;
    const unidadeEl = currentRow.querySelector('.item-unidade');
    unidadeEl.textContent = selectedInsumo.unidade_nome || '-';
    currentRow.querySelector('.item-unidade-id').value = selectedInsumo.unidade_medida_padrao_id || '';
    closeModal();
    // Foco no campo de quantidade
    const qtdInput = currentRow.querySelector('.entrada-quantidade');
    if (qtdInput) qtdInput.focus();
}

// Event listeners do modal
document.getElementById('close-modal').addEventListener('click', closeModal);
document.getElementById('cancel-modal').addEventListener('click', closeModal);
document.getElementById('modal-insumos').addEventListener('click', (e) => {
    if (e.target.id === 'modal-insumos') closeModal();
});
document.getElementById('search-insumo').addEventListener('input', renderInsumos);
document.getElementById('select-insumo').addEventListener('click', applySelection);

// Fechar com ESC
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && document.getElementById('modal-insumos').style.display === 'flex') {
        closeModal();
    }
    if (e.key === 'Escape' && document.getElementById('modal-cadastro-insumo').style.display === 'flex') {
        closeCadastroInsumoModal();
    }
});

// Event delegation para os botões nas linhas
document.getElementById('entrada-body').addEventListener('click', (e) => {
    const target = e.target.closest('.open-modal');
    if (target) {
        const row = target.closest('.entrada-item-row');
        openModal(row);
        return;
    }
    
    const removeBtn = e.target.closest('.remove-row');
    if (removeBtn) {
        const rows = document.querySelectorAll('#entrada-body .entrada-item-row');
        if (rows.length > 1) {
            removeBtn.closest('.entrada-item-row').remove();
        }
    }
});

// Adicionar nova linha
document.getElementById('add-row').addEventListener('click', () => {
    const container = document.getElementById('entrada-body');
    const row = document.createElement('div');
    row.className = 'entrada-item-row';
    row.innerHTML = `
        <div class="entrada-insumo-field">
            <input type="text" class="item-nome" readonly placeholder="Clique para selecionar...">
            <input type="hidden" name="insumo_id[]" class="item-id">
            <button class="btn-buscar open-modal" type="button" title="Buscar insumo">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
            </button>
        </div>
        <div class="entrada-quantidade-wrapper">
            <input type="text" name="quantidade[]" class="entrada-quantidade" placeholder="0,00">
        </div>
        <div class="entrada-unidade-wrapper">
            <span class="entrada-unidade-text item-unidade">-</span>
            <input type="hidden" name="unidade_medida_id[]" class="item-unidade-id">
        </div>
        <div class="entrada-mobile-actions">
            <button class="btn-remover remove-row" type="button" title="Remover item">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
    `;
    container.appendChild(row);
    const selectBtn = row.querySelector('.open-modal');
    if (selectBtn) {
        selectBtn.focus();
    }
});
</script>

<?php endSidebar(); ?>
