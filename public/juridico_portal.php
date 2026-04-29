<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/upload_magalu.php';
require_once __DIR__ . '/setup_administrativo_juridico.php';

setupAdministrativoJuridico($pdo);

function jp_format_bytes(?int $bytes): string
{
    if ($bytes === null || $bytes <= 0) {
        return '-';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = (float)$bytes;
    $index = 0;
    while ($size >= 1024 && $index < count($units) - 1) {
        $size /= 1024;
        $index++;
    }

    return number_format($size, $index === 0 ? 0 : 2, ',', '.') . ' ' . $units[$index];
}

function jp_page_url(?int $folderId = null): string
{
    $base = 'index.php?page=juridico_portal';
    if ($folderId !== null && $folderId > 0) {
        $base .= '&pasta=' . $folderId;
    }

    return $base;
}

function jp_folder_option_tags(array $childrenByParent, array $pastasById, int $parentId = 0, int $level = 0): string
{
    $html = '';
    foreach ($childrenByParent[$parentId] ?? [] as $folderId) {
        $folder = $pastasById[$folderId] ?? null;
        if (!$folder) {
            continue;
        }

        $prefix = str_repeat('&nbsp;&nbsp;&nbsp;', $level);
        $html .= '<option value="' . $folderId . '">' . $prefix . htmlspecialchars((string)$folder['nome']) . '</option>';
        $html .= jp_folder_option_tags($childrenByParent, $pastasById, $folderId, $level + 1);
    }

    return $html;
}

function jp_pasta_path_label(array $pastasById, int $folderId): string
{
    if ($folderId <= 0 || !isset($pastasById[$folderId])) {
        return 'Raiz';
    }

    $labels = [];
    $cursor = $folderId;
    $guard = 0;
    while ($cursor > 0 && isset($pastasById[$cursor]) && $guard < 30) {
        $labels[] = (string)($pastasById[$cursor]['nome'] ?? 'Pasta');
        $cursor = (int)($pastasById[$cursor]['parent_id'] ?? 0);
        $guard++;
    }

    return implode(' / ', array_reverse($labels));
}

if (isset($_GET['logout'])) {
    unset($_SESSION['juridico_logado']);
    unset($_SESSION['juridico_usuario_id']);
    unset($_SESSION['juridico_usuario_nome']);
    unset($_SESSION['juridico_login_at']);

    header('Location: index.php?page=juridico_login');
    exit;
}

if (empty($_SESSION['juridico_logado']) || empty($_SESSION['juridico_usuario_id'])) {
    $redirect = (string)($_SERVER['REQUEST_URI'] ?? '');
    $redirectParam = '';
    $startsOk = (substr($redirect, 0, 9) === '/index.php' || substr($redirect, 0, 8) === 'index.php');
    if ($redirect !== '' && $startsOk && strpos($redirect, 'page=juridico_portal') !== false) {
        $redirectParam = '&redirect=' . urlencode($redirect);
    }
    header('Location: index.php?page=juridico_login' . $redirectParam);
    exit;
}

$usuarioId = (int)$_SESSION['juridico_usuario_id'];
$usuarioNome = (string)($_SESSION['juridico_usuario_nome'] ?? 'Usuário');
$mensagem = '';
$erro = '';
$currentPastaId = (int)($_GET['pasta'] ?? 0);

try {
    $stmt = $pdo->prepare('SELECT id, nome FROM administrativo_juridico_usuarios WHERE id = :id AND ativo = TRUE LIMIT 1');
    $stmt->execute([':id' => $usuarioId]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        unset($_SESSION['juridico_logado'], $_SESSION['juridico_usuario_id'], $_SESSION['juridico_usuario_nome'], $_SESSION['juridico_login_at']);
        header('Location: index.php?page=juridico_login');
        exit;
    }

    $usuarioNome = (string)($usuario['nome'] ?? $usuarioNome);
    $_SESSION['juridico_usuario_nome'] = $usuarioNome;
} catch (Exception $e) {
    error_log('Juridico portal - validar usuario: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = trim((string)($_POST['acao'] ?? ''));

    try {
        if ($acao === 'adicionar_arquivo') {
            $pastaId = (int)($_POST['pasta_id'] ?? 0);
            $titulo = trim((string)($_POST['titulo'] ?? ''));
            $descricao = trim((string)($_POST['descricao'] ?? ''));

            if ($pastaId <= 0) {
                throw new Exception('Selecione a pasta para o arquivo.');
            }

            $stmtPasta = $pdo->prepare('SELECT id, nome FROM administrativo_juridico_pastas WHERE id = :id LIMIT 1');
            $stmtPasta->execute([':id' => $pastaId]);
            $pasta = $stmtPasta->fetch(PDO::FETCH_ASSOC);
            if (!$pasta) {
                throw new Exception('Pasta não encontrada.');
            }

            if (!isset($_FILES['arquivo']) || (int)($_FILES['arquivo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new Exception('Anexe um arquivo para continuar.');
            }

            $uploader = new MagaluUpload();
            $resultadoUpload = $uploader->upload($_FILES['arquivo'], 'administrativo/juridico/' . $pastaId);

            $arquivoNome = (string)($resultadoUpload['nome_original'] ?? ($_FILES['arquivo']['name'] ?? 'arquivo'));
            $tituloFinal = $titulo !== '' ? $titulo : $arquivoNome;

            $stmtInsert = $pdo->prepare(
                'INSERT INTO administrativo_juridico_arquivos
                 (pasta_id, titulo, descricao, arquivo_nome, arquivo_url, chave_storage, mime_type, tamanho_bytes, criado_por_usuario_id)
                 VALUES
                 (:pasta_id, :titulo, :descricao, :arquivo_nome, :arquivo_url, :chave_storage, :mime_type, :tamanho_bytes, NULL)'
            );
            $stmtInsert->execute([
                ':pasta_id' => $pastaId,
                ':titulo' => $tituloFinal,
                ':descricao' => $descricao !== '' ? $descricao : null,
                ':arquivo_nome' => $arquivoNome,
                ':arquivo_url' => $resultadoUpload['url'] ?? null,
                ':chave_storage' => $resultadoUpload['chave_storage'] ?? null,
                ':mime_type' => $resultadoUpload['mime_type'] ?? null,
                ':tamanho_bytes' => $resultadoUpload['tamanho_bytes'] ?? null,
            ]);

            $mensagem = 'Arquivo enviado com sucesso para a pasta "' . ((string)($pasta['nome'] ?? '')) . '".';
        }
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

$pastas = [];
$pastasById = [];
$childrenByParent = [];
$pastasFilhas = [];
$arquivosAtuais = [];
$folderOptionsHtml = '';
$currentPasta = null;
$breadcrumbs = [];

try {
    $stmtPastas = $pdo->query(
        'SELECT id, nome, descricao, parent_id
         FROM administrativo_juridico_pastas
         ORDER BY CAST(COALESCE(parent_id, 0) AS INTEGER) ASC, LOWER(nome) ASC'
    );
    $pastas = $stmtPastas->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($pastas as $pasta) {
        $folderId = (int)($pasta['id'] ?? 0);
        $pastasById[$folderId] = $pasta;
        $parentId = (int)($pasta['parent_id'] ?? 0);
        if (!isset($childrenByParent[$parentId])) {
            $childrenByParent[$parentId] = [];
        }
        $childrenByParent[$parentId][] = $folderId;
    }
} catch (Exception $e) {
    $erro = $erro !== '' ? $erro : 'Erro ao carregar pastas.';
    error_log('Juridico portal - listar pastas: ' . $e->getMessage());
}

if ($currentPastaId > 0 && !isset($pastasById[$currentPastaId])) {
    $currentPastaId = 0;
}

$currentPasta = $currentPastaId > 0 ? ($pastasById[$currentPastaId] ?? null) : null;

foreach ($childrenByParent[$currentPastaId] ?? [] as $childId) {
    if (isset($pastasById[$childId])) {
        $pastasFilhas[] = $pastasById[$childId];
    }
}

if ($currentPastaId > 0) {
    try {
        $stmtArquivos = $pdo->prepare(
            'SELECT id, pasta_id, titulo, descricao, arquivo_nome, criado_em, tamanho_bytes
             FROM administrativo_juridico_arquivos
             WHERE pasta_id = :pasta_id
             ORDER BY LOWER(titulo) ASC, criado_em DESC'
        );
        $stmtArquivos->execute([':pasta_id' => $currentPastaId]);
        $arquivosAtuais = $stmtArquivos->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        $erro = $erro !== '' ? $erro : 'Erro ao carregar arquivos da pasta.';
        error_log('Juridico portal - listar arquivos da pasta: ' . $e->getMessage());
    }
}

$cursorId = $currentPastaId;
$guard = 0;
while ($cursorId > 0 && isset($pastasById[$cursorId]) && $guard < 30) {
    $breadcrumbs[] = $pastasById[$cursorId];
    $cursorId = (int)($pastasById[$cursorId]['parent_id'] ?? 0);
    $guard++;
}
$breadcrumbs = array_reverse($breadcrumbs);
$folderOptionsHtml = jp_folder_option_tags($childrenByParent, $pastasById);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal Jurídico</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8fafc;
            color: #0f172a;
            min-height: 100vh;
        }

        .header {
            background: linear-gradient(135deg, #1e3a8a 0%, #1d4ed8 100%);
            color: #fff;
            padding: 1rem 1.3rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: .8rem;
            flex-wrap: wrap;
        }

        .header-brand {
            display: inline-flex;
            align-items: center;
            gap: .75rem;
        }
        .header-logo {
            width: 120px;
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            background: rgba(255, 255, 255, .12);
            padding: .35rem .45rem;
        }
        .header-text h1 {
            margin: 0;
            font-size: 1.45rem;
            line-height: 1.1;
        }
        .header-company {
            opacity: .94;
            font-size: .85rem;
            margin-top: .2rem;
            display: block;
        }
        .header-user {
            opacity: .9;
            display: block;
            margin-top: .1rem;
            font-size: .84rem;
        }

        .header-actions {
            display: inline-flex;
            align-items: center;
            gap: .7rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            border: 0;
            border-radius: 9px;
            padding: .52rem .88rem;
            text-decoration: none;
            font-weight: 700;
            cursor: pointer;
        }

        .btn-light { background: rgba(255,255,255,.18); color: #fff; }
        .btn-light:hover { background: rgba(255,255,255,.26); }

        .container {
            max-width: 1250px;
            margin: 0 auto;
            padding: 1.1rem;
        }

        .intro {
            margin-bottom: .95rem;
            color: #475569;
            font-size: .94rem;
        }

        .jp-company-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: .7rem .85rem;
            display: inline-flex;
            align-items: center;
            gap: .7rem;
            box-shadow: 0 2px 8px rgba(15, 23, 42, .05);
            margin-bottom: .9rem;
        }
        .jp-company-logo {
            width: 86px;
            max-width: 100%;
            height: auto;
        }
        .jp-company-text strong {
            display: block;
            color: #1e3a8a;
            font-size: .92rem;
            line-height: 1.1;
        }
        .jp-company-text span {
            display: block;
            margin-top: .14rem;
            color: #64748b;
            font-size: .8rem;
        }

        .alert {
            border-radius: 10px;
            padding: .78rem .9rem;
            margin-bottom: .9rem;
            font-weight: 600;
            font-size: .9rem;
        }

        .alert.ok { background: #dcfce7; border: 1px solid #86efac; color: #166534; }
        .alert.err { background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; }

        .jp-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: .62rem;
            margin-bottom: .9rem;
        }

        .jp-btn {
            border: 0;
            border-radius: 10px;
            padding: .6rem .95rem;
            font-weight: 700;
            cursor: pointer;
            background: #1d4ed8;
            color: #fff;
            display: inline-flex;
            align-items: center;
            gap: .38rem;
        }

        .jp-btn:hover { background: #1e40af; }
        .jp-btn.secondary { background: #0f766e; }
        .jp-btn.secondary:hover { background: #115e59; }

        .jp-section {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 3px 12px rgba(15, 23, 42, .06);
            padding: 1rem;
        }

        .jp-section h2 {
            margin: 0 0 .75rem;
            color: #0f172a;
            font-size: 1.1rem;
        }

        .jp-breadcrumbs {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: .45rem;
            margin-bottom: .8rem;
            color: #475569;
            font-size: .92rem;
        }

        .jp-breadcrumbs a {
            color: #1d4ed8;
            text-decoration: none;
            font-weight: 700;
        }

        .jp-folder-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: .9rem;
            margin-bottom: 1rem;
        }

        .jp-folder-tile {
            display: block;
            text-decoration: none;
            color: inherit;
            border: 1px solid #dbe3ef;
            border-radius: 16px;
            background: linear-gradient(180deg, #fff8db 0%, #fffdf3 100%);
            padding: 1rem;
            min-height: 130px;
            position: relative;
            box-shadow: 0 8px 20px rgba(15, 23, 42, .05);
        }

        .jp-folder-tile:hover {
            transform: translateY(-1px);
        }

        .jp-folder-icon {
            font-size: 2rem;
            margin-bottom: .7rem;
            display: block;
        }

        .jp-folder-name {
            font-size: 1rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.25;
            margin-bottom: .35rem;
        }

        .jp-folder-meta {
            color: #64748b;
            font-size: .8rem;
        }

        .jp-empty {
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            background: #f8fafc;
            padding: .8rem;
            font-size: .9rem;
            color: #64748b;
        }

        .jp-file-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: .75rem;
            margin-top: .3rem;
        }

        .jp-file-card {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #fff;
            padding: .8rem;
            display: flex;
            flex-direction: column;
            gap: .4rem;
            min-height: 146px;
        }

        .jp-file-top { min-width: 0; }

        .jp-file-title {
            font-weight: 800;
            font-size: .95rem;
            color: #0f172a;
            line-height: 1.28;
            word-break: break-word;
        }

        .jp-file-meta {
            color: #64748b;
            font-size: .82rem;
            line-height: 1.3;
            word-break: break-word;
        }

        .jp-file-desc {
            margin-top: .1rem;
            color: #334155;
            font-size: .84rem;
            line-height: 1.34;
            word-break: break-word;
        }

        .jp-file-actions {
            margin-top: auto;
            display: flex;
            justify-content: flex-start;
            gap: .42rem;
        }

        .jp-link-btn {
            border: 0;
            border-radius: 8px;
            background: #e8efff;
            color: #1d4ed8;
            font-size: .8rem;
            font-weight: 800;
            padding: .38rem .62rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            text-decoration: none;
        }

        .jp-link-btn:hover { background: #dbeafe; }

        .no-data {
            margin-top: .8rem;
            border: 1px dashed #cbd5e1;
            border-radius: 12px;
            padding: 1rem;
            background: #fff;
            color: #64748b;
        }

        .jp-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 4200;
            background: rgba(2, 6, 23, .58);
            padding: 1rem;
        }

        .jp-modal.open { display: flex; align-items: center; justify-content: center; }

        .jp-modal-dialog {
            width: 100%;
            max-width: 640px;
            background: #fff;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(2, 6, 23, .35);
        }
        .jp-modal-dialog.jp-preview-dialog {
            max-width: 1100px;
        }

        .jp-modal-header {
            background: #1d4ed8;
            color: #fff;
            padding: .95rem 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .jp-modal-header h3 { margin: 0; font-size: 1rem; }

        .jp-modal-close {
            background: transparent;
            border: 0;
            color: #fff;
            font-size: 1.25rem;
            cursor: pointer;
        }

        .jp-modal-body { padding: 1rem; }
        .jp-preview-body {
            padding: 0;
            background: #0b1220;
        }
        .jp-preview-frame {
            width: 100%;
            height: min(78vh, 860px);
            border: 0;
            background: #fff;
        }

        .jp-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: .75rem;
        }

        .jp-field { display: flex; flex-direction: column; }

        .jp-field label {
            margin-bottom: .34rem;
            font-weight: 600;
            color: #334155;
            font-size: .88rem;
        }

        .jp-field input,
        .jp-field textarea,
        .jp-field select {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: .62rem .7rem;
            font-size: .92rem;
        }

        .jp-field textarea { min-height: 92px; resize: vertical; }

        .jp-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: .6rem;
            margin-top: .9rem;
        }

        .jp-btn-outline {
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            padding: .6rem .9rem;
            background: #fff;
            color: #0f172a;
            font-weight: 700;
            cursor: pointer;
        }

        @media (max-width: 880px) {
            .jp-grid { grid-template-columns: 1fr; }
            .jp-folder-grid { grid-template-columns: 1fr; }
            .jp-file-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-brand">
            <img src="/logo-smile.png" alt="Grupo Smile Eventos" class="header-logo">
            <div class="header-text">
                <h1>Portal Jurídico</h1>
                <span class="header-company">Grupo Smile Eventos</span>
                <span class="header-user">Arquivos compartilhados para <?= htmlspecialchars($usuarioNome) ?></span>
            </div>
        </div>
        <div class="header-actions">
            <a href="index.php?page=juridico_portal&logout=1" class="btn btn-light">Sair</a>
        </div>
    </header>

    <main class="container">
        <div class="jp-company-card">
            <img src="/logo-smile.png" alt="Grupo Smile Eventos" class="jp-company-logo">
            <div class="jp-company-text">
                <strong>Grupo Smile Eventos</strong>
                <span>Ambiente oficial de documentos jurídicos</span>
            </div>
        </div>
        <p class="intro">Aqui o jurídico acessa os documentos organizados por funcionário e também pode adicionar novos arquivos com descrição.</p>

        <?php if ($mensagem !== ''): ?>
            <div class="alert ok"><?= htmlspecialchars($mensagem) ?></div>
        <?php endif; ?>

        <?php if ($erro !== ''): ?>
            <div class="alert err"><?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>

        <div class="jp-toolbar">
            <button type="button" class="jp-btn secondary" onclick="openJpModal('modalArquivo')">📎 Adicionar arquivo</button>
        </div>

        <section class="jp-section">
            <div class="jp-breadcrumbs">
                <a href="<?= htmlspecialchars(jp_page_url()) ?>">Raiz</a>
                <?php foreach ($breadcrumbs as $index => $item): ?>
                    <span>/</span>
                    <?php if ($index === count($breadcrumbs) - 1): ?>
                        <strong><?= htmlspecialchars((string)$item['nome']) ?></strong>
                    <?php else: ?>
                        <a href="<?= htmlspecialchars(jp_page_url((int)$item['id'])) ?>"><?= htmlspecialchars((string)$item['nome']) ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <h2>📁 <?= htmlspecialchars(jp_pasta_path_label($pastasById, $currentPastaId)) ?></h2>

            <?php if (empty($pastas)): ?>
                <div class="no-data">Nenhuma pasta foi disponibilizada ainda.</div>
            <?php else: ?>
                <?php if (!empty($pastasFilhas)): ?>
                    <div class="jp-folder-grid">
                        <?php foreach ($pastasFilhas as $pasta): ?>
                            <a href="<?= htmlspecialchars(jp_page_url((int)$pasta['id'])) ?>" class="jp-folder-tile">
                                <span class="jp-folder-icon">📁</span>
                                <div class="jp-folder-name"><?= htmlspecialchars((string)($pasta['nome'] ?? 'Pasta')) ?></div>
                                <div class="jp-folder-meta"><?= htmlspecialchars((string)($pasta['descricao'] ?? 'Acessar subpasta')) ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($currentPastaId > 0): ?>
                    <?php if (empty($arquivosAtuais)): ?>
                        <div class="jp-empty">Nenhum arquivo nesta pasta.</div>
                    <?php else: ?>
                        <div class="jp-file-grid">
                            <?php foreach ($arquivosAtuais as $arquivo): ?>
                                <div class="jp-file-card">
                                    <div class="jp-file-top">
                                        <div class="jp-file-title"><?= htmlspecialchars((string)($arquivo['titulo'] ?? 'Documento')) ?></div>
                                        <div class="jp-file-meta">
                                            <?= htmlspecialchars((string)($arquivo['arquivo_nome'] ?? '')) ?> • <?= htmlspecialchars(jp_format_bytes(isset($arquivo['tamanho_bytes']) ? (int)$arquivo['tamanho_bytes'] : null)) ?>
                                            <br>
                                            <?= !empty($arquivo['criado_em']) ? date('d/m/Y H:i', strtotime((string)$arquivo['criado_em'])) : '-' ?>
                                        </div>
                                        <?php if (!empty($arquivo['descricao'])): ?>
                                            <div class="jp-file-desc"><?= nl2br(htmlspecialchars((string)$arquivo['descricao'])) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="jp-file-actions">
                                        <button
                                            type="button"
                                            class="jp-link-btn"
                                            onclick='openJpPreview(<?= json_encode("juridico_download.php?id=" . (int)($arquivo["id"] ?? 0)) ?>, <?= json_encode((string)($arquivo["titulo"] ?? "Arquivo"), JSON_UNESCAPED_UNICODE) ?>)'>
                                            Visualizar arquivo
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="jp-empty">Entre em uma pasta para visualizar os arquivos.</div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>

    <div class="jp-modal" id="modalArquivo" aria-hidden="true">
        <div class="jp-modal-dialog">
            <div class="jp-modal-header" style="background:#0f766e;">
                <h3>Adicionar arquivo</h3>
                <button type="button" class="jp-modal-close" onclick="closeJpModal('modalArquivo')">×</button>
            </div>
            <div class="jp-modal-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="acao" value="adicionar_arquivo">

                    <div class="jp-grid">
                        <div class="jp-field" style="grid-column: 1 / -1;">
                            <label>Pasta *</label>
                            <select name="pasta_id" required>
                                <option value="">Selecione</option>
                                <?= $folderOptionsHtml ?>
                            </select>
                        </div>
                        <div class="jp-field" style="grid-column: 1 / -1;">
                            <label>Título (opcional)</label>
                            <input type="text" name="titulo" maxlength="255" placeholder="Se vazio, usa nome do arquivo">
                        </div>
                        <div class="jp-field" style="grid-column: 1 / -1;">
                            <label>Descrição do arquivo</label>
                            <textarea name="descricao" placeholder="Descreva o arquivo"></textarea>
                        </div>
                        <div class="jp-field" style="grid-column: 1 / -1;">
                            <label>Arquivo *</label>
                            <input type="file" name="arquivo" required>
                        </div>
                    </div>

                    <div class="jp-modal-actions">
                        <button type="button" class="jp-btn-outline" onclick="closeJpModal('modalArquivo')">Cancelar</button>
                        <button type="submit" class="jp-btn secondary">Enviar arquivo</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="jp-modal" id="modalVisualizarArquivo" aria-hidden="true">
        <div class="jp-modal-dialog jp-preview-dialog">
            <div class="jp-modal-header">
                <h3 id="jpPreviewTitle">Visualizar arquivo</h3>
                <button type="button" class="jp-modal-close" onclick="closeJpPreview()">×</button>
            </div>
            <div class="jp-modal-body jp-preview-body">
                <iframe id="jpPreviewFrame" class="jp-preview-frame" src="about:blank"></iframe>
            </div>
        </div>
    </div>

    <script>
        function openJpModal(id) {
            var modal = document.getElementById(id);
            if (!modal) return;
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
        }

        function closeJpModal(id) {
            var modal = document.getElementById(id);
            if (!modal) return;
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
        }

        document.querySelectorAll('.jp-modal').forEach(function(modal) {
            modal.addEventListener('click', function(event) {
                if (event.target === modal) {
                    if (modal.id === 'modalVisualizarArquivo') {
                        closeJpPreview();
                        return;
                    }
                    modal.classList.remove('open');
                    modal.setAttribute('aria-hidden', 'true');
                }
            });
        });

        document.addEventListener('keydown', function(event) {
            if (event.key !== 'Escape') return;
            document.querySelectorAll('.jp-modal.open').forEach(function(modal) {
                if (modal.id === 'modalVisualizarArquivo') {
                    closeJpPreview();
                    return;
                }
                modal.classList.remove('open');
                modal.setAttribute('aria-hidden', 'true');
            });
        });

        function openJpPreview(url, title) {
            var modal = document.getElementById('modalVisualizarArquivo');
            var frame = document.getElementById('jpPreviewFrame');
            var titleNode = document.getElementById('jpPreviewTitle');
            if (!modal || !frame) return;

            frame.src = url || 'about:blank';
            if (titleNode) {
                titleNode.textContent = title ? ('Visualizar: ' + title) : 'Visualizar arquivo';
            }

            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
        }

        function closeJpPreview() {
            var modal = document.getElementById('modalVisualizarArquivo');
            var frame = document.getElementById('jpPreviewFrame');
            if (!modal) return;

            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
            if (frame) {
                frame.src = 'about:blank';
            }
        }
    </script>
</body>
</html>
