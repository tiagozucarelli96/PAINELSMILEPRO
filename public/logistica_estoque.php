<?php
require_once __DIR__ . '/logistica_tz.php';
// logistica_estoque.php — HUB de estoque
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/upload_magalu.php';

if (empty($_SESSION['perm_superadmin']) && empty($_SESSION['perm_logistico'])) {
    http_response_code(403);
    echo '<div class="alert-error">Acesso negado.</div>';
    exit;
}

$quick_errors = [];
$quick_messages = [];
$open_quick_modal = false;
$quick_form = [
    'barcode' => '',
    'nome_oficial' => '',
    'unidade_medida_padrao_id' => '',
    'unidade_embalagem' => '',
    'tipologia_insumo_id' => ''
];

function fetch_unidades_medida_ativas(PDO $pdo): array {
    $sql = "SELECT id, nome FROM logistica_unidades_medida WHERE ativo IS TRUE ORDER BY ordem, nome";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function fetch_tipologias_insumo_ativas(PDO $pdo): array {
    $sql = "SELECT id, nome FROM logistica_tipologias_insumo WHERE ativo IS TRUE ORDER BY ordem, nome";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

$unidades_medida = fetch_unidades_medida_ativas($pdo);
$tipologias_insumo = fetch_tipologias_insumo_ativas($pdo);

$unidade_index = [];
foreach ($unidades_medida as $item) {
    $unidade_index[(int)$item['id']] = $item['nome'];
}

$tipologia_index = [];
foreach ($tipologias_insumo as $item) {
    $tipologia_index[(int)$item['id']] = $item['nome'];
}

$can_quick_save = !empty($unidade_index) && !empty($tipologia_index);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quick_save_insumo') {
    $open_quick_modal = true;
    $quick_form = [
        'barcode' => trim((string)($_POST['barcode'] ?? '')),
        'nome_oficial' => trim((string)($_POST['nome_oficial'] ?? '')),
        'unidade_medida_padrao_id' => (string)(int)($_POST['unidade_medida_padrao_id'] ?? 0),
        'unidade_embalagem' => trim((string)($_POST['unidade_embalagem'] ?? '')),
        'tipologia_insumo_id' => (string)(int)($_POST['tipologia_insumo_id'] ?? 0)
    ];

    if (!$can_quick_save) {
        $quick_errors[] = 'Cadastre ao menos uma unidade de medida e uma tipologia de insumo antes de usar o cadastro rápido.';
    }

    if ($quick_form['nome_oficial'] === '') {
        $quick_errors[] = 'Informe o nome do insumo.';
    }

    $unidade_medida_padrao_id = (int)$quick_form['unidade_medida_padrao_id'];
    if ($unidade_medida_padrao_id <= 0 || !isset($unidade_index[$unidade_medida_padrao_id])) {
        $quick_errors[] = 'Selecione uma unidade de medida válida.';
    }

    if ($quick_form['unidade_embalagem'] === '') {
        $quick_errors[] = 'Selecione a unidade da embalagem.';
    } elseif (!in_array($quick_form['unidade_embalagem'], array_values($unidade_index), true)) {
        $quick_errors[] = 'Unidade da embalagem inválida.';
    }

    $tipologia_insumo_id = (int)$quick_form['tipologia_insumo_id'];
    if ($tipologia_insumo_id <= 0 || !isset($tipologia_index[$tipologia_insumo_id])) {
        $quick_errors[] = 'Selecione uma tipologia de insumo válida.';
    }

    if ($quick_form['barcode'] !== '') {
        $stmt = $pdo->prepare("SELECT id, nome_oficial FROM logistica_insumos WHERE barcode = :barcode LIMIT 1");
        $stmt->execute([':barcode' => $quick_form['barcode']]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $quick_errors[] = 'Este código de barras já está cadastrado para "' . ($existing['nome_oficial'] ?? 'outro insumo') . '" (ID ' . (int)$existing['id'] . ').';
        }
    }

    $quick_foto_url = null;
    $quick_foto_chave = null;
    if (empty($quick_errors) && !empty($_FILES['quick_foto_file']['tmp_name']) && is_uploaded_file($_FILES['quick_foto_file']['tmp_name'])) {
        try {
            $uploader = new MagaluUpload();
            $result = $uploader->upload($_FILES['quick_foto_file'], 'logistica/insumos');
            $quick_foto_url = $result['url'] ?? null;
            $quick_foto_chave = $result['chave_storage'] ?? null;
        } catch (Throwable $e) {
            $quick_errors[] = 'Falha ao enviar foto do insumo: ' . $e->getMessage();
        }
    }

    if (empty($quick_errors)) {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO logistica_insumos
                    (nome_oficial, unidade_medida, unidade_medida_padrao_id, tipologia_insumo_id,
                     visivel_na_lista, ativo, barcode, fracionavel, unidade_embalagem,
                     foto_url, foto_chave_storage)
                 VALUES
                    (:nome_oficial, :unidade_medida, :unidade_medida_padrao_id, :tipologia_insumo_id,
                     TRUE, TRUE, :barcode, TRUE, :unidade_embalagem,
                     :foto_url, :foto_chave_storage)"
            );

            $stmt->execute([
                ':nome_oficial' => $quick_form['nome_oficial'],
                ':unidade_medida' => $unidade_index[$unidade_medida_padrao_id] ?? null,
                ':unidade_medida_padrao_id' => $unidade_medida_padrao_id,
                ':tipologia_insumo_id' => $tipologia_insumo_id,
                ':barcode' => $quick_form['barcode'] !== '' ? $quick_form['barcode'] : null,
                ':unidade_embalagem' => $quick_form['unidade_embalagem'],
                ':foto_url' => $quick_foto_url,
                ':foto_chave_storage' => $quick_foto_chave
            ]);

            $new_id = (int)$pdo->lastInsertId();
            $quick_messages[] = 'Insumo cadastrado com sucesso (ID ' . $new_id . ').';
            $open_quick_modal = false;
            $quick_form = [
                'barcode' => '',
                'nome_oficial' => '',
                'unidade_medida_padrao_id' => '',
                'unidade_embalagem' => '',
                'tipologia_insumo_id' => ''
            ];
        } catch (Throwable $e) {
            $quick_errors[] = 'Não foi possível salvar o insumo rápido: ' . $e->getMessage();
        }
    }
}

ob_start();
?>

<style>
.estoque-hub {
    max-width: 1400px;
    margin: 0 auto;
    padding: 1.5rem;
}

.estoque-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}

.estoque-header h1 {
    margin: 0;
}

.estoque-header p {
    margin: 0.35rem 0 0;
    color: #64748b;
    font-size: 0.95rem;
}

.estoque-cards {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 1.25rem;
}

.estoque-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    padding: 1.5rem;
    text-decoration: none;
    color: inherit;
    transition: all .2s ease;
    display: flex;
    flex-direction: column;
    gap: .75rem;
}

.estoque-card:hover {
    transform: translateY(-2px);
    border-color: #3b82f6;
    box-shadow: 0 6px 16px rgba(0,0,0,0.12);
}

.estoque-card span {
    font-size: 2rem;
}

.estoque-card h3 {
    margin: 0;
    font-size: 1.1rem;
}

.estoque-card p {
    margin: 0;
    color: #64748b;
    font-size: .95rem;
}

.estoque-card-btn {
    border: 1px solid #0f766e;
    background: linear-gradient(135deg, #0f766e 0%, #115e59 100%);
    color: #ffffff;
    text-align: left;
    cursor: pointer;
}

.estoque-card-btn p {
    color: rgba(255,255,255,0.86);
}

.alert {
    padding: 0.75rem 1rem;
    border-radius: 8px;
    margin-bottom: 0.75rem;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
}

.alert-success {
    background: #dcfce7;
    color: #166534;
}

.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.65);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}

.modal {
    background: #ffffff;
    border-radius: 12px;
    width: min(1080px, 96vw);
    max-height: 92vh;
    overflow: hidden;
    box-shadow: 0 20px 50px rgba(0,0,0,0.25);
    display: flex;
    flex-direction: column;
}

.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.85rem 1rem;
    border-bottom: 1px solid #e5e7eb;
    background: #f8fafc;
}

.modal-title h3 {
    margin: 0;
    font-size: 1.05rem;
    color: #0f172a;
}

.modal-title p {
    margin: 0.2rem 0 0;
    color: #64748b;
    font-size: 0.85rem;
}

.modal-close {
    border: none;
    background: #e2e8f0;
    color: #0f172a;
    border-radius: 8px;
    width: 32px;
    height: 32px;
    cursor: pointer;
    font-weight: 700;
}

.modal-body {
    padding: 1rem;
    overflow: auto;
}

.quick-layout {
    display: grid;
    grid-template-columns: minmax(320px, 440px) minmax(320px, 1fr);
    gap: 1rem;
}

.quick-panel {
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 0.9rem;
    background: #fff;
}

.quick-panel h4 {
    margin: 0 0 0.25rem;
    color: #0f172a;
}

.quick-panel p {
    margin: 0 0 0.75rem;
    color: #64748b;
    font-size: 0.85rem;
}

.quick-form-panel {
    display: none;
}

.quick-form-panel.visible {
    display: block;
}

.form-row {
    margin-bottom: 0.75rem;
}

.field-label {
    display: block;
    margin-bottom: 0.35rem;
    color: #334155;
    font-size: 0.85rem;
    font-weight: 600;
}

.form-input,
.form-select {
    width: 100%;
    border: 1px solid #cbd5f5;
    border-radius: 8px;
    padding: 0.6rem 0.75rem;
    font-size: 0.95rem;
}

.form-hint {
    margin-top: 0.3rem;
    color: #64748b;
    font-size: 0.8rem;
}

.photo-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.photo-preview {
    margin-top: 0.55rem;
    min-height: 120px;
    border: 1px dashed #cbd5e1;
    border-radius: 10px;
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.photo-preview img {
    width: 100%;
    max-height: 220px;
    object-fit: cover;
}

.photo-placeholder {
    color: #64748b;
    font-size: 0.82rem;
}

.camera-capture-box {
    display: none;
    margin-top: 0.55rem;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    background: #f8fafc;
    padding: 0.6rem;
}

.camera-capture-box.active {
    display: block;
}

.camera-live-preview {
    width: 100%;
    aspect-ratio: 4/3;
    border-radius: 8px;
    background: #0f172a;
    overflow: hidden;
}

.camera-live-preview video {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.camera-capture-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    margin-top: 0.5rem;
}

.btn-primary {
    background: #2563eb;
    color: #fff;
    border: none;
    padding: 0.6rem 1rem;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
}

.btn-secondary {
    background: #e2e8f0;
    color: #1f2937;
    border: none;
    padding: 0.55rem 0.9rem;
    border-radius: 8px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.btn-scanner {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    color: #fff;
    box-shadow: 0 2px 4px rgba(5,150,105,0.3);
}

.btn-scanner:hover {
    background: linear-gradient(135deg, #047857 0%, #065f46 100%);
    box-shadow: 0 4px 8px rgba(5,150,105,0.35);
}

.btn-row {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.scanner-preview {
    width: 100%;
    aspect-ratio: 4/3;
    background: #0f172a;
    border-radius: 8px;
    overflow: hidden;
    position: relative;
    margin: 0.7rem 0;
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

.scanner-frame {
    width: 80%;
    height: 60%;
    border: 2px solid rgba(34,197,94,0.6);
    border-radius: 8px;
    position: relative;
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

.scanner-status {
    text-align: center;
    padding: 0.5rem;
    font-size: 0.85rem;
    color: #64748b;
    border-radius: 6px;
    background: #f8fafc;
}

.scanner-status.success {
    color: #15803d;
    background: #f0fdf4;
}

.scanner-status.error {
    color: #b91c1c;
    background: #fef2f2;
}

.scanner-status.scanning {
    color: #0369a1;
    background: #f0f9ff;
}

.scanner-manual {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.75rem;
}

.scanner-manual input {
    flex: 1;
}

.quick-note {
    margin-top: 0.5rem;
    color: #64748b;
    font-size: 0.82rem;
}

.form-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    margin-top: 0.95rem;
}

@media (max-width: 980px) {
    .quick-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 720px) {
    .estoque-hub {
        padding: 1rem;
    }

    .modal-overlay {
        align-items: stretch;
        justify-content: stretch;
    }

    .modal {
        width: 100vw;
        height: 100dvh;
        max-height: 100dvh;
        border-radius: 0;
    }

    .modal-header {
        padding: 0.75rem;
    }

    .modal-body {
        padding: 0.75rem;
    }

    .btn-row button,
    .photo-actions button,
    .camera-capture-actions button,
    .form-actions button,
    .form-actions a {
        flex: 1;
        min-height: 44px;
    }

    .form-input,
    .form-select {
        font-size: 16px;
    }

    .scanner-preview {
        aspect-ratio: 16 / 11;
    }
}
</style>

<div class="estoque-hub">
    <div class="estoque-header">
        <div>
            <h1>Estoque</h1>
            <p>Operações de contagem, entrada, transferência e cadastro rápido de insumos.</p>
        </div>
    </div>

    <?php foreach ($quick_errors as $error): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
    <?php endforeach; ?>

    <?php foreach ($quick_messages as $message): ?>
        <div class="alert alert-success"><?= h($message) ?></div>
    <?php endforeach; ?>

    <div class="estoque-cards">
        <button class="estoque-card estoque-card-btn" type="button" id="open-quick-insumo">
            <span>⚡</span>
            <h3>Cadastro de insumo rápido</h3>
            <p>Ler código de barras (câmera/manual) e cadastrar em poucos campos</p>
        </button>
        <a class="estoque-card" href="index.php?page=logistica_contagem">
            <span>🧮</span>
            <h3>Contagem semanal</h3>
            <p>Modo guiado item a item</p>
        </a>
        <a class="estoque-card" href="index.php?page=logistica_entrada">
            <span>📥</span>
            <h3>Entrada de mercadoria</h3>
            <p>Registrar recebimentos</p>
        </a>
        <a class="estoque-card" href="index.php?page=logistica_transferencias">
            <span>🚚</span>
            <h3>Transferências</h3>
            <p>Garden → unidades</p>
        </a>
        <a class="estoque-card" href="index.php?page=logistica_saldo">
            <span>📊</span>
            <h3>Saldo atual</h3>
            <p>Consulta rápida por unidade</p>
        </a>
    </div>
</div>

<div class="modal-overlay" id="modal-quick-insumo">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title">
                <h3>Cadastro de insumo rápido</h3>
                <p>Etapa 1: leia/digite o código. Etapa 2: preencha os dados e tire a foto do insumo.</p>
            </div>
            <button class="modal-close" type="button" id="close-quick-insumo">X</button>
        </div>
        <div class="modal-body">
            <div class="quick-layout">
                <div class="quick-panel">
                    <h4>1. Código de barras</h4>
                    <p>Use a câmera para ler o código, ou digite manualmente.</p>

                    <div class="btn-row">
                        <button class="btn-primary btn-scanner" type="button" id="start-quick-scanner">Iniciar câmera</button>
                        <button class="btn-secondary" type="button" id="skip-quick-barcode">Continuar sem código</button>
                    </div>

                    <div class="scanner-preview" id="quick-scanner-preview">
                        <video autoplay playsinline muted></video>
                        <div class="scanner-overlay">
                            <div class="scanner-frame">
                                <div class="scanner-line"></div>
                            </div>
                        </div>
                    </div>

                    <div class="scanner-status" id="quick-scanner-status">Clique em "Iniciar câmera" para começar a leitura</div>

                    <div class="scanner-manual">
                        <input class="form-input" type="text" id="quick-barcode-manual" placeholder="Digite o código manualmente..." autocomplete="off">
                        <button class="btn-primary" type="button" id="use-quick-barcode">Usar</button>
                    </div>

                    <div class="quick-note">
                        Não encontrou a tipologia desejada?
                        <a href="index.php?page=logistica_tipologias" target="_blank" rel="noopener">Cadastrar tipologia de insumo</a>
                    </div>
                </div>

                <div class="quick-panel quick-form-panel<?= ($open_quick_modal || $quick_form['barcode'] !== '') ? ' visible' : '' ?>" id="quick-form-panel">
                    <h4>2. Dados mínimos do insumo</h4>
                    <p>Tipologia nesta etapa é sempre do tipo insumo.</p>

                    <?php if (!$can_quick_save): ?>
                        <div class="alert alert-error">
                            Para usar este cadastro rápido, cadastre primeiro:
                            <a href="index.php?page=logistica_tipologias" target="_blank" rel="noopener">tipologia de insumo</a>
                            e
                            <a href="index.php?page=logistica_unidades_medida" target="_blank" rel="noopener">unidade de medida</a>.
                        </div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data" id="quick-insumo-form">
                        <input type="hidden" name="action" value="quick_save_insumo">
                        <input type="hidden" name="barcode" id="quick-barcode" value="<?= h($quick_form['barcode']) ?>">

                        <div class="form-row">
                            <label class="field-label" for="quick-barcode-display">Código de barras</label>
                            <input class="form-input" id="quick-barcode-display" type="text" value="<?= h($quick_form['barcode']) ?>" readonly placeholder="Sem código">
                        </div>

                        <div class="form-row">
                            <label class="field-label" for="quick-nome">Nome do insumo *</label>
                            <input class="form-input" id="quick-nome" name="nome_oficial" required value="<?= h($quick_form['nome_oficial']) ?>" placeholder="Ex.: Leite integral">
                        </div>

                        <div class="form-row">
                            <label class="field-label" for="quick-unidade-medida">Unidade de medida *</label>
                            <select class="form-select" id="quick-unidade-medida" name="unidade_medida_padrao_id" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($unidades_medida as $unidade): ?>
                                    <?php $id = (int)$unidade['id']; ?>
                                    <option value="<?= $id ?>" <?= $quick_form['unidade_medida_padrao_id'] === (string)$id ? 'selected' : '' ?>>
                                        <?= h($unidade['nome']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-row">
                            <label class="field-label" for="quick-unidade-embalagem">Unidade da embalagem *</label>
                            <select class="form-select" id="quick-unidade-embalagem" name="unidade_embalagem" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($unidades_medida as $unidade): ?>
                                    <option value="<?= h($unidade['nome']) ?>" <?= $quick_form['unidade_embalagem'] === $unidade['nome'] ? 'selected' : '' ?>>
                                        <?= h($unidade['nome']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-row">
                            <label class="field-label" for="quick-tipologia">Tipologia (insumo) *</label>
                            <select class="form-select" id="quick-tipologia" name="tipologia_insumo_id" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($tipologias_insumo as $tipologia): ?>
                                    <?php $id = (int)$tipologia['id']; ?>
                                    <option value="<?= $id ?>" <?= $quick_form['tipologia_insumo_id'] === (string)$id ? 'selected' : '' ?>>
                                        <?= h($tipologia['nome']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-hint">Tipologias são gerenciadas em Catálogo &gt; Tipologias.</div>
                        </div>

                        <div class="form-row">
                            <label class="field-label">Foto do insumo (opcional)</label>
                            <div class="photo-actions">
                                <button class="btn-secondary" type="button" id="quick-photo-camera-btn">Tirar foto</button>
                                <button class="btn-secondary" type="button" id="quick-photo-gallery-btn">Escolher da galeria</button>
                            </div>
                            <input type="file" id="quick-photo-file" name="quick_foto_file" accept="image/*" style="display:none;">
                            <div class="camera-capture-box" id="quick-camera-capture-box">
                                <div class="camera-live-preview">
                                    <video id="quick-camera-live" autoplay playsinline muted></video>
                                </div>
                                <div class="camera-capture-actions">
                                    <button class="btn-primary" type="button" id="quick-camera-shot">Capturar foto</button>
                                    <button class="btn-secondary" type="button" id="quick-camera-cancel">Cancelar câmera</button>
                                </div>
                            </div>
                            <canvas id="quick-camera-canvas" style="display:none;"></canvas>
                            <div class="photo-preview" id="quick-photo-preview">
                                <span class="photo-placeholder">Nenhuma foto selecionada.</span>
                            </div>
                            <div class="form-hint" id="quick-photo-hint">No celular, use “Tirar foto” para abrir a câmera traseira.</div>
                        </div>

                        <div class="form-actions">
                            <button class="btn-primary" type="submit" <?= $can_quick_save ? '' : 'disabled' ?>>Salvar insumo</button>
                            <a class="btn-secondary" href="index.php?page=logistica_insumos" target="_blank" rel="noopener">Cadastro completo</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@ericblade/quagga2@1.8.4/dist/quagga.min.js"></script>
<script>
let quickScannerActive = false;
let quickScannerHandler = null;
let quickLastScanned = '';
let quickPhotoStream = null;
const quickPhotoHintDefault = 'No celular, use “Tirar foto” para abrir a câmera traseira.';

function setQuickScannerStatus(message, type = '') {
    const statusEl = document.getElementById('quick-scanner-status');
    if (!statusEl) return;
    statusEl.className = type ? `scanner-status ${type}` : 'scanner-status';
    statusEl.textContent = message;
}

function setQuickBarcode(code) {
    const normalized = (code || '').trim();
    const hiddenInput = document.getElementById('quick-barcode');
    const displayInput = document.getElementById('quick-barcode-display');

    if (hiddenInput) hiddenInput.value = normalized;
    if (displayInput) displayInput.value = normalized;

    const panel = document.getElementById('quick-form-panel');
    if (panel) panel.classList.add('visible');

    const nameInput = document.getElementById('quick-nome');
    if (nameInput) nameInput.focus();
}

function renderQuickPhotoPreview(file) {
    const preview = document.getElementById('quick-photo-preview');
    if (!preview) return;

    if (!file) {
        preview.innerHTML = '<span class="photo-placeholder">Nenhuma foto selecionada.</span>';
        return;
    }

    const reader = new FileReader();
    reader.onload = () => {
        preview.innerHTML = '<img src="' + reader.result + '" alt="Prévia da foto do insumo">';
    };
    reader.readAsDataURL(file);
}

function setQuickPhotoHint(message = '') {
    const hintEl = document.getElementById('quick-photo-hint');
    if (!hintEl) return;
    hintEl.textContent = message || quickPhotoHintDefault;
}

function stopQuickPhotoCamera() {
    if (quickPhotoStream) {
        quickPhotoStream.getTracks().forEach((track) => track.stop());
        quickPhotoStream = null;
    }

    const cameraBox = document.getElementById('quick-camera-capture-box');
    const liveVideo = document.getElementById('quick-camera-live');
    if (liveVideo) {
        liveVideo.srcObject = null;
    }
    if (cameraBox) {
        cameraBox.classList.remove('active');
    }
}

function openNativePhotoPicker(mode = 'gallery') {
    const input = document.getElementById('quick-photo-file');
    if (!input) return;
    if (mode === 'camera') {
        input.setAttribute('capture', 'environment');
    } else {
        input.removeAttribute('capture');
    }
    input.click();
}

async function openQuickPhotoCamera() {
    stopQuickPhotoCamera();

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        setQuickPhotoHint('Câmera direta indisponível neste navegador. Use o anexo para tirar/escolher a foto.');
        openNativePhotoPicker('camera');
        return;
    }

    try {
        const stream = await navigator.mediaDevices.getUserMedia({
            video: {
                facingMode: { ideal: 'environment' }
            },
            audio: false
        });

        const cameraBox = document.getElementById('quick-camera-capture-box');
        const liveVideo = document.getElementById('quick-camera-live');
        if (!cameraBox || !liveVideo) {
            stream.getTracks().forEach((track) => track.stop());
            return;
        }

        quickPhotoStream = stream;
        liveVideo.srcObject = stream;
        cameraBox.classList.add('active');
        setQuickPhotoHint('Câmera aberta. Clique em “Capturar foto”.');
    } catch (err) {
        console.error('Erro ao abrir câmera para foto:', err);
        setQuickPhotoHint('Não foi possível abrir a câmera diretamente. Use o anexo para tirar/escolher a foto.');
        openNativePhotoPicker('camera');
    }
}

function captureQuickPhotoFromCamera() {
    const liveVideo = document.getElementById('quick-camera-live');
    const canvas = document.getElementById('quick-camera-canvas');
    const input = document.getElementById('quick-photo-file');

    if (!quickPhotoStream || !liveVideo || !canvas || !input) {
        setQuickPhotoHint('Câmera não está ativa no momento.');
        return;
    }

    const width = liveVideo.videoWidth;
    const height = liveVideo.videoHeight;
    if (!width || !height) {
        setQuickPhotoHint('Aguardando imagem da câmera. Tente novamente em 1 segundo.');
        return;
    }

    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    if (!ctx) {
        setQuickPhotoHint('Falha ao processar a imagem da câmera.');
        return;
    }

    ctx.drawImage(liveVideo, 0, 0, width, height);
    canvas.toBlob((blob) => {
        if (!blob) {
            setQuickPhotoHint('Não foi possível capturar a foto.');
            return;
        }

        const file = new File([blob], `insumo-${Date.now()}.jpg`, { type: 'image/jpeg' });

        if (typeof DataTransfer !== 'undefined') {
            const dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
            renderQuickPhotoPreview(file);
            setQuickPhotoHint('Foto capturada com sucesso.');
            stopQuickPhotoCamera();
        } else {
            setQuickPhotoHint('Seu navegador não suporta anexar direto da câmera. Use o anexo para selecionar a foto.');
            stopQuickPhotoCamera();
            openNativePhotoPicker('camera');
        }
    }, 'image/jpeg', 0.92);
}

function handleQuickBarcodeResult(code) {
    const normalized = (code || '').trim();
    if (!normalized) return;
    if (normalized === quickLastScanned) return;
    quickLastScanned = normalized;

    setQuickBarcode(normalized);
    setQuickScannerStatus(`Código lido: ${normalized}`, 'success');
    stopQuickScanner();
}

function startQuickScanner() {
    if (typeof Quagga === 'undefined') {
        setQuickScannerStatus('Scanner indisponível neste navegador. Use o campo manual.', 'error');
        return;
    }

    const previewEl = document.getElementById('quick-scanner-preview');
    if (!previewEl) return;

    setQuickScannerStatus('Iniciando câmera...', 'scanning');

    Quagga.init({
        inputStream: {
            name: 'Live',
            type: 'LiveStream',
            target: previewEl,
            constraints: {
                facingMode: 'environment',
                width: { ideal: 1280 },
                height: { ideal: 720 }
            }
        },
        decoder: {
            readers: [
                'ean_reader',
                'ean_8_reader',
                'code_128_reader',
                'code_39_reader',
                'upc_reader',
                'upc_e_reader'
            ]
        },
        locate: true,
        locator: {
            patchSize: 'medium',
            halfSample: true
        }
    }, function(err) {
        if (err) {
            console.error('Erro ao iniciar scanner rápido:', err);
            setQuickScannerStatus('Erro ao acessar câmera. Use o campo manual.', 'error');
            return;
        }

        quickScannerActive = true;
        Quagga.start();
        setQuickScannerStatus('Posicione o código de barras na área destacada');
    });

    if (quickScannerHandler) {
        Quagga.offDetected(quickScannerHandler);
    }

    quickScannerHandler = function(result) {
        const code = result?.codeResult?.code || '';
        if (code) {
            handleQuickBarcodeResult(code);
        }
    };

    Quagga.onDetected(quickScannerHandler);
}

function stopQuickScanner() {
    if (typeof Quagga !== 'undefined' && quickScannerHandler) {
        Quagga.offDetected(quickScannerHandler);
        quickScannerHandler = null;
    }

    if (quickScannerActive && typeof Quagga !== 'undefined') {
        try {
            Quagga.stop();
        } catch (err) {
            console.error('Falha ao parar scanner rápido:', err);
        }
        quickScannerActive = false;
    }

    quickLastScanned = '';
}

function openQuickModal() {
    const modal = document.getElementById('modal-quick-insumo');
    if (!modal) return;
    modal.style.display = 'flex';

    const manualInput = document.getElementById('quick-barcode-manual');
    if (manualInput) {
        manualInput.value = '';
    }

    const photoInput = document.getElementById('quick-photo-file');
    if (photoInput) {
        photoInput.value = '';
    }
    stopQuickPhotoCamera();
    renderQuickPhotoPreview(null);
    setQuickPhotoHint();

    const panel = document.getElementById('quick-form-panel');
    const hasServerPrefill = <?= ($open_quick_modal || $quick_form['barcode'] !== '' || $quick_form['nome_oficial'] !== '' || $quick_form['unidade_medida_padrao_id'] !== '' || $quick_form['unidade_embalagem'] !== '' || $quick_form['tipologia_insumo_id'] !== '') ? 'true' : 'false' ?>;

    if (!hasServerPrefill && panel) {
        panel.classList.remove('visible');
        const hiddenInput = document.getElementById('quick-barcode');
        const displayInput = document.getElementById('quick-barcode-display');
        if (hiddenInput) hiddenInput.value = '';
        if (displayInput) displayInput.value = '';
    }

    setQuickScannerStatus('Iniciando câmera...', 'scanning');
    stopQuickScanner();
    startQuickScanner();
}

function closeQuickModal() {
    stopQuickScanner();
    stopQuickPhotoCamera();
    const modal = document.getElementById('modal-quick-insumo');
    if (modal) {
        modal.style.display = 'none';
    }
}

document.getElementById('open-quick-insumo')?.addEventListener('click', openQuickModal);
document.getElementById('close-quick-insumo')?.addEventListener('click', closeQuickModal);
document.getElementById('modal-quick-insumo')?.addEventListener('click', (event) => {
    if (event.target.id === 'modal-quick-insumo') {
        closeQuickModal();
    }
});

document.getElementById('start-quick-scanner')?.addEventListener('click', () => {
    stopQuickScanner();
    startQuickScanner();
});

document.getElementById('use-quick-barcode')?.addEventListener('click', () => {
    const manualInput = document.getElementById('quick-barcode-manual');
    const code = manualInput ? manualInput.value.trim() : '';
    if (!code) {
        setQuickScannerStatus('Digite um código para continuar.', 'error');
        return;
    }
    handleQuickBarcodeResult(code);
});

document.getElementById('quick-photo-camera-btn')?.addEventListener('click', () => {
    openQuickPhotoCamera();
});

document.getElementById('quick-photo-gallery-btn')?.addEventListener('click', () => {
    stopQuickPhotoCamera();
    openNativePhotoPicker('gallery');
});

document.getElementById('quick-camera-shot')?.addEventListener('click', () => {
    captureQuickPhotoFromCamera();
});

document.getElementById('quick-camera-cancel')?.addEventListener('click', () => {
    stopQuickPhotoCamera();
    setQuickPhotoHint();
});

document.getElementById('quick-photo-file')?.addEventListener('change', (event) => {
    const file = event.target.files && event.target.files[0] ? event.target.files[0] : null;
    stopQuickPhotoCamera();
    renderQuickPhotoPreview(file);
    if (file) {
        setQuickPhotoHint('Foto selecionada e pronta para envio.');
    } else {
        setQuickPhotoHint();
    }
});

document.getElementById('quick-barcode-manual')?.addEventListener('keypress', (event) => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    const code = event.target.value.trim();
    if (!code) {
        setQuickScannerStatus('Digite um código para continuar.', 'error');
        return;
    }
    handleQuickBarcodeResult(code);
});

document.getElementById('skip-quick-barcode')?.addEventListener('click', () => {
    stopQuickScanner();
    setQuickBarcode('');
    setQuickScannerStatus('Continuando sem código de barras.', 'scanning');
});

window.addEventListener('beforeunload', () => {
    try {
        stopQuickScanner();
        stopQuickPhotoCamera();
    } catch (e) {
        // no-op
    }
});

const shouldOpenQuickModal = <?= $open_quick_modal ? 'true' : 'false' ?>;
if (shouldOpenQuickModal) {
    openQuickModal();
}
</script>

<?php
$conteudo = ob_get_clean();
includeSidebar('Estoque - Logística');
echo $conteudo;
endSidebar();
?>
