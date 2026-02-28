<?php
require_once __DIR__ . '/logistica_tz.php';
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
$saved_modal_payload = null;

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
                $saved_modal_payload = [
                    'id' => $id,
                    'nome_oficial' => $nome,
                    'barcode' => $dados[':barcode'],
                    'unidade_medida_padrao_id' => $unidade_id,
                    'unidade_nome' => $unidade_nome
                ];
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
                $new_id = (int)$pdo->lastInsertId();
                $messages[] = 'Insumo criado.';
                $saved_modal_payload = [
                    'id' => $new_id,
                    'nome_oficial' => $nome,
                    'barcode' => $dados[':barcode'],
                    'unidade_medida_padrao_id' => $unidade_id,
                    'unidade_nome' => $unidade_nome
                ];
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

if ($is_modal && $saved_modal_payload !== null && empty($errors) && !$duplicate_warning) {
    $payload_json = json_encode(
        $saved_modal_payload,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    echo "<script>
        try {
            if (window.parent && typeof window.parent.onCadastroInsumoSalvo === 'function') {
                window.parent.onCadastroInsumoSalvo({$payload_json});
            } else if (window.parent && typeof window.parent.closeCatalogModal === 'function') {
                window.parent.closeCatalogModal();
                if (window.parent.location && typeof window.parent.location.reload === 'function') {
                    window.parent.location.reload();
                }
            } else if (window.parent && typeof window.parent.closeCadastroInsumoModal === 'function') {
                window.parent.closeCadastroInsumoModal();
            }
        } catch (e) {}
    </script>";
    exit;
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
$prefill_barcode = trim((string)($_GET['barcode'] ?? ''));
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
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
<?php endif; ?>
* {
    box-sizing: border-box;
}
html,
body {
    margin: 0;
    padding: 0;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    color: #1e293b;
    background: #f8fafc;
}
<?php if ($is_modal): ?>
body {
    background: transparent;
}
.page-container {
    max-width: 100%;
    padding: 0.85rem;
}
.page-container h1 {
    display: none;
}
.section-card {
    margin-bottom: 0.9rem;
    padding: 1rem;
    box-shadow: none;
}
<?php endif; ?>
.page-container {
    max-width: 1300px;
    margin: 0 auto;
    padding: 1.5rem;
}
.page-container h1 {
    margin: 0 0 1rem;
    font-size: 1.65rem;
    font-weight: 700;
    color: #0f172a;
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
.form-grid > div {
    min-width: 0;
}
.span-2 { grid-column: span 2; }
.span-3 { grid-column: span 3; }
.section-card h2 {
    margin: 0 0 1rem;
    font-size: 1.2rem;
    color: #0f172a;
}
.field-label {
    display: block;
    margin: 0 0 0.35rem;
    font-size: 0.88rem;
    color: #334155;
    font-weight: 600;
}
.form-input {
    width: 100%;
    padding: 0.6rem 0.75rem;
    border: 1px solid #cbd5f5;
    border-radius: 8px;
    font-size: 0.95rem;
    color: #0f172a;
    background: #fff;
}
.form-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
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
.input-group input:focus {
    outline: none;
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
.check-item {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    font-size: 0.9rem;
    color: #334155;
}
.check-item input {
    width: 16px;
    height: 16px;
}
.form-block {
    margin-top: 1rem;
}
.form-actions {
    margin-top: 1rem;
    display: flex;
    justify-content: flex-start;
    gap: 0.6rem;
}
.camera-box {
    display: none;
    margin-top: 0.75rem;
    padding: 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #f8fafc;
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
    background: #fff;
    border-radius: 12px;
    width: min(480px, 94vw);
    box-shadow: 0 20px 50px rgba(0,0,0,0.25);
    padding: 1rem;
}
.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}
.modal-close {
    border: none;
    background: #e2e8f0;
    color: #0f172a;
    border-radius: 8px;
    width: 32px;
    height: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-weight: 700;
}
.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
    margin-top: 1rem;
}
.btn-scanner {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    color: #fff;
    box-shadow: 0 2px 4px rgba(5,150,105,0.3);
    white-space: nowrap;
}
.btn-scanner:hover {
    background: linear-gradient(135deg, #047857 0%, #065f46 100%);
    box-shadow: 0 4px 8px rgba(5,150,105,0.35);
}
.scanner-preview {
    width: 100%;
    aspect-ratio: 4 / 3;
    background: #0f172a;
    border-radius: 8px;
    overflow: hidden;
    position: relative;
    margin: 0.75rem 0;
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
}
.scanner-status {
    text-align: center;
    padding: 0.5rem;
    font-size: 0.85rem;
    color: #64748b;
    border-radius: 6px;
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
@media (max-width: 900px) {
    .span-2,
    .span-3 {
        grid-column: span 1;
    }
}
@media (max-width: 640px) {
    .page-container {
        padding: 1rem;
    }
    .section-card {
        padding: 1rem;
    }
    .scanner-manual {
        flex-wrap: wrap;
    }
    .scanner-manual button {
        width: 100%;
    }
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
                    <label class="field-label">Nome oficial *</label>
                    <input class="form-input" name="nome_oficial" required value="<?= h($edit_item['nome_oficial'] ?? '') ?>">
                </div>
                <div>
                    <label class="field-label">Tipologia</label>
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
                    <label class="field-label">Unidade de medida (padrão)</label>
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
                    <label class="field-label">Barcode</label>
                    <div class="upload-actions">
                        <input class="form-input" id="barcode_input" name="barcode" value="<?= h($edit_item['barcode'] ?? $prefill_barcode) ?>">
                        <button type="button" class="btn-primary btn-scanner" id="open-scanner">Ler câmera</button>
                    </div>
                </div>
                <?php if (!$no_checks): ?>
                <div>
                    <label class="field-label">Fracionável</label>
                    <label class="check-item">
                        <input type="checkbox" name="fracionavel" <?= !isset($edit_item) || !empty($edit_item['fracionavel']) ? 'checked' : '' ?>>
                        <span>Sim</span>
                    </label>
                </div>
                <?php endif; ?>
                <div>
                    <label class="field-label">Tamanho embalagem</label>
                    <input class="form-input" name="tamanho_embalagem" id="tamanho_embalagem" type="text" inputmode="decimal" value="<?= isset($edit_item['tamanho_embalagem']) && $edit_item['tamanho_embalagem'] !== null ? number_format((float)$edit_item['tamanho_embalagem'], 4, ',', '.') : '' ?>">
                </div>
                <div>
                    <label class="field-label">Unidade embalagem</label>
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
                    <label class="field-label">Custo padrão</label>
                    <div class="input-group">
                        <span>R$</span>
                        <input id="custo_padrao" name="custo_padrao" type="text" inputmode="decimal" value="<?= isset($edit_item['custo_padrao']) && $edit_item['custo_padrao'] !== null ? number_format((float)$edit_item['custo_padrao'], 2, ',', '.') : '' ?>">
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!$no_checks): ?>
                <div>
                    <label class="field-label">Visível na lista</label>
                    <label class="check-item">
                        <input type="checkbox" name="visivel_na_lista" <?= !isset($edit_item) || !empty($edit_item['visivel_na_lista']) ? 'checked' : '' ?>>
                        <span>Sim</span>
                    </label>
                </div>
                <div>
                    <label class="field-label">Ativo</label>
                    <label class="check-item">
                        <input type="checkbox" name="ativo" <?= !isset($edit_item) || !empty($edit_item['ativo']) ? 'checked' : '' ?>>
                        <span>Sim</span>
                    </label>
                </div>
                <?php endif; ?>
            </div>
            <div class="form-block">
                <label class="field-label">Foto</label>
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
            <div class="form-block">
                <label class="field-label">Sinônimos (1 por linha)</label>
                <textarea class="form-input" name="sinonimos" rows="4"><?= h($edit_item['sinonimos'] ?? '') ?></textarea>
            </div>
            <div class="form-block">
                <label class="field-label">Observações</label>
                <textarea class="form-input" name="observacoes" rows="3"><?= h($edit_item['observacoes'] ?? '') ?></textarea>
            </div>
            <div class="form-actions">
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

<div class="modal-overlay" id="modal-scanner">
    <div class="modal">
        <div class="modal-header">
            <strong>Leitor de Código de Barras</strong>
            <button class="modal-close" type="button" id="close-scanner" title="Fechar">X</button>
        </div>
        <div class="scanner-preview" id="scanner-preview">
            <video autoplay playsinline muted></video>
            <div class="scanner-overlay">
                <div class="scanner-frame">
                    <div class="scanner-line"></div>
                </div>
            </div>
        </div>
        <div class="scanner-status" id="scanner-status">Posicione o código de barras na área destacada</div>
        <div class="scanner-manual">
            <input class="form-input" type="text" id="barcode-manual" placeholder="Ou digite o código manualmente...">
            <button class="btn-primary" type="button" id="barcode-search">Usar</button>
        </div>
        <div class="modal-footer">
            <button class="btn-secondary" type="button" id="cancel-scanner">Fechar</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@ericblade/quagga2@1.8.4/dist/quagga.min.js"></script>
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

let scannerActive = false;
let scannerDetectedHandler = null;
let lastScannedCode = '';

function setScannerStatus(message, type = '') {
    const statusEl = document.getElementById('scanner-status');
    if (!statusEl) return;
    statusEl.className = type ? `scanner-status ${type}` : 'scanner-status';
    statusEl.textContent = message;
}

function applyBarcodeValue(code) {
    const normalized = (code || '').trim();
    if (!normalized) return false;
    const barcodeInput = document.getElementById('barcode_input');
    if (!barcodeInput) return false;
    barcodeInput.value = normalized;
    barcodeInput.dispatchEvent(new Event('change'));
    return true;
}

function handleBarcodeResult(code) {
    const normalized = (code || '').trim();
    if (!normalized) return;
    if (normalized === lastScannedCode) return;
    lastScannedCode = normalized;

    if (applyBarcodeValue(normalized)) {
        setScannerStatus(`Código lido: ${normalized}`, 'success');
        setTimeout(() => {
            closeScannerModal();
            const barcodeInput = document.getElementById('barcode_input');
            if (barcodeInput) {
                barcodeInput.focus();
                barcodeInput.select();
            }
        }, 650);
    } else {
        setScannerStatus('Não foi possível preencher o campo de código.', 'error');
    }
}

function startScanner() {
    if (typeof Quagga === 'undefined') {
        setScannerStatus('Scanner indisponível neste navegador. Use o campo manual.', 'error');
        return;
    }

    const previewEl = document.getElementById('scanner-preview');
    if (!previewEl) return;
    setScannerStatus('Iniciando câmera...', 'scanning');

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
            console.error('Erro ao iniciar scanner:', err);
            setScannerStatus('Erro ao acessar câmera. Use o campo manual.', 'error');
            return;
        }

        scannerActive = true;
        Quagga.start();
        setScannerStatus('Posicione o código de barras na área destacada');
    });

    if (scannerDetectedHandler) {
        Quagga.offDetected(scannerDetectedHandler);
    }
    scannerDetectedHandler = function(result) {
        const code = result?.codeResult?.code || '';
        if (code) {
            handleBarcodeResult(code);
        }
    };
    Quagga.onDetected(scannerDetectedHandler);
}

function stopScanner() {
    if (typeof Quagga !== 'undefined' && scannerDetectedHandler) {
        Quagga.offDetected(scannerDetectedHandler);
        scannerDetectedHandler = null;
    }
    if (scannerActive && typeof Quagga !== 'undefined') {
        try {
            Quagga.stop();
        } catch (err) {
            console.error('Falha ao parar scanner:', err);
        }
        scannerActive = false;
    }
    lastScannedCode = '';
}

function openScannerModal() {
    const modal = document.getElementById('modal-scanner');
    if (!modal) return;
    modal.style.display = 'flex';
    const manualInput = document.getElementById('barcode-manual');
    if (manualInput) {
        manualInput.value = '';
    }
    setScannerStatus('Posicione o código de barras na área destacada');
    startScanner();
}

function closeScannerModal() {
    stopScanner();
    const modal = document.getElementById('modal-scanner');
    if (modal) {
        modal.style.display = 'none';
    }
}

document.getElementById('open-scanner')?.addEventListener('click', openScannerModal);
document.getElementById('close-scanner')?.addEventListener('click', closeScannerModal);
document.getElementById('cancel-scanner')?.addEventListener('click', closeScannerModal);
document.getElementById('modal-scanner')?.addEventListener('click', (e) => {
    if (e.target.id === 'modal-scanner') {
        closeScannerModal();
    }
});

document.getElementById('barcode-search')?.addEventListener('click', () => {
    const manualInput = document.getElementById('barcode-manual');
    const code = manualInput ? manualInput.value.trim() : '';
    if (!code) {
        setScannerStatus('Digite um código para continuar.', 'error');
        return;
    }
    handleBarcodeResult(code);
});

document.getElementById('barcode-manual')?.addEventListener('keypress', (e) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const code = e.target.value.trim();
    if (!code) {
        setScannerStatus('Digite um código para continuar.', 'error');
        return;
    }
    handleBarcodeResult(code);
});

window.addEventListener('beforeunload', () => {
    try {
        stopScanner();
    } catch (e) {
        // no-op
    }
});

function parseLocaleDecimal(value) {
    const raw = (value || '').trim();
    if (!raw) return null;

    const cleaned = raw.replace(/[^0-9,.\-]/g, '');
    if (!cleaned) return null;

    let normalized = cleaned;
    if (normalized.includes(',') && normalized.includes('.')) {
        normalized = normalized.replace(/\./g, '').replace(',', '.');
    } else {
        normalized = normalized.replace(',', '.');
    }

    const parsed = Number(normalized);
    return Number.isFinite(parsed) ? parsed : null;
}

function formatPtBrDecimal(value, decimals) {
    return value.toLocaleString('pt-BR', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
}

function attachSmartDecimalMask(input, decimals) {
    if (!input) return;

    const formatFromDigits = (rawValue) => {
        const digits = rawValue.replace(/\D/g, '');
        if (!digits) {
            input.value = '';
            return;
        }
        const scaled = Number(digits) / Math.pow(10, decimals);
        input.value = formatPtBrDecimal(scaled, decimals);
    };

    input.addEventListener('input', () => {
        formatFromDigits(input.value);
    });

    input.addEventListener('blur', () => {
        const parsed = parseLocaleDecimal(input.value);
        if (parsed === null) {
            input.value = '';
            return;
        }
        input.value = formatPtBrDecimal(parsed, decimals);
    });

    // Garante consistência quando o formulário abre em modo de edição.
    const parsedInitial = parseLocaleDecimal(input.value);
    if (parsedInitial !== null) {
        input.value = formatPtBrDecimal(parsedInitial, decimals);
    }
}

attachSmartDecimalMask(document.getElementById('custo_padrao'), 2);
attachSmartDecimalMask(document.getElementById('tamanho_embalagem'), 4);

</script>

<?php if (!$is_modal) { endSidebar(); } ?>
