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
require_once __DIR__ . '/setup_gestao_documentos.php';
require_once __DIR__ . '/core/clicksign_helper.php';
require_once __DIR__ . '/core/push_helper.php';
require_once __DIR__ . '/sidebar_integration.php';

setupGestaoDocumentos($pdo);

function gd_usuario_logado_id(): int
{
    return (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
}

function gd_tipo_label(string $tipo): string
{
    $map = [
        'holerite' => 'Holerite',
        'folha_ponto' => 'Folha de ponto',
        'outro' => 'Outro',
    ];

    return $map[$tipo] ?? ucfirst($tipo);
}

function gd_status_label(string $status): string
{
    $map = [
        'nao_solicitada' => 'Sem assinatura',
        'pendente_envio' => 'Pendente envio',
        'enviado' => 'Enviado para assinar',
        'assinado' => 'Assinado',
        'cancelado' => 'Cancelado',
        'recusado' => 'Recusado',
        'erro' => 'Erro',
    ];

    return $map[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function gd_status_class(string $status): string
{
    $map = [
        'nao_solicitada' => 'badge-neutral',
        'pendente_envio' => 'badge-warning',
        'enviado' => 'badge-info',
        'assinado' => 'badge-success',
        'cancelado' => 'badge-neutral',
        'recusado' => 'badge-error',
        'erro' => 'badge-error',
    ];

    return $map[$status] ?? 'badge-neutral';
}

function gd_disparar_push_documento(int $usuarioId, string $titulo, string $mensagem): void
{
    if ($usuarioId <= 0) {
        return;
    }

    try {
        $push = new PushHelper();
        $push->enviarPush($usuarioId, $titulo, $mensagem, [
            'url' => 'index.php?page=minha_conta',
            'tipo' => 'documento',
        ]);
    } catch (Exception $e) {
        error_log('Gestao Documentos - push: ' . $e->getMessage());
    }
}

$mensagem = '';
$erro = '';
$clicksign = new ClicksignHelper();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = trim((string)($_POST['acao'] ?? ''));

    if ($acao === 'cadastrar_documento') {
        try {
            $usuarioId = (int)($_POST['usuario_id'] ?? 0);
            $tipoDocumento = trim((string)($_POST['tipo_documento'] ?? 'outro'));
            $titulo = trim((string)($_POST['titulo'] ?? ''));
            $competencia = trim((string)($_POST['competencia'] ?? ''));
            $descricao = trim((string)($_POST['descricao'] ?? ''));
            $exibirMinhaConta = isset($_POST['exibir_minha_conta']);
            $exigirAssinatura = isset($_POST['exigir_assinatura']);

            if ($usuarioId <= 0) {
                throw new Exception('Selecione o usuário do documento.');
            }

            if (!in_array($tipoDocumento, ['holerite', 'folha_ponto', 'outro'], true)) {
                $tipoDocumento = 'outro';
            }

            if ($competencia !== '' && !preg_match('/^\\d{2}\\/\\d{4}$/', $competencia)) {
                throw new Exception('Competência inválida. Use MM/AAAA.');
            }

            if ($titulo === '') {
                if ($tipoDocumento === 'holerite' && $competencia !== '') {
                    $titulo = 'Holerite ' . $competencia;
                } elseif ($tipoDocumento === 'folha_ponto' && $competencia !== '') {
                    $titulo = 'Folha de ponto ' . $competencia;
                } else {
                    throw new Exception('Informe o título do documento.');
                }
            }

            if (!isset($_FILES['arquivo']) || (int)($_FILES['arquivo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new Exception('Anexe um arquivo para o documento.');
            }

            $stmtUsuario = $pdo->prepare('SELECT id, nome, email FROM usuarios WHERE id = :id LIMIT 1');
            $stmtUsuario->execute([':id' => $usuarioId]);
            $usuario = $stmtUsuario->fetch(PDO::FETCH_ASSOC);

            if (!$usuario) {
                throw new Exception('Usuário selecionado não foi encontrado.');
            }

            $uploader = new MagaluUpload();
            $resultadoUpload = $uploader->upload($_FILES['arquivo'], 'administrativo/gestao_documentos/' . $usuarioId);

            $arquivoUrl = $resultadoUpload['url'] ?? null;
            $chaveStorage = $resultadoUpload['chave_storage'] ?? null;
            $arquivoNome = $resultadoUpload['nome_original'] ?? ($_FILES['arquivo']['name'] ?? 'documento');

            $statusAssinatura = $exigirAssinatura ? 'pendente_envio' : 'nao_solicitada';

            $stmtInsert = $pdo->prepare(
                'INSERT INTO administrativo_documentos_colaboradores
                (usuario_id, tipo_documento, titulo, competencia, descricao, arquivo_url, arquivo_nome, chave_storage,
                 exibir_minha_conta, exigir_assinatura, status_assinatura, criado_por_usuario_id)
                VALUES
                (:usuario_id, :tipo_documento, :titulo, :competencia, :descricao, :arquivo_url, :arquivo_nome, :chave_storage,
                 :exibir_minha_conta, :exigir_assinatura, :status_assinatura, :criado_por)
                RETURNING id'
            );
            $stmtInsert->execute([
                ':usuario_id' => $usuarioId,
                ':tipo_documento' => $tipoDocumento,
                ':titulo' => $titulo,
                ':competencia' => $competencia !== '' ? $competencia : null,
                ':descricao' => $descricao !== '' ? $descricao : null,
                ':arquivo_url' => $arquivoUrl,
                ':arquivo_nome' => $arquivoNome,
                ':chave_storage' => $chaveStorage,
                ':exibir_minha_conta' => $exibirMinhaConta,
                ':exigir_assinatura' => $exigirAssinatura,
                ':status_assinatura' => $statusAssinatura,
                ':criado_por' => gd_usuario_logado_id() ?: null,
            ]);

            $documentoId = (int)$stmtInsert->fetchColumn();

            $erroAssinatura = null;
            $assinaturaEnviada = false;

            if ($exigirAssinatura) {
                if (!$clicksign->isConfigured()) {
                    $erroAssinatura = $clicksign->getConfigurationError();
                } elseif (empty($usuario['email']) || !filter_var($usuario['email'], FILTER_VALIDATE_EMAIL)) {
                    $erroAssinatura = 'Usuário sem e-mail válido para assinatura na Clicksign.';
                } else {
                    $tmpPath = (string)($_FILES['arquivo']['tmp_name'] ?? '');
                    $raw = @file_get_contents($tmpPath);
                    if ($raw === false || $raw === '') {
                        $erroAssinatura = 'Não foi possível ler o arquivo para envio à Clicksign.';
                    } else {
                        $conteudoBase64 = base64_encode($raw);
                        $deadlineAt = (new DateTime('+20 days'))->format(DateTime::RFC3339);

                        $resAssinatura = $clicksign->criarFluxoAssinatura([
                            'envelope_name' => $titulo . ' - ' . ($usuario['nome'] ?? 'Colaborador'),
                            'filename' => $arquivoNome,
                            'content_base64' => $conteudoBase64,
                            'signer_name' => (string)$usuario['nome'],
                            'signer_email' => (string)$usuario['email'],
                            'deadline_at' => $deadlineAt,
                            'notification_message' => 'Olá! Você possui um documento pendente para assinatura no Grupo Smile.',
                        ]);

                        if (!($resAssinatura['success'] ?? false)) {
                            $erroAssinatura = (string)($resAssinatura['error'] ?? 'Erro desconhecido ao criar assinatura.');
                        } else {
                            $assinaturaEnviada = true;
                            $stmtUpdateAssinatura = $pdo->prepare(
                                'UPDATE administrativo_documentos_colaboradores SET
                                    status_assinatura = :status_assinatura,
                                    clicksign_envelope_id = :clicksign_envelope_id,
                                    clicksign_document_id = :clicksign_document_id,
                                    clicksign_signer_id = :clicksign_signer_id,
                                    clicksign_sign_url = :clicksign_sign_url,
                                    clicksign_payload = :clicksign_payload::jsonb,
                                    clicksign_ultimo_erro = NULL,
                                    enviado_assinatura_em = NOW(),
                                    atualizado_em = NOW()
                                 WHERE id = :id'
                            );
                            $stmtUpdateAssinatura->execute([
                                ':status_assinatura' => 'enviado',
                                ':clicksign_envelope_id' => $resAssinatura['envelope_id'] ?? null,
                                ':clicksign_document_id' => $resAssinatura['document_id'] ?? null,
                                ':clicksign_signer_id' => $resAssinatura['signer_id'] ?? null,
                                ':clicksign_sign_url' => $resAssinatura['sign_url'] ?? null,
                                ':clicksign_payload' => json_encode($resAssinatura['raw'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                ':id' => $documentoId,
                            ]);

                            if (!empty($resAssinatura['notify_error'])) {
                                $mensagem = 'Documento salvo. Assinatura criada, porém houve falha no envio de notificação da Clicksign.';
                            }
                        }
                    }
                }
            }

            if ($erroAssinatura !== null) {
                $stmtErroAssinatura = $pdo->prepare(
                    'UPDATE administrativo_documentos_colaboradores SET
                        status_assinatura = :status_assinatura,
                        clicksign_ultimo_erro = :clicksign_ultimo_erro,
                        atualizado_em = NOW()
                     WHERE id = :id'
                );
                $stmtErroAssinatura->execute([
                    ':status_assinatura' => 'erro',
                    ':clicksign_ultimo_erro' => $erroAssinatura,
                    ':id' => $documentoId,
                ]);
            }

            if ($exibirMinhaConta) {
                if ($assinaturaEnviada) {
                    gd_disparar_push_documento(
                        $usuarioId,
                        'Documento para assinatura',
                        'Você tem um documento enviado para assinatura. Veja em Minha Conta.'
                    );
                } else {
                    gd_disparar_push_documento(
                        $usuarioId,
                        'Novo documento disponível',
                        'Um novo documento foi disponibilizado para você em Minha Conta.'
                    );
                }
            }

            if ($erroAssinatura !== null) {
                $mensagem = 'Documento salvo, mas a solicitação de assinatura falhou: ' . $erroAssinatura;
            } elseif ($assinaturaEnviada) {
                $mensagem = 'Documento salvo e enviado para assinatura na Clicksign.';
            } else {
                $mensagem = 'Documento salvo com sucesso.';
            }
        } catch (Exception $e) {
            $erro = $e->getMessage();
        }
    }

    if ($acao === 'atualizar_assinatura') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('Documento inválido para atualização de assinatura.');
            }

            $stmtDoc = $pdo->prepare('SELECT id, clicksign_envelope_id FROM administrativo_documentos_colaboradores WHERE id = :id LIMIT 1');
            $stmtDoc->execute([':id' => $id]);
            $doc = $stmtDoc->fetch(PDO::FETCH_ASSOC);

            if (!$doc || empty($doc['clicksign_envelope_id'])) {
                throw new Exception('Documento sem envelope Clicksign vinculado.');
            }

            $resStatus = $clicksign->atualizarStatusEnvelope((string)$doc['clicksign_envelope_id']);
            if (!($resStatus['success'] ?? false)) {
                throw new Exception((string)($resStatus['error'] ?? 'Falha ao consultar status na Clicksign.'));
            }

            $novoStatus = (string)($resStatus['status_local'] ?? 'enviado');
            $statusClicksign = (string)($resStatus['status_clicksign'] ?? '');

            $sql =
                'UPDATE administrativo_documentos_colaboradores SET
                    status_assinatura = :status_assinatura,
                    clicksign_payload = :clicksign_payload::jsonb,
                    clicksign_ultimo_erro = NULL,
                    atualizado_em = NOW()' .
                ($novoStatus === 'assinado' ? ', assinado_em = COALESCE(assinado_em, NOW())' : '') .
                ' WHERE id = :id';

            $stmtUp = $pdo->prepare($sql);
            $stmtUp->execute([
                ':status_assinatura' => $novoStatus,
                ':clicksign_payload' => json_encode($resStatus['data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':id' => $id,
            ]);

            $mensagem = 'Status atualizado na Clicksign: ' . ($statusClicksign !== '' ? $statusClicksign : $novoStatus) . '.';
        } catch (Exception $e) {
            $erro = $e->getMessage();
        }
    }

    if ($acao === 'reenviar_assinatura') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('Documento inválido para reenvio.');
            }

            $stmtDoc = $pdo->prepare('SELECT id, clicksign_envelope_id, clicksign_signer_id FROM administrativo_documentos_colaboradores WHERE id = :id LIMIT 1');
            $stmtDoc->execute([':id' => $id]);
            $doc = $stmtDoc->fetch(PDO::FETCH_ASSOC);

            if (!$doc || empty($doc['clicksign_envelope_id']) || empty($doc['clicksign_signer_id'])) {
                throw new Exception('Documento sem dados de assinatura para reenvio.');
            }

            $resReenvio = $clicksign->reenviarNotificacao((string)$doc['clicksign_envelope_id'], (string)$doc['clicksign_signer_id']);
            if (!($resReenvio['success'] ?? false)) {
                throw new Exception((string)($resReenvio['error'] ?? 'Falha ao reenviar assinatura.'));
            }

            $stmtOk = $pdo->prepare('UPDATE administrativo_documentos_colaboradores SET status_assinatura = :status, clicksign_ultimo_erro = NULL, atualizado_em = NOW() WHERE id = :id');
            $stmtOk->execute([
                ':status' => 'enviado',
                ':id' => $id,
            ]);

            $mensagem = 'Solicitação de assinatura reenviada com sucesso.';
        } catch (Exception $e) {
            $erro = $e->getMessage();
        }
    }
}

$usuarios = [];
try {
    $stmt = $pdo->query("SELECT id, nome, email FROM usuarios WHERE ativo IS DISTINCT FROM FALSE ORDER BY nome ASC");
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Gestao Documentos - listar usuarios: ' . $e->getMessage());
}

$documentos = [];
try {
    $stmt = $pdo->query(
        "SELECT d.id, d.usuario_id, d.tipo_documento, d.titulo, d.competencia, d.arquivo_nome, d.criado_em,
                d.exibir_minha_conta, d.exigir_assinatura, d.status_assinatura,
                d.clicksign_envelope_id, d.clicksign_signer_id, d.clicksign_ultimo_erro,
                u.nome AS usuario_nome, u.email AS usuario_email,
                uc.nome AS criado_por_nome
         FROM administrativo_documentos_colaboradores d
         JOIN usuarios u ON u.id = d.usuario_id
         LEFT JOIN usuarios uc ON uc.id = d.criado_por_usuario_id
         ORDER BY d.criado_em DESC
         LIMIT 120"
    );
    $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Gestao Documentos - listar documentos: ' . $e->getMessage());
}

ob_start();
?>
<style>
    .gd-container { max-width: 1320px; margin: 0 auto; padding: 1.5rem; }
    .gd-title { margin: 0 0 .35rem 0; font-size: 2rem; color: #1e3a8a; font-weight: 800; }
    .gd-subtitle { margin: 0 0 1.3rem 0; color: #64748b; }
    .gd-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; box-shadow: 0 3px 10px rgba(15, 23, 42, .06); padding: 1.2rem; margin-bottom: 1.1rem; }
    .gd-card h2 { margin: 0 0 1rem 0; color: #0f172a; font-size: 1.1rem; }
    .gd-alert { padding: .85rem 1rem; border-radius: 10px; margin-bottom: 1rem; font-weight: 600; }
    .gd-alert.ok { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
    .gd-alert.err { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
    .gd-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .9rem 1rem; }
    .gd-field { display: flex; flex-direction: column; }
    .gd-field label { margin-bottom: .35rem; font-weight: 600; color: #334155; font-size: .88rem; }
    .gd-field input, .gd-field textarea, .gd-field select {
        width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: .62rem .72rem; font-size: .92rem;
    }
    .gd-field textarea { min-height: 88px; resize: vertical; }
    .gd-actions { margin-top: .95rem; display: flex; gap: .65rem; align-items: center; flex-wrap: wrap; }
    .gd-btn {
        border: 0; border-radius: 9px; padding: .62rem .95rem; font-weight: 700; cursor: pointer;
        background: #1d4ed8; color: #fff; text-decoration: none; display: inline-flex; align-items: center; gap: .35rem;
    }
    .gd-btn:hover { background: #1e40af; }
    .gd-btn.secondary { background: #e2e8f0; color: #0f172a; }
    .gd-btn.secondary:hover { background: #cbd5e1; }
    .gd-check { display: inline-flex; gap: .45rem; align-items: center; color: #334155; font-weight: 600; }
    .gd-check input { transform: translateY(1px); }
    .gd-table-wrap { overflow: auto; }
    .gd-table { width: 100%; border-collapse: collapse; min-width: 1080px; }
    .gd-table th, .gd-table td { padding: .68rem .65rem; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: middle; }
    .gd-table th { background: #f8fafc; color: #0f172a; font-size: .84rem; text-transform: uppercase; letter-spacing: .03em; }
    .gd-small { color: #64748b; font-size: .8rem; }
    .gd-link { color: #1d4ed8; text-decoration: none; font-weight: 700; }
    .gd-link:hover { text-decoration: underline; }
    .gd-badge { border-radius: 999px; padding: .22rem .52rem; font-size: .75rem; font-weight: 700; display: inline-block; }
    .badge-neutral { background: #e2e8f0; color: #334155; }
    .badge-info { background: #dbeafe; color: #1d4ed8; }
    .badge-success { background: #dcfce7; color: #166534; }
    .badge-warning { background: #fef3c7; color: #92400e; }
    .badge-error { background: #fee2e2; color: #991b1b; }
    .gd-config { margin-top: .7rem; padding: .7rem .9rem; border-radius: 10px; }
    .gd-config.ok { background: #dcfce7; color: #14532d; border: 1px solid #86efac; }
    .gd-config.err { background: #fff7ed; color: #9a3412; border: 1px solid #fdba74; }
    @media (max-width: 960px) {
        .gd-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="gd-container">
    <h1 class="gd-title">Gestão de Documentos</h1>
    <p class="gd-subtitle">Envie holerites, folha de ponto e outros documentos por colaborador. Controle se aparece em <strong>Minha Conta</strong> e se precisa de assinatura na Clicksign.</p>

    <?php if ($mensagem !== ''): ?>
        <div class="gd-alert ok"><?= htmlspecialchars($mensagem) ?></div>
    <?php endif; ?>

    <?php if ($erro !== ''): ?>
        <div class="gd-alert err"><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>

    <div class="gd-card">
        <h2>➕ Novo lançamento de documento</h2>

        <?php if ($clicksign->isConfigured()): ?>
            <div class="gd-config ok">Integração Clicksign: ativa (token configurado).</div>
        <?php else: ?>
            <div class="gd-config err">Integração Clicksign: inativa. Configure a variável <code>CLICKSIGN_API_TOKEN</code> no ambiente para habilitar assinatura.</div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" style="margin-top: .9rem;">
            <input type="hidden" name="acao" value="cadastrar_documento">

            <div class="gd-grid">
                <div class="gd-field">
                    <label>Colaborador *</label>
                    <select name="usuario_id" required>
                        <option value="">Selecione</option>
                        <?php foreach ($usuarios as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['nome']) ?><?= !empty($u['email']) ? ' (' . htmlspecialchars($u['email']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="gd-field">
                    <label>Tipo de documento *</label>
                    <select name="tipo_documento" required>
                        <option value="holerite">Holerite</option>
                        <option value="folha_ponto">Folha de ponto</option>
                        <option value="outro">Outro</option>
                    </select>
                </div>

                <div class="gd-field">
                    <label>Título *</label>
                    <input type="text" name="titulo" placeholder="Ex: Holerite 01/2026" maxlength="255" required>
                </div>

                <div class="gd-field">
                    <label>Competência (MM/AAAA)</label>
                    <input type="text" name="competencia" placeholder="Ex: 01/2026" maxlength="7">
                </div>

                <div class="gd-field" style="grid-column: 1 / -1;">
                    <label>Descrição (opcional)</label>
                    <textarea name="descricao" placeholder="Observações para o colaborador..."></textarea>
                </div>

                <div class="gd-field" style="grid-column: 1 / -1;">
                    <label>Arquivo *</label>
                    <input type="file" name="arquivo" required>
                    <span class="gd-small">O arquivo será salvo no storage da Magalu e disponibilizado de forma segura.</span>
                </div>
            </div>

            <div class="gd-actions">
                <label class="gd-check">
                    <input type="checkbox" name="exibir_minha_conta" checked>
                    Exibir em Minha Conta do usuário
                </label>

                <label class="gd-check">
                    <input type="checkbox" name="exigir_assinatura">
                    Solicitar assinatura via Clicksign
                </label>
            </div>

            <div class="gd-actions">
                <button type="submit" class="gd-btn">Salvar Documento</button>
            </div>
        </form>
    </div>

    <div class="gd-card">
        <h2>📄 Documentos recentes</h2>

        <div class="gd-table-wrap">
            <table class="gd-table">
                <thead>
                <tr>
                    <th>Colaborador</th>
                    <th>Documento</th>
                    <th>Tipo</th>
                    <th>Minha Conta</th>
                    <th>Assinatura</th>
                    <th>Status</th>
                    <th>Data</th>
                    <th>Ações</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($documentos)): ?>
                    <tr>
                        <td colspan="8" class="gd-small">Nenhum documento lançado ainda.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($documentos as $d): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($d['usuario_nome'] ?? 'Usuário') ?></strong><br>
                                <span class="gd-small"><?= htmlspecialchars($d['usuario_email'] ?? '') ?></span>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($d['titulo'] ?? '') ?></strong><br>
                                <span class="gd-small"><?= htmlspecialchars($d['arquivo_nome'] ?? '') ?><?= !empty($d['competencia']) ? ' • ' . htmlspecialchars($d['competencia']) : '' ?></span>
                            </td>
                            <td><?= htmlspecialchars(gd_tipo_label((string)$d['tipo_documento'])) ?></td>
                            <td>
                                <?php if (!empty($d['exibir_minha_conta'])): ?>
                                    <span class="gd-badge badge-success">Sim</span>
                                <?php else: ?>
                                    <span class="gd-badge badge-neutral">Não</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($d['exigir_assinatura'])): ?>
                                    <span class="gd-badge badge-info">Sim</span>
                                <?php else: ?>
                                    <span class="gd-badge badge-neutral">Não</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="gd-badge <?= gd_status_class((string)$d['status_assinatura']) ?>">
                                    <?= htmlspecialchars(gd_status_label((string)$d['status_assinatura'])) ?>
                                </span>
                                <?php if (!empty($d['clicksign_ultimo_erro'])): ?>
                                    <div class="gd-small" style="color:#b91c1c; margin-top:.35rem; max-width: 250px;">
                                        <?= htmlspecialchars($d['clicksign_ultimo_erro']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= !empty($d['criado_em']) ? date('d/m/Y H:i', strtotime((string)$d['criado_em'])) : '-' ?><br>
                                <span class="gd-small">por <?= htmlspecialchars($d['criado_por_nome'] ?? 'sistema') ?></span>
                            </td>
                            <td>
                                <a class="gd-link" href="contabilidade_download.php?tipo=gestao_documento&id=<?= (int)$d['id'] ?>" target="_blank">Ver/Baixar</a>

                                <?php if (!empty($d['exigir_assinatura']) && !empty($d['clicksign_envelope_id'])): ?>
                                    <form method="POST" style="margin-top:.45rem;">
                                        <input type="hidden" name="acao" value="atualizar_assinatura">
                                        <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                        <button type="submit" class="gd-btn secondary" style="padding:.35rem .55rem;">Atualizar status</button>
                                    </form>

                                    <?php if (!empty($d['clicksign_signer_id'])): ?>
                                        <form method="POST" style="margin-top:.35rem;">
                                            <input type="hidden" name="acao" value="reenviar_assinatura">
                                            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                            <button type="submit" class="gd-btn secondary" style="padding:.35rem .55rem;">Reenviar assinatura</button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$conteudo = ob_get_clean();
includeSidebar('Gestão de Documentos');
echo $conteudo;
endSidebar();
