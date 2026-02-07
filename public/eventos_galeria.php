<?php
/**
 * eventos_galeria.php
 * Gestão interna da galeria de imagens de Eventos.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/upload_magalu.php';

if (empty($_SESSION['perm_eventos']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

$user_id = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? 0);
$action = $_POST['action'] ?? '';
$categoria_filter = $_GET['categoria'] ?? '';
$search = trim((string)($_GET['search'] ?? ''));

$error = '';
$success = '';

$categorias = [
    'infantil' => ['icon' => '🎈', 'label' => 'Infantil'],
    'casamento' => ['icon' => '💒', 'label' => 'Casamento'],
    '15_anos' => ['icon' => '👑', 'label' => '15 Anos'],
    'geral' => ['icon' => '🎉', 'label' => 'Geral']
];
$categorias_filtro = [
    'infantil' => $categorias['infantil'],
    'casamento' => $categorias['casamento'],
    '15_anos' => $categorias['15_anos']
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload') {
    $categoria = $_POST['categoria'] ?? '';
    $nome = trim((string)($_POST['nome'] ?? ''));
    $descricao = trim((string)($_POST['descricao'] ?? ''));
    $tags = trim((string)($_POST['tags'] ?? ''));

    if (!isset($categorias[$categoria])) {
        $error = 'Categoria inválida.';
    } else {
        $uploaded_files = [];
        if (!empty($_FILES['imagens'])) {
            $files = $_FILES['imagens'];
            $total = is_array($files['name']) ? count($files['name']) : 0;
            for ($i = 0; $i < $total; $i++) {
                $uploaded_files[] = [
                    'name' => $files['name'][$i] ?? '',
                    'type' => $files['type'][$i] ?? '',
                    'tmp_name' => $files['tmp_name'][$i] ?? '',
                    'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $files['size'][$i] ?? 0
                ];
            }
        } elseif (!empty($_FILES['imagem'])) {
            $uploaded_files[] = $_FILES['imagem'];
        }

        if (empty($uploaded_files)) {
            $error = 'Selecione pelo menos uma imagem.';
        } else {
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $success_count = 0;
            $fail_messages = [];

            foreach ($uploaded_files as $file) {
                if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $fail_messages[] = 'Falha ao ler uma das imagens selecionadas.';
                    continue;
                }

                if (!in_array((string)$file['type'], $allowed, true)) {
                    $fail_messages[] = 'Tipo de arquivo não permitido. Use JPG, PNG, GIF ou WEBP.';
                    continue;
                }

                if ((int)$file['size'] > 10 * 1024 * 1024) {
                    $fail_messages[] = 'Arquivo muito grande. Máximo 10MB.';
                    continue;
                }

                $file_base = trim((string)pathinfo((string)($file['name'] ?? ''), PATHINFO_FILENAME));
                $file_base = preg_replace('/[_-]+/', ' ', $file_base);
                $file_base = preg_replace('/\s+/', ' ', (string)$file_base);
                $nome_final = $nome !== '' ? $nome : $file_base;
                if (count($uploaded_files) > 1 && $nome !== '' && $file_base !== '') {
                    $nome_final = $nome . ' - ' . $file_base;
                }

                if ($nome_final === '') {
                    $fail_messages[] = 'Não foi possível identificar o nome de uma imagem.';
                    continue;
                }

                try {
                    $uploader = new MagaluUpload();
                    $result = $uploader->upload($file, 'galeria_eventos');

                    if (empty($result['chave_storage'])) {
                        $fail_messages[] = 'Erro ao fazer upload para o storage.';
                        continue;
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO eventos_galeria
                            (categoria, nome, descricao, tags, storage_key, public_url, mime_type, size_bytes, uploaded_by, uploaded_at)
                        VALUES
                            (:categoria, :nome, :descricao, :tags, :storage_key, :public_url, :mime_type, :size_bytes, :uploaded_by, NOW())
                    ");
                    $stmt->execute([
                        ':categoria' => $categoria,
                        ':nome' => $nome_final,
                        ':descricao' => $descricao !== '' ? $descricao : null,
                        ':tags' => $tags !== '' ? $tags : null,
                        ':storage_key' => $result['chave_storage'],
                        ':public_url' => $result['url'] ?? null,
                        ':mime_type' => $result['mime_type'] ?? ($file['type'] ?? null),
                        ':size_bytes' => $result['tamanho_bytes'] ?? ($file['size'] ?? null),
                        ':uploaded_by' => $user_id > 0 ? $user_id : null
                    ]);
                    $success_count++;
                } catch (Throwable $e) {
                    $fail_messages[] = 'Erro ao fazer upload: ' . $e->getMessage();
                }
            }

            if ($success_count > 0) {
                $success = $success_count === 1
                    ? 'Imagem adicionada com sucesso.'
                    : $success_count . ' imagens adicionadas com sucesso.';
            }

            if (!empty($fail_messages)) {
                $fail_messages = array_slice($fail_messages, 0, 3);
                $error = implode(' ', $fail_messages);
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_meta') {
    $id = (int)($_POST['id'] ?? 0);
    $nome = trim((string)($_POST['nome'] ?? ''));
    $descricao = trim((string)($_POST['descricao'] ?? ''));
    $tags = trim((string)($_POST['tags'] ?? ''));

    if ($id <= 0) {
        $error = 'Imagem inválida.';
    } elseif ($nome === '') {
        $error = 'Nome é obrigatório.';
    } else {
        try {
            $stmt = $pdo->prepare("
                UPDATE eventos_galeria
                SET nome = :nome,
                    descricao = :descricao,
                    tags = :tags
                WHERE id = :id AND deleted_at IS NULL
            ");
            $stmt->execute([
                ':nome' => $nome,
                ':descricao' => $descricao !== '' ? $descricao : null,
                ':tags' => $tags !== '' ? $tags : null,
                ':id' => $id
            ]);
            $success = 'Informações atualizadas com sucesso.';
        } catch (Throwable $e) {
            $error = 'Erro ao atualizar imagem: ' . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'excluir') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE eventos_galeria SET deleted_at = NOW() WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $success = 'Imagem removida.';
        } catch (Throwable $e) {
            $error = 'Erro ao remover imagem: ' . $e->getMessage();
        }
    } else {
        $error = 'Imagem inválida para exclusão.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'rotacionar') {
    $id = (int)($_POST['id'] ?? 0);
    $angulo = (int)($_POST['angulo'] ?? 90);
    header('Content-Type: application/json; charset=utf-8');

    if ($id <= 0) {
        echo json_encode(['ok' => false, 'message' => 'ID inválido.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT transform_css FROM eventos_galeria WHERE id = :id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id]);
        $current = (string)($stmt->fetchColumn() ?: '');

        $current_angle = 0;
        if (preg_match('/rotate\((\d+)deg\)/', $current, $m)) {
            $current_angle = (int)$m[1];
        }

        $new_angle = ($current_angle + $angulo) % 360;
        $new_transform = $new_angle > 0 ? "rotate({$new_angle}deg)" : '';

        $update = $pdo->prepare("UPDATE eventos_galeria SET transform_css = :transform_css WHERE id = :id");
        $update->execute([
            ':transform_css' => $new_transform,
            ':id' => $id
        ]);

        echo json_encode(['ok' => true, 'transform' => $new_transform]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$where = ["deleted_at IS NULL"];
$params = [];

if ($categoria_filter !== '' && isset($categorias[$categoria_filter])) {
    $where[] = "categoria = :categoria";
    $params[':categoria'] = $categoria_filter;
}

if ($search !== '') {
    $where[] = "(nome ILIKE :search OR COALESCE(tags, '') ILIKE :search OR COALESCE(descricao, '') ILIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$where_sql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT id, categoria, nome, descricao, tags, transform_css, size_bytes, uploaded_at
    FROM eventos_galeria
    WHERE {$where_sql}
    ORDER BY uploaded_at DESC
    LIMIT 200
");
$stmt->execute($params);
$imagens = $stmt->fetchAll(PDO::FETCH_ASSOC);

$contadores = [];
$stmt = $pdo->query("
    SELECT categoria, COUNT(*) AS total
    FROM eventos_galeria
    WHERE deleted_at IS NULL
    GROUP BY categoria
");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $contadores[$row['categoria']] = (int)$row['total'];
}

$public_path = 'index.php?page=eventos_galeria_public';

includeSidebar('Galeria de Imagens - Eventos');
?>

<style>
    .galeria-container {
        padding: 24px;
        max-width: 1480px;
        margin: 0 auto;
    }

    .galeria-shell {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        box-shadow: 0 16px 40px rgba(15, 23, 42, 0.05);
        padding: 20px;
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }

    .page-title {
        margin: 0;
        color: #1e3a8a;
        font-size: 1.5rem;
        font-weight: 800;
    }

    .header-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .btn {
        padding: 10px 14px;
        border-radius: 10px;
        border: 1px solid transparent;
        font-size: 0.85rem;
        font-weight: 700;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s ease;
    }

    .btn:hover {
        transform: translateY(-1px);
    }

    .btn-primary {
        background: #1e3a8a;
        border-color: #1e3a8a;
        color: #fff;
    }

    .btn-secondary {
        background: #f8fafc;
        border-color: #cbd5e1;
        color: #334155;
    }

    .btn-danger {
        background: #dc2626;
        border-color: #dc2626;
        color: #fff;
    }

    .alert {
        border-radius: 10px;
        border: 1px solid transparent;
        padding: 10px 12px;
        margin-bottom: 16px;
        font-size: 0.9rem;
    }

    .alert-success {
        background: #ecfdf5;
        border-color: #a7f3d0;
        color: #065f46;
    }

    .alert-error {
        background: #fef2f2;
        border-color: #fecaca;
        color: #991b1b;
    }

    .categorias-bar {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 16px;
    }

    .categoria-btn {
        background: #fff;
        border: 1px solid #dbe3ef;
        border-radius: 12px;
        padding: 10px 12px;
        text-decoration: none;
        color: #0f172a;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 0.86rem;
        transition: all 0.2s ease;
    }

    .categoria-btn:hover {
        border-color: #93c5fd;
        box-shadow: 0 8px 24px rgba(37, 99, 235, 0.1);
    }

    .categoria-btn.active {
        background: #1e3a8a;
        border-color: #1e3a8a;
        color: #fff;
    }

    .filters {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 12px;
        display: flex;
        gap: 10px;
        margin-bottom: 18px;
        flex-wrap: wrap;
    }

    .filter-input {
        flex: 1;
        min-width: 220px;
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        padding: 10px 12px;
    }

    .images-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
        gap: 14px;
    }

    .image-card {
        border: 1px solid #e2e8f0;
        background: #fff;
        border-radius: 14px;
        overflow: hidden;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .image-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 14px 28px rgba(15, 23, 42, 0.1);
        border-color: #bfdbfe;
    }

    .image-wrapper {
        aspect-ratio: 1/1;
        overflow: hidden;
        position: relative;
        background: #f1f5f9;
    }

    .image-wrapper img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .image-actions {
        position: absolute;
        top: 8px;
        right: 8px;
        display: flex;
        gap: 6px;
        opacity: 0;
        transition: opacity 0.2s ease;
    }

    .image-card:hover .image-actions {
        opacity: 1;
    }

    .icon-btn {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        border: 1px solid #cbd5e1;
        background: rgba(255, 255, 255, 0.95);
        cursor: pointer;
    }

    .image-info {
        padding: 10px 12px 12px;
    }

    .image-name {
        font-size: 0.9rem;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 4px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .image-meta {
        color: #64748b;
        font-size: 0.78rem;
    }

    .image-tags {
        display: flex;
        gap: 4px;
        flex-wrap: wrap;
        margin-top: 8px;
    }

    .tag {
        background: #eff6ff;
        color: #1e40af;
        border-radius: 999px;
        padding: 2px 8px;
        font-size: 0.68rem;
        font-weight: 600;
    }

    .empty-state {
        border: 1px dashed #cbd5e1;
        border-radius: 12px;
        background: #f8fafc;
        text-align: center;
        padding: 40px 20px;
        color: #64748b;
    }

    .modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(2, 6, 23, 0.65);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 12000;
        padding: 16px;
    }

    .modal-overlay.show {
        display: flex;
    }

    .modal-content {
        width: min(560px, 100%);
        max-height: calc(100vh - 32px);
        overflow: auto;
        border-radius: 14px;
        background: #fff;
        border: 1px solid #dbe3ef;
    }

    .modal-content-preview {
        width: min(900px, 100%);
    }

    .modal-header {
        padding: 12px 14px;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
    }

    .modal-title {
        margin: 0;
        font-size: 1rem;
        color: #1e293b;
    }

    .modal-close {
        border: none;
        background: transparent;
        font-size: 22px;
        color: #64748b;
        cursor: pointer;
    }

    .modal-body {
        padding: 14px;
    }

    .form-group {
        margin-bottom: 12px;
    }

    .form-label {
        display: block;
        margin-bottom: 6px;
        font-size: 0.83rem;
        font-weight: 700;
        color: #334155;
    }

    .form-input,
    .form-select,
    .form-textarea {
        width: 100%;
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        padding: 10px 12px;
    }

    .form-textarea {
        min-height: 90px;
        resize: vertical;
    }

    .preview-image-wrap {
        background: #0f172a;
        border-radius: 12px;
        overflow: hidden;
        text-align: center;
    }

    .preview-image-wrap img {
        max-width: 100%;
        max-height: 72vh;
        object-fit: contain;
    }

    .preview-description {
        margin-top: 12px;
        color: #334155;
        font-size: 0.92rem;
    }

    @media (max-width: 768px) {
        .galeria-container {
            padding: 12px;
        }

        .galeria-shell {
            padding: 14px;
        }
    }
</style>

<div class="galeria-container">
    <div class="galeria-shell">
        <div class="page-header">
            <h1 class="page-title">🖼️ Galeria de Imagens</h1>
            <div class="header-actions">
                <button type="button" class="btn btn-secondary" id="btnCopyPublic" data-public-path="<?= htmlspecialchars($public_path) ?>">🔗 Copiar link público</button>
                <button type="button" class="btn btn-primary" onclick="abrirModalUpload()">+ Adicionar imagem</button>
                <a href="index.php?page=eventos" class="btn btn-secondary">← Voltar</a>
            </div>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <div class="categorias-bar">
            <a href="?page=eventos_galeria" class="categoria-btn <?= $categoria_filter === '' ? 'active' : '' ?>">
                📷 Todas (<?= array_sum($contadores) ?>)
            </a>
            <?php foreach ($categorias_filtro as $key => $cat): ?>
                <a href="?page=eventos_galeria&categoria=<?= urlencode($key) ?>" class="categoria-btn <?= $categoria_filter === $key ? 'active' : '' ?>">
                    <?= $cat['icon'] ?> <?= htmlspecialchars($cat['label']) ?> (<?= (int)($contadores[$key] ?? 0) ?>)
                </a>
            <?php endforeach; ?>
        </div>

        <form class="filters" method="GET">
            <input type="hidden" name="page" value="eventos_galeria">
            <?php if ($categoria_filter !== ''): ?>
                <input type="hidden" name="categoria" value="<?= htmlspecialchars($categoria_filter) ?>">
            <?php endif; ?>
            <input class="filter-input" type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Buscar por nome, descrição ou tags...">
            <button type="submit" class="btn btn-primary">Buscar</button>
        </form>

        <?php if (empty($imagens)): ?>
            <div class="empty-state">
                <p style="font-size:2rem;margin:0 0 8px;">📷</p>
                <p style="margin:0 0 4px;"><strong>Nenhuma imagem encontrada</strong></p>
                <p style="margin:0;">Adicione imagens para montar seu acervo visual para clientes.</p>
            </div>
        <?php else: ?>
            <div class="images-grid">
                <?php foreach ($imagens as $img): ?>
                    <?php
                    $img_id = (int)$img['id'];
                    $img_name = (string)($img['nome'] ?? '');
                    $img_desc = (string)($img['descricao'] ?? '');
                    $img_tags = (string)($img['tags'] ?? '');
                    $img_transform = (string)($img['transform_css'] ?? '');
                    $img_src = 'eventos_galeria_imagem.php?id=' . $img_id;
                    ?>
                    <div class="image-card"
                         data-image-id="<?= $img_id ?>"
                         data-preview-src="<?= htmlspecialchars($img_src) ?>"
                         data-preview-name="<?= htmlspecialchars($img_name) ?>"
                         data-preview-desc="<?= htmlspecialchars($img_desc) ?>"
                         data-preview-tags="<?= htmlspecialchars($img_tags) ?>">
                        <div class="image-wrapper">
                            <img src="<?= htmlspecialchars($img_src) ?>"
                                 alt="<?= htmlspecialchars($img_name) ?>"
                                 loading="lazy"
                                 style="<?= $img_transform !== '' ? 'transform:' . htmlspecialchars($img_transform) . ';' : '' ?>">
                            <div class="image-actions">
                                <button type="button" class="icon-btn" title="Girar" onclick="event.stopPropagation(); rotacionarImagem(<?= $img_id ?>)">↻</button>
                                <button type="button" class="icon-btn" title="Excluir" onclick='event.stopPropagation(); abrirConfirmacaoExclusao(<?= $img_id ?>, <?= json_encode($img_name, JSON_UNESCAPED_UNICODE) ?>)'>🗑️</button>
                            </div>
                        </div>
                        <div class="image-info">
                            <div class="image-name"><?= htmlspecialchars($img_name) ?></div>
                            <div class="image-meta">
                                <?= htmlspecialchars($categorias[$img['categoria']]['icon'] ?? '📷') ?>
                                <?= htmlspecialchars($categorias[$img['categoria']]['label'] ?? (string)$img['categoria']) ?>
                                • <?= isset($img['size_bytes']) ? (int)round(((int)$img['size_bytes']) / 1024) : 0 ?> KB
                            </div>
                            <?php if (!empty($img['tags'])): ?>
                                <div class="image-tags">
                                    <?php foreach (explode(',', (string)$img['tags']) as $tag): ?>
                                        <?php $tag = trim($tag); if ($tag === '') { continue; } ?>
                                        <span class="tag"><?= htmlspecialchars($tag) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<form method="POST" id="deleteImageForm" style="display:none;">
    <input type="hidden" name="action" value="excluir">
    <input type="hidden" name="id" id="deleteImageId" value="">
</form>

<div class="modal-overlay" id="modalUpload">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">📷 Adicionar imagem</h3>
            <button type="button" class="modal-close" onclick="fecharModalUpload()">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload">

                <div class="form-group">
                    <label class="form-label">Categoria *</label>
                    <select name="categoria" class="form-select" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($categorias_filtro as $key => $cat): ?>
                            <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($cat['icon']) ?> <?= htmlspecialchars($cat['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Nome (opcional para envio em massa)</label>
                    <input type="text" name="nome" class="form-input" placeholder="Se vazio, usa o nome do arquivo">
                </div>

                <div class="form-group">
                    <label class="form-label">Descrição</label>
                    <textarea name="descricao" class="form-textarea"></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Tags (separadas por vírgula)</label>
                    <input type="text" name="tags" class="form-input" placeholder="elegante, verde, pista">
                </div>

                <div class="form-group">
                    <label class="form-label">Imagens (máx. 10MB cada) *</label>
                    <input type="file" name="imagens[]" class="form-input" accept="image/*" multiple required>
                </div>

                <div style="display:flex;justify-content:flex-end;gap:8px;">
                    <button type="button" class="btn btn-secondary" onclick="fecharModalUpload()">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Enviar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal-overlay" id="modalPreview">
    <div class="modal-content modal-content-preview">
        <div class="modal-header">
            <h3 class="modal-title" id="previewTitle">Imagem</h3>
            <button type="button" class="modal-close" onclick="fecharModalPreview()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="preview-image-wrap">
                <img id="previewImage" src="" alt="Preview">
            </div>
            <div class="preview-description" id="previewDescription"></div>
            <form method="POST" id="previewForm" style="margin-top:16px;">
                <input type="hidden" name="action" value="update_meta">
                <input type="hidden" name="id" id="previewId" value="">
                <div class="form-group">
                    <label class="form-label">Nome *</label>
                    <input type="text" name="nome" id="previewNome" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Descrição</label>
                    <textarea name="descricao" id="previewDescricao" class="form-textarea"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Tags (separadas por vírgula)</label>
                    <input type="text" name="tags" id="previewTags" class="form-input">
                </div>
                <div style="display:flex;justify-content:flex-end;gap:8px;">
                    <button type="button" class="btn btn-secondary" onclick="fecharModalPreview()">Fechar</button>
                    <button type="submit" class="btn btn-primary">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal-overlay" id="modalDeleteConfirm">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Confirmar exclusão</h3>
            <button type="button" class="modal-close" onclick="fecharConfirmacaoExclusao()">&times;</button>
        </div>
        <div class="modal-body">
            <p id="deleteConfirmText" style="margin:0 0 16px;color:#334155;">Deseja remover esta imagem?</p>
            <div style="display:flex;justify-content:flex-end;gap:8px;">
                <button type="button" class="btn btn-secondary" onclick="fecharConfirmacaoExclusao()">Cancelar</button>
                <button type="button" class="btn btn-danger" onclick="confirmarExclusaoImagem()">Excluir imagem</button>
            </div>
        </div>
    </div>
</div>

<script>
    let pendingDeleteId = null;

    function abrirModalUpload() {
        document.getElementById('modalUpload').classList.add('show');
    }

    function fecharModalUpload() {
        document.getElementById('modalUpload').classList.remove('show');
    }

    function abrirModalPreview(src, nome, descricao, tags, id) {
        const modal = document.getElementById('modalPreview');
        const title = document.getElementById('previewTitle');
        const image = document.getElementById('previewImage');
        const description = document.getElementById('previewDescription');
        const inputId = document.getElementById('previewId');
        const inputNome = document.getElementById('previewNome');
        const inputDescricao = document.getElementById('previewDescricao');
        const inputTags = document.getElementById('previewTags');

        title.textContent = nome || 'Imagem';
        image.src = src;
        image.alt = nome || 'Imagem';
        description.textContent = descricao || '';
        inputId.value = id ? String(id) : '';
        inputNome.value = nome || '';
        inputDescricao.value = descricao || '';
        inputTags.value = tags || '';
        modal.classList.add('show');
    }

    function fecharModalPreview() {
        const modal = document.getElementById('modalPreview');
        modal.classList.remove('show');
    }

    function abrirConfirmacaoExclusao(id, nome) {
        pendingDeleteId = Number(id) || null;
        const text = document.getElementById('deleteConfirmText');
        text.textContent = nome
            ? `Deseja remover a imagem "${nome}"?`
            : 'Deseja remover esta imagem?';
        document.getElementById('modalDeleteConfirm').classList.add('show');
    }

    function fecharConfirmacaoExclusao() {
        pendingDeleteId = null;
        document.getElementById('modalDeleteConfirm').classList.remove('show');
    }

    function confirmarExclusaoImagem() {
        if (!pendingDeleteId) {
            return;
        }
        document.getElementById('deleteImageId').value = String(pendingDeleteId);
        document.getElementById('deleteImageForm').submit();
    }

    async function rotacionarImagem(id) {
        const formData = new FormData();
        formData.append('action', 'rotacionar');
        formData.append('id', String(id));
        formData.append('angulo', '90');

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            const data = await response.json();
            if (data && data.ok) {
                window.location.reload();
                return;
            }
            alert(data && data.message ? data.message : 'Não foi possível rotacionar a imagem.');
        } catch (error) {
            alert('Erro ao rotacionar imagem.');
        }
    }

    function copyPublicLink() {
        const btn = document.getElementById('btnCopyPublic');
        const path = btn.getAttribute('data-public-path');
        const absolute = window.location.origin + '/' + path.replace(/^\//, '');

        navigator.clipboard.writeText(absolute).then(() => {
            const oldText = btn.textContent;
            btn.textContent = '✅ Link copiado';
            setTimeout(() => {
                btn.textContent = oldText;
            }, 1800);
        }).catch(() => {
            window.prompt('Copie este link público:', absolute);
        });
    }

    document.getElementById('btnCopyPublic')?.addEventListener('click', copyPublicLink);

    document.querySelectorAll('.image-card').forEach((card) => {
        card.addEventListener('click', () => {
            const src = card.getAttribute('data-preview-src') || '';
            const nome = card.getAttribute('data-preview-name') || '';
            const descricao = card.getAttribute('data-preview-desc') || '';
            const tags = card.getAttribute('data-preview-tags') || '';
            const id = card.getAttribute('data-image-id') || '';
            if (src) {
                abrirModalPreview(src, nome, descricao, tags, id);
            }
        });
    });

    document.querySelectorAll('.modal-overlay').forEach((modal) => {
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                modal.classList.remove('show');
            }
        });
    });
</script>

<?php endSidebar(); ?>
