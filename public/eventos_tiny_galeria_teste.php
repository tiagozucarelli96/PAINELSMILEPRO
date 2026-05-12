<?php
/**
 * Página isolada para testar inserção de imagens da galeria interna no TinyMCE.
 * Fluxo: selecionar item da galeria -> copiar para storage de teste -> inserir no editor.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/upload_magalu.php';
require_once __DIR__ . '/eventos_galeria_helper.php';

$canEventos = !empty($_SESSION['perm_eventos']);
$canComercial = !empty($_SESSION['perm_comercial']);
$isSuperadmin = !empty($_SESSION['perm_superadmin']);
$hasAccess = $canEventos || $canComercial || $isSuperadmin;

if (!$hasAccess) {
    header('Location: index.php?page=dashboard');
    exit;
}

function tinyGaleriaTesteJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tinyGaleriaTesteBuildSourceUrl(array $row): string
{
    $publicUrl = trim((string)($row['public_url'] ?? ''));
    if ($publicUrl !== '') {
        return $publicUrl;
    }

    $storageKey = trim((string)($row['storage_key'] ?? ''));
    return (string)(eventosGaleriaStoragePublicUrl($storageKey) ?? '');
}

function tinyGaleriaTesteImageUrl(array $row): string
{
    $thumbPublicUrl = trim((string)($row['thumb_public_url'] ?? ''));
    if ($thumbPublicUrl !== '') {
        return $thumbPublicUrl;
    }

    return tinyGaleriaTesteBuildSourceUrl($row);
}

function tinyGaleriaTesteSlug(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'imagem-galeria';
    }

    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: '';
    $value = trim($value, '-');

    return $value !== '' ? $value : 'imagem-galeria';
}

function tinyGaleriaTesteExtensionFromMime(string $mimeType): string
{
    $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
    ];

    return $map[$mimeType] ?? 'jpg';
}

$isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

if ($isAjax && $action === 'import_gallery_image') {
    $imageId = (int)($_POST['image_id'] ?? 0);
    if ($imageId <= 0) {
        tinyGaleriaTesteJson(['ok' => false, 'message' => 'Imagem inválida.'], 400);
    }

    $thumbSelect = eventosGaleriaThumbColumns($pdo)['thumb_public_url']
        ? ', thumb_public_url'
        : ", NULL::text AS thumb_public_url";

    $stmt = $pdo->prepare("
        SELECT id, nome, public_url, storage_key{$thumbSelect}
        FROM eventos_galeria
        WHERE id = :id
          AND deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([':id' => $imageId]);
    $image = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$image) {
        tinyGaleriaTesteJson(['ok' => false, 'message' => 'Imagem da galeria não encontrada.'], 404);
    }

    $sourceUrl = tinyGaleriaTesteBuildSourceUrl($image);
    if ($sourceUrl === '') {
        tinyGaleriaTesteJson(['ok' => false, 'message' => 'A imagem selecionada não possui origem disponível.'], 422);
    }

    $tmpPath = '';
    try {
        $tmpPath = eventosGaleriaDownloadSourceToTemp($sourceUrl);
        $mimeType = eventosGaleriaDetectMimeLocal($tmpPath);
        if (strpos($mimeType, 'image/') !== 0) {
            throw new RuntimeException('O arquivo selecionado não é uma imagem válida.');
        }

        $filename = tinyGaleriaTesteSlug((string)($image['nome'] ?? 'imagem-galeria')) . '.' . tinyGaleriaTesteExtensionFromMime($mimeType);
        $uploader = new MagaluUpload();
        $upload = $uploader->uploadFromPath($tmpPath, 'eventos/tiny-galeria-teste', $filename, $mimeType);
        $location = trim((string)($upload['url'] ?? ''));
        if ($location === '') {
            throw new RuntimeException('Upload concluído sem URL pública de retorno.');
        }

        tinyGaleriaTesteJson([
            'ok' => true,
            'location' => $location,
            'name' => (string)($image['nome'] ?? ''),
            'message' => 'Imagem copiada para o storage de teste e pronta para inserção.'
        ]);
    } catch (Throwable $e) {
        tinyGaleriaTesteJson([
            'ok' => false,
            'message' => $e->getMessage(),
        ], 500);
    } finally {
        if ($tmpPath !== '' && file_exists($tmpPath)) {
            @unlink($tmpPath);
        }
    }
}

$thumbSelect = eventosGaleriaThumbColumns($pdo)['thumb_public_url']
    ? ', thumb_public_url'
    : ", NULL::text AS thumb_public_url";

$stmt = $pdo->query("
    SELECT id, categoria, nome, descricao, tags, public_url, storage_key{$thumbSelect}
    FROM eventos_galeria
    WHERE deleted_at IS NULL
    ORDER BY uploaded_at DESC
    LIMIT 96
");
$galleryItems = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $previewUrl = tinyGaleriaTesteImageUrl($row);
    $sourceUrl = tinyGaleriaTesteBuildSourceUrl($row);
    if ($previewUrl === '' || $sourceUrl === '') {
        continue;
    }

    $galleryItems[] = [
        'id' => (int)$row['id'],
        'categoria' => (string)($row['categoria'] ?? ''),
        'nome' => (string)($row['nome'] ?? ''),
        'descricao' => (string)($row['descricao'] ?? ''),
        'tags' => (string)($row['tags'] ?? ''),
        'preview_url' => $previewUrl,
        'source_url' => $sourceUrl,
    ];
}

includeSidebar('Teste Tiny + Galeria');
?>
<style>
    .tiny-galeria-page {
        padding: 24px;
        max-width: 1520px;
        margin: 0 auto;
        display: flex;
        flex-direction: column;
        gap: 18px;
    }

    .tiny-galeria-hero,
    .tiny-galeria-card {
        background: #fff;
        border: 1px solid #dbe3ef;
        border-radius: 18px;
        box-shadow: 0 16px 40px rgba(15, 23, 42, 0.05);
    }

    .tiny-galeria-hero {
        padding: 22px 24px;
        background:
            radial-gradient(420px 180px at 100% 0%, rgba(16, 185, 129, 0.12), transparent 60%),
            linear-gradient(135deg, #f8fbff 0%, #f5fff9 100%);
    }

    .tiny-galeria-hero h1 {
        margin: 0 0 8px;
        font-size: 1.8rem;
        color: #112b57;
    }

    .tiny-galeria-hero p {
        margin: 0;
        max-width: 980px;
        color: #52627a;
        line-height: 1.55;
    }

    .tiny-galeria-card {
        padding: 18px;
    }

    .tiny-galeria-toolbar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 14px;
    }

    .tiny-galeria-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .tiny-galeria-btn {
        border: 0;
        border-radius: 12px;
        padding: 10px 14px;
        font-weight: 700;
        cursor: pointer;
        transition: transform 0.18s ease, box-shadow 0.18s ease, opacity 0.18s ease;
    }

    .tiny-galeria-btn:hover {
        transform: translateY(-1px);
    }

    .tiny-galeria-btn-primary {
        background: #123b84;
        color: #fff;
        box-shadow: 0 12px 24px rgba(18, 59, 132, 0.18);
    }

    .tiny-galeria-btn-secondary {
        background: #edf4ff;
        color: #123b84;
    }

    .tiny-galeria-status {
        min-height: 22px;
        font-size: 0.95rem;
        color: #52627a;
    }

    .tiny-galeria-status.is-error {
        color: #b42318;
    }

    .tiny-galeria-status.is-success {
        color: #067647;
    }

    .tiny-galeria-editor-shell {
        border: 1px solid #dbe3ef;
        border-radius: 16px;
        overflow: hidden;
    }

    .tiny-galeria-meta {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
        margin-top: 12px;
        color: #52627a;
        font-size: 0.92rem;
    }

    .tiny-galeria-modal {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(4px);
        display: none;
        align-items: center;
        justify-content: center;
        padding: 24px;
        z-index: 3000;
    }

    .tiny-galeria-modal.is-open {
        display: flex;
    }

    .tiny-galeria-modal-panel {
        width: min(1240px, 100%);
        max-height: 90vh;
        background: #fff;
        border-radius: 22px;
        border: 1px solid #dbe3ef;
        box-shadow: 0 28px 70px rgba(15, 23, 42, 0.28);
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }

    .tiny-galeria-modal-header {
        padding: 18px 20px;
        border-bottom: 1px solid #e5edf7;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }

    .tiny-galeria-modal-header h2 {
        margin: 0;
        font-size: 1.2rem;
        color: #102a56;
    }

    .tiny-galeria-modal-close {
        border: 0;
        background: #eef4ff;
        color: #123b84;
        width: 40px;
        height: 40px;
        border-radius: 999px;
        font-size: 1.4rem;
        cursor: pointer;
    }

    .tiny-galeria-filters {
        padding: 16px 20px;
        border-bottom: 1px solid #e5edf7;
        display: grid;
        grid-template-columns: minmax(0, 1.5fr) minmax(180px, 220px);
        gap: 12px;
    }

    .tiny-galeria-input,
    .tiny-galeria-select {
        width: 100%;
        border: 1px solid #cbd5e1;
        border-radius: 12px;
        padding: 11px 13px;
        font-size: 0.95rem;
        outline: none;
        transition: border-color 0.18s ease, box-shadow 0.18s ease;
    }

    .tiny-galeria-input:focus,
    .tiny-galeria-select:focus {
        border-color: #2f6fec;
        box-shadow: 0 0 0 4px rgba(47, 111, 236, 0.12);
    }

    .tiny-galeria-grid {
        padding: 20px;
        overflow: auto;
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 16px;
    }

    .tiny-galeria-item {
        border: 1px solid #dde6f1;
        border-radius: 16px;
        overflow: hidden;
        background: #fff;
        display: flex;
        flex-direction: column;
        min-height: 280px;
        transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
    }

    .tiny-galeria-item:hover {
        transform: translateY(-2px);
        box-shadow: 0 18px 30px rgba(15, 23, 42, 0.09);
        border-color: #b7cae6;
    }

    .tiny-galeria-item[hidden] {
        display: none;
    }

    .tiny-galeria-thumb {
        aspect-ratio: 4 / 3;
        background: #f5f9ff;
    }

    .tiny-galeria-thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .tiny-galeria-item-body {
        padding: 12px;
        display: flex;
        flex-direction: column;
        gap: 8px;
        flex: 1;
    }

    .tiny-galeria-badge {
        align-self: flex-start;
        background: #edf4ff;
        color: #123b84;
        border-radius: 999px;
        padding: 5px 9px;
        font-size: 0.74rem;
        font-weight: 800;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .tiny-galeria-item-title {
        margin: 0;
        color: #142c58;
        font-size: 0.97rem;
    }

    .tiny-galeria-item-text {
        margin: 0;
        color: #66758d;
        font-size: 0.88rem;
        line-height: 1.45;
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .tiny-galeria-item-action {
        margin-top: auto;
    }

    .tiny-galeria-empty {
        padding: 24px 20px 30px;
        text-align: center;
        color: #66758d;
        display: none;
    }

    .tiny-galeria-empty.is-visible {
        display: block;
    }

    @media (max-width: 900px) {
        .tiny-galeria-page {
            padding: 16px;
        }

        .tiny-galeria-filters {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="tiny-galeria-page">
    <section class="tiny-galeria-hero">
        <h1>Teste isolado: Tiny com Galeria interna</h1>
        <p>
            Esta página existe só para validar o fluxo novo sem tocar na Reunião Final atual.
            Escolha uma imagem da galeria interna, copie para um storage próprio de teste e insira no Tiny automaticamente.
        </p>
    </section>

    <section class="tiny-galeria-card">
        <div class="tiny-galeria-toolbar">
            <div>
                <strong>Editor de teste</strong><br>
                <span style="color:#66758d;">Use o botão da galeria no topo do editor ou o botão abaixo.</span>
            </div>
            <div class="tiny-galeria-actions">
                <button type="button" class="tiny-galeria-btn tiny-galeria-btn-secondary" id="openGalleryOutside">
                    Inserir da Galeria
                </button>
            </div>
        </div>

        <div id="tinyGaleriaStatus" class="tiny-galeria-status"></div>

        <div class="tiny-galeria-editor-shell">
            <textarea id="tinyGaleriaTesteEditor"></textarea>
        </div>

        <div class="tiny-galeria-meta">
            <span>Total de imagens carregadas para teste: <strong><?= (int)count($galleryItems) ?></strong></span>
            <span>Destino do upload de teste: <code>eventos/tiny-galeria-teste</code></span>
        </div>
    </section>
</div>

<div class="tiny-galeria-modal" id="tinyGaleriaModal" aria-hidden="true">
    <div class="tiny-galeria-modal-panel" role="dialog" aria-modal="true" aria-labelledby="tinyGaleriaModalTitle">
        <div class="tiny-galeria-modal-header">
            <h2 id="tinyGaleriaModalTitle">Selecionar imagem da galeria</h2>
            <button type="button" class="tiny-galeria-modal-close" id="closeTinyGaleriaModal" aria-label="Fechar">×</button>
        </div>

        <div class="tiny-galeria-filters">
            <input
                type="search"
                id="tinyGaleriaSearch"
                class="tiny-galeria-input"
                placeholder="Buscar por nome, descrição ou tags"
            >
            <select id="tinyGaleriaCategory" class="tiny-galeria-select">
                <option value="">Todas as categorias</option>
                <option value="casamento">Casamento</option>
                <option value="15_anos">15 anos</option>
                <option value="infantil">Infantil</option>
                <option value="geral">Geral</option>
            </select>
        </div>

        <div class="tiny-galeria-grid" id="tinyGaleriaGrid">
            <?php foreach ($galleryItems as $item): ?>
                <?php
                $searchBlob = mb_strtolower(trim($item['nome'] . ' ' . $item['descricao'] . ' ' . $item['tags']));
                ?>
                <article
                    class="tiny-galeria-item"
                    data-id="<?= (int)$item['id'] ?>"
                    data-category="<?= htmlspecialchars($item['categoria'], ENT_QUOTES, 'UTF-8') ?>"
                    data-search="<?= htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8') ?>"
                >
                    <div class="tiny-galeria-thumb">
                        <img
                            src="<?= htmlspecialchars($item['preview_url'], ENT_QUOTES, 'UTF-8') ?>"
                            alt="<?= htmlspecialchars($item['nome'], ENT_QUOTES, 'UTF-8') ?>"
                            loading="lazy"
                        >
                    </div>
                    <div class="tiny-galeria-item-body">
                        <span class="tiny-galeria-badge"><?= htmlspecialchars(str_replace('_', ' ', $item['categoria']), ENT_QUOTES, 'UTF-8') ?></span>
                        <h3 class="tiny-galeria-item-title"><?= htmlspecialchars($item['nome'], ENT_QUOTES, 'UTF-8') ?></h3>
                        <p class="tiny-galeria-item-text"><?= htmlspecialchars($item['descricao'] !== '' ? $item['descricao'] : $item['tags'], ENT_QUOTES, 'UTF-8') ?></p>
                        <div class="tiny-galeria-item-action">
                            <button
                                type="button"
                                class="tiny-galeria-btn tiny-galeria-btn-primary js-import-gallery-image"
                                data-id="<?= (int)$item['id'] ?>"
                                data-name="<?= htmlspecialchars($item['nome'], ENT_QUOTES, 'UTF-8') ?>"
                            >
                                Usar esta imagem
                            </button>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="tiny-galeria-empty" id="tinyGaleriaEmpty">
            Nenhuma imagem corresponde aos filtros atuais.
        </div>
    </div>
</div>

<script>
(() => {
    const pageUrl = 'index.php?page=eventos_tiny_galeria_teste';
    const statusEl = document.getElementById('tinyGaleriaStatus');
    const modalEl = document.getElementById('tinyGaleriaModal');
    const gridEl = document.getElementById('tinyGaleriaGrid');
    const searchEl = document.getElementById('tinyGaleriaSearch');
    const categoryEl = document.getElementById('tinyGaleriaCategory');
    const emptyEl = document.getElementById('tinyGaleriaEmpty');
    const openOutsideBtn = document.getElementById('openGalleryOutside');
    const closeModalBtn = document.getElementById('closeTinyGaleriaModal');
    let activeEditor = null;

    function setStatus(message, type = '') {
        statusEl.textContent = message || '';
        statusEl.classList.remove('is-error', 'is-success');
        if (type === 'error') statusEl.classList.add('is-error');
        if (type === 'success') statusEl.classList.add('is-success');
    }

    function openModal() {
        modalEl.classList.add('is-open');
        modalEl.setAttribute('aria-hidden', 'false');
        setTimeout(() => searchEl.focus(), 50);
    }

    function closeModal() {
        modalEl.classList.remove('is-open');
        modalEl.setAttribute('aria-hidden', 'true');
    }

    function normalize(value) {
        return String(value || '').toLocaleLowerCase('pt-BR').trim();
    }

    function applyFilters() {
        const term = normalize(searchEl.value);
        const category = normalize(categoryEl.value);
        const items = Array.from(gridEl.querySelectorAll('.tiny-galeria-item'));
        let visibleCount = 0;

        items.forEach((item) => {
            const itemCategory = normalize(item.getAttribute('data-category'));
            const itemSearch = normalize(item.getAttribute('data-search'));
            const matchesTerm = term === '' || itemSearch.includes(term);
            const matchesCategory = category === '' || itemCategory === category;
            const visible = matchesTerm && matchesCategory;
            item.hidden = !visible;
            if (visible) visibleCount += 1;
        });

        emptyEl.classList.toggle('is-visible', visibleCount === 0);
    }

    async function importGalleryImage(imageId, imageName, button) {
        if (!activeEditor) {
            setStatus('Editor Tiny ainda não está pronto.', 'error');
            return;
        }

        const originalLabel = button.textContent;
        button.disabled = true;
        button.textContent = 'Importando...';
        setStatus('Copiando a imagem da galeria para o storage de teste...');

        try {
            const body = new URLSearchParams();
            body.set('action', 'import_gallery_image');
            body.set('image_id', String(imageId));

            const response = await fetch(pageUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString()
            });

            const data = await response.json();
            if (!response.ok || !data.ok || !data.location) {
                throw new Error(data.message || 'Falha ao importar imagem da galeria.');
            }

            const safeAlt = String(imageName || '').replace(/"/g, '&quot;');
            activeEditor.insertContent(
                '<p><img src="' + data.location + '" alt="' + safeAlt + '" style="max-width:100%;height:auto;border-radius:8px;" /></p>'
            );

            setStatus(data.message || 'Imagem inserida com sucesso.', 'success');
            closeModal();
        } catch (error) {
            setStatus(error instanceof Error ? error.message : 'Erro ao importar imagem.', 'error');
        } finally {
            button.disabled = false;
            button.textContent = originalLabel;
        }
    }

    function bindGalleryButtons() {
        document.querySelectorAll('.js-import-gallery-image').forEach((button) => {
            button.addEventListener('click', () => {
                importGalleryImage(button.getAttribute('data-id'), button.getAttribute('data-name'), button);
            });
        });
    }

    function loadTiny() {
        return new Promise((resolve, reject) => {
            if (typeof window.tinymce !== 'undefined') {
                resolve(window.tinymce);
                return;
            }

            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js';
            script.referrerPolicy = 'origin';
            script.onload = () => resolve(window.tinymce);
            script.onerror = () => reject(new Error('Não foi possível carregar o TinyMCE.'));
            document.head.appendChild(script);
        });
    }

    searchEl.addEventListener('input', applyFilters);
    categoryEl.addEventListener('change', applyFilters);
    openOutsideBtn.addEventListener('click', openModal);
    closeModalBtn.addEventListener('click', closeModal);
    modalEl.addEventListener('click', (event) => {
        if (event.target === modalEl) closeModal();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modalEl.classList.contains('is-open')) {
            closeModal();
        }
    });

    bindGalleryButtons();
    applyFilters();

    loadTiny()
        .then((tinymce) => {
            tinymce.init({
                selector: '#tinyGaleriaTesteEditor',
                plugins: 'lists link image table code',
                toolbar: 'undo redo | bold italic underline | alignleft aligncenter alignright | bullist numlist | link image table | galeriaSmile | removeformat code',
                menubar: false,
                height: 540,
                content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; font-size: 14px; line-height: 1.55; }',
                setup: (editor) => {
                    activeEditor = editor;
                    editor.ui.registry.addButton('galeriaSmile', {
                        text: 'Galeria Smile',
                        tooltip: 'Inserir imagem da galeria interna',
                        onAction: () => openModal()
                    });
                    editor.on('init', () => {
                        editor.setContent('<p>Teste aqui a inserção de imagens da galeria interna.</p>');
                        setStatus('Editor pronto para teste.');
                    });
                }
            });
        })
        .catch((error) => {
            setStatus(error instanceof Error ? error.message : 'Erro ao carregar editor.', 'error');
        });
})();
</script>
