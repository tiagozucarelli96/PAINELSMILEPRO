<?php
// logistica_receitas.php — Cadastro de receitas/fichas técnicas
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/upload_magalu.php';

$can_manage = !empty($_SESSION['perm_superadmin']) || !empty($_SESSION['perm_logistico']);

if (!$can_manage) {
    http_response_code(403);
    echo '<div class="alert-error">Acesso negado.</div>';
    exit;
}

$errors = [];
$messages = [];
$redirect_id = 0;

function ensure_unidades_medida(PDO $pdo): array {
    $rows = $pdo->query("SELECT nome FROM logistica_unidades_medida WHERE ativo IS TRUE ORDER BY ordem, nome")->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($rows)) {
        return $rows;
    }

    $defaults = ['un', 'kg', 'g', 'l', 'ml', 'cx', 'pct'];
    $stmt = $pdo->prepare("INSERT INTO logistica_unidades_medida (nome, ordem, ativo) VALUES (:nome, :ordem, TRUE)");
    foreach ($defaults as $idx => $nome) {
        $stmt->execute([':nome' => $nome, ':ordem' => ($idx + 1) * 10]);
    }

    return $pdo->query("SELECT nome FROM logistica_unidades_medida WHERE ativo IS TRUE ORDER BY ordem, nome")->fetchAll(PDO::FETCH_COLUMN);
}

function gerarUrlPreviewMagalu(?string $chave_storage, ?string $fallback_url): ?string {
    if (!empty($chave_storage)) {
        if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
            require_once __DIR__ . '/../vendor/autoload.php';
            if (class_exists('Aws\\S3\\S3Client')) {
                try {
                    $bucket = $_ENV['MAGALU_BUCKET'] ?? getenv('MAGALU_BUCKET') ?: 'smilepainel';
                    $region = $_ENV['MAGALU_REGION'] ?? getenv('MAGALU_REGION') ?: 'br-se1';
                    $endpoint = $_ENV['MAGALU_ENDPOINT'] ?? getenv('MAGALU_ENDPOINT') ?: 'https://br-se1.magaluobjects.com';
                    $accessKey = $_ENV['MAGALU_ACCESS_KEY'] ?? getenv('MAGALU_ACCESS_KEY');
                    $secretKey = $_ENV['MAGALU_SECRET_KEY'] ?? getenv('MAGALU_SECRET_KEY');
                    $bucket = strtolower($bucket);

                    if ($accessKey && $secretKey) {
                        $s3Client = new \Aws\S3\S3Client([
                            'region' => $region,
                            'version' => 'latest',
                            'credentials' => [
                                'key' => $accessKey,
                                'secret' => $secretKey,
                            ],
                            'endpoint' => $endpoint,
                            'use_path_style_endpoint' => true,
                        ]);

                        $cmd = $s3Client->getCommand('GetObject', [
                            'Bucket' => $bucket,
                            'Key' => $chave_storage,
                        ]);
                        $presignedUrl = $s3Client->createPresignedRequest($cmd, '+1 hour')->getUri();
                        return (string)$presignedUrl;
                    }
                } catch (Throwable $e) {
                    error_log("Erro ao gerar URL presigned (receita): " . $e->getMessage());
                }
            }
        }

        $bucket = $_ENV['MAGALU_BUCKET'] ?? getenv('MAGALU_BUCKET') ?: 'smilepainel';
        $endpoint = $_ENV['MAGALU_ENDPOINT'] ?? getenv('MAGALU_ENDPOINT') ?: 'https://br-se1.magaluobjects.com';
        return rtrim($endpoint, '/') . '/' . strtolower($bucket) . '/' . ltrim($chave_storage, '/');
    }

    return $fallback_url ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim((string)($_POST['nome'] ?? ''));
        if ($nome === '') {
            $errors[] = 'Nome é obrigatório.';
        } else {
            $foto_url = null;
            $foto_chave = null;
            if (!empty($_FILES['foto_file']['tmp_name']) && is_uploaded_file($_FILES['foto_file']['tmp_name'])) {
                try {
                    $uploader = new MagaluUpload();
                    $result = $uploader->upload($_FILES['foto_file'], 'logistica/receitas');
                    $foto_url = $result['url'] ?? null;
                    $foto_chave = $result['chave_storage'] ?? null;
                } catch (Throwable $e) {
                    $errors[] = 'Falha ao enviar foto: ' . $e->getMessage();
                }
            } elseif ($id > 0) {
                $stmt = $pdo->prepare("SELECT foto_url, foto_chave_storage FROM logistica_receitas WHERE id = :id");
                $stmt->execute([':id' => $id]);
                $current = $stmt->fetch(PDO::FETCH_ASSOC);
                $foto_url = $current['foto_url'] ?? null;
                $foto_chave = $current['foto_chave_storage'] ?? null;
            }

            if ($errors) {
                $messages = [];
            }

            $visivel = !empty($_POST['visivel_na_lista']);
            $ativo = !empty($_POST['ativo']);
            $dados = [
                ':nome' => $nome,
                ':foto_url' => $foto_url,
                ':foto_chave_storage' => $foto_chave,
                ':tipologia_receita_id' => !empty($_POST['tipologia_receita_id']) ? (int)$_POST['tipologia_receita_id'] : null,
                ':ativo' => $ativo,
                ':visivel_na_lista' => $visivel,
                ':rendimento_base_pessoas' => (int)($_POST['rendimento_base_pessoas'] ?? 1)
            ];

            if ($id > 0) {
                $sql = "
                    UPDATE logistica_receitas
                    SET nome = :nome,
                        foto_url = :foto_url,
                        foto_chave_storage = :foto_chave_storage,
                        tipologia_receita_id = :tipologia_receita_id,
                        ativo = :ativo,
                        visivel_na_lista = :visivel_na_lista,
                        rendimento_base_pessoas = :rendimento_base_pessoas,
                        updated_at = NOW()
                    WHERE id = :id
                ";
                $dados[':id'] = $id;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($dados);
                $messages[] = 'Receita atualizada.';
                $redirect_id = $id;
            } else {
                $sql = "
                    INSERT INTO logistica_receitas
                    (nome, foto_url, foto_chave_storage, tipologia_receita_id, ativo, visivel_na_lista, rendimento_base_pessoas)
                    VALUES
                    (:nome, :foto_url, :foto_chave_storage, :tipologia_receita_id, :ativo, :visivel_na_lista, :rendimento_base_pessoas)
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($dados);
                $messages[] = 'Receita criada.';
                $redirect_id = (int)$pdo->lastInsertId();
            }
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE logistica_receitas SET ativo = NOT ativo, updated_at = NOW() WHERE id = :id")
                ->execute([':id' => $id]);
        }
    }

    if ($action === 'add_component') {
        $receita_id = (int)($_POST['receita_id'] ?? 0);
        $insumo_id = (int)($_POST['insumo_id'] ?? 0);
        $quantidade = $_POST['quantidade_base'] ?? '';
        if ($receita_id > 0 && $insumo_id > 0 && $quantidade !== '') {
            $stmt = $pdo->prepare("
                INSERT INTO logistica_receita_componentes (receita_id, insumo_id, quantidade_base, unidade)
                VALUES (:receita_id, :insumo_id, :quantidade_base, :unidade)
            ");
            $stmt->execute([
                ':receita_id' => $receita_id,
                ':insumo_id' => $insumo_id,
                ':quantidade_base' => (float)$quantidade,
                ':unidade' => trim((string)($_POST['unidade'] ?? '')) ?: null
            ]);
            $messages[] = 'Componente adicionado.';
        }
    }

    if ($action === 'remove_component') {
        $id = (int)($_POST['component_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM logistica_receita_componentes WHERE id = :id")
                ->execute([':id' => $id]);
            $messages[] = 'Componente removido.';
        }
    }
}

if ($redirect_id > 0 && empty($errors)) {
    header('Location: index.php?page=logistica_receitas&edit_id=' . $redirect_id);
    exit;
}

$search = trim((string)($_GET['q'] ?? ''));
$where = '';
$params = [];
if ($search !== '') {
    $where = "WHERE LOWER(r.nome) LIKE LOWER(:q)";
    $params[':q'] = '%' . $search . '%';
}

$stmt = $pdo->prepare("
    SELECT r.*, t.nome AS tipologia_nome
    FROM logistica_receitas r
    LEFT JOIN logistica_tipologias_receita t ON t.id = r.tipologia_receita_id
    {$where}
    ORDER BY r.nome
");
$stmt->execute($params);
$receitas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tipologias = $pdo->query("SELECT id, nome FROM logistica_tipologias_receita WHERE ativo IS TRUE ORDER BY ordem, nome")->fetchAll(PDO::FETCH_ASSOC);
$insumos = $pdo->query("SELECT id, nome_oficial FROM logistica_insumos WHERE ativo IS TRUE ORDER BY nome_oficial")->fetchAll(PDO::FETCH_ASSOC);
$unidades_medida = ensure_unidades_medida($pdo);

$edit_id = (int)($_GET['edit_id'] ?? 0);
$edit_item = null;
$componentes = [];
if ($edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM logistica_receitas WHERE id = :id");
    $stmt->execute([':id' => $edit_id]);
    $edit_item = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT c.*, i.nome_oficial
        FROM logistica_receita_componentes c
        JOIN logistica_insumos i ON i.id = c.insumo_id
        WHERE c.receita_id = :id
        ORDER BY i.nome_oficial
    ");
    $stmt->execute([':id' => $edit_id]);
    $componentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
if ($edit_id > 0 && !$edit_item) {
    $errors[] = 'Receita não encontrada.';
}
$edit_foto_url = null;
if ($edit_item) {
    $edit_foto_url = gerarUrlPreviewMagalu($edit_item['foto_chave_storage'] ?? null, $edit_item['foto_url'] ?? null);
}

includeSidebar('Receitas - Logística');
?>

<style>
.page-container {
    max-width: 1300px;
    margin: 0 auto;
    padding: 1.5rem;
}
.section-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
}
.span-2 { grid-column: span 2; }
.span-3 { grid-column: span 3; }
.form-input {
    width: 100%;
    padding: 0.6rem 0.75rem;
    border: 1px solid #cbd5f5;
    border-radius: 8px;
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
    padding: 0.5rem 0.9rem;
    border-radius: 8px;
    cursor: pointer;
}
.table {
    width: 100%;
    border-collapse: collapse;
}
.table th, .table td {
    text-align: left;
    padding: 0.6rem;
    border-bottom: 1px solid #e5e7eb;
    font-size: 0.95rem;
}
.status-pill {
    display: inline-block;
    padding: 0.15rem 0.6rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
    color: #fff;
}
.status-ativo { background: #16a34a; }
.status-inativo { background: #f97316; }
.alert {
    padding: 0.75rem 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
}
.alert-error { background: #fee2e2; color: #991b1b; }
.alert-success { background: #dcfce7; color: #166534; }
.upload-preview img {
    max-height: 120px;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
}
.upload-box {
    border: 1px dashed #cbd5f5;
    border-radius: 10px;
    padding: 0.75rem;
    background: #f8fafc;
}
.upload-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    align-items: center;
    margin-top: 0.5rem;
}
.link-muted {
    font-size: 0.85rem;
    color: #64748b;
    cursor: pointer;
}
</style>

<div class="page-container">
    <h1>Receitas / Fichas Técnicas</h1>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
    <?php endforeach; ?>

    <?php foreach ($messages as $message): ?>
        <div class="alert alert-success"><?= h($message) ?></div>
    <?php endforeach; ?>

    <div class="section-card">
        <h2>Nova / Editar Receita</h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $edit_item ? (int)$edit_item['id'] : '' ?>">
            <div class="form-grid">
                <div class="span-2">
                    <label>Nome *</label>
                    <input class="form-input" name="nome" required value="<?= h($edit_item['nome'] ?? '') ?>">
                </div>
                <div>
                    <label>Tipologia</label>
                    <select class="form-input" name="tipologia_receita_id">
                        <option value="">Selecione...</option>
                        <?php foreach ($tipologias as $tip): ?>
                            <option value="<?= (int)$tip['id'] ?>" <?= (int)($edit_item['tipologia_receita_id'] ?? 0) === (int)$tip['id'] ? 'selected' : '' ?>>
                                <?= h($tip['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Rendimento base (pessoas)</label>
                    <input class="form-input" name="rendimento_base_pessoas" type="number" min="1" value="<?= h($edit_item['rendimento_base_pessoas'] ?? 1) ?>">
                </div>
                <div>
                    <label>Visível na lista</label>
                    <input type="checkbox" name="visivel_na_lista" <?= !isset($edit_item) || !empty($edit_item['visivel_na_lista']) ? 'checked' : '' ?>>
                </div>
                <div>
                    <label>Ativo</label>
                    <input type="checkbox" name="ativo" <?= !isset($edit_item) || !empty($edit_item['ativo']) ? 'checked' : '' ?>>
                </div>
            </div>
            <div style="margin-top:1rem;">
                <label>Foto</label>
                <div class="upload-box">
                    <div class="upload-preview" id="foto_preview">
                        <?php if (!empty($edit_foto_url)): ?>
                            <img src="<?= h($edit_foto_url) ?>" alt="Preview">
                        <?php else: ?>
                            <span class="link-muted">Nenhuma foto enviada.</span>
                        <?php endif; ?>
                    </div>
                    <div class="upload-actions">
                        <input type="file" id="foto_file" name="foto_file" accept="image/*">
                    </div>
                </div>
            </div>
            <div style="margin-top:1rem;">
                <button class="btn-primary" type="submit">Salvar</button>
            </div>
        </form>
    </div>


    <div class="section-card">
        <h2>Lista de Receitas</h2>
        <form method="GET" style="margin-bottom:1rem;">
            <input type="hidden" name="page" value="logistica_receitas">
            <input class="form-input" name="q" placeholder="Buscar por nome" value="<?= h($search) ?>">
        </form>
        <table class="table">
            <thead>
                <tr>
                    <th>Foto</th>
                    <th>Nome</th>
                    <th>Tipologia</th>
                    <th>Rendimento</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($receitas as $rec): ?>
                    <?php $thumb_url = gerarUrlPreviewMagalu($rec['foto_chave_storage'] ?? null, $rec['foto_url'] ?? null); ?>
                    <tr>
                        <td>
                            <?php if (!empty($thumb_url)): ?>
                                <img src="<?= h($thumb_url) ?>" alt="Foto" style="max-height:44px;border-radius:6px;border:1px solid #e5e7eb;">
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><?= h($rec['nome']) ?></td>
                        <td><?= h($rec['tipologia_nome'] ?? '') ?></td>
                        <td><?= (int)($rec['rendimento_base_pessoas'] ?? 0) ?></td>
                        <td>
                            <span class="status-pill <?= $rec['ativo'] ? 'status-ativo' : 'status-inativo' ?>">
                                <?= $rec['ativo'] ? 'Ativo' : 'Inativo' ?>
                            </span>
                        </td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$rec['id'] ?>">
                                <button class="btn-secondary" type="submit">Ativar/Desativar</button>
                            </form>
                            <a class="btn-secondary" href="index.php?page=logistica_receitas&edit_id=<?= (int)$rec['id'] ?>">Editar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($receitas)): ?>
                    <tr><td colspan="6">Nenhuma receita encontrada.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.getElementById('foto_file')?.addEventListener('change', (e) => {
    const file = e.target.files[0];
    const preview = document.getElementById('foto_preview');
    if (!file || !preview) return;
    const reader = new FileReader();
    reader.onload = () => {
        preview.innerHTML = '<img src="' + reader.result + '" alt="Preview">';
    };
    reader.readAsDataURL(file);
});
</script>

<?php endSidebar(); ?>
