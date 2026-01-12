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
$can_see_cost = !empty($_SESSION['perm_superadmin']) || !empty($_SESSION['perm_logistico_financeiro']);

if (!$can_manage) {
    http_response_code(403);
    echo '<div class="alert-error">Acesso negado.</div>';
    exit;
}

$errors = [];
$messages = [];
$redirect_id = 0;

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

function parse_decimal($value): ?float {
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }
    $normalized = preg_replace('/[^0-9,\\.]/', '', $raw);
    $normalized = str_replace(['.', ','], ['', '.'], $normalized);
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

function calcular_custo_receita_total(int $receita_id, array $componentes_by_receita, array $insumo_cost, array $receita_yield, array &$memo, array &$stack): ?float {
    if (isset($memo[$receita_id])) {
        return $memo[$receita_id];
    }
    if (isset($stack[$receita_id])) {
        return null;
    }
    $stack[$receita_id] = true;

    $total = 0.0;
    $componentes = $componentes_by_receita[$receita_id] ?? [];
    foreach ($componentes as $comp) {
        $peso_bruto = (float)($comp['peso_bruto'] ?? 0);
        if ($peso_bruto <= 0) {
            $peso_liquido = (float)($comp['peso_liquido'] ?? 0);
            $fator = (float)($comp['fator_correcao'] ?? 1);
            if ($fator <= 0) {
                $fator = 1.0;
            }
            $peso_bruto = $peso_liquido * $fator;
        }
        if ($peso_bruto <= 0) {
            continue;
        }
        if ($comp['item_tipo'] === 'insumo') {
            $custo_unit = isset($insumo_cost[$comp['item_id']]) ? (float)$insumo_cost[$comp['item_id']] : 0.0;
            $total += $peso_bruto * $custo_unit;
        } elseif ($comp['item_tipo'] === 'receita') {
            $sub_total = calcular_custo_receita_total((int)$comp['item_id'], $componentes_by_receita, $insumo_cost, $receita_yield, $memo, $stack);
            if ($sub_total === null) {
                $total += 0.0;
                continue;
            }
            $yield = (int)($receita_yield[$comp['item_id']] ?? 1);
            if ($yield <= 0) {
                $yield = 1;
            }
            $custo_unit = $sub_total / $yield;
            $total += $peso_bruto * $custo_unit;
        }
    }

    unset($stack[$receita_id]);
    $memo[$receita_id] = $total;
    return $total;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim((string)($_POST['nome'] ?? ''));
        if ($nome === '') {
            $errors[] = 'Nome é obrigatório.';
        } else {
            $foto_url = trim((string)($_POST['foto_url'] ?? '')) ?: null;
            $foto_chave = trim((string)($_POST['foto_chave_storage'] ?? '')) ?: null;

            if (!empty($_FILES['foto_file']['tmp_name']) && is_uploaded_file($_FILES['foto_file']['tmp_name'])) {
                try {
                    $uploader = new MagaluUpload();
                    $result = $uploader->upload($_FILES['foto_file'], 'logistica/receitas');
                    $foto_url = $result['url'] ?? $foto_url;
                    $foto_chave = $result['chave_storage'] ?? $foto_chave;
                } catch (Throwable $e) {
                    $errors[] = 'Falha ao enviar foto: ' . $e->getMessage();
                }
            } elseif ($id > 0 && empty($foto_url)) {
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
                ':unidade_medida_padrao_id' => !empty($_POST['unidade_medida_padrao_id']) ? (int)$_POST['unidade_medida_padrao_id'] : null,
                ':ativo' => $ativo,
                ':visivel_na_lista' => $visivel,
                ':rendimento_base_pessoas' => (int)($_POST['rendimento_base_pessoas'] ?? 1)
            ];

            $componentes_post = $_POST['componentes'] ?? [];
            $componentes_norm = [];

            $valid_insumos = $pdo->query("SELECT id FROM logistica_insumos")->fetchAll(PDO::FETCH_COLUMN);
            $valid_receitas = $pdo->query("SELECT id FROM logistica_receitas")->fetchAll(PDO::FETCH_COLUMN);
            $valid_insumos = array_flip(array_map('intval', $valid_insumos));
            $valid_receitas = array_flip(array_map('intval', $valid_receitas));

            foreach ($componentes_post as $comp) {
                $tipo = $comp['item_tipo'] ?? '';
                $item_id = (int)($comp['item_id'] ?? 0);
                $peso_liquido = parse_decimal($comp['peso_liquido'] ?? null);
                $fator = parse_decimal($comp['fator_correcao'] ?? null);
                $unidade_id = !empty($comp['unidade_medida_id']) ? (int)$comp['unidade_medida_id'] : null;

                if ($tipo !== 'insumo' && $tipo !== 'receita') {
                    continue;
                }
                if ($item_id <= 0) {
                    continue;
                }
                if ($tipo === 'receita' && $id > 0 && $item_id === $id) {
                    $errors[] = 'Receita não pode referenciar ela mesma.';
                    continue;
                }
                if ($peso_liquido === null || $peso_liquido <= 0) {
                    continue;
                }
                if ($tipo === 'insumo' && !isset($valid_insumos[$item_id])) {
                    continue;
                }
                if ($tipo === 'receita' && !isset($valid_receitas[$item_id])) {
                    continue;
                }

                if ($fator === null || $fator <= 0) {
                    $fator = 1.0;
                }
                $peso_bruto = $peso_liquido * $fator;

                $componentes_norm[] = [
                    'item_tipo' => $tipo,
                    'item_id' => $item_id,
                    'unidade_medida_id' => $unidade_id,
                    'peso_liquido' => $peso_liquido,
                    'fator_correcao' => $fator,
                    'peso_bruto' => $peso_bruto
                ];
            }

            if (empty($errors)) {
                try {
                    $pdo->beginTransaction();
                    if ($id > 0) {
                        $sql = "
                    UPDATE logistica_receitas
                    SET nome = :nome,
                        foto_url = :foto_url,
                        foto_chave_storage = :foto_chave_storage,
                        tipologia_receita_id = :tipologia_receita_id,
                        unidade_medida_padrao_id = :unidade_medida_padrao_id,
                        ativo = :ativo,
                        visivel_na_lista = :visivel_na_lista,
                        rendimento_base_pessoas = :rendimento_base_pessoas,
                        updated_at = NOW()
                    WHERE id = :id
                        ";
                        $dados[':id'] = $id;
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($dados);
                        $receita_id = $id;
                        $messages[] = 'Receita atualizada.';
                        $redirect_id = $id;
                    } else {
                        $sql = "
                    INSERT INTO logistica_receitas
                    (nome, foto_url, foto_chave_storage, tipologia_receita_id, unidade_medida_padrao_id, ativo, visivel_na_lista, rendimento_base_pessoas)
                    VALUES
                    (:nome, :foto_url, :foto_chave_storage, :tipologia_receita_id, :unidade_medida_padrao_id, :ativo, :visivel_na_lista, :rendimento_base_pessoas)
                ";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($dados);
                        $receita_id = (int)$pdo->lastInsertId();
                        $messages[] = 'Receita criada.';
                        $redirect_id = $receita_id;
                    }

                    if ($receita_id > 0) {
                        $pdo->prepare("DELETE FROM logistica_receita_componentes WHERE receita_id = :id")
                            ->execute([':id' => $receita_id]);

                        if (!empty($componentes_norm)) {
                            $stmt = $pdo->prepare("
                                INSERT INTO logistica_receita_componentes
                                (receita_id, item_tipo, item_id, unidade_medida_id, peso_liquido, fator_correcao, peso_bruto, created_at, updated_at)
                                VALUES
                                (:receita_id, :item_tipo, :item_id, :unidade_medida_id, :peso_liquido, :fator_correcao, :peso_bruto, NOW(), NOW())
                            ");
                            foreach ($componentes_norm as $comp) {
                                if ($comp['item_tipo'] === 'receita' && $comp['item_id'] === $receita_id) {
                                    throw new RuntimeException('Receita não pode referenciar ela mesma.');
                                }
                                $stmt->execute([
                                    ':receita_id' => $receita_id,
                                    ':item_tipo' => $comp['item_tipo'],
                                    ':item_id' => $comp['item_id'],
                                    ':unidade_medida_id' => $comp['unidade_medida_id'],
                                    ':peso_liquido' => $comp['peso_liquido'],
                                    ':fator_correcao' => $comp['fator_correcao'],
                                    ':peso_bruto' => $comp['peso_bruto']
                                ]);
                            }
                        }
                    }

                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $errors[] = 'Erro ao salvar ficha técnica: ' . $e->getMessage();
                    $messages = [];
                }
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

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM logistica_receitas WHERE id = :id")
                ->execute([':id' => $id]);
            $messages[] = 'Receita excluída.';
        }
    }

    // componentes são salvos junto com a receita (tabela completa)
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
$unidades_medida = ensure_unidades_medida($pdo);
$unidades_medida_map = [];
foreach ($unidades_medida as $un) {
    $unidades_medida_map[(int)$un['id']] = $un['nome'];
}

$insumos_all = $pdo->query("SELECT id, nome_oficial, custo_padrao, ativo, unidade_medida_padrao_id FROM logistica_insumos ORDER BY nome_oficial")->fetchAll(PDO::FETCH_ASSOC);
$receitas_all = $pdo->query("SELECT id, nome, rendimento_base_pessoas, ativo, unidade_medida_padrao_id FROM logistica_receitas ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
$componentes_all = $pdo->query("SELECT * FROM logistica_receita_componentes ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$insumos_select = array_values(array_filter($insumos_all, static fn($i) => !isset($i['ativo']) || $i['ativo']));
$receitas_select = array_values(array_filter($receitas_all, static fn($r) => !isset($r['ativo']) || $r['ativo']));

$insumo_nome = [];
$insumo_cost = [];
foreach ($insumos_all as $ins) {
    $insumo_nome[(int)$ins['id']] = $ins['nome_oficial'];
    if ($ins['custo_padrao'] !== null) {
        $insumo_cost[(int)$ins['id']] = (float)$ins['custo_padrao'];
    }
}

$receita_nome = [];
$receita_yield = [];
foreach ($receitas_all as $rec) {
    $receita_nome[(int)$rec['id']] = $rec['nome'];
    $receita_yield[(int)$rec['id']] = (int)($rec['rendimento_base_pessoas'] ?? 1);
}

$componentes_by_receita = [];
foreach ($componentes_all as $comp) {
    $rid = (int)$comp['receita_id'];
    if (!isset($componentes_by_receita[$rid])) {
        $componentes_by_receita[$rid] = [];
    }
    $componentes_by_receita[$rid][] = $comp;
}

$memo_cost = [];
$stack_cost = [];
$receita_cost_total = [];
foreach (array_keys($receita_nome) as $rid) {
    $receita_cost_total[$rid] = calcular_custo_receita_total((int)$rid, $componentes_by_receita, $insumo_cost, $receita_yield, $memo_cost, $stack_cost);
}

$edit_id = (int)($_GET['edit_id'] ?? 0);
$edit_item = null;
$componentes = [];
if ($edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM logistica_receitas WHERE id = :id");
    $stmt->execute([':id' => $edit_id]);
    $edit_item = $stmt->fetch(PDO::FETCH_ASSOC);

    $componentes = $componentes_by_receita[$edit_id] ?? [];
}
if ($edit_id > 0 && !$edit_item) {
    $errors[] = 'Receita não encontrada.';
}
$edit_foto_url = null;
if ($edit_item) {
    $edit_foto_url = gerarUrlPreviewMagalu($edit_item['foto_chave_storage'] ?? null, $edit_item['foto_url'] ?? null);
}

$fichas = [];
foreach ($receitas as $rec) {
    $rid = (int)$rec['id'];
    $foto_modal = gerarUrlPreviewMagalu($rec['foto_chave_storage'] ?? null, $rec['foto_url'] ?? null);
    $linhas = [];
    foreach ($componentes_by_receita[$rid] ?? [] as $comp) {
        $item_tipo = $comp['item_tipo'] ?? 'insumo';
        $item_id = (int)($comp['item_id'] ?? 0);
        $item_nome = $item_tipo === 'receita'
            ? ($receita_nome[$item_id] ?? ('Receita #' . $item_id))
            : ($insumo_nome[$item_id] ?? ('Insumo #' . $item_id));
        $peso_liquido = (float)($comp['peso_liquido'] ?? 0);
        $fator = (float)($comp['fator_correcao'] ?? 1);
        if ($fator <= 0) {
            $fator = 1.0;
        }
        $peso_bruto = (float)($comp['peso_bruto'] ?? ($peso_liquido * $fator));

        $custo_unit = null;
        $custo_total = null;
        if ($can_see_cost) {
            if ($item_tipo === 'insumo') {
                $custo_unit = $insumo_cost[$item_id] ?? null;
            } else {
                $sub_total = $receita_cost_total[$item_id] ?? null;
                if ($sub_total !== null) {
                    $yield = (int)($receita_yield[$item_id] ?? 1);
                    if ($yield <= 0) {
                        $yield = 1;
                    }
                    $custo_unit = $sub_total / $yield;
                }
            }
            if ($custo_unit !== null) {
                $custo_total = $peso_bruto * $custo_unit;
            }
        }

        $linhas[] = [
            'item' => $item_nome,
            'item_tipo' => $item_tipo,
            'unidade_medida' => $unidades_medida_map[(int)($comp['unidade_medida_id'] ?? 0)] ?? '',
            'peso_liquido' => $peso_liquido,
            'fator' => $fator,
            'peso_bruto' => $peso_bruto,
            'custo_unit' => $custo_unit,
            'custo_total' => $custo_total
        ];
    }
    $fichas[$rid] = [
        'id' => $rid,
        'nome' => $rec['nome'] ?? '',
        'tipologia' => $rec['tipologia_nome'] ?? '',
        'rendimento' => (int)($rec['rendimento_base_pessoas'] ?? 1),
        'updated_at' => $rec['updated_at'] ?? '',
        'foto' => $foto_modal,
        'linhas' => $linhas
    ];
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
.item-option {
    display: block;
    width: 100%;
    text-align: left;
    padding: 0.5rem 0.75rem;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: #f8fafc;
    margin-bottom: 0.5rem;
    cursor: pointer;
}
.item-option.selected {
    border-color: #2563eb;
    background: #dbeafe;
}
.link-muted {
    font-size: 0.85rem;
    color: #64748b;
    cursor: pointer;
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
    width: min(1100px, 92vw);
    max-height: 90vh;
    overflow: auto;
    box-shadow: 0 20px 50px rgba(0,0,0,0.25);
    padding: 1.25rem;
}
.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
}
.modal-title {
    font-size: 1.3rem;
    font-weight: 700;
    color: #0f172a;
}
.modal-close {
    border: none;
    background: #e2e8f0;
    color: #0f172a;
    border-radius: 8px;
    padding: 0.4rem 0.7rem;
    cursor: pointer;
}
.ficha-header {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 0.75rem;
    margin-bottom: 1rem;
}
.ficha-header .box {
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 0.6rem 0.75rem;
}
.ficha-photo img {
    max-height: 120px;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
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
                    <label>Unidade padrão</label>
                    <select class="form-input" name="unidade_medida_padrao_id" id="unidade_medida_padrao_id">
                        <option value="">Selecione...</option>
                        <?php if (empty($unidades_medida)): ?>
                            <option value="" disabled>Nenhuma unidade cadastrada</option>
                        <?php endif; ?>
                        <?php foreach ($unidades_medida as $un): ?>
                            <option value="<?= (int)$un['id'] ?>" <?= (int)($edit_item['unidade_medida_padrao_id'] ?? 0) === (int)$un['id'] ? 'selected' : '' ?>>
                                <?= h($un['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
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
                        <button type="button" class="btn-secondary" onclick="uploadFoto()">Upload Magalu</button>
                    </div>
                    <div style="margin-top:0.5rem;">
                        <input type="hidden" name="foto_chave_storage" id="foto_chave_storage" value="<?= h($edit_item['foto_chave_storage'] ?? '') ?>">
                        <input class="form-input" name="foto_url" id="foto_url" placeholder="Cole a URL da foto" value="<?= h($edit_item['foto_url'] ?? '') ?>">
                    </div>
                </div>
            </div>
            <div style="margin-top:1rem;">
                <h3 style="margin:0 0 0.75rem 0;">Tabela de Insumos (Ficha Técnica)</h3>
                <div class="link-muted" style="margin-bottom:0.75rem;">
                    Componentes podem ser insumos ou sub-receitas. Peso bruto = peso líquido × fator.
                </div>
                <table class="table ficha-table">
                    <thead>
                        <tr>
                            <th>Itens</th>
                            <th>Peso líquido</th>
                            <th>Unidade</th>
                            <th>Fator de correção</th>
                            <th>Peso bruto</th>
                            <?php if ($can_see_cost): ?>
                                <th>Valor unidade</th>
                                <th>Valor total</th>
                            <?php endif; ?>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="componentes-body">
                        <?php
                        $row_index = 0;
                        $componentes_render = $componentes ?: [];
                        if (empty($componentes_render)) {
                            $componentes_render[] = [
                                'item_tipo' => 'insumo',
                                'item_id' => 0,
                                'unidade_medida_id' => null,
                                'peso_liquido' => '',
                                'fator_correcao' => '1',
                                'peso_bruto' => ''
                            ];
                        }
                        foreach ($componentes_render as $comp):
                            $tipo = $comp['item_tipo'] ?? 'insumo';
                            $item_id = (int)($comp['item_id'] ?? 0);
                            $item_nome = $tipo === 'receita'
                                ? ($receita_nome[$item_id] ?? '')
                                : ($insumo_nome[$item_id] ?? '');
                            $unidade_id = (int)($comp['unidade_medida_id'] ?? 0);
                            $peso_liquido = $comp['peso_liquido'] ?? '';
                            $fator_correcao = $comp['fator_correcao'] ?? '';
                            $peso_bruto = $comp['peso_bruto'] ?? '';
                        ?>
                            <tr class="componente-row" data-index="<?= $row_index ?>">
                                <td>
                                    <input class="form-input item-nome" name="componentes[<?= $row_index ?>][item_nome]" readonly value="<?= h($item_nome) ?>">
                                    <input type="hidden" class="item-tipo" name="componentes[<?= $row_index ?>][item_tipo]" value="<?= h($tipo) ?>">
                                    <input type="hidden" class="item-id" name="componentes[<?= $row_index ?>][item_id]" value="<?= (int)$item_id ?>">
                                    <button type="button" class="btn-secondary abrir-modal">🔍</button>
                                </td>
                                <td><input class="form-input peso-liquido" name="componentes[<?= $row_index ?>][peso_liquido]" type="text" inputmode="decimal" value="<?= h($peso_liquido) ?>"></td>
                                <td>
                                    <select class="form-input unidade-medida" name="componentes[<?= $row_index ?>][unidade_medida_id]">
                                        <option value="">Selecione...</option>
                                        <?php foreach ($unidades_medida as $un): ?>
                                            <option value="<?= (int)$un['id'] ?>" <?= $unidade_id === (int)$un['id'] ? 'selected' : '' ?>>
                                                <?= h($un['nome']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input class="form-input fator-correcao" name="componentes[<?= $row_index ?>][fator_correcao]" type="text" inputmode="decimal" value="<?= h($fator_correcao) ?>"></td>
                                <td><input class="form-input peso-bruto" name="componentes[<?= $row_index ?>][peso_bruto]" readonly value="<?= h($peso_bruto) ?>"></td>
                                <?php if ($can_see_cost): ?>
                                    <td><input class="form-input custo-unit" readonly value=""></td>
                                    <td><input class="form-input custo-total" readonly value=""></td>
                                <?php endif; ?>
                                <td><button type="button" class="btn-secondary remover-linha">Remover</button></td>
                            </tr>
                        <?php
                            $row_index++;
                        endforeach;
                        ?>
                    </tbody>
                    <tfoot>
                        <?php if ($can_see_cost): ?>
                            <tr>
                                <td colspan="5" style="text-align:right;font-weight:700;">Total</td>
                                <td></td>
                                <td><input class="form-input" id="total-receita" readonly value="R$ 0,00"></td>
                                <td></td>
                            </tr>
                            <tr>
                                <td colspan="6" style="text-align:right;font-weight:700;">Total por porção</td>
                                <td><input class="form-input" id="total-por-porcao" readonly value="R$ 0,00"></td>
                                <td></td>
                            </tr>
                        <?php endif; ?>
                    </tfoot>
                </table>
                <div style="margin-top:0.75rem;">
                    <button type="button" class="btn-secondary" id="add-linha">Adicionar linha</button>
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
                    <?php $ficha_json = isset($fichas[(int)$rec['id']]) ? htmlspecialchars(json_encode($fichas[(int)$rec['id']], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') : ''; ?>
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
                            <button type="button" class="btn-secondary ver-ficha" data-ficha="<?= $ficha_json ?>">Ver ficha</button>
                            <a class="btn-secondary" href="index.php?page=logistica_receitas&edit_id=<?= (int)$rec['id'] ?>">Editar</a>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Excluir esta receita?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$rec['id'] ?>">
                                <button class="btn-secondary" type="submit">Excluir</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($receitas)): ?>
                    <tr><td colspan="6">Nenhuma receita encontrada.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="modal-overlay" id="modal-ficha">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-title">Ficha Técnica</div>
                <button class="modal-close" type="button" onclick="closeFicha()">Fechar</button>
            </div>
            <div id="ficha-conteudo"></div>
        </div>
    </div>

    <div class="modal-overlay" id="modal-item">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-title">Selecionar item</div>
                <button class="modal-close" type="button" onclick="closeItemModal()">Fechar</button>
            </div>
            <div class="upload-actions" style="margin-bottom:0.75rem;">
                <button type="button" class="btn-secondary" id="tab-insumos">Insumos</button>
                <button type="button" class="btn-secondary" id="tab-receitas">Receitas</button>
                <input class="form-input" id="item-search" placeholder="Buscar...">
            </div>
            <div id="item-list" style="max-height:50vh;overflow:auto;border:1px solid #e5e7eb;border-radius:8px;padding:0.5rem;"></div>
            <div style="margin-top:0.75rem;">
                <button class="btn-primary" type="button" id="confirm-item">Selecionar</button>
            </div>
        </div>
    </div>
</div>

<script>
const CAN_SEE_COST = <?= $can_see_cost ? 'true' : 'false' ?>;
const CURRENT_RECIPE_ID = <?= (int)$edit_id ?>;
const INSUMOS = <?= json_encode(array_map(fn($i) => ['id' => (int)$i['id'], 'nome' => $i['nome_oficial'], 'unidade_padrao' => (int)($i['unidade_medida_padrao_id'] ?? 0), 'sinonimos' => $i['sinonimos'] ?? ''], $insumos_select), JSON_UNESCAPED_UNICODE) ?>;
const RECEITAS = <?= json_encode(array_map(fn($r) => ['id' => (int)$r['id'], 'nome' => $r['nome'], 'unidade_padrao' => (int)($r['unidade_medida_padrao_id'] ?? 0)], $receitas_select), JSON_UNESCAPED_UNICODE) ?>;
const UNIDADES = <?= json_encode($unidades_medida, JSON_UNESCAPED_UNICODE) ?>;
const COST_INSUMO = <?= json_encode($insumo_cost, JSON_UNESCAPED_UNICODE) ?>;
const COST_RECEITA = <?= json_encode(array_map(function($total) use ($receita_yield) {
    if ($total === null) { return null; }
    return $total;
}, $receita_cost_total), JSON_UNESCAPED_UNICODE) ?>;
const YIELD_RECEITA = <?= json_encode($receita_yield, JSON_UNESCAPED_UNICODE) ?>;

function parseNumber(value) {
    if (value === null || value === undefined) return 0;
    const raw = String(value).trim();
    if (raw === '') return 0;
    const normalized = raw.replace(/\./g, '').replace(',', '.');
    const num = parseFloat(normalized);
    return Number.isNaN(num) ? 0 : num;
}

function formatNumber(value, decimals = 3) {
    if (value === null || value === undefined || Number.isNaN(value)) return '-';
    return Number(value).toFixed(decimals).replace('.', ',');
}

function formatCurrency(value) {
    if (value === null || value === undefined || Number.isNaN(value)) return '-';
    return 'R$ ' + Number(value).toFixed(2).replace('.', ',');
}

function calcRow(row) {
    const pesoLiquidoInput = row.querySelector('.peso-liquido');
    const fatorInput = row.querySelector('.fator-correcao');
    const pesoBrutoInput = row.querySelector('.peso-bruto');
    const tipo = row.querySelector('.item-tipo').value;
    const itemId = parseInt(row.querySelector('.item-id').value || '0', 10);

    const pesoLiquido = parseNumber(pesoLiquidoInput.value);
    let fator = parseNumber(fatorInput.value);
    if (!fator || fator <= 0) {
        fator = 1;
        if (fatorInput.value.trim() === '') {
            fatorInput.value = '1';
        }
    }
    const pesoBruto = pesoLiquido * fator;
    if (pesoBrutoInput) {
        pesoBrutoInput.value = pesoLiquido > 0 ? formatNumber(pesoBruto, 3) : '';
    }

    if (!CAN_SEE_COST) return;

    let custoUnit = null;
    if (tipo === 'insumo' && itemId > 0) {
        custoUnit = COST_INSUMO[itemId] ?? null;
    } else if (tipo === 'receita' && itemId > 0) {
        const total = COST_RECEITA[itemId];
        const yieldBase = YIELD_RECEITA[itemId] || 1;
        if (total !== null && total !== undefined) {
            custoUnit = Number(total) / Math.max(1, Number(yieldBase));
        }
    }
    const custoTotal = custoUnit !== null ? pesoBruto * custoUnit : null;
    const custoUnitInput = row.querySelector('.custo-unit');
    const custoTotalInput = row.querySelector('.custo-total');
    if (custoUnitInput) custoUnitInput.value = formatCurrency(custoUnit);
    if (custoTotalInput) custoTotalInput.value = formatCurrency(custoTotal);
    row.dataset.custoTotal = custoTotal !== null ? String(custoTotal) : '0';
}

function updateTotalReceita() {
    if (!CAN_SEE_COST) return;
    let total = 0;
    document.querySelectorAll('.componente-row').forEach(row => {
        const raw = row.dataset.custoTotal || '0';
        const num = parseFloat(raw);
        if (!Number.isNaN(num)) total += num;
    });
    const totalEl = document.getElementById('total-receita');
    if (totalEl) totalEl.value = formatCurrency(total);
    const rendimento = parseInt(document.querySelector('[name=\"rendimento_base_pessoas\"]')?.value || '1', 10) || 1;
    const totalPorPorcao = total / Math.max(1, rendimento);
    const totalPorcaoEl = document.getElementById('total-por-porcao');
    if (totalPorcaoEl) totalPorcaoEl.value = formatCurrency(totalPorPorcao);
}

function attachRowEvents(row) {
    row.querySelector('.peso-liquido').addEventListener('input', () => {
        calcRow(row);
        updateTotalReceita();
    });
    row.querySelector('.fator-correcao').addEventListener('input', () => {
        calcRow(row);
        updateTotalReceita();
    });
    row.querySelector('.remover-linha').addEventListener('click', () => {
        row.remove();
        updateTotalReceita();
    });
    row.querySelector('.abrir-modal').addEventListener('click', () => openItemModal(row));
    calcRow(row);
}

document.querySelectorAll('.componente-row').forEach(row => attachRowEvents(row));
updateTotalReceita();
document.querySelector('[name=\"rendimento_base_pessoas\"]')?.addEventListener('input', updateTotalReceita);

let nextIndex = document.querySelectorAll('.componente-row').length;
document.getElementById('add-linha')?.addEventListener('click', () => {
    const tbody = document.getElementById('componentes-body');
    if (!tbody) return;
    const row = document.createElement('tr');
    row.className = 'componente-row';
    row.dataset.index = nextIndex;
    row.innerHTML = `
        <td>
            <input class=\"form-input item-nome\" name=\"componentes[${nextIndex}][item_nome]\" readonly>
            <input type=\"hidden\" class=\"item-tipo\" name=\"componentes[${nextIndex}][item_tipo]\" value=\"\">
            <input type=\"hidden\" class=\"item-id\" name=\"componentes[${nextIndex}][item_id]\" value=\"\">
            <button type=\"button\" class=\"btn-secondary abrir-modal\">🔍</button>
        </td>
        <td><input class=\"form-input peso-liquido\" name=\"componentes[${nextIndex}][peso_liquido]\" type=\"text\" inputmode=\"decimal\"></td>
        <td>
            <select class=\"form-input unidade-medida\" name=\"componentes[${nextIndex}][unidade_medida_id]\">
                <option value=\"\">Selecione...</option>
                ${UNIDADES.map(un => `<option value=\"${un.id}\">${un.nome}</option>`).join('')}
            </select>
        </td>
        <td><input class=\"form-input fator-correcao\" name=\"componentes[${nextIndex}][fator_correcao]\" type=\"text\" inputmode=\"decimal\" value=\"1\"></td>
        <td><input class=\"form-input peso-bruto\" name=\"componentes[${nextIndex}][peso_bruto]\" readonly></td>
        ${CAN_SEE_COST ? '<td><input class=\"form-input custo-unit\" readonly value=\"\"></td><td><input class=\"form-input custo-total\" readonly value=\"\"></td>' : ''}
        <td><button type=\"button\" class=\"btn-secondary remover-linha\">Remover</button></td>
    `;
    tbody.appendChild(row);
    attachRowEvents(row);
    updateTotalReceita();
    nextIndex += 1;
});

function closeFicha() {
    document.getElementById('modal-ficha').style.display = 'none';
}

document.querySelectorAll('.ver-ficha').forEach(btn => {
    btn.addEventListener('click', () => {
        const data = btn.dataset.ficha ? JSON.parse(btn.dataset.ficha) : null;
        if (!data) return;
        const linhas = data.linhas || [];
        const content = document.getElementById('ficha-conteudo');
        const rowsHtml = linhas.map(l => `
            <tr>
                <td>${l.item}</td>
                <td>${formatNumber(l.peso_liquido, 3)}</td>
                <td>${l.unidade_medida || '-'}</td>
                <td>${formatNumber(l.fator, 3)}</td>
                <td>${formatNumber(l.peso_bruto, 3)}</td>
                ${CAN_SEE_COST ? `<td>${formatCurrency(l.custo_unit)}</td><td>${formatCurrency(l.custo_total)}</td>` : ''}
            </tr>
        `).join('');

        const total = linhas.reduce((sum, l) => sum + (l.custo_total || 0), 0);
        const totalPorPorcao = total / Math.max(1, Number(data.rendimento || 1));

        content.innerHTML = `
            <div class=\"ficha-header\">
                <div class=\"box\"><strong>Nome</strong><div>${data.nome}</div></div>
                <div class=\"box\"><strong>Categoria</strong><div>${data.tipologia || '-'}</div></div>
                <div class=\"box\"><strong>Rendimento</strong><div>${data.rendimento}</div></div>
                <div class=\"box\"><strong>Última alteração</strong><div>${data.updated_at || '-'}</div></div>
                <div class=\"box ficha-photo\"><strong>Foto</strong><div>${data.foto ? `<img src=\"${data.foto}\" alt=\"Foto\">` : '-'}</div></div>
            </div>
            <table class=\"table\">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Peso líquido</th>
                        <th>Unidade</th>
                        <th>Fator</th>
                        <th>Peso bruto</th>
                        ${CAN_SEE_COST ? '<th>Custo unitário</th><th>Custo total</th>' : ''}
                    </tr>
                </thead>
                <tbody>
                    ${rowsHtml || '<tr><td colspan=\"' + (CAN_SEE_COST ? 7 : 5) + '\">Nenhum componente cadastrado.</td></tr>'}
                </tbody>
                ${CAN_SEE_COST ? `
                <tfoot>
                    <tr>
                        <td colspan=\"5\" style=\"text-align:right;font-weight:700;\">Total da receita</td>
                        <td></td>
                        <td>${formatCurrency(total)}</td>
                    </tr>
                    <tr>
                        <td colspan=\"6\" style=\"text-align:right;font-weight:700;\">Total por porção</td>
                        <td>${formatCurrency(totalPorPorcao)}</td>
                    </tr>
                </tfoot>
                ` : ''}
            </table>
            <div style=\"margin-top:0.75rem;\">\n                <a class=\"btn-primary\" href=\"index.php?page=logistica_receitas&edit_id=${data.id}\">Editar</a>\n            </div>
        `;
        document.getElementById('modal-ficha').style.display = 'flex';
    });
});

document.getElementById('modal-ficha')?.addEventListener('click', (e) => {
    if (e.target.id === 'modal-ficha') {
        closeFicha();
    }
});

let modalTargetRow = null;
let modalTab = 'insumos';
let modalSelected = null;

function openItemModal(row) {
    modalTargetRow = row;
    modalTab = 'insumos';
    modalSelected = null;
    document.getElementById('item-search').value = '';
    renderItemList();
    document.getElementById('modal-item').style.display = 'flex';
}

function closeItemModal() {
    document.getElementById('modal-item').style.display = 'none';
    modalTargetRow = null;
    modalSelected = null;
}

function renderItemList() {
    const listEl = document.getElementById('item-list');
    if (!listEl) return;
    const query = (document.getElementById('item-search').value || '').toLowerCase();
    const source = modalTab === 'receitas' ? RECEITAS : INSUMOS;
    const items = source.filter(item => {
        if (modalTab === 'receitas' && CURRENT_RECIPE_ID && item.id === CURRENT_RECIPE_ID) {
            return false;
        }
        const name = (item.nome || '').toLowerCase();
        if (name.includes(query)) return true;
        if (modalTab === 'insumos' && item.sinonimos) {
            return item.sinonimos.toLowerCase().includes(query);
        }
        return query === '';
    });
    listEl.innerHTML = items.map(item => {
        const selected = modalSelected && modalSelected.id === item.id ? ' selected' : '';
        return `<button type=\"button\" class=\"item-option${selected}\" data-id=\"${item.id}\" data-nome=\"${item.nome}\">${item.nome}</button>`;
    }).join('');
    listEl.querySelectorAll('.item-option').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = parseInt(btn.dataset.id || '0', 10);
            const nome = btn.dataset.nome || '';
            const item = source.find(i => i.id === id);
            modalSelected = {
                id,
                nome,
                tipo: modalTab === 'receitas' ? 'receita' : 'insumo',
                unidade_padrao: item ? (item.unidade_padrao || 0) : 0
            };
            renderItemList();
        });
    });
}

document.getElementById('tab-insumos')?.addEventListener('click', () => {
    modalTab = 'insumos';
    modalSelected = null;
    renderItemList();
});
document.getElementById('tab-receitas')?.addEventListener('click', () => {
    modalTab = 'receitas';
    modalSelected = null;
    renderItemList();
});
document.getElementById('item-search')?.addEventListener('input', renderItemList);
document.getElementById('confirm-item')?.addEventListener('click', () => {
    if (!modalTargetRow || !modalSelected) return;
    modalTargetRow.querySelector('.item-nome').value = modalSelected.nome;
    modalTargetRow.querySelector('.item-tipo').value = modalSelected.tipo;
    modalTargetRow.querySelector('.item-id').value = modalSelected.id;
    const unidadeSelect = modalTargetRow.querySelector('.unidade-medida');
    if (unidadeSelect && modalSelected.unidade_padrao) {
        unidadeSelect.value = String(modalSelected.unidade_padrao);
    }
    calcRow(modalTargetRow);
    updateTotalReceita();
    closeItemModal();
});

document.getElementById('modal-item')?.addEventListener('click', (e) => {
    if (e.target.id === 'modal-item') {
        closeItemModal();
    }
});

async function uploadFoto() {
    const fileInput = document.getElementById('foto_file');
    if (!fileInput.files.length) {
        alert('Selecione um arquivo.');
        return;
    }
    const formData = new FormData();
    formData.append('file', fileInput.files[0]);
    formData.append('context', 'receita');

    const response = await fetch('index.php?page=logistica_upload', {
        method: 'POST',
        body: formData
    });
    const result = await response.json();
    if (!result.ok) {
        alert('Erro no upload: ' + (result.error || ''));
        return;
    }
    const urlInput = document.getElementById('foto_url');
    const chaveInput = document.getElementById('foto_chave_storage');
    if (urlInput) {
        urlInput.value = result.url || '';
    }
    if (chaveInput) {
        chaveInput.value = result.chave_storage || '';
    }
    const preview = document.getElementById('foto_preview');
    if (preview) {
        preview.innerHTML = '<img src=\"' + (result.url || '') + '\" alt=\"Preview\">';
    }
}

document.getElementById('foto_file')?.addEventListener('change', (e) => {
    const file = e.target.files[0];
    const preview = document.getElementById('foto_preview');
    if (!file || !preview) return;
    const reader = new FileReader();
    reader.onload = () => {
        preview.innerHTML = '<img src=\"' + reader.result + '\" alt=\"Preview\">';
    };
    reader.readAsDataURL(file);
});

document.getElementById('foto_url')?.addEventListener('input', (e) => {
    const chaveInput = document.getElementById('foto_chave_storage');
    if (chaveInput && e.target.value.trim() !== '') {
        chaveInput.value = '';
    }
});
</script>

<?php endSidebar(); ?>
