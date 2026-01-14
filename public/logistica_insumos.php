<?php
// logistica_insumos.php — Cadastro de insumos
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/upload_magalu.php';

$is_modal = !empty($_GET['modal']) || !empty($_POST['modal']);
$no_checks = !empty($_GET['nochecks']) || !empty($_POST['nochecks']);

$can_manage = !empty($_SESSION['perm_superadmin']) || !empty($_SESSION['perm_logistico']);
$can_see_cost = !empty($_SESSION['perm_superadmin']) || !empty($_SESSION['perm_logistico_financeiro']);

if (!$can_manage) {
    http_response_code(403);
    echo '<div class="alert-error">Acesso negado.</div>';
    exit;
}

$errors = [];
$messages = [];
$duplicate_warning = null;

function find_duplicates(PDO $pdo, string $nome, array $sinonimos, int $excludeId = 0): array {
    $conditions = [];
    $params = [];

    $conditions[] = "LOWER(nome_oficial) LIKE LOWER(:nome)";
    $params[':nome'] = '%' . $nome . '%';

    foreach ($sinonimos as $i => $syn) {
        $conditions[] = "LOWER(sinonimos) LIKE LOWER(:syn{$i})";
        $params[":syn{$i}"] = '%' . $syn . '%';
    }

    $sql = "SELECT id, nome_oficial FROM logistica_insumos WHERE (" . implode(' OR ', $conditions) . ")";
    if ($excludeId > 0) {
        $sql .= " AND id <> :exclude_id";
        $params[':exclude_id'] = $excludeId;
    }
    $sql .= " ORDER BY nome_oficial";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ensure_unidades_medida(PDO $pdo): array {
    $rows = $pdo->query("SELECT id, nome FROM logistica_unidades_medida WHERE ativo IS TRUE ORDER BY ordem, nome")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($rows)) {
        return $rows;
    }

    $defaults = ['un', 'kg', 'g', 'l', 'ml', 'cx', 'pct'];
    $stmt = $pdo->prepare("INSERT INTO logistica_unidades_medida (nome, ordem, ativo) VALUES (:nome, :ordem, TRUE)");
    foreach ($defaults as $idx => $nome) {
        $stmt->execute([':nome' => $nome, ':ordem' => ($idx + 1) * 10]);
    }

    return $pdo->query("SELECT id, nome FROM logistica_unidades_medida WHERE ativo IS TRUE ORDER BY ordem, nome")->fetchAll(PDO::FETCH_ASSOC);
}

function parse_decimal_input(string $value): ?float {
    $raw = trim($value);
    if ($raw === '') {
        return null;
    }
    $normalized = preg_replace('/[^0-9,\\.]/', '', $raw);
    if (strpos($normalized, ',') !== false && strpos($normalized, '.') !== false) {
        $normalized = str_replace('.', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);
    } else {
        $normalized = str_replace(',', '.', $normalized);
    }
    return $normalized === '' ? null : (float)$normalized;
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
                    error_log("Erro ao gerar URL presigned (insumo): " . $e->getMessage());
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
        $nome = trim((string)($_POST['nome_oficial'] ?? ''));
        $sinonimos_raw = trim((string)($_POST['sinonimos'] ?? ''));
        $sinonimos = array_values(array_filter(array_map('trim', preg_split('/\R/', $sinonimos_raw))));
        $confirm_duplicate = !empty($_POST['confirm_duplicate']);

        if ($nome === '') {
            $errors[] = 'Nome oficial é obrigatório.';
        }

        if (!$errors && !$confirm_duplicate) {
            $dups = find_duplicates($pdo, $nome, $sinonimos ?: [$nome], $id);
            if (!empty($dups)) {
                $duplicate_warning = $dups;
            }
        }

        if (!$errors && !$duplicate_warning) {
            $foto_url = null;
            $foto_chave = null;

            if (!empty($_FILES['foto_file']['tmp_name']) && is_uploaded_file($_FILES['foto_file']['tmp_name'])) {
                try {
                    $uploader = new MagaluUpload();
                    $result = $uploader->upload($_FILES['foto_file'], 'logistica/insumos');
                    $foto_url = $result['url'] ?? null;
                    $foto_chave = $result['chave_storage'] ?? null;
                } catch (Throwable $e) {
                    $errors[] = 'Falha ao enviar foto: ' . $e->getMessage();
                }
            } elseif ($id > 0) {
                $stmt = $pdo->prepare("SELECT foto_url, foto_chave_storage FROM logistica_insumos WHERE id = :id");
                $stmt->execute([':id' => $id]);
                $current = $stmt->fetch(PDO::FETCH_ASSOC);
                $foto_url = $current['foto_url'] ?? null;
                $foto_chave = $current['foto_chave_storage'] ?? null;
            }

            if ($errors) {
                $duplicate_warning = null;
            }
        }

        if (!$errors && !$duplicate_warning) {
            $visivel = $no_checks ? true : !empty($_POST['visivel_na_lista']);
            $ativo = $no_checks ? true : !empty($_POST['ativo']);
            $fracionavel = $no_checks ? true : !empty($_POST['fracionavel']);

            $unidade_id = !empty($_POST['unidade_medida_padrao_id']) ? (int)$_POST['unidade_medida_padrao_id'] : null;
            $unidade_nome = null;
            if ($unidade_id) {
                $stmt = $pdo->prepare("SELECT nome FROM logistica_unidades_medida WHERE id = :id");
                $stmt->execute([':id' => $unidade_id]);
                $unidade_nome = $stmt->fetchColumn() ?: null;
            }

            $dados = [
                ':nome_oficial' => $nome,
                ':foto_url' => $foto_url,
                ':foto_chave_storage' => $foto_chave,
                ':unidade_medida' => $unidade_nome,
                ':unidade_medida_padrao_id' => $unidade_id,
                ':tipologia_insumo_id' => !empty($_POST['tipologia_insumo_id']) ? (int)$_POST['tipologia_insumo_id'] : null,
                ':visivel_na_lista' => $visivel,
                ':ativo' => $ativo,
                ':sinonimos' => $sinonimos_raw !== '' ? $sinonimos_raw : null,
                ':barcode' => trim((string)($_POST['barcode'] ?? '')) ?: null,
                ':fracionavel' => $fracionavel,
                ':tamanho_embalagem' => parse_decimal_input((string)($_POST['tamanho_embalagem'] ?? '')),
                ':unidade_embalagem' => trim((string)($_POST['unidade_embalagem'] ?? '')) ?: null,
                ':observacoes' => trim((string)($_POST['observacoes'] ?? '')) ?: null
            ];

            if ($can_see_cost) {
                $custo_raw = (string)($_POST['custo_padrao'] ?? '');
                $dados[':custo_padrao'] = parse_decimal_input($custo_raw);
            }

            if ($id > 0) {
                $sql = "
                    UPDATE logistica_insumos
                    SET nome_oficial = :nome_oficial,
                        foto_url = :foto_url,
                        foto_chave_storage = :foto_chave_storage,
                        unidade_medida = :unidade_medida,
                        unidade_medida_padrao_id = :unidade_medida_padrao_id,
                        tipologia_insumo_id = :tipologia_insumo_id,
                        visivel_na_lista = :visivel_na_lista,
                        ativo = :ativo,
                        sinonimos = :sinonimos,
                        barcode = :barcode,
                        fracionavel = :fracionavel,
                        tamanho_embalagem = :tamanho_embalagem,
                        unidade_embalagem = :unidade_embalagem,
                        observacoes = :observacoes,
                        updated_at = NOW()
                ";
                if ($can_see_cost) {
                    $sql .= ", custo_padrao = :custo_padrao";
                }
                $sql .= " WHERE id = :id";
                $dados[':id'] = $id;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($dados);
                $messages[] = 'Insumo atualizado.';
            } else {
                $cols = [
                    'nome_oficial', 'foto_url', 'foto_chave_storage', 'unidade_medida', 'unidade_medida_padrao_id', 'tipologia_insumo_id',
                    'visivel_na_lista', 'ativo', 'sinonimos', 'barcode', 'fracionavel',
                    'tamanho_embalagem', 'unidade_embalagem', 'observacoes'
                ];
                $vals = [
                    ':nome_oficial', ':foto_url', ':foto_chave_storage', ':unidade_medida', ':unidade_medida_padrao_id', ':tipologia_insumo_id',
                    ':visivel_na_lista', ':ativo', ':sinonimos', ':barcode', ':fracionavel',
                    ':tamanho_embalagem', ':unidade_embalagem', ':observacoes'
                ];
                if ($can_see_cost) {
                    $cols[] = 'custo_padrao';
                    $vals[] = ':custo_padrao';
                }

                $sql = "
                    INSERT INTO logistica_insumos
                    (" . implode(', ', $cols) . ")
                    VALUES
                    (" . implode(', ', $vals) . ")
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($dados);
                $messages[] = 'Insumo criado.';
            }
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE logistica_insumos SET ativo = NOT ativo, updated_at = NOW() WHERE id = :id")
                ->execute([':id' => $id]);
        }
    }
}

$search = trim((string)($_GET['q'] ?? ''));
$where = '';
$params = [];
if ($search !== '') {
    $where = "WHERE LOWER(nome_oficial) LIKE LOWER(:q) OR LOWER(sinonimos) LIKE LOWER(:q)";
    $params[':q'] = '%' . $search . '%';
}

$stmt = $pdo->prepare("
    SELECT i.*, t.nome AS tipologia_nome, u.nome AS unidade_nome
    FROM logistica_insumos i
    LEFT JOIN logistica_tipologias_insumo t ON t.id = i.tipologia_insumo_id
    LEFT JOIN logistica_unidades_medida u ON u.id = i.unidade_medida_padrao_id
    {$where}
    ORDER BY i.nome_oficial
");
$stmt->execute($params);
$insumos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tipologias = $pdo->query("SELECT id, nome FROM logistica_tipologias_insumo WHERE ativo IS TRUE ORDER BY ordem, nome")->fetchAll(PDO::FETCH_ASSOC);
$unidades_medida = ensure_unidades_medida($pdo);

$edit_id = (int)($_GET['edit_id'] ?? 0);
$edit_item = null;
if ($edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM logistica_insumos WHERE id = :id");
    $stmt->execute([':id' => $edit_id]);
    $edit_item = $stmt->fetch(PDO::FETCH_ASSOC);
}
$edit_foto_url = null;
if ($edit_item) {
    $edit_foto_url = gerarUrlPreviewMagalu($edit_item['foto_chave_storage'] ?? null, $edit_item['foto_url'] ?? null);
}

if (!$is_modal) {
    includeSidebar('Insumos - Logística');
}
?>

<style>
<?php if ($is_modal): ?>
body {
    background: transparent;
}
.page-container {
    max-width: 100%;
    padding: 0.5rem;
}
.section-card {
    border: none;
    box-shadow: none;
    padding: 0;
    margin: 0;
}
.page-container h1 {
    display: none;
}
.section-card h2 {
    font-size: 1.1rem;
    margin-bottom: 0.5rem;
}
.form-grid {
    gap: 0.65rem;
}
.form-input {
    padding: 0.5rem 0.65rem;
}
.upload-box {
    padding: 0.5rem;
}
.upload-actions {
    margin-top: 0.35rem;
}
<?php endif; ?>
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
.input-group {
    display: flex;
    align-items: center;
    border: 1px solid #cbd5f5;
    border-radius: 8px;
    overflow: hidden;
    background: #fff;
}
.input-group span {
    padding: 0.55rem 0.7rem;
    background: #f1f5f9;
    color: #475569;
    font-weight: 600;
    border-right: 1px solid #e2e8f0;
}
.input-group input {
    border: none;
    padding: 0.6rem 0.75rem;
    width: 100%;
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
.camera-box {
    display: none;
    margin-top: 0.75rem;
    padding: 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #f8fafc;
}
</style>

<div class="page-container">
    <h1>Insumos</h1>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
    <?php endforeach; ?>

    <?php foreach ($messages as $message): ?>
        <div class="alert alert-success"><?= h($message) ?></div>
    <?php endforeach; ?>

    <?php if ($duplicate_warning): ?>
        <div class="alert alert-error">
            Possível duplicado encontrado:
            <ul>
                <?php foreach ($duplicate_warning as $dup): ?>
                    <li><?= h($dup['nome_oficial']) ?> (ID <?= (int)$dup['id'] ?>)</li>
                <?php endforeach; ?>
            </ul>
            Reenvie salvando para confirmar.
        </div>
    <?php endif; ?>

    <div class="section-card">
        <h2>Novo / Editar Insumo</h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save">
            <?php if ($is_modal): ?>
                <input type="hidden" name="modal" value="1">
            <?php endif; ?>
            <?php if ($no_checks): ?>
                <input type="hidden" name="nochecks" value="1">
                <input type="hidden" name="visivel_na_lista" value="1">
                <input type="hidden" name="ativo" value="1">
                <input type="hidden" name="fracionavel" value="1">
            <?php endif; ?>
            <input type="hidden" name="id" value="<?= $edit_item ? (int)$edit_item['id'] : '' ?>">
            <input type="hidden" name="confirm_duplicate" value="<?= $duplicate_warning ? '1' : '' ?>">
            <div class="form-grid">
                <div class="span-2">
                    <label>Nome oficial *</label>
                    <input class="form-input" name="nome_oficial" required value="<?= h($edit_item['nome_oficial'] ?? '') ?>">
                </div>
                <div>
                    <label>Tipologia</label>
                    <select class="form-input" name="tipologia_insumo_id">
                        <option value="">Selecione...</option>
                        <?php foreach ($tipologias as $tip): ?>
                            <option value="<?= (int)$tip['id'] ?>" <?= (int)($edit_item['tipologia_insumo_id'] ?? 0) === (int)$tip['id'] ? 'selected' : '' ?>>
                                <?= h($tip['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Unidade de medida (padrão)</label>
                    <select class="form-input" name="unidade_medida_padrao_id">
                        <option value="">Selecione...</option>
                        <?php foreach ($unidades_medida as $un): ?>
                            <option value="<?= (int)$un['id'] ?>" <?= (int)($edit_item['unidade_medida_padrao_id'] ?? 0) === (int)$un['id'] ? 'selected' : '' ?>>
                                <?= h($un['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Barcode</label>
                    <div class="upload-actions">
                        <input class="form-input" id="barcode_input" name="barcode" value="<?= h($edit_item['barcode'] ?? '') ?>">
                        <button type="button" class="btn-secondary" onclick="openBarcodeCamera()">Ler câmera</button>
                    </div>
                    <input type="file" id="barcode_file" accept="image/*" capture="environment" style="display:none;">
                </div>
                <?php if (!$no_checks): ?>
                <div>
                    <label>Fracionável</label>
                    <input type="checkbox" name="fracionavel" <?= !isset($edit_item) || !empty($edit_item['fracionavel']) ? 'checked' : '' ?>>
                </div>
                <?php endif; ?>
                <div>
                    <label>Tamanho embalagem</label>
                    <input class="form-input" name="tamanho_embalagem" id="tamanho_embalagem" type="number" step="0.001" value="<?= h($edit_item['tamanho_embalagem'] ?? '') ?>">
                </div>
                <div>
                    <label>Unidade embalagem</label>
                    <select class="form-input" name="unidade_embalagem">
                        <option value="">Selecione...</option>
                        <?php foreach ($unidades_medida as $un): ?>
                            <option value="<?= h($un['nome']) ?>" <?= ($edit_item['unidade_embalagem'] ?? '') === $un['nome'] ? 'selected' : '' ?>>
                                <?= h($un['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($can_see_cost): ?>
                <div>
                    <label>Custo padrão</label>
                    <div class="input-group">
                        <span>R$</span>
                        <input id="custo_padrao" name="custo_padrao" type="text" inputmode="decimal" value="<?= isset($edit_item['custo_padrao']) && $edit_item['custo_padrao'] !== null ? number_format((float)$edit_item['custo_padrao'], 2, ',', '.') : '' ?>">
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!$no_checks): ?>
                <div>
                    <label>Visível na lista</label>
                    <input type="checkbox" name="visivel_na_lista" <?= !isset($edit_item) || !empty($edit_item['visivel_na_lista']) ? 'checked' : '' ?>>
                </div>
                <div>
                    <label>Ativo</label>
                    <input type="checkbox" name="ativo" <?= !isset($edit_item) || !empty($edit_item['ativo']) ? 'checked' : '' ?>>
                </div>
                <?php endif; ?>
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
                <label>Sinônimos (1 por linha)</label>
                <textarea class="form-input" name="sinonimos" rows="4"><?= h($edit_item['sinonimos'] ?? '') ?></textarea>
            </div>
            <div style="margin-top:1rem;">
                <label>Observações</label>
                <textarea class="form-input" name="observacoes" rows="3"><?= h($edit_item['observacoes'] ?? '') ?></textarea>
            </div>
            <div style="margin-top:1rem;">
                <button class="btn-primary" type="submit">Salvar</button>
            </div>
        </form>
    </div>

    <?php if (!$is_modal): ?>
    <div class="section-card">
        <h2>Lista de Insumos</h2>
        <form method="GET" style="margin-bottom:1rem;">
            <input type="hidden" name="page" value="logistica_insumos">
            <input class="form-input" name="q" placeholder="Buscar por nome ou sinônimo" value="<?= h($search) ?>">
        </form>
    <table class="table">
        <thead>
            <tr>
                <th>Foto</th>
                <th>Nome</th>
                <th>Tipologia</th>
                <th>Unidade</th>
                <th>Status</th>
                <?php if ($can_see_cost): ?>
                    <th>Custo</th>
                <?php endif; ?>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($insumos as $insumo): ?>
                <?php $thumb_url = gerarUrlPreviewMagalu($insumo['foto_chave_storage'] ?? null, $insumo['foto_url'] ?? null); ?>
                <tr>
                    <td>
                        <?php if (!empty($thumb_url)): ?>
                            <img src="<?= h($thumb_url) ?>" alt="Foto" style="max-height:44px;border-radius:6px;border:1px solid #e5e7eb;">
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td><?= h($insumo['nome_oficial']) ?></td>
                        <td><?= h($insumo['tipologia_nome'] ?? '') ?></td>
                        <td><?= h($insumo['unidade_medida'] ?? '') ?></td>
                        <td>
                            <span class="status-pill <?= $insumo['ativo'] ? 'status-ativo' : 'status-inativo' ?>">
                                <?= $insumo['ativo'] ? 'Ativo' : 'Inativo' ?>
                            </span>
                        </td>
                        <?php if ($can_see_cost): ?>
                            <td><?= $insumo['custo_padrao'] !== null ? 'R$ ' . number_format((float)$insumo['custo_padrao'], 2, ',', '.') : '-' ?></td>
                        <?php endif; ?>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$insumo['id'] ?>">
                                <button class="btn-secondary" type="submit">Ativar/Desativar</button>
                            </form>
                            <a class="btn-secondary" href="index.php?page=logistica_insumos&edit_id=<?= (int)$insumo['id'] ?>">Editar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($insumos)): ?>
                    <tr><td colspan="<?= $can_see_cost ? '7' : '6' ?>">Nenhum insumo encontrado.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
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

async function openBarcodeCamera() {
    const fileInput = document.getElementById('barcode_file');
    if (!fileInput) return;

    if (!('BarcodeDetector' in window)) {
        alert('Leitura por câmera não suportada neste navegador. Digite o código manualmente.');
        return;
    }
    fileInput.click();
}

document.getElementById('barcode_file')?.addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    try {
        const bitmap = await createImageBitmap(file);
        const detector = new BarcodeDetector({ formats: ['code_128', 'ean_13', 'ean_8', 'upc_a', 'upc_e', 'qr_code'] });
        const codes = await detector.detect(bitmap);
        if (codes.length) {
            const input = document.getElementById('barcode_input');
            if (input) input.value = codes[0].rawValue || '';
        } else {
            alert('Nenhum código detectado na imagem.');
        }
    } catch (err) {
        alert('Falha ao ler o código: ' + err.message);
    }
});

document.getElementById('tamanho_embalagem')?.addEventListener('blur', (e) => {
    const val = e.target.value;
    if (val === '') return;
    const num = Number(val);
    if (!Number.isNaN(num)) {
        e.target.value = num.toFixed(3);
    }
});

</script>

<?php if (!$is_modal) { endSidebar(); } ?>
