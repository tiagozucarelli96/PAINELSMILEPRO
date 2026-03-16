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
        if ($acao === 'criar_pasta') {
            $nomePasta = trim((string)($_POST['nome_pasta'] ?? ''));
            $descricaoPasta = trim((string)($_POST['descricao_pasta'] ?? ''));

            if ($nomePasta === '') {
                throw new Exception('Informe o nome da pasta.');
            }

            $stmtExiste = $pdo->prepare('SELECT id FROM administrativo_juridico_pastas WHERE LOWER(nome) = LOWER(:nome) LIMIT 1');
            $stmtExiste->execute([':nome' => $nomePasta]);
            if ($stmtExiste->fetchColumn()) {
                throw new Exception('Já existe uma pasta com esse nome.');
            }

            $stmtInsert = $pdo->prepare(
                'INSERT INTO administrativo_juridico_pastas (nome, descricao, criado_por_usuario_id)
                 VALUES (:nome, :descricao, NULL)'
            );
            $stmtInsert->execute([
                ':nome' => $nomePasta,
                ':descricao' => $descricaoPasta !== '' ? $descricaoPasta : null,
            ]);

            $mensagem = 'Pasta criada com sucesso.';
        }

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
$arquivosPorPasta = [];

try {
    $stmtPastas = $pdo->query(
        'SELECT id, nome, descricao
         FROM administrativo_juridico_pastas
         ORDER BY nome ASC'
    );
    $pastas = $stmtPastas->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmtArquivos = $pdo->query(
        'SELECT id, pasta_id, titulo, descricao, arquivo_nome, criado_em, tamanho_bytes
         FROM administrativo_juridico_arquivos
         ORDER BY criado_em DESC'
    );

    $arquivos = $stmtArquivos->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($arquivos as $arquivo) {
        $pastaId = (int)($arquivo['pasta_id'] ?? 0);
        if (!isset($arquivosPorPasta[$pastaId])) {
            $arquivosPorPasta[$pastaId] = [];
        }
        $arquivosPorPasta[$pastaId][] = $arquivo;
    }
} catch (Exception $e) {
    error_log('Juridico portal - listar arquivos: ' . $e->getMessage());
}
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

        .jp-folder-grid {
            display: flex;
            flex-direction: column;
            gap: .75rem;
        }

        .jp-folder-card {
            border: 1px solid #dbe3ef;
            border-radius: 12px;
            background: #fcfdff;
            overflow: hidden;
        }

        .jp-folder-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: .7rem;
            padding: .78rem .9rem;
            cursor: pointer;
            user-select: none;
            list-style: none;
        }

        .jp-folder-head::-webkit-details-marker { display: none; }

        .jp-folder-left {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            min-width: 0;
        }

        .jp-folder-toggle {
            color: #334155;
            font-size: .86rem;
            transition: transform .2s ease;
            transform-origin: center;
            line-height: 1;
        }

        .jp-folder-card[open] .jp-folder-toggle { transform: rotate(90deg); }

        .jp-folder-title {
            margin: 0;
            font-size: 1.04rem;
            color: #0f172a;
            line-height: 1.25;
        }

        .jp-folder-count {
            border-radius: 999px;
            background: #dbeafe;
            color: #1d4ed8;
            font-size: .73rem;
            font-weight: 700;
            padding: .22rem .55rem;
            white-space: nowrap;
        }

        .jp-folder-body {
            padding: 0 .9rem .9rem .9rem;
            border-top: 1px solid #e2e8f0;
        }

        .jp-folder-desc {
            margin: .6rem 0 .65rem;
            font-size: .86rem;
            color: #475569;
            line-height: 1.35;
        }

        .jp-file-list {
            display: flex;
            flex-direction: column;
            gap: .55rem;
        }

        .jp-file-item {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #fff;
            padding: .72rem;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: .7rem;
            align-items: center;
        }

        .jp-file-main { min-width: 0; }

        .jp-file-title {
            font-weight: 800;
            font-size: .95rem;
            color: #0f172a;
            margin-bottom: .17rem;
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
            margin-top: .28rem;
            color: #334155;
            font-size: .84rem;
            line-height: 1.34;
            word-break: break-word;
        }

        .jp-file-side {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: .42rem;
            text-align: right;
        }

        .jp-file-date {
            color: #64748b;
            font-size: .78rem;
            line-height: 1.28;
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

        .empty {
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            background: #f8fafc;
            padding: .8rem;
            font-size: .9rem;
            color: #64748b;
        }

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
            .jp-file-item { grid-template-columns: 1fr; }
            .jp-file-side { align-items: flex-start; text-align: left; }
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
        <p class="intro">Aqui você também pode criar pastas e adicionar arquivos jurídicos com descrição.</p>

        <?php if ($mensagem !== ''): ?>
            <div class="alert ok"><?= htmlspecialchars($mensagem) ?></div>
        <?php endif; ?>

        <?php if ($erro !== ''): ?>
            <div class="alert err"><?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>

        <div class="jp-toolbar">
            <button type="button" class="jp-btn" onclick="openJpModal('modalPasta')">📁 Criar pasta</button>
            <button type="button" class="jp-btn secondary" onclick="openJpModal('modalArquivo')">📎 Adicionar arquivo</button>
        </div>

        <?php if (empty($pastas)): ?>
            <div class="no-data">Nenhuma pasta foi disponibilizada ainda.</div>
        <?php else: ?>
            <section class="jp-section">
                <h2>Pastas e arquivos</h2>
                <div class="jp-folder-grid">
                    <?php foreach ($pastas as $pasta): ?>
                        <?php $pastaId = (int)($pasta['id'] ?? 0); ?>
                        <?php $arquivosDaPasta = $arquivosPorPasta[$pastaId] ?? []; ?>
                        <details class="jp-folder-card">
                            <summary class="jp-folder-head">
                                <div class="jp-folder-left">
                                    <span class="jp-folder-toggle">▸</span>
                                    <h3 class="jp-folder-title">📁 <?= htmlspecialchars((string)($pasta['nome'] ?? 'Pasta')) ?></h3>
                                </div>
                                <span class="jp-folder-count"><?= count($arquivosDaPasta) ?> arquivo(s)</span>
                            </summary>

                            <div class="jp-folder-body">
                                <?php if (!empty($pasta['descricao'])): ?>
                                    <div class="jp-folder-desc"><?= nl2br(htmlspecialchars((string)$pasta['descricao'])) ?></div>
                                <?php endif; ?>

                                <?php if (empty($arquivosDaPasta)): ?>
                                    <div class="empty">Nenhum arquivo nesta pasta.</div>
                                <?php else: ?>
                                    <div class="jp-file-list">
                                        <?php foreach ($arquivosDaPasta as $arquivo): ?>
                                            <div class="jp-file-item">
                                                <div class="jp-file-main">
                                                    <div class="jp-file-title"><?= htmlspecialchars((string)($arquivo['titulo'] ?? 'Documento')) ?></div>
                                                    <div class="jp-file-meta">
                                                        <?= htmlspecialchars((string)($arquivo['arquivo_nome'] ?? '')) ?>
                                                        • <?= htmlspecialchars(jp_format_bytes(isset($arquivo['tamanho_bytes']) ? (int)$arquivo['tamanho_bytes'] : null)) ?>
                                                    </div>

                                                    <?php if (!empty($arquivo['descricao'])): ?>
                                                        <div class="jp-file-desc"><?= nl2br(htmlspecialchars((string)$arquivo['descricao'])) ?></div>
                                                    <?php endif; ?>
                                                </div>

                                                <div class="jp-file-side">
                                                    <div class="jp-file-date">
                                                        <?= !empty($arquivo['criado_em']) ? date('d/m/Y H:i', strtotime((string)$arquivo['criado_em'])) : '-' ?>
                                                    </div>
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
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <div class="jp-modal" id="modalPasta" aria-hidden="true">
        <div class="jp-modal-dialog">
            <div class="jp-modal-header">
                <h3>Criar pasta</h3>
                <button type="button" class="jp-modal-close" onclick="closeJpModal('modalPasta')">×</button>
            </div>
            <div class="jp-modal-body">
                <form method="POST">
                    <input type="hidden" name="acao" value="criar_pasta">

                    <div class="jp-grid">
                        <div class="jp-field" style="grid-column: 1 / -1;">
                            <label>Nome da pasta *</label>
                            <input type="text" name="nome_pasta" maxlength="150" required>
                        </div>
                        <div class="jp-field" style="grid-column: 1 / -1;">
                            <label>Descrição (opcional)</label>
                            <textarea name="descricao_pasta" placeholder="Ex: Contratos, procurações, processos..."></textarea>
                        </div>
                    </div>

                    <div class="jp-modal-actions">
                        <button type="button" class="jp-btn-outline" onclick="closeJpModal('modalPasta')">Cancelar</button>
                        <button type="submit" class="jp-btn">Salvar pasta</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

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
                                <?php foreach ($pastas as $pasta): ?>
                                    <option value="<?= (int)($pasta['id'] ?? 0) ?>"><?= htmlspecialchars((string)($pasta['nome'] ?? '')) ?></option>
                                <?php endforeach; ?>
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
