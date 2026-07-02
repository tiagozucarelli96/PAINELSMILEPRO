<?php
/**
 * eventos_documentos.php
 * Contratos e documentos gerais do evento.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/eventos_documentos_helper.php';

if (empty($_SESSION['perm_agenda_eventos']) && empty($_SESSION['perm_superadmin'])) {
    echo '<div style="padding:24px;font-family:Arial,sans-serif;color:#991b1b;">Acesso negado.</div>';
    return;
}

eventos_documentos_ensure_schema($pdo);

$eventoId = (int)($_GET['evento_id'] ?? $_POST['evento_id'] ?? 0);
$evento = $eventoId > 0 ? eventos_documentos_evento($pdo, $eventoId) : null;
$userId = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? 0);
$messages = [];
$errors = [];

if (!$evento) {
    echo '<div style="padding:24px;font-family:Arial,sans-serif;color:#991b1b;">Evento não encontrado.</div>';
    return;
}

if (!empty($_SESSION['eventos_documentos_message'])) {
    $messages[] = (string)$_SESSION['eventos_documentos_message'];
    unset($_SESSION['eventos_documentos_message']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'generate_documento') {
            $modeloId = (int)($_POST['modelo_id'] ?? 0);
            $modelo = $modeloId > 0 ? eventos_documentos_buscar_modelo($pdo, $modeloId) : null;
            if (!$modelo) {
                throw new RuntimeException('Selecione um modelo válido.');
            }
            $clienteNome = trim((string)($evento['cliente_nome'] ?? ''));
            $sufixo = $clienteNome !== '' ? $clienteNome : (string)($evento['nome_evento'] ?? 'Evento');
            $titulo = trim((string)$modelo['nome']) . ' - ' . $sufixo;
            $conteudo = eventos_documentos_renderizar_modelo(
                (string)($modelo['conteudo_html'] ?? ''),
                eventos_documentos_mapa_tags($pdo, $eventoId, $evento)
            );
            eventos_documentos_criar($pdo, $eventoId, $modeloId, $titulo, $conteudo, $userId);
            $_SESSION['eventos_documentos_message'] = 'Documento gerado.';
            header('Location: index.php?page=eventos_documentos&evento_id=' . $eventoId);
            exit;
        }

        if ($action === 'save_documento') {
            $documentoId = (int)($_POST['documento_id'] ?? 0);
            $titulo = trim((string)($_POST['titulo'] ?? ''));
            $conteudo = (string)($_POST['conteudo_html'] ?? '');
            if ($documentoId <= 0 || $titulo === '') {
                throw new RuntimeException('Informe um título válido.');
            }
            eventos_documentos_atualizar($pdo, $eventoId, $documentoId, $titulo, $conteudo);
            $_SESSION['eventos_documentos_message'] = 'Documento atualizado.';
            header('Location: index.php?page=eventos_documentos&evento_id=' . $eventoId);
            exit;
        }

        if ($action === 'delete_documento') {
            $documentoId = (int)($_POST['documento_id'] ?? 0);
            if ($documentoId <= 0) {
                throw new RuntimeException('Documento inválido.');
            }
            eventos_documentos_excluir($pdo, $eventoId, $documentoId, $userId);
            $_SESSION['eventos_documentos_message'] = 'Documento excluído.';
            header('Location: index.php?page=eventos_documentos&evento_id=' . $eventoId);
            exit;
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$modelos = eventos_documentos_modelos($pdo);
$documentosGerais = eventos_documentos_listar($pdo, $eventoId);
$documentosFormatura = eventos_documentos_listar_formatura($pdo, $eventoId);
$documentos = array_merge($documentosGerais, $documentosFormatura);
usort($documentos, static function (array $a, array $b): int {
    return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
});

function eventos_documentos_data_hora_br(?string $date): string
{
    $date = trim((string)$date);
    if ($date === '') {
        return '-';
    }
    $time = strtotime($date);
    return $time ? date('d/m/Y H:i', $time) : $date;
}
includeSidebar('Contratos e Documentos');
?>
    <style>
        body { margin:0; background:#f4f7fb; color:#26364d; font-family: Arial, sans-serif; }
        .doc-page { padding:28px clamp(18px, 4vw, 56px); }
        .doc-header { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; margin-bottom:22px; }
        .doc-title h1 { margin:0; color:#17357a; font-size:30px; line-height:1.15; }
        .doc-title p { margin:6px 0 0; color:#6b7b91; font-weight:700; }
        .doc-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; min-height:42px; padding:0 18px; border-radius:9px; border:0; text-decoration:none; cursor:pointer; font-weight:800; font-size:14px; }
        .doc-btn--primary { background:#223f91; color:#fff; }
        .doc-btn--green { background:#20c77a; color:#fff; }
        .doc-btn--warning { background:#f4bd32; color:#fff; }
        .doc-btn--light { background:#fff; color:#23344e; border:1px solid #d5dfec; }
        .doc-card { background:#fff; border:1px solid #dbe5f0; border-radius:10px; box-shadow:0 16px 42px rgba(31,50,82,.08); overflow:hidden; }
        .doc-card-header { display:flex; justify-content:space-between; gap:14px; align-items:center; padding:18px 20px; border-bottom:1px solid #e3ebf4; }
        .doc-card-header h2 { margin:0; font-size:20px; color:#23344e; }
        .doc-toolbar { display:flex; gap:10px; flex-wrap:wrap; padding:18px 20px; }
        .doc-alert { padding:12px 14px; border-radius:9px; margin-bottom:12px; font-weight:700; }
        .doc-alert--ok { background:#dcfce7; color:#166534; border:1px solid #86efac; }
        .doc-alert--err { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
        .doc-table-wrap { padding:0 20px 22px; overflow:auto; }
        .doc-table { width:100%; border-collapse:collapse; min-width:760px; }
        .doc-table th { background:#eef3f8; color:#4b5e77; font-size:12px; text-align:left; padding:13px; border:1px solid #dce5ef; }
        .doc-table td { padding:13px; border:1px solid #e4ebf3; vertical-align:middle; }
        .doc-muted { color:#76869b; font-size:12px; margin-top:4px; }
        .doc-chip { display:inline-flex; align-items:center; min-height:24px; padding:0 9px; border-radius:999px; background:#dbeafe; color:#1d4ed8; font-weight:800; font-size:12px; }
        .doc-chip--formatura { background:#ede9fe; color:#6d28d9; }
        .doc-actions { display:flex; gap:7px; align-items:center; flex-wrap:wrap; }
        .doc-icon { display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; border-radius:8px; border:1px solid #d5dfec; background:#fff; color:#23344e; cursor:pointer; text-decoration:none; font-weight:800; }
        .doc-icon--danger { background:#ef4444; color:#fff; border-color:#ef4444; }
        .doc-icon--blue { background:#21a8c7; color:#fff; border-color:#21a8c7; }
        .doc-empty { padding:28px 20px; color:#6b7b91; font-weight:700; }
        .doc-modal { position:fixed; inset:0; background:rgba(15,23,42,.55); display:none; align-items:center; justify-content:center; padding:24px; z-index:50; }
        .doc-modal:target { display:flex; }
        .doc-dialog { width:min(860px, 100%); max-height:90vh; overflow:auto; background:#fff; border-radius:12px; box-shadow:0 20px 60px rgba(0,0,0,.25); }
        .doc-dialog-header { display:flex; justify-content:space-between; align-items:center; padding:18px 20px; border-bottom:1px solid #e3ebf4; }
        .doc-dialog-header h3 { margin:0; font-size:21px; }
        .doc-close { width:36px; height:36px; border-radius:999px; display:inline-flex; align-items:center; justify-content:center; background:#f1f5f9; color:#24364f; text-decoration:none; font-weight:900; }
        .doc-form { padding:20px; }
        .doc-field { margin-bottom:16px; }
        .doc-field label { display:block; margin-bottom:7px; font-weight:800; }
        .doc-field select, .doc-field input, .doc-field textarea { width:100%; box-sizing:border-box; border:1px solid #cad7e6; border-radius:9px; min-height:44px; padding:10px 12px; font:inherit; }
        .doc-field textarea { min-height:360px; font-family:Menlo, Consolas, monospace; font-size:13px; }
        .doc-dialog-actions { display:flex; justify-content:flex-end; gap:10px; padding:16px 20px; border-top:1px solid #e3ebf4; }
        @media (max-width: 760px) {
            .doc-header, .doc-card-header { flex-direction:column; align-items:stretch; }
            .doc-title h1 { font-size:24px; }
        }
    </style>
    <div class="doc-page">
        <div class="doc-header">
            <div class="doc-title">
                <h1>Contratos e Documentos</h1>
                <p><?= eventos_documentos_e((string)$evento['nome_evento']) ?> · <?= eventos_documentos_e((string)$evento['space_visivel']) ?></p>
            </div>
            <a class="doc-btn doc-btn--warning" href="index.php?page=agenda_eventos&evento_id=<?= (int)$eventoId ?>">← Voltar ao evento</a>
        </div>

        <?php foreach ($messages as $message): ?>
            <div class="doc-alert doc-alert--ok"><?= eventos_documentos_e($message) ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="doc-alert doc-alert--err"><?= eventos_documentos_e($error) ?></div>
        <?php endforeach; ?>

        <section class="doc-card">
            <div class="doc-card-header">
                <h2>Arquivos do evento</h2>
                <span class="doc-muted">Modelos cadastrados em Cadastros &gt; Contratos.</span>
            </div>
            <div class="doc-toolbar">
                <a class="doc-btn doc-btn--green" href="#novo-documento">＋ Novo Documento</a>
            </div>

            <?php if (!$documentos): ?>
                <div class="doc-empty">Nenhum documento gerado.</div>
            <?php else: ?>
                <div class="doc-table-wrap">
                    <table class="doc-table">
                        <thead>
                            <tr>
                                <th>Arquivos</th>
                                <th>Status</th>
                                <th>Assinaturas</th>
                                <th>Opções</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($documentos as $documento): ?>
                            <?php
                            $origem = (string)($documento['origem'] ?? 'geral');
                            $isFormatura = $origem === 'formatura';
                            $status = ucfirst((string)($documento['status'] ?? 'criado'));
                            $assinaturasTotal = (int)($documento['assinaturas_total'] ?? 0);
                            $assinaturas = $assinaturasTotal > 0
                                ? (int)($documento['assinaturas_realizadas'] ?? 0) . '/' . $assinaturasTotal
                                : '-';
                            $info = 'Gerado em ' . eventos_documentos_data_hora_br((string)($documento['created_at'] ?? '')) . ' por ' . (string)($documento['criado_por_nome'] ?? 'Sistema');
                            $minutaUrl = $isFormatura
                                ? eventos_documentos_public_url('formatura_minuta.php?token=' . rawurlencode((string)($documento['minuta_token'] ?? '')))
                                : eventos_documentos_public_url('evento_documento_minuta.php?token=' . rawurlencode((string)($documento['minuta_token'] ?? '')));
                            ?>
                            <tr>
                                <td>
                                    <strong>📄 <?= eventos_documentos_e((string)$documento['titulo']) ?></strong>
                                    <div class="doc-muted">
                                        <span class="doc-chip <?= $isFormatura ? 'doc-chip--formatura' : '' ?>"><?= $isFormatura ? 'Formatura' : 'Evento' ?></span>
                                        <?php if ($isFormatura && !empty($documento['nome_formando'])): ?>
                                            <?= eventos_documentos_e((string)$documento['nome_formando']) ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><span class="doc-chip"><?= eventos_documentos_e($status) ?></span></td>
                                <td><?= eventos_documentos_e($assinaturas) ?></td>
                                <td>
                                    <div class="doc-actions">
                                        <button class="doc-icon" type="button" title="<?= eventos_documentos_e($info) ?>">ℹ</button>
                                        <a class="doc-icon doc-icon--blue" href="<?= eventos_documentos_e($minutaUrl) ?>" target="_blank" rel="noopener" title="Minuta">M</a>
                                        <?php if ($isFormatura): ?>
                                            <a class="doc-icon" href="index.php?page=eventos_formatura&evento_id=<?= (int)$eventoId ?>" title="Abrir formatura">↗</a>
                                        <?php else: ?>
                                            <a
                                                class="doc-icon"
                                                href="#editar-documento"
                                                title="Editar"
                                                data-edit-doc
                                                data-id="<?= (int)$documento['id'] ?>"
                                                data-titulo="<?= eventos_documentos_e((string)$documento['titulo']) ?>"
                                                data-conteudo="<?= eventos_documentos_e((string)$documento['conteudo_html']) ?>"
                                            >✎</a>
                                            <form method="post" onsubmit="return confirm('Excluir este documento?');">
                                                <input type="hidden" name="action" value="delete_documento">
                                                <input type="hidden" name="evento_id" value="<?= (int)$eventoId ?>">
                                                <input type="hidden" name="documento_id" value="<?= (int)$documento['id'] ?>">
                                                <button class="doc-icon doc-icon--danger" type="submit" title="Excluir">🗑</button>
                                            </form>
                                        <?php endif; ?>
                                        <button class="doc-icon" type="button" title="Clicksign será conectado nesta ação">Ass</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

<div id="novo-documento" class="doc-modal">
    <div class="doc-dialog">
        <div class="doc-dialog-header">
            <h3>Selecionar Modelo de Contrato</h3>
            <a class="doc-close" href="index.php?page=eventos_documentos&evento_id=<?= (int)$eventoId ?>">×</a>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="generate_documento">
            <input type="hidden" name="evento_id" value="<?= (int)$eventoId ?>">
            <div class="doc-form">
                <div class="doc-field">
                    <label for="modelo_id">Meus modelos</label>
                    <select id="modelo_id" name="modelo_id" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($modelos as $modelo): ?>
                            <option value="<?= (int)$modelo['id'] ?>"><?= eventos_documentos_e((string)$modelo['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="doc-dialog-actions">
                <a class="doc-btn doc-btn--light" href="index.php?page=eventos_documentos&evento_id=<?= (int)$eventoId ?>">Cancelar</a>
                <button class="doc-btn doc-btn--green" type="submit">Continuar</button>
            </div>
        </form>
    </div>
</div>

<div id="editar-documento" class="doc-modal">
    <div class="doc-dialog">
        <div class="doc-dialog-header">
            <h3>Editar Documento</h3>
            <a class="doc-close" href="index.php?page=eventos_documentos&evento_id=<?= (int)$eventoId ?>">×</a>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="save_documento">
            <input type="hidden" name="evento_id" value="<?= (int)$eventoId ?>">
            <input type="hidden" id="editDocumentoId" name="documento_id" value="">
            <div class="doc-form">
                <div class="doc-field">
                    <label for="editTitulo">Título</label>
                    <input id="editTitulo" name="titulo" type="text" required>
                </div>
                <div class="doc-field">
                    <label for="editConteudo">Conteúdo HTML</label>
                    <textarea id="editConteudo" name="conteudo_html"></textarea>
                </div>
            </div>
            <div class="doc-dialog-actions">
                <a class="doc-btn doc-btn--light" href="index.php?page=eventos_documentos&evento_id=<?= (int)$eventoId ?>">Cancelar</a>
                <button class="doc-btn doc-btn--green" type="submit">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('[data-edit-doc]').forEach((button) => {
    button.addEventListener('click', () => {
        document.getElementById('editDocumentoId').value = button.dataset.id || '';
        document.getElementById('editTitulo').value = button.dataset.titulo || '';
        document.getElementById('editConteudo').value = button.dataset.conteudo || '';
    });
});
</script>
<?php endSidebar(); ?>
