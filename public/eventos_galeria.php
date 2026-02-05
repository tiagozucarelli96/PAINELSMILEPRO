<?php
/**
 * eventos_galeria.php
 * Galeria de imagens por categoria (Infantil, Casamento, 15 Anos)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/upload_magalu.php';

// Verificar permissão
if (empty($_SESSION['perm_eventos']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

$user_id = $_SESSION['id'] ?? $_SESSION['user_id'] ?? 0;
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$categoria_filter = $_GET['categoria'] ?? '';
$search = trim($_GET['search'] ?? '');

$error = '';
$success = '';

// Processar upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload') {
    $categoria = $_POST['categoria'] ?? '';
    $nome = trim($_POST['nome'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $tags = trim($_POST['tags'] ?? '');
    
    if (!in_array($categoria, ['infantil', 'casamento', '15_anos', 'geral'])) {
        $error = 'Categoria inválida';
    } elseif (!$nome) {
        $error = 'Nome é obrigatório';
    } elseif (empty($_FILES['imagem']) || $_FILES['imagem']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Selecione uma imagem válida';
    } else {
        $file = $_FILES['imagem'];
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        
        if (!in_array($file['type'], $allowed)) {
            $error = 'Tipo de arquivo não permitido. Use JPG, PNG, GIF ou WEBP.';
        } elseif ($file['size'] > 10 * 1024 * 1024) { // 10MB
            $error = 'Arquivo muito grande. Máximo 10MB.';
        } else {
            try {
                $uploader = new MagaluUpload();
                $result = $uploader->upload($file, 'galeria_eventos');
                
                if (!$result || empty($result['chave_storage'])) {
                    $error = 'Erro ao fazer upload para o Magalu Cloud';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO eventos_galeria 
                        (categoria, nome, descricao, tags, storage_key, public_url, mime_type, size_bytes, uploaded_by, uploaded_at)
                        VALUES (:cat, :nome, :desc, :tags, :key, :url, :mime, :size, :user, NOW())
                    ");
                    $stmt->execute([
                        ':cat' => $categoria,
                        ':nome' => $nome,
                        ':desc' => $descricao,
                        ':tags' => $tags,
                        ':key' => $result['chave_storage'],
                        ':url' => $result['url'],
                        ':mime' => $result['mime_type'],
                        ':size' => $result['tamanho_bytes'],
                        ':user' => $user_id
                    ]);
                    $success = 'Imagem adicionada com sucesso!';
                }
            } catch (Exception $e) {
                $error = 'Erro: ' . $e->getMessage();
            }
        }
    }
}

// Processar exclusão
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'excluir') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE eventos_galeria SET deleted_at = NOW() WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $success = 'Imagem removida!';
        } catch (Exception $e) {
            $error = 'Erro ao remover: ' . $e->getMessage();
        }
    }
}

// Processar rotação
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'rotacionar') {
    $id = (int)($_POST['id'] ?? 0);
    $angulo = (int)($_POST['angulo'] ?? 90);
    if ($id > 0) {
        // Buscar transform atual
        $stmt = $pdo->prepare("SELECT transform_css FROM eventos_galeria WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $current = $stmt->fetchColumn() ?: '';
        
        // Calcular novo ângulo
        $current_angle = 0;
        if (preg_match('/rotate\((\d+)deg\)/', $current, $m)) {
            $current_angle = (int)$m[1];
        }
        $new_angle = ($current_angle + $angulo) % 360;
        $new_transform = $new_angle > 0 ? "rotate({$new_angle}deg)" : '';
        
        $stmt = $pdo->prepare("UPDATE eventos_galeria SET transform_css = :transform WHERE id = :id");
        $stmt->execute([':transform' => $new_transform, ':id' => $id]);
        
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'transform' => $new_transform]);
        exit;
    }
}

// Buscar imagens
$where = ["deleted_at IS NULL"];
$params = [];

if ($categoria_filter && in_array($categoria_filter, ['infantil', 'casamento', '15_anos', 'geral'])) {
    $where[] = "categoria = :cat";
    $params[':cat'] = $categoria_filter;
}

if ($search) {
    $where[] = "(nome ILIKE :search OR tags ILIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$where_sql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT * FROM eventos_galeria 
    WHERE {$where_sql}
    ORDER BY uploaded_at DESC
    LIMIT 100
");
$stmt->execute($params);
$imagens = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contadores por categoria
$contadores = [];
$stmt = $pdo->query("
    SELECT categoria, COUNT(*) as total 
    FROM eventos_galeria 
    WHERE deleted_at IS NULL 
    GROUP BY categoria
");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $contadores[$row['categoria']] = (int)$row['total'];
}

$categorias = [
    'infantil' => ['icon' => '🎈', 'label' => 'Infantil'],
    'casamento' => ['icon' => '💒', 'label' => 'Casamento'],
    '15_anos' => ['icon' => '👑', 'label' => '15 Anos'],
    'geral' => ['icon' => '🎉', 'label' => 'Geral']
];

includeSidebar('Galeria de Imagens - Eventos');
?>

<style>
    .galeria-container {
        padding: 2rem;
        max-width: 1400px;
        margin: 0 auto;
        background: #f8fafc;
    }
    
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
        flex-wrap: wrap;
        gap: 1rem;
    }
    
    .page-title {
        font-size: 1.5rem;
        font-weight: 700;
        color: #1e3a8a;
        margin: 0;
    }
    
    .btn {
        padding: 0.625rem 1.25rem;
        border-radius: 8px;
        font-size: 0.875rem;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        text-decoration: none;
    }
    
    .btn-primary { background: #1e3a8a; color: white; }
    .btn-secondary { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
    .btn-danger { background: #dc2626; color: white; }
    .btn-sm { padding: 0.375rem 0.75rem; font-size: 0.75rem; }
    
    .alert {
        padding: 1rem;
        border-radius: 8px;
        margin-bottom: 1.5rem;
    }
    .alert-success { background: #d1fae5; color: #065f46; }
    .alert-error { background: #fee2e2; color: #991b1b; }
    
    /* Categorias */
    .categorias-bar {
        display: flex;
        gap: 1rem;
        margin-bottom: 2rem;
        flex-wrap: wrap;
    }
    
    .categoria-btn {
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 1rem 1.5rem;
        cursor: pointer;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        text-decoration: none;
        color: inherit;
    }
    
    .categoria-btn:hover {
        border-color: #1e3a8a;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }
    
    .categoria-btn.active {
        background: #1e3a8a;
        color: white;
        border-color: #1e3a8a;
    }
    
    .categoria-icon {
        font-size: 1.5rem;
    }
    
    .categoria-info {
        display: flex;
        flex-direction: column;
    }
    
    .categoria-label {
        font-weight: 600;
        font-size: 0.95rem;
    }
    
    .categoria-count {
        font-size: 0.75rem;
        opacity: 0.7;
    }
    
    /* Filtros */
    .filters {
        background: white;
        border-radius: 12px;
        padding: 1rem;
        margin-bottom: 1.5rem;
        display: flex;
        gap: 1rem;
        align-items: center;
        flex-wrap: wrap;
    }
    
    .filter-input {
        flex: 1;
        min-width: 200px;
        padding: 0.625rem 1rem;
        border: 1px solid #d1d5db;
        border-radius: 8px;
    }
    
    /* Grid de imagens */
    .images-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 1.5rem;
    }
    
    .image-card {
        background: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        transition: all 0.2s;
    }
    
    .image-card:hover {
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        transform: translateY(-2px);
    }
    
    .image-wrapper {
        aspect-ratio: 1;
        overflow: hidden;
        position: relative;
    }
    
    .image-wrapper img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.3s;
    }
    
    .image-card:hover .image-wrapper img {
        transform: scale(1.05);
    }
    
    .image-actions {
        position: absolute;
        top: 0.5rem;
        right: 0.5rem;
        display: flex;
        gap: 0.25rem;
        opacity: 0;
        transition: opacity 0.2s;
    }
    
    .image-card:hover .image-actions {
        opacity: 1;
    }
    
    .image-actions button {
        width: 32px;
        height: 32px;
        border-radius: 6px;
        border: none;
        background: rgba(255,255,255,0.9);
        cursor: pointer;
        font-size: 0.875rem;
    }
    
    .image-info {
        padding: 1rem;
    }
    
    .image-name {
        font-weight: 600;
        font-size: 0.875rem;
        margin-bottom: 0.25rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .image-meta {
        font-size: 0.75rem;
        color: #64748b;
    }
    
    .image-tags {
        display: flex;
        gap: 0.25rem;
        flex-wrap: wrap;
        margin-top: 0.5rem;
    }
    
    .tag {
        background: #eff6ff;
        color: #1e40af;
        padding: 0.125rem 0.5rem;
        border-radius: 4px;
        font-size: 0.7rem;
    }
    
    /* Modal */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s;
    }
    
    .modal-overlay.show {
        opacity: 1;
        visibility: visible;
    }
    
    .modal-content {
        background: white;
        border-radius: 12px;
        width: 90%;
        max-width: 500px;
        max-height: 90vh;
        overflow-y: auto;
    }
    
    .modal-header {
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .modal-header h3 { margin: 0; }
    .modal-close { background: none; border: none; font-size: 1.5rem; cursor: pointer; }
    .modal-body { padding: 1.5rem; }
    
    .form-group { margin-bottom: 1rem; }
    .form-label { display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.375rem; }
    .form-input, .form-select, .form-textarea {
        width: 100%;
        padding: 0.625rem;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 0.875rem;
    }
    .form-textarea { min-height: 80px; resize: vertical; }
    
    .empty-state {
        text-align: center;
        padding: 3rem;
        color: #64748b;
    }
    
    @media (max-width: 768px) {
        .galeria-container { padding: 1rem; }
        .categorias-bar { overflow-x: auto; flex-wrap: nowrap; }
    }
</style>

<div class="galeria-container">
    <div class="page-header">
        <h1 class="page-title">🖼️ Galeria de Imagens</h1>
        <div>
            <button type="button" class="btn btn-primary" onclick="abrirModal()">+ Adicionar Imagem</button>
            <a href="index.php?page=eventos" class="btn btn-secondary">← Voltar</a>
        </div>
    </div>
    
    <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    
    <!-- Categorias -->
    <div class="categorias-bar">
        <a href="?page=eventos_galeria" class="categoria-btn <?= !$categoria_filter ? 'active' : '' ?>">
            <span class="categoria-icon">📷</span>
            <div class="categoria-info">
                <span class="categoria-label">Todas</span>
                <span class="categoria-count"><?= array_sum($contadores) ?> imagens</span>
            </div>
        </a>
        <?php foreach ($categorias as $key => $cat): ?>
        <a href="?page=eventos_galeria&categoria=<?= $key ?>" class="categoria-btn <?= $categoria_filter === $key ? 'active' : '' ?>">
            <span class="categoria-icon"><?= $cat['icon'] ?></span>
            <div class="categoria-info">
                <span class="categoria-label"><?= $cat['label'] ?></span>
                <span class="categoria-count"><?= $contadores[$key] ?? 0 ?> imagens</span>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    
    <!-- Filtros -->
    <form class="filters" method="GET">
        <input type="hidden" name="page" value="eventos_galeria">
        <?php if ($categoria_filter): ?>
        <input type="hidden" name="categoria" value="<?= htmlspecialchars($categoria_filter) ?>">
        <?php endif; ?>
        <input type="text" name="search" class="filter-input" placeholder="Buscar por nome ou tags..." value="<?= htmlspecialchars($search) ?>">
        <button type="submit" class="btn btn-primary">Buscar</button>
    </form>
    
    <!-- Grid de Imagens -->
    <?php if (empty($imagens)): ?>
    <div class="empty-state">
        <p style="font-size: 2rem;">🖼️</p>
        <p><strong>Nenhuma imagem encontrada</strong></p>
        <p>Adicione imagens à galeria para usar nas reuniões de decoração.</p>
    </div>
    <?php else: ?>
    <div class="images-grid">
        <?php foreach ($imagens as $img): ?>
        <div class="image-card">
            <div class="image-wrapper">
<img src="eventos_galeria_imagem.php?id=<?= (int)$img['id'] ?>"
                     alt="<?= htmlspecialchars($img['nome']) ?>"
                     loading="lazy"
                     style="<?= !empty($img['transform_css']) ? "transform: " . htmlspecialchars($img['transform_css']) : '' ?>"
                     onerror="this.onerror=null; this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22100%22 height=%22100%22%3E%3Crect fill=%22%23f1f5f9%22 width=%22100%22 height=%22100%22/%3E%3Ctext x=%2250%22 y=%2255%22 fill=%22%2364748b%22 text-anchor=%22middle%22 font-size=%2212%22%3E?%3C/text%3E%3C/svg%3E';">
                <div class="image-actions">
                    <button type="button" onclick="rotacionar(<?= $img['id'] ?>)" title="Girar">↻</button>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Remover esta imagem?')">
                        <input type="hidden" name="action" value="excluir">
                        <input type="hidden" name="id" value="<?= $img['id'] ?>">
                        <button type="submit" title="Excluir">🗑️</button>
                    </form>
                </div>
            </div>
            <div class="image-info">
                <div class="image-name"><?= htmlspecialchars($img['nome']) ?></div>
                <div class="image-meta">
                    <?= $categorias[$img['categoria']]['icon'] ?? '' ?> <?= $categorias[$img['categoria']]['label'] ?? $img['categoria'] ?>
                    • <?= round($img['size_bytes'] / 1024) ?> KB
                </div>
                <?php if ($img['tags']): ?>
                <div class="image-tags">
                    <?php foreach (explode(',', $img['tags']) as $tag): ?>
                    <span class="tag"><?= htmlspecialchars(trim($tag)) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Modal Upload -->
<div class="modal-overlay" id="modalUpload">
    <div class="modal-content">
        <div class="modal-header">
            <h3>📷 Adicionar Imagem</h3>
            <button type="button" class="modal-close" onclick="fecharModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload">
                
                <div class="form-group">
                    <label class="form-label">Categoria *</label>
                    <select name="categoria" class="form-select" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($categorias as $key => $cat): ?>
                        <option value="<?= $key ?>"><?= $cat['icon'] ?> <?= $cat['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Nome *</label>
                    <input type="text" name="nome" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Descrição</label>
                    <textarea name="descricao" class="form-textarea"></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Tags (separadas por vírgula)</label>
                    <input type="text" name="tags" class="form-input" placeholder="rosa, dourado, elegante">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Imagem * (máx. 10MB)</label>
                    <input type="file" name="imagem" class="form-input" accept="image/*" required>
                </div>
                
                <div style="display: flex; gap: 0.75rem; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="fecharModal()">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Enviar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function abrirModal() {
    document.getElementById('modalUpload').classList.add('show');
}

function fecharModal() {
    document.getElementById('modalUpload').classList.remove('show');
}

async function rotacionar(id) {
    const formData = new FormData();
    formData.append('action', 'rotacionar');
    formData.append('id', id);
    formData.append('angulo', 90);
    
    try {
        const resp = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await resp.json();
        if (data.ok) {
            location.reload();
        }
    } catch (err) {
        console.error(err);
    }
}
</script>

<?php endSidebar(); ?>
