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
            padding: 1.1rem 1.3rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: .8rem;
            flex-wrap: wrap;
        }
        .header h1 { font-size: 1.2rem; }
        .header small { opacity: .9; display: block; margin-top: .2rem; }
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
            max-width: 1120px;
            margin: 0 auto;
            padding: 1.2rem;
        }

        .intro {
            margin-bottom: 1rem;
            color: #475569;
            font-size: .94rem;
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

        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: .9rem;
            margin-bottom: 1rem;
        }

        .action-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 3px 10px rgba(15, 23, 42, .06);
            padding: .95rem;
        }

        .action-card h2 {
            font-size: 1rem;
            margin-bottom: .7rem;
            color: #1e293b;
        }

        .field { margin-bottom: .65rem; }
        .field:last-child { margin-bottom: 0; }
        .field label {
            display: block;
            font-size: .83rem;
            font-weight: 600;
            color: #334155;
            margin-bottom: .3rem;
        }
        .field input,
        .field select,
        .field textarea {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: .6rem .68rem;
            font-size: .9rem;
        }
        .field textarea { min-height: 85px; resize: vertical; }

        .action-btn {
            margin-top: .25rem;
            border: 0;
            border-radius: 8px;
            background: #1d4ed8;
            color: #fff;
            font-weight: 700;
            padding: .6rem .9rem;
            cursor: pointer;
        }
        .action-btn:hover { background: #1e40af; }
        .action-btn.green { background: #0f766e; }
        .action-btn.green:hover { background: #115e59; }

        .folders {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: .9rem;
        }

        .folder {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 3px 10px rgba(15, 23, 42, .06);
            padding: .9rem;
        }

        .folder-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: .5rem;
            margin-bottom: .5rem;
        }
        .folder-title { font-size: 1rem; }
        .badge {
            border-radius: 999px;
            background: #dbeafe;
            color: #1d4ed8;
            font-size: .72rem;
            font-weight: 700;
            padding: .2rem .5rem;
        }
        .folder-desc { font-size: .85rem; color: #64748b; margin-bottom: .6rem; }

        .empty {
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            background: #f8fafc;
            padding: .8rem;
            font-size: .9rem;
            color: #64748b;
        }

        .file {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #f8fafc;
            padding: .7rem;
            margin-bottom: .5rem;
        }
        .file:last-child { margin-bottom: 0; }
        .file-title { font-weight: 700; font-size: .92rem; margin-bottom: .2rem; }
        .file-desc { font-size: .83rem; color: #475569; margin-bottom: .35rem; }
        .file-meta { font-size: .77rem; color: #64748b; margin-bottom: .45rem; }
        .file-link {
            color: #1d4ed8;
            text-decoration: none;
            font-weight: 700;
            font-size: .84rem;
        }
        .file-link:hover { text-decoration: underline; }

        .no-data {
            margin-top: .8rem;
            border: 1px dashed #cbd5e1;
            border-radius: 12px;
            padding: 1rem;
            background: #fff;
            color: #64748b;
        }
    </style>
</head>
<body>
    <header class="header">
        <div>
            <h1>⚖️ Portal Jurídico</h1>
            <small>Arquivos compartilhados para <?= htmlspecialchars($usuarioNome) ?></small>
        </div>
        <div class="header-actions">
            <a href="index.php?page=juridico_portal&logout=1" class="btn btn-light">Sair</a>
        </div>
    </header>

    <main class="container">
        <p class="intro">Aqui você também pode criar pastas e adicionar arquivos jurídicos com descrição.</p>

        <?php if ($mensagem !== ''): ?>
            <div class="alert ok"><?= htmlspecialchars($mensagem) ?></div>
        <?php endif; ?>

        <?php if ($erro !== ''): ?>
            <div class="alert err"><?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>

        <section class="actions-grid">
            <article class="action-card">
                <h2>📁 Criar pasta</h2>
                <form method="POST">
                    <input type="hidden" name="acao" value="criar_pasta">

                    <div class="field">
                        <label>Nome da pasta *</label>
                        <input type="text" name="nome_pasta" maxlength="150" required>
                    </div>

                    <div class="field">
                        <label>Descrição (opcional)</label>
                        <textarea name="descricao_pasta" placeholder="Ex: Contratos, procurações, processos..."></textarea>
                    </div>

                    <button class="action-btn" type="submit">Salvar pasta</button>
                </form>
            </article>

            <article class="action-card">
                <h2>📎 Adicionar arquivo</h2>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="acao" value="adicionar_arquivo">

                    <div class="field">
                        <label>Pasta *</label>
                        <select name="pasta_id" required>
                            <option value="">Selecione</option>
                            <?php foreach ($pastas as $pasta): ?>
                                <option value="<?= (int)($pasta['id'] ?? 0) ?>"><?= htmlspecialchars((string)($pasta['nome'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label>Título (opcional)</label>
                        <input type="text" name="titulo" maxlength="255" placeholder="Se vazio, usa nome do arquivo">
                    </div>

                    <div class="field">
                        <label>Descrição do arquivo</label>
                        <textarea name="descricao" placeholder="Descreva o arquivo"></textarea>
                    </div>

                    <div class="field">
                        <label>Arquivo *</label>
                        <input type="file" name="arquivo" required>
                    </div>

                    <button class="action-btn green" type="submit">Enviar arquivo</button>
                </form>
            </article>
        </section>

        <?php if (empty($pastas)): ?>
            <div class="no-data">Nenhuma pasta foi disponibilizada ainda.</div>
        <?php else: ?>
            <section class="folders">
                <?php foreach ($pastas as $pasta): ?>
                    <?php $pastaId = (int)($pasta['id'] ?? 0); ?>
                    <?php $arquivosDaPasta = $arquivosPorPasta[$pastaId] ?? []; ?>
                    <article class="folder">
                        <div class="folder-head">
                            <h2 class="folder-title">📁 <?= htmlspecialchars((string)($pasta['nome'] ?? 'Pasta')) ?></h2>
                            <span class="badge"><?= count($arquivosDaPasta) ?> arquivo(s)</span>
                        </div>

                        <?php if (!empty($pasta['descricao'])): ?>
                            <div class="folder-desc"><?= nl2br(htmlspecialchars((string)$pasta['descricao'])) ?></div>
                        <?php endif; ?>

                        <?php if (empty($arquivosDaPasta)): ?>
                            <div class="empty">Nenhum arquivo nesta pasta.</div>
                        <?php else: ?>
                            <?php foreach ($arquivosDaPasta as $arquivo): ?>
                                <div class="file">
                                    <div class="file-title"><?= htmlspecialchars((string)($arquivo['titulo'] ?? 'Documento')) ?></div>
                                    <?php if (!empty($arquivo['descricao'])): ?>
                                        <div class="file-desc"><?= nl2br(htmlspecialchars((string)$arquivo['descricao'])) ?></div>
                                    <?php endif; ?>
                                    <div class="file-meta">
                                        <?= htmlspecialchars((string)($arquivo['arquivo_nome'] ?? '')) ?>
                                        • <?= htmlspecialchars(jp_format_bytes(isset($arquivo['tamanho_bytes']) ? (int)$arquivo['tamanho_bytes'] : null)) ?>
                                        • <?= !empty($arquivo['criado_em']) ? date('d/m/Y H:i', strtotime((string)$arquivo['criado_em'])) : '-' ?>
                                    </div>
                                    <a class="file-link" href="juridico_download.php?id=<?= (int)($arquivo['id'] ?? 0) ?>" target="_blank" rel="noopener">Visualizar arquivo</a>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
