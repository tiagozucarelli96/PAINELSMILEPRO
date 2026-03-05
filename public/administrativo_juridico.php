<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['logado']) || empty($_SESSION['perm_administrativo'])) {
    header('Location: index.php?page=login');
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/upload_magalu.php';
require_once __DIR__ . '/setup_administrativo_juridico.php';
require_once __DIR__ . '/sidebar_integration.php';

setupAdministrativoJuridico($pdo);

function aj_usuario_logado_id(): int
{
    return (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
}

function aj_format_bytes(?int $bytes): string
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

function aj_base_url(): string
{
    $base = trim((string)(getenv('APP_URL') ?: getenv('BASE_URL') ?: ''));
    if ($base !== '') {
        return rtrim($base, '/');
    }

    $host = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return '';
    }

    $schemeForwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $scheme = $schemeForwarded !== ''
        ? strtolower($schemeForwarded)
        : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');

    return $scheme . '://' . $host;
}

$mensagem = '';
$erro = '';

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

            $stmt = $pdo->prepare(
                'INSERT INTO administrativo_juridico_pastas (nome, descricao, criado_por_usuario_id)
                 VALUES (:nome, :descricao, :criado_por)'
            );
            $stmt->execute([
                ':nome' => $nomePasta,
                ':descricao' => $descricaoPasta !== '' ? $descricaoPasta : null,
                ':criado_por' => aj_usuario_logado_id() ?: null,
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
                (:pasta_id, :titulo, :descricao, :arquivo_nome, :arquivo_url, :chave_storage, :mime_type, :tamanho_bytes, :criado_por)'
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
                ':criado_por' => aj_usuario_logado_id() ?: null,
            ]);

            $mensagem = 'Arquivo adicionado com sucesso na pasta "' . ($pasta['nome'] ?? '') . '".';
        }

        if ($acao === 'cadastrar_usuario') {
            $nomeUsuario = trim((string)($_POST['nome_usuario'] ?? ''));
            $emailUsuario = trim((string)($_POST['email_usuario'] ?? ''));
            $senha = (string)($_POST['senha_usuario'] ?? '');
            $confirmarSenha = (string)($_POST['senha_usuario_confirmacao'] ?? '');

            if ($nomeUsuario === '') {
                throw new Exception('Informe o nome do usuário jurídico.');
            }

            if ($senha === '' || strlen($senha) < 6) {
                throw new Exception('A senha precisa ter no mínimo 6 caracteres.');
            }

            if ($senha !== $confirmarSenha) {
                throw new Exception('As senhas informadas não conferem.');
            }

            if ($emailUsuario !== '' && !filter_var($emailUsuario, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Informe um e-mail válido ou deixe em branco.');
            }

            $stmtExiste = $pdo->prepare('SELECT id FROM administrativo_juridico_usuarios WHERE LOWER(nome) = LOWER(:nome) LIMIT 1');
            $stmtExiste->execute([':nome' => $nomeUsuario]);
            if ($stmtExiste->fetchColumn()) {
                throw new Exception('Já existe um usuário jurídico com esse nome.');
            }

            $senhaHash = password_hash($senha, PASSWORD_DEFAULT);
            if ($senhaHash === false) {
                throw new Exception('Não foi possível gerar a senha segura para este usuário.');
            }

            $stmtInsert = $pdo->prepare(
                'INSERT INTO administrativo_juridico_usuarios (nome, email, senha_hash, ativo, criado_por_usuario_id)
                 VALUES (:nome, :email, :senha_hash, TRUE, :criado_por)'
            );
            $stmtInsert->execute([
                ':nome' => $nomeUsuario,
                ':email' => $emailUsuario !== '' ? $emailUsuario : null,
                ':senha_hash' => $senhaHash,
                ':criado_por' => aj_usuario_logado_id() ?: null,
            ]);

            $mensagem = 'Usuário jurídico cadastrado com sucesso.';
        }
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

$pastas = [];
$arquivos = [];
$arquivosPorPasta = [];
$usuariosJuridico = [];

try {
    $stmt = $pdo->query(
        'SELECT p.id, p.nome, p.descricao, p.criado_em, COUNT(a.id) AS total_arquivos
         FROM administrativo_juridico_pastas p
         LEFT JOIN administrativo_juridico_arquivos a ON a.pasta_id = p.id
         GROUP BY p.id
         ORDER BY p.nome ASC'
    );
    $pastas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    error_log('Juridico - listar pastas: ' . $e->getMessage());
}

try {
    $stmt = $pdo->query(
        'SELECT a.id, a.pasta_id, a.titulo, a.descricao, a.arquivo_nome, a.criado_em, a.tamanho_bytes,
                u.nome AS criado_por_nome
         FROM administrativo_juridico_arquivos a
         LEFT JOIN usuarios u ON u.id = a.criado_por_usuario_id
         ORDER BY a.criado_em DESC'
    );
    $arquivos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($arquivos as $arquivo) {
        $pastaId = (int)($arquivo['pasta_id'] ?? 0);
        if (!isset($arquivosPorPasta[$pastaId])) {
            $arquivosPorPasta[$pastaId] = [];
        }
        $arquivosPorPasta[$pastaId][] = $arquivo;
    }
} catch (Exception $e) {
    error_log('Juridico - listar arquivos: ' . $e->getMessage());
}

try {
    $stmt = $pdo->query(
        'SELECT id, nome, email, ativo, criado_em
         FROM administrativo_juridico_usuarios
         ORDER BY ativo DESC, nome ASC'
    );
    $usuariosJuridico = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    error_log('Juridico - listar usuarios: ' . $e->getMessage());
}

$baseUrl = aj_base_url();
$juridicoLoginLink = ($baseUrl !== '' ? $baseUrl : '') . '/index.php?page=juridico_login';
if ($baseUrl === '') {
    $juridicoLoginLink = 'index.php?page=juridico_login';
}

ob_start();
?>
<style>
    .aj-container { max-width: 1320px; margin: 0 auto; padding: 1.5rem; }
    .aj-title { margin: 0; font-size: 2rem; color: #1e3a8a; font-weight: 800; }
    .aj-subtitle { margin: .45rem 0 1.25rem; color: #64748b; }
    .aj-toolbar { display: flex; flex-wrap: wrap; gap: .65rem; margin-bottom: 1.1rem; }
    .aj-btn {
        border: 0; border-radius: 10px; padding: .64rem 1rem; font-weight: 700; cursor: pointer;
        background: #1d4ed8; color: #fff; text-decoration: none; display: inline-flex; align-items: center; gap: .45rem;
    }
    .aj-btn:hover { background: #1e40af; }
    .aj-btn.secondary { background: #0f766e; }
    .aj-btn.secondary:hover { background: #115e59; }
    .aj-btn.dark { background: #334155; }
    .aj-btn.dark:hover { background: #1e293b; }
    .aj-alert { border-radius: 10px; padding: .85rem 1rem; margin-bottom: 1rem; font-weight: 600; }
    .aj-alert.ok { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
    .aj-alert.err { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .aj-folder-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: .95rem; }
    .aj-folder-card {
        background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 3px 12px rgba(15, 23, 42, .06);
        padding: 1rem;
    }
    .aj-folder-head { display: flex; justify-content: space-between; gap: .65rem; align-items: flex-start; margin-bottom: .55rem; }
    .aj-folder-title { margin: 0; color: #0f172a; font-size: 1.06rem; }
    .aj-folder-count { background: #e0e7ff; color: #1d4ed8; border-radius: 999px; font-size: .74rem; font-weight: 700; padding: .25rem .55rem; }
    .aj-folder-desc { color: #475569; font-size: .88rem; margin-bottom: .7rem; }
    .aj-files-table-wrap { overflow-x: auto; }
    .aj-files-table { width: 100%; border-collapse: collapse; min-width: 460px; }
    .aj-files-table th, .aj-files-table td { border-bottom: 1px solid #e2e8f0; text-align: left; padding: .55rem .45rem; vertical-align: top; }
    .aj-files-table th { font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: #334155; background: #f8fafc; }
    .aj-file-title { font-weight: 700; color: #0f172a; }
    .aj-file-meta { color: #64748b; font-size: .78rem; margin-top: .2rem; }
    .aj-link { color: #1d4ed8; text-decoration: none; font-weight: 700; }
    .aj-link:hover { text-decoration: underline; }
    .aj-empty {
        background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 10px; padding: .9rem;
        color: #64748b; font-size: .9rem;
    }

    .aj-users-card { margin-top: 1.1rem; background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 1rem; }
    .aj-users-card h2 { margin: 0 0 .75rem 0; font-size: 1.05rem; color: #0f172a; }
    .aj-users-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: .65rem; }
    .aj-user-item { border: 1px solid #e2e8f0; border-radius: 10px; padding: .7rem; background: #f8fafc; }
    .aj-user-name { font-weight: 700; color: #0f172a; margin-bottom: .2rem; }
    .aj-user-meta { font-size: .8rem; color: #475569; }

    .aj-modal { display: none; position: fixed; inset: 0; z-index: 4200; background: rgba(2, 6, 23, .58); padding: 1rem; }
    .aj-modal.open { display: flex; align-items: center; justify-content: center; }
    .aj-modal-dialog { width: 100%; max-width: 640px; background: #fff; border-radius: 14px; overflow: hidden; box-shadow: 0 20px 40px rgba(2, 6, 23, .35); }
    .aj-modal-header { background: #1d4ed8; color: #fff; padding: .95rem 1rem; display: flex; justify-content: space-between; align-items: center; }
    .aj-modal-header h3 { margin: 0; font-size: 1rem; }
    .aj-modal-close { background: transparent; border: 0; color: #fff; font-size: 1.25rem; cursor: pointer; }
    .aj-modal-body { padding: 1rem; }
    .aj-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .75rem; }
    .aj-field { display: flex; flex-direction: column; }
    .aj-field label { margin-bottom: .34rem; font-weight: 600; color: #334155; font-size: .88rem; }
    .aj-field input, .aj-field textarea, .aj-field select {
        width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: .62rem .7rem; font-size: .92rem;
    }
    .aj-field textarea { min-height: 92px; resize: vertical; }
    .aj-modal-actions { display: flex; justify-content: flex-end; gap: .6rem; margin-top: .9rem; }
    .aj-btn-outline {
        border: 1px solid #cbd5e1; border-radius: 9px; padding: .6rem .9rem; background: #fff;
        color: #0f172a; font-weight: 700; cursor: pointer;
    }

    .aj-link-box { margin-top: .9rem; padding: .75rem; border: 1px solid #dbeafe; background: #eff6ff; border-radius: 10px; }
    .aj-copy-row { display: flex; gap: .45rem; margin-top: .45rem; }
    .aj-copy-row input { flex: 1; border: 1px solid #bfdbfe; border-radius: 8px; padding: .58rem .62rem; font-size: .88rem; }

    @media (max-width: 820px) {
        .aj-grid { grid-template-columns: 1fr; }
        .aj-title { font-size: 1.6rem; }
    }
</style>

<div class="aj-container">
    <h1 class="aj-title">⚖️ Jurídico</h1>
    <p class="aj-subtitle">Área para gestão de arquivos jurídicos por pasta, com acesso externo dedicado por usuário e senha.</p>

    <?php if ($mensagem !== ''): ?>
        <div class="aj-alert ok"><?= htmlspecialchars($mensagem) ?></div>
    <?php endif; ?>

    <?php if ($erro !== ''): ?>
        <div class="aj-alert err"><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>

    <div class="aj-toolbar">
        <button type="button" class="aj-btn" onclick="openAjModal('modalPasta')">📁 Criar pasta</button>
        <button type="button" class="aj-btn secondary" onclick="openAjModal('modalArquivo')">📎 Adicionar arquivos</button>
        <button type="button" class="aj-btn dark" onclick="openAjModal('modalUsuario')">👤 Usuário</button>
    </div>

    <?php if (empty($pastas)): ?>
        <div class="aj-empty">Nenhuma pasta criada ainda. Clique em <strong>Criar pasta</strong> para começar a organizar os arquivos.</div>
    <?php else: ?>
        <div class="aj-folder-grid">
            <?php foreach ($pastas as $pasta): ?>
                <?php $pastaId = (int)($pasta['id'] ?? 0); ?>
                <?php $arquivosDaPasta = $arquivosPorPasta[$pastaId] ?? []; ?>
                <div class="aj-folder-card">
                    <div class="aj-folder-head">
                        <h2 class="aj-folder-title">📁 <?= htmlspecialchars((string)($pasta['nome'] ?? 'Pasta')) ?></h2>
                        <span class="aj-folder-count"><?= count($arquivosDaPasta) ?> arquivo(s)</span>
                    </div>

                    <?php if (!empty($pasta['descricao'])): ?>
                        <div class="aj-folder-desc"><?= nl2br(htmlspecialchars((string)$pasta['descricao'])) ?></div>
                    <?php endif; ?>

                    <?php if (empty($arquivosDaPasta)): ?>
                        <div class="aj-empty">Sem arquivos nesta pasta.</div>
                    <?php else: ?>
                        <div class="aj-files-table-wrap">
                            <table class="aj-files-table">
                                <thead>
                                    <tr>
                                        <th>Arquivo</th>
                                        <th>Descrição</th>
                                        <th>Data</th>
                                        <th>Ação</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($arquivosDaPasta as $arquivo): ?>
                                        <tr>
                                            <td>
                                                <div class="aj-file-title"><?= htmlspecialchars((string)($arquivo['titulo'] ?? 'Documento')) ?></div>
                                                <div class="aj-file-meta"><?= htmlspecialchars((string)($arquivo['arquivo_nome'] ?? '')) ?> • <?= htmlspecialchars(aj_format_bytes(isset($arquivo['tamanho_bytes']) ? (int)$arquivo['tamanho_bytes'] : null)) ?></div>
                                            </td>
                                            <td>
                                                <?php if (!empty($arquivo['descricao'])): ?>
                                                    <?= nl2br(htmlspecialchars((string)$arquivo['descricao'])) ?>
                                                <?php else: ?>
                                                    <span class="aj-file-meta">Sem descrição</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= !empty($arquivo['criado_em']) ? date('d/m/Y H:i', strtotime((string)$arquivo['criado_em'])) : '-' ?><br>
                                                <span class="aj-file-meta">por <?= htmlspecialchars((string)($arquivo['criado_por_nome'] ?? 'sistema')) ?></span>
                                            </td>
                                            <td>
                                                <a class="aj-link" href="juridico_download.php?id=<?= (int)($arquivo['id'] ?? 0) ?>" target="_blank" rel="noopener">Abrir</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="aj-users-card">
        <h2>Usuários jurídicos cadastrados</h2>
        <?php if (empty($usuariosJuridico)): ?>
            <div class="aj-empty">Nenhum usuário jurídico cadastrado.</div>
        <?php else: ?>
            <div class="aj-users-list">
                <?php foreach ($usuariosJuridico as $user): ?>
                    <div class="aj-user-item">
                        <div class="aj-user-name"><?= htmlspecialchars((string)($user['nome'] ?? '')) ?></div>
                        <div class="aj-user-meta"><?= !empty($user['email']) ? htmlspecialchars((string)$user['email']) : 'Sem e-mail' ?></div>
                        <div class="aj-user-meta">Criado em: <?= !empty($user['criado_em']) ? date('d/m/Y H:i', strtotime((string)$user['criado_em'])) : '-' ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="aj-modal" id="modalPasta" aria-hidden="true">
    <div class="aj-modal-dialog">
        <div class="aj-modal-header">
            <h3>Criar pasta</h3>
            <button type="button" class="aj-modal-close" onclick="closeAjModal('modalPasta')">×</button>
        </div>
        <div class="aj-modal-body">
            <form method="POST">
                <input type="hidden" name="acao" value="criar_pasta">

                <div class="aj-grid">
                    <div class="aj-field" style="grid-column: 1 / -1;">
                        <label>Nome da pasta *</label>
                        <input type="text" name="nome_pasta" maxlength="150" required>
                    </div>
                    <div class="aj-field" style="grid-column: 1 / -1;">
                        <label>Descrição (opcional)</label>
                        <textarea name="descricao_pasta" placeholder="Ex: Contratos em andamento, processos, notificações..."></textarea>
                    </div>
                </div>

                <div class="aj-modal-actions">
                    <button type="button" class="aj-btn-outline" onclick="closeAjModal('modalPasta')">Cancelar</button>
                    <button type="submit" class="aj-btn">Salvar pasta</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="aj-modal" id="modalArquivo" aria-hidden="true">
    <div class="aj-modal-dialog">
        <div class="aj-modal-header" style="background:#0f766e;">
            <h3>Adicionar arquivos</h3>
            <button type="button" class="aj-modal-close" onclick="closeAjModal('modalArquivo')">×</button>
        </div>
        <div class="aj-modal-body">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="acao" value="adicionar_arquivo">

                <div class="aj-grid">
                    <div class="aj-field" style="grid-column: 1 / -1;">
                        <label>Pasta *</label>
                        <select name="pasta_id" required>
                            <option value="">Selecione uma pasta</option>
                            <?php foreach ($pastas as $pasta): ?>
                                <option value="<?= (int)($pasta['id'] ?? 0) ?>"><?= htmlspecialchars((string)($pasta['nome'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="aj-field" style="grid-column: 1 / -1;">
                        <label>Título do arquivo (opcional)</label>
                        <input type="text" name="titulo" maxlength="255" placeholder="Se vazio, usa o nome original do arquivo">
                    </div>

                    <div class="aj-field" style="grid-column: 1 / -1;">
                        <label>Descrição do arquivo</label>
                        <textarea name="descricao" placeholder="Descreva o conteúdo deste arquivo para facilitar a identificação"></textarea>
                    </div>

                    <div class="aj-field" style="grid-column: 1 / -1;">
                        <label>Arquivo *</label>
                        <input type="file" name="arquivo" required>
                    </div>
                </div>

                <div class="aj-modal-actions">
                    <button type="button" class="aj-btn-outline" onclick="closeAjModal('modalArquivo')">Cancelar</button>
                    <button type="submit" class="aj-btn secondary">Enviar arquivo</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="aj-modal" id="modalUsuario" aria-hidden="true">
    <div class="aj-modal-dialog">
        <div class="aj-modal-header" style="background:#334155;">
            <h3>Adicionar usuário jurídico</h3>
            <button type="button" class="aj-modal-close" onclick="closeAjModal('modalUsuario')">×</button>
        </div>
        <div class="aj-modal-body">
            <form method="POST">
                <input type="hidden" name="acao" value="cadastrar_usuario">

                <div class="aj-grid">
                    <div class="aj-field" style="grid-column: 1 / -1;">
                        <label>Nome do usuário *</label>
                        <input type="text" name="nome_usuario" maxlength="120" required>
                    </div>

                    <div class="aj-field" style="grid-column: 1 / -1;">
                        <label>E-mail (opcional)</label>
                        <input type="email" name="email_usuario" maxlength="180" placeholder="Opcional">
                    </div>

                    <div class="aj-field">
                        <label>Senha *</label>
                        <input type="password" name="senha_usuario" minlength="6" required>
                    </div>

                    <div class="aj-field">
                        <label>Confirmar senha *</label>
                        <input type="password" name="senha_usuario_confirmacao" minlength="6" required>
                    </div>
                </div>

                <div class="aj-link-box">
                    <strong>Link para enviar para a advogada:</strong>
                    <div class="aj-copy-row">
                        <input type="text" id="juridicoLoginLink" value="<?= htmlspecialchars($juridicoLoginLink) ?>" readonly>
                        <button type="button" class="aj-btn dark" onclick="copyJuridicoLink()">Copiar</button>
                    </div>
                </div>

                <div class="aj-modal-actions">
                    <button type="button" class="aj-btn-outline" onclick="closeAjModal('modalUsuario')">Cancelar</button>
                    <button type="submit" class="aj-btn dark">Salvar usuário</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function openAjModal(id) {
        var modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeAjModal(id) {
        var modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
    }

    document.querySelectorAll('.aj-modal').forEach(function(modal) {
        modal.addEventListener('click', function(event) {
            if (event.target === modal) {
                modal.classList.remove('open');
                modal.setAttribute('aria-hidden', 'true');
            }
        });
    });

    document.addEventListener('keydown', function(event) {
        if (event.key !== 'Escape') return;
        document.querySelectorAll('.aj-modal.open').forEach(function(modal) {
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
        });
    });

    function copyJuridicoLink() {
        var input = document.getElementById('juridicoLoginLink');
        if (!input) return;

        var value = input.value;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(function() {
                if (typeof customAlert === 'function') {
                    customAlert('Link copiado para a área de transferência.', 'Sucesso');
                }
            }).catch(function() {
                input.select();
                document.execCommand('copy');
            });
            return;
        }

        input.select();
        input.setSelectionRange(0, value.length);
        document.execCommand('copy');
    }
</script>

<?php
$conteudo = ob_get_clean();
includeSidebar('Jurídico');
echo $conteudo;
endSidebar();
