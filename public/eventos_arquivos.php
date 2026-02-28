<?php
/**
 * eventos_arquivos.php
 * Gestão interna de arquivos da organização do evento.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';
require_once __DIR__ . '/upload_magalu.php';

if (empty($_SESSION['perm_eventos']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

$user_id = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? 0);
$meeting_id = (int)($_GET['id'] ?? $_POST['meeting_id'] ?? 0);
if ($meeting_id <= 0) {
    header('Location: index.php?page=eventos_organizacao');
    exit;
}

$reuniao = eventos_reuniao_get($pdo, $meeting_id);
if (!$reuniao) {
    header('Location: index.php?page=eventos_organizacao');
    exit;
}

function eventos_arquivos_e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function eventos_arquivos_upload_error_message(int $code): string {
    switch ($code) {
        case UPLOAD_ERR_OK:
            return '';
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'Arquivo excede o limite máximo permitido pelo servidor.';
        case UPLOAD_ERR_PARTIAL:
            return 'Upload incompleto. Tente novamente.';
        case UPLOAD_ERR_NO_FILE:
            return 'Selecione um arquivo para enviar.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Servidor sem pasta temporária para upload.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Falha ao gravar o arquivo temporário.';
        case UPLOAD_ERR_EXTENSION:
            return 'Upload bloqueado por extensão do servidor.';
        default:
            return 'Erro desconhecido de upload.';
    }
}

$snapshot = json_decode((string)($reuniao['me_event_snapshot'] ?? '{}'), true);
if (!is_array($snapshot)) {
    $snapshot = [];
}

$nome_evento = trim((string)($snapshot['nome'] ?? 'Evento'));
$data_evento_raw = trim((string)($snapshot['data'] ?? ''));
$data_evento_fmt = $data_evento_raw !== '' ? date('d/m/Y', strtotime($data_evento_raw)) : '-';
$hora_evento = trim((string)($snapshot['hora_inicio'] ?? $snapshot['hora'] ?? ''));
$local_evento = trim((string)($snapshot['local'] ?? 'Local não informado'));
$cliente_nome = trim((string)($snapshot['cliente']['nome'] ?? 'Cliente'));
$tipo_evento_real = eventos_reuniao_normalizar_tipo_evento_real((string)($reuniao['tipo_evento_real'] ?? ($snapshot['tipo_evento_real'] ?? '')));
$tipo_evento_real_label = eventos_reuniao_tipo_evento_real_label($tipo_evento_real);

$seed_result = eventos_arquivos_seed_campos_por_tipo($pdo, $meeting_id, $tipo_evento_real, $user_id);
$seed_campos_sistema_result = eventos_arquivos_seed_campos_sistema($pdo, $meeting_id, $user_id);

$feedback_ok = '';
$feedback_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        switch ($action) {
            case 'adicionar_campo':
                $titulo = trim((string)($_POST['titulo'] ?? ''));
                $descricao = trim((string)($_POST['descricao'] ?? ''));
                $obrigatorio_cliente = ((string)($_POST['obrigatorio_cliente'] ?? '0') === '1');

                $result = eventos_arquivos_salvar_campo($pdo, $meeting_id, $titulo, $descricao, $obrigatorio_cliente, $user_id);
                if (!empty($result['ok'])) {
                    $feedback_ok = !empty($result['mode']) && $result['mode'] === 'updated'
                        ? 'Campo solicitado atualizado com sucesso.'
                        : 'Campo solicitado criado com sucesso.';
                } else {
                    $feedback_error = (string)($result['error'] ?? 'Não foi possível salvar o campo solicitado.');
                }
                break;

            case 'upload_arquivo':
                $campo_id = (int)($_POST['campo_id'] ?? 0);
                $descricao = trim((string)($_POST['descricao_arquivo'] ?? ''));
                $visivel_cliente = ((string)($_POST['visivel_cliente'] ?? '0') === '1');
                $file = $_FILES['arquivo'] ?? null;

                if (!$file || !is_array($file)) {
                    $feedback_error = 'Selecione um arquivo para upload.';
                    break;
                }

                $upload_error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($upload_error !== UPLOAD_ERR_OK) {
                    $feedback_error = eventos_arquivos_upload_error_message($upload_error);
                    break;
                }

                if ((int)($file['size'] ?? 0) > 500 * 1024 * 1024) {
                    $feedback_error = 'Arquivo muito grande. Limite máximo: 500MB.';
                    break;
                }

                $uploader = new MagaluUpload(500);
                $upload_result = $uploader->upload($file, 'eventos/reunioes/' . $meeting_id . '/arquivos');
                $saved = eventos_arquivos_salvar_item(
                    $pdo,
                    $meeting_id,
                    $upload_result,
                    $campo_id > 0 ? $campo_id : null,
                    $descricao,
                    $visivel_cliente,
                    'interno',
                    $user_id > 0 ? $user_id : null
                );

                if (!empty($saved['ok'])) {
                    $replaced_count = (int)($saved['replaced_count'] ?? 0);
                    $feedback_ok = $replaced_count > 0
                        ? 'Arquivo enviado e versão anterior substituída com sucesso.'
                        : 'Arquivo enviado com sucesso.';
                } else {
                    $feedback_error = (string)($saved['error'] ?? 'Falha ao salvar o arquivo.');
                }
                break;

            case 'upload_resumo_evento':
                $descricao = trim((string)($_POST['descricao_resumo_evento'] ?? ''));
                $file = $_FILES['arquivo_resumo_evento'] ?? null;
                if (!$file || !is_array($file)) {
                    $feedback_error = 'Selecione um PDF para o Resumo do evento.';
                    break;
                }

                $upload_error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($upload_error !== UPLOAD_ERR_OK) {
                    $feedback_error = eventos_arquivos_upload_error_message($upload_error);
                    break;
                }

                if ((int)($file['size'] ?? 0) > 500 * 1024 * 1024) {
                    $feedback_error = 'Arquivo muito grande. Limite máximo: 500MB.';
                    break;
                }

                $campo_resumo = eventos_arquivos_buscar_campo_por_chave($pdo, $meeting_id, 'resumo_evento', true);
                if (!$campo_resumo || (int)($campo_resumo['id'] ?? 0) <= 0) {
                    $feedback_error = 'Campo "Resumo do evento" não encontrado.';
                    break;
                }

                $uploader = new MagaluUpload(500);
                $upload_result = $uploader->upload($file, 'eventos/reunioes/' . $meeting_id . '/arquivos/resumo_evento');
                $saved = eventos_arquivos_salvar_item(
                    $pdo,
                    $meeting_id,
                    $upload_result,
                    (int)$campo_resumo['id'],
                    $descricao,
                    false,
                    'interno',
                    $user_id > 0 ? $user_id : null
                );

                if (!empty($saved['ok'])) {
                    $replaced_count = (int)($saved['replaced_count'] ?? 0);
                    $feedback_ok = $replaced_count > 0
                        ? 'Resumo do evento atualizado com sucesso (versão anterior substituída).'
                        : 'Resumo do evento anexado com sucesso.';
                } else {
                    $feedback_error = (string)($saved['error'] ?? 'Falha ao salvar o Resumo do evento.');
                }
                break;

            case 'upload_cardapio':
                $descricao = trim((string)($_POST['descricao_cardapio'] ?? ''));
                $visivel_cliente = ((string)($_POST['visivel_cliente_cardapio'] ?? '0') === '1');
                $file = $_FILES['arquivo_cardapio'] ?? null;
                if (!$file || !is_array($file)) {
                    $feedback_error = 'Selecione um arquivo para o cardápio.';
                    break;
                }

                $upload_error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($upload_error !== UPLOAD_ERR_OK) {
                    $feedback_error = eventos_arquivos_upload_error_message($upload_error);
                    break;
                }

                if ((int)($file['size'] ?? 0) > 500 * 1024 * 1024) {
                    $feedback_error = 'Arquivo muito grande. Limite máximo: 500MB.';
                    break;
                }

                $campo_cardapio = eventos_arquivos_buscar_campo_por_chave($pdo, $meeting_id, 'cardapio', true);
                if (!$campo_cardapio || (int)($campo_cardapio['id'] ?? 0) <= 0) {
                    $feedback_error = 'Campo "Cardápio" não encontrado.';
                    break;
                }

                $uploader = new MagaluUpload(500);
                $upload_result = $uploader->upload($file, 'eventos/reunioes/' . $meeting_id . '/arquivos/cardapio');
                $saved = eventos_arquivos_salvar_item(
                    $pdo,
                    $meeting_id,
                    $upload_result,
                    (int)$campo_cardapio['id'],
                    $descricao,
                    $visivel_cliente,
                    'interno',
                    $user_id > 0 ? $user_id : null
                );

                if (!empty($saved['ok'])) {
                    $feedback_ok = 'Arquivo de cardápio anexado com sucesso.';
                } else {
                    $feedback_error = (string)($saved['error'] ?? 'Falha ao salvar arquivo de cardápio.');
                }
                break;

            case 'toggle_visibilidade_arquivo':
                $arquivo_id = (int)($_POST['arquivo_id'] ?? 0);
                $visivel_cliente = ((string)($_POST['visivel_cliente'] ?? '0') === '1');
                $updated = eventos_arquivos_atualizar_visibilidade($pdo, $meeting_id, $arquivo_id, $visivel_cliente, $user_id);
                if (!empty($updated['ok'])) {
                    $feedback_ok = $visivel_cliente ? 'Arquivo marcado como visível no portal do cliente.' : 'Arquivo ocultado do portal do cliente.';
                } else {
                    $feedback_error = (string)($updated['error'] ?? 'Falha ao atualizar visibilidade.');
                }
                break;

            case 'toggle_campo_ativo':
                $campo_id = (int)($_POST['campo_id'] ?? 0);
                $ativo = ((string)($_POST['ativo'] ?? '0') === '1');
                $updated = eventos_arquivos_atualizar_campo_ativo($pdo, $meeting_id, $campo_id, $ativo);
                if (!empty($updated['ok'])) {
                    $feedback_ok = $ativo ? 'Campo reativado com sucesso.' : 'Campo desativado com sucesso.';
                } else {
                    $feedback_error = (string)($updated['error'] ?? 'Falha ao atualizar campo.');
                }
                break;

            case 'excluir_arquivo':
                $arquivo_id = (int)($_POST['arquivo_id'] ?? 0);
                $deleted = eventos_arquivos_excluir_item($pdo, $meeting_id, $arquivo_id, $user_id);
                if (!empty($deleted['ok'])) {
                    $feedback_ok = 'Arquivo removido com sucesso.';
                } else {
                    $feedback_error = (string)($deleted['error'] ?? 'Falha ao remover arquivo.');
                }
                break;
        }
    } catch (Throwable $e) {
        error_log('eventos_arquivos.php POST: ' . $e->getMessage());
        $feedback_error = 'Erro interno ao processar a solicitação.';
    }
}

$campos = eventos_arquivos_listar_campos($pdo, $meeting_id, false, true);
$campos_cliente = array_values(array_filter($campos, static function ($campo): bool {
    return empty($campo['interno_only']) && trim((string)($campo['chave_sistema'] ?? '')) === '';
}));
$campos_ativos = array_values(array_filter($campos_cliente, static function ($campo): bool {
    return !empty($campo['ativo']);
}));
$arquivos = eventos_arquivos_listar($pdo, $meeting_id, false);
$resumo = eventos_arquivos_resumo($pdo, $meeting_id);
$logs_arquivos = eventos_arquivos_logs_listar($pdo, $meeting_id, null, 200);

$campo_resumo_evento = null;
$campo_cardapio = null;
foreach ($campos as $campo_item) {
    $chave_sistema = (string)($campo_item['chave_sistema'] ?? '');
    if ($chave_sistema === 'resumo_evento' && !$campo_resumo_evento) {
        $campo_resumo_evento = $campo_item;
    } elseif ($chave_sistema === 'cardapio' && !$campo_cardapio) {
        $campo_cardapio = $campo_item;
    }
}

$resumo_evento_arquivos = [];
$cardapio_arquivos = [];
$campo_resumo_evento_id = (int)($campo_resumo_evento['id'] ?? 0);
$campo_cardapio_id = (int)($campo_cardapio['id'] ?? 0);
foreach ($arquivos as $arquivo_item) {
    $arquivo_campo_id = (int)($arquivo_item['campo_id'] ?? 0);
    if ($campo_resumo_evento_id > 0 && $arquivo_campo_id === $campo_resumo_evento_id) {
        $resumo_evento_arquivos[] = $arquivo_item;
    }
    if ($campo_cardapio_id > 0 && $arquivo_campo_id === $campo_cardapio_id) {
        $cardapio_arquivos[] = $arquivo_item;
    }
}
$resumo_evento_arquivo_atual = !empty($resumo_evento_arquivos) ? $resumo_evento_arquivos[0] : null;

includeSidebar('Arquivos do Evento');
?>

<style>
    .arquivos-container {
        padding: 2rem;
        max-width: 1240px;
        margin: 0 auto;
        background: #f8fafc;
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        align-items: flex-start;
        flex-wrap: wrap;
        margin-bottom: 1rem;
    }

    .page-title {
        margin: 0;
        color: #1e3a8a;
        font-size: 1.6rem;
        font-weight: 700;
    }

    .page-subtitle {
        color: #64748b;
        margin-top: 0.35rem;
        font-size: 0.9rem;
    }

    .btn {
        padding: 0.62rem 0.95rem;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        text-decoration: none;
        font-size: 0.84rem;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
    }

    .btn-primary {
        background: #1e3a8a;
        color: #fff;
    }

    .btn-secondary {
        background: #f1f5f9;
        color: #334155;
        border: 1px solid #dbe3ef;
    }

    .btn-danger {
        background: #ef4444;
        color: #fff;
    }

    .event-summary {
        background: #fff;
        border: 1px solid #dbe3ef;
        border-radius: 12px;
        padding: 1rem;
        margin-bottom: 1rem;
    }

    .event-summary h2 {
        margin: 0 0 0.4rem 0;
        color: #0f172a;
        font-size: 1.22rem;
    }

    .event-meta {
        display: grid;
        gap: 0.45rem;
        grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
        font-size: 0.9rem;
        color: #334155;
    }

    .resumo-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: 0.65rem;
        margin-bottom: 1rem;
    }

    .resumo-card {
        background: #fff;
        border: 1px solid #dbe3ef;
        border-radius: 10px;
        padding: 0.7rem 0.8rem;
    }

    .resumo-card .valor {
        display: block;
        color: #1e3a8a;
        font-weight: 800;
        font-size: 1.2rem;
        line-height: 1;
    }

    .resumo-card .label {
        display: block;
        color: #64748b;
        font-size: 0.8rem;
        margin-top: 0.25rem;
    }

    .alert {
        border-radius: 10px;
        border: 1px solid transparent;
        padding: 0.72rem 0.85rem;
        margin-bottom: 1rem;
        font-size: 0.88rem;
    }

    .alert-success {
        background: #dcfce7;
        border-color: #86efac;
        color: #166534;
    }

    .alert-error {
        background: #fee2e2;
        border-color: #fca5a5;
        color: #991b1b;
    }

    .grid-two {
        display: grid;
        gap: 1rem;
        grid-template-columns: repeat(auto-fit, minmax(330px, 1fr));
        margin-bottom: 1rem;
    }

    .panel {
        background: #fff;
        border: 1px solid #dbe3ef;
        border-radius: 12px;
        padding: 1rem;
    }

    .panel h3 {
        margin: 0;
        color: #0f172a;
        font-size: 1.04rem;
    }

    .panel-subtitle {
        margin-top: 0.35rem;
        color: #64748b;
        font-size: 0.84rem;
    }

    .form-grid {
        margin-top: 0.85rem;
        display: grid;
        gap: 0.75rem;
    }

    .field {
        display: grid;
        gap: 0.35rem;
    }

    .field label {
        font-size: 0.82rem;
        color: #334155;
        font-weight: 700;
    }

    .field input[type="text"],
    .field input[type="file"],
    .field textarea,
    .field select {
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 0.58rem 0.7rem;
        font-size: 0.86rem;
        color: #1f2937;
        width: 100%;
        background: #fff;
    }

    .field textarea {
        min-height: 90px;
        resize: vertical;
    }

    .check-row {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        font-size: 0.84rem;
        color: #334155;
    }

    .hint {
        color: #64748b;
        font-size: 0.76rem;
        margin-top: 0.1rem;
    }

    .campos-list,
    .arquivos-list {
        margin-top: 0.9rem;
        display: grid;
        gap: 0.7rem;
    }

    .item-card {
        border: 1px solid #dbe3ef;
        border-radius: 10px;
        background: #fff;
        padding: 0.72rem 0.8rem;
    }

    .item-head {
        display: flex;
        justify-content: space-between;
        gap: 0.8rem;
        align-items: flex-start;
        flex-wrap: wrap;
    }

    .item-title {
        font-weight: 700;
        color: #0f172a;
        font-size: 0.92rem;
    }

    .item-meta {
        margin-top: 0.2rem;
        color: #64748b;
        font-size: 0.8rem;
        line-height: 1.45;
    }

    .badge {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 0.2rem 0.5rem;
        font-size: 0.72rem;
        font-weight: 700;
        border: 1px solid transparent;
        white-space: nowrap;
    }

    .badge-required {
        color: #7c2d12;
        background: #ffedd5;
        border-color: #fdba74;
    }

    .badge-optional {
        color: #334155;
        background: #e2e8f0;
        border-color: #cbd5e1;
    }

    .badge-active {
        color: #065f46;
        background: #d1fae5;
        border-color: #6ee7b7;
    }

    .badge-inactive {
        color: #991b1b;
        background: #fee2e2;
        border-color: #fca5a5;
    }

    .badge-visible {
        color: #1d4ed8;
        background: #dbeafe;
        border-color: #93c5fd;
    }

    .badge-hidden {
        color: #6b7280;
        background: #f3f4f6;
        border-color: #d1d5db;
    }

    .item-actions {
        margin-top: 0.6rem;
        display: flex;
        gap: 0.45rem;
        flex-wrap: wrap;
    }

    .empty {
        color: #64748b;
        font-size: 0.86rem;
        font-style: italic;
        padding: 0.6rem 0;
    }

    @media (max-width: 768px) {
        .arquivos-container {
            padding: 1rem;
        }
    }
</style>

<div class="arquivos-container">
    <div class="page-header">
        <div>
            <h1 class="page-title">📁 Arquivos do Evento</h1>
            <div class="page-subtitle">Uploads até 500MB por arquivo, com descrição e controle de visibilidade para o cliente.</div>
        </div>
        <a href="index.php?page=eventos_organizacao&id=<?= (int)$meeting_id ?>" class="btn btn-secondary">← Voltar à organização</a>
    </div>

    <?php if (!empty($feedback_ok)): ?>
    <div class="alert alert-success"><?= eventos_arquivos_e($feedback_ok) ?></div>
    <?php endif; ?>

    <?php if (!empty($feedback_error)): ?>
    <div class="alert alert-error"><?= eventos_arquivos_e($feedback_error) ?></div>
    <?php endif; ?>

    <?php if (!empty($seed_result['ok']) && (int)($seed_result['inserted'] ?? 0) > 0): ?>
    <div class="alert alert-success">
        Campos padrão aplicados automaticamente para o tipo <strong><?= eventos_arquivos_e($tipo_evento_real_label) ?></strong>.
    </div>
    <?php endif; ?>

    <?php if (!empty($seed_campos_sistema_result['ok']) && (int)($seed_campos_sistema_result['inserted'] ?? 0) > 0): ?>
    <div class="alert alert-success">
        Estrutura de anexos atualizada: campos fixos de <strong>Resumo do evento</strong> e <strong>Cardápio</strong> prontos para uso.
    </div>
    <?php endif; ?>

    <section class="event-summary">
        <h2><?= eventos_arquivos_e($nome_evento) ?></h2>
        <div class="event-meta">
            <div><strong>📅 Data:</strong> <?= eventos_arquivos_e($data_evento_fmt) ?></div>
            <div><strong>⏰ Horário:</strong> <?= eventos_arquivos_e($hora_evento !== '' ? $hora_evento : '-') ?></div>
            <div><strong>📍 Local:</strong> <?= eventos_arquivos_e($local_evento) ?></div>
            <div><strong>👤 Cliente:</strong> <?= eventos_arquivos_e($cliente_nome) ?></div>
            <div><strong>🏷️ Tipo:</strong> <?= eventos_arquivos_e($tipo_evento_real_label) ?></div>
        </div>
    </section>

    <section class="resumo-grid">
        <div class="resumo-card">
            <span class="valor"><?= (int)$resumo['arquivos_total'] ?></span>
            <span class="label">Arquivos enviados</span>
        </div>
        <div class="resumo-card">
            <span class="valor"><?= (int)$resumo['arquivos_visiveis_cliente'] ?></span>
            <span class="label">Visíveis no portal</span>
        </div>
        <div class="resumo-card">
            <span class="valor"><?= (int)$resumo['arquivos_cliente'] ?></span>
            <span class="label">Enviados pelo cliente</span>
        </div>
        <div class="resumo-card">
            <span class="valor"><?= (int)$resumo['campos_total'] ?></span>
            <span class="label">Campos solicitados</span>
        </div>
        <div class="resumo-card">
            <span class="valor"><?= (int)$resumo['campos_obrigatorios'] ?></span>
            <span class="label">Campos obrigatórios</span>
        </div>
        <div class="resumo-card">
            <span class="valor"><?= (int)$resumo['campos_pendentes'] ?></span>
            <span class="label">Pendências obrigatórias</span>
        </div>
    </section>

    <section class="grid-two">
        <div class="panel" id="resumo-evento">
            <h3>📄 Resumo do evento (Contrato cliente)</h3>
            <div class="panel-subtitle">Upload exclusivo em PDF. Sempre interno, sem opção de exibição no portal do cliente.</div>

            <form method="POST" enctype="multipart/form-data" class="form-grid">
                <input type="hidden" name="meeting_id" value="<?= (int)$meeting_id ?>">
                <input type="hidden" name="action" value="upload_resumo_evento">

                <div class="field">
                    <label for="arquivoResumoEvento">Arquivo PDF</label>
                    <input type="file" id="arquivoResumoEvento" name="arquivo_resumo_evento" accept=".pdf,application/pdf" required>
                    <div class="hint">Ao anexar novo arquivo, o anterior é substituído automaticamente e fica registrado no log.</div>
                </div>

                <div class="field">
                    <label for="descricaoResumoEvento">Descrição (opcional)</label>
                    <textarea id="descricaoResumoEvento" name="descricao_resumo_evento" placeholder="Ex.: Contrato assinado atualizado em 28/02/2026."></textarea>
                </div>

                <div>
                    <button type="submit" class="btn btn-primary">Salvar resumo do evento</button>
                </div>
            </form>

            <div class="campos-list">
                <?php if (!$resumo_evento_arquivo_atual): ?>
                <div class="empty">Nenhum resumo de evento anexado ainda.</div>
                <?php else: ?>
                <?php
                    $resumo_nome = trim((string)($resumo_evento_arquivo_atual['original_name'] ?? 'arquivo.pdf'));
                    $resumo_url = trim((string)($resumo_evento_arquivo_atual['public_url'] ?? ''));
                    $resumo_desc = trim((string)($resumo_evento_arquivo_atual['descricao'] ?? ''));
                    $resumo_uploaded_at = trim((string)($resumo_evento_arquivo_atual['uploaded_at'] ?? ''));
                    $resumo_uploaded_at_fmt = $resumo_uploaded_at !== '' ? date('d/m/Y H:i', strtotime($resumo_uploaded_at)) : '-';
                ?>
                <article class="item-card">
                    <div class="item-head">
                        <div>
                            <div class="item-title">Atual: <?= eventos_arquivos_e($resumo_nome) ?></div>
                            <div class="item-meta">
                                Última atualização: <?= eventos_arquivos_e($resumo_uploaded_at_fmt) ?>
                                <?php if ($resumo_desc !== ''): ?><br><?= eventos_arquivos_e($resumo_desc) ?><?php endif; ?>
                            </div>
                        </div>
                        <span class="badge badge-hidden">Uso interno</span>
                    </div>
                    <div class="item-actions">
                        <?php if ($resumo_url !== ''): ?>
                        <a class="btn btn-primary" href="<?= eventos_arquivos_e($resumo_url) ?>" target="_blank" rel="noopener noreferrer">Abrir PDF</a>
                        <?php endif; ?>
                    </div>
                </article>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel" id="cardapio">
            <h3>🍽️ Cardápio</h3>
            <div class="panel-subtitle">Anexe os arquivos de cardápio do evento e decida se ficam visíveis no portal.</div>

            <form method="POST" enctype="multipart/form-data" class="form-grid">
                <input type="hidden" name="meeting_id" value="<?= (int)$meeting_id ?>">
                <input type="hidden" name="action" value="upload_cardapio">

                <div class="field">
                    <label for="arquivoCardapio">Arquivo do cardápio</label>
                    <input type="file"
                           id="arquivoCardapio"
                           name="arquivo_cardapio"
                           accept=".png,.jpg,.jpeg,.gif,.webp,.heic,.heif,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx,.xlsm,.ppt,.pptx,.odt,.ods,.odp,.zip,.rar,.7z"
                           required>
                </div>

                <div class="field">
                    <label for="descricaoCardapio">Descrição (opcional)</label>
                    <textarea id="descricaoCardapio" name="descricao_cardapio" placeholder="Ex.: Cardápio final validado pela cozinha."></textarea>
                </div>

                <label class="check-row">
                    <input type="checkbox" name="visivel_cliente_cardapio" value="1">
                    <span>Cliente pode visualizar no portal</span>
                </label>

                <div>
                    <button type="submit" class="btn btn-primary">Anexar cardápio</button>
                </div>
            </form>

            <div class="campos-list">
                <?php if (empty($cardapio_arquivos)): ?>
                <div class="empty">Nenhum arquivo de cardápio anexado ainda.</div>
                <?php else: ?>
                <?php foreach ($cardapio_arquivos as $cardapio_item): ?>
                <?php
                    $cardapio_nome = trim((string)($cardapio_item['original_name'] ?? 'arquivo'));
                    $cardapio_url = trim((string)($cardapio_item['public_url'] ?? ''));
                    $cardapio_visivel = !empty($cardapio_item['visivel_cliente']);
                    $cardapio_data = trim((string)($cardapio_item['uploaded_at'] ?? ''));
                    $cardapio_data_fmt = $cardapio_data !== '' ? date('d/m/Y H:i', strtotime($cardapio_data)) : '-';
                ?>
                <article class="item-card">
                    <div class="item-head">
                        <div>
                            <div class="item-title"><?= eventos_arquivos_e($cardapio_nome) ?></div>
                            <div class="item-meta">Enviado em <?= eventos_arquivos_e($cardapio_data_fmt) ?></div>
                        </div>
                        <span class="badge <?= $cardapio_visivel ? 'badge-visible' : 'badge-hidden' ?>">
                            <?= $cardapio_visivel ? 'Visível ao cliente' : 'Oculto do cliente' ?>
                        </span>
                    </div>
                    <div class="item-actions">
                        <?php if ($cardapio_url !== ''): ?>
                        <a class="btn btn-primary" href="<?= eventos_arquivos_e($cardapio_url) ?>" target="_blank" rel="noopener noreferrer">Abrir arquivo</a>
                        <?php endif; ?>
                    </div>
                </article>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="grid-two">
        <div class="panel">
            <h3>📌 Campos Solicitados do Cliente</h3>
            <div class="panel-subtitle">Cadastre os arquivos que você quer receber neste evento.</div>

            <form method="POST" class="form-grid">
                <input type="hidden" name="meeting_id" value="<?= (int)$meeting_id ?>">
                <input type="hidden" name="action" value="adicionar_campo">

                <div class="field">
                    <label for="campoTitulo">Nome do arquivo solicitado</label>
                    <input type="text" id="campoTitulo" name="titulo" maxlength="180" placeholder="Ex.: Imagem do convite" required>
                </div>

                <div class="field">
                    <label for="campoDescricao">Descrição/instrução</label>
                    <textarea id="campoDescricao" name="descricao" placeholder="Ex.: Preferencialmente em alta resolução."></textarea>
                </div>

                <label class="check-row">
                    <input type="checkbox" name="obrigatorio_cliente" value="1">
                    <span>Campo obrigatório para o cliente</span>
                </label>

                <div>
                    <button type="submit" class="btn btn-primary">Salvar campo solicitado</button>
                </div>
            </form>

            <div class="campos-list">
                <?php if (empty($campos_cliente)): ?>
                <div class="empty">Nenhum campo solicitado cadastrado ainda.</div>
                <?php else: ?>
                <?php foreach ($campos_cliente as $campo): ?>
                <?php
                    $campo_id = (int)($campo['id'] ?? 0);
                    $campo_ativo = !empty($campo['ativo']);
                    $campo_obrigatorio = !empty($campo['obrigatorio_cliente']);
                ?>
                <article class="item-card">
                    <div class="item-head">
                        <div>
                            <div class="item-title"><?= eventos_arquivos_e((string)($campo['titulo'] ?? 'Campo')) ?></div>
                            <div class="item-meta">
                                <?= eventos_arquivos_e((string)($campo['descricao'] ?? 'Sem descrição')) ?><br>
                                Arquivos recebidos: <strong><?= (int)($campo['total_arquivos'] ?? 0) ?></strong> •
                                do cliente: <strong><?= (int)($campo['total_upload_cliente'] ?? 0) ?></strong>
                            </div>
                        </div>
                        <div style="display:flex; gap:0.3rem; flex-wrap:wrap;">
                            <span class="badge <?= $campo_obrigatorio ? 'badge-required' : 'badge-optional' ?>">
                                <?= $campo_obrigatorio ? 'Obrigatório' : 'Opcional' ?>
                            </span>
                            <span class="badge <?= $campo_ativo ? 'badge-active' : 'badge-inactive' ?>">
                                <?= $campo_ativo ? 'Ativo' : 'Inativo' ?>
                            </span>
                        </div>
                    </div>
                    <div class="item-actions">
                        <form method="POST" style="display:inline-flex;">
                            <input type="hidden" name="meeting_id" value="<?= (int)$meeting_id ?>">
                            <input type="hidden" name="action" value="toggle_campo_ativo">
                            <input type="hidden" name="campo_id" value="<?= $campo_id ?>">
                            <input type="hidden" name="ativo" value="<?= $campo_ativo ? '0' : '1' ?>">
                            <button type="submit" class="btn btn-secondary"><?= $campo_ativo ? 'Desativar campo' : 'Reativar campo' ?></button>
                        </form>
                    </div>
                </article>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel">
            <h3>⬆️ Enviar Arquivo (Equipe)</h3>
            <div class="panel-subtitle">Suporte a vários tipos de arquivo, com limite de 500MB por envio.</div>

            <form method="POST" enctype="multipart/form-data" class="form-grid">
                <input type="hidden" name="meeting_id" value="<?= (int)$meeting_id ?>">
                <input type="hidden" name="action" value="upload_arquivo">

                <div class="field">
                    <label for="arquivoInput">Arquivo</label>
                    <input type="file"
                           id="arquivoInput"
                           name="arquivo"
                           accept=".png,.jpg,.jpeg,.gif,.webp,.heic,.heif,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx,.xlsm,.ppt,.pptx,.odt,.ods,.odp,.mp3,.wav,.ogg,.aac,.m4a,.mp4,.mov,.webm,.avi,.zip,.rar,.7z,.xml,.ofx"
                           required>
                    <div class="hint">Tipos comuns de imagem, documento, áudio, vídeo e compactados.</div>
                </div>

                <div class="field">
                    <label for="campoId">Vincular a um campo solicitado (opcional)</label>
                    <select id="campoId" name="campo_id">
                        <option value="">Nenhum campo específico</option>
                        <?php foreach ($campos_ativos as $campo): ?>
                        <option value="<?= (int)$campo['id'] ?>"><?= eventos_arquivos_e((string)($campo['titulo'] ?? 'Campo')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="descricaoArquivo">Descrição do arquivo</label>
                    <textarea id="descricaoArquivo" name="descricao_arquivo" placeholder="Ex.: Convite final aprovado em alta resolução."></textarea>
                </div>

                <label class="check-row">
                    <input type="checkbox" name="visivel_cliente" value="1">
                    <span>Cliente pode visualizar no portal</span>
                </label>

                <div>
                    <button type="submit" class="btn btn-primary">Enviar arquivo</button>
                </div>
            </form>
        </div>
    </section>

    <section class="panel">
        <h3>🗂️ Arquivos Enviados</h3>
        <div class="panel-subtitle">Controle de visibilidade e remoção dos arquivos do evento.</div>

        <div class="arquivos-list">
            <?php if (empty($arquivos)): ?>
            <div class="empty">Nenhum arquivo enviado para este evento.</div>
            <?php else: ?>
            <?php foreach ($arquivos as $arquivo): ?>
            <?php
                $arquivo_id = (int)($arquivo['id'] ?? 0);
                $visivel = !empty($arquivo['visivel_cliente']);
                $campo_interno = !empty($arquivo['campo_interno_only']);
                $nome = trim((string)($arquivo['original_name'] ?? 'arquivo'));
                $descricao = trim((string)($arquivo['descricao'] ?? ''));
                $campo_titulo = trim((string)($arquivo['campo_titulo'] ?? ''));
                $uploaded_by = trim((string)($arquivo['uploaded_by_type'] ?? 'interno'));
                $uploaded_at = trim((string)($arquivo['uploaded_at'] ?? ''));
                $uploaded_at_fmt = $uploaded_at !== '' ? date('d/m/Y H:i', strtotime($uploaded_at)) : '-';
                $size_bytes = max(0, (int)($arquivo['size_bytes'] ?? 0));
                $size_mb = $size_bytes > 0 ? number_format($size_bytes / 1024 / 1024, 2, ',', '.') . ' MB' : '0 MB';
                $public_url = trim((string)($arquivo['public_url'] ?? ''));
            ?>
            <article class="item-card">
                <div class="item-head">
                    <div>
                        <div class="item-title">📎 <?= eventos_arquivos_e($nome) ?></div>
                        <div class="item-meta">
                            <?= $campo_titulo !== '' ? 'Campo: <strong>' . eventos_arquivos_e($campo_titulo) . '</strong><br>' : '' ?>
                            Enviado por: <strong><?= eventos_arquivos_e($uploaded_by) ?></strong> • <?= eventos_arquivos_e($uploaded_at_fmt) ?><br>
                            Tamanho: <?= eventos_arquivos_e($size_mb) ?>
                            <?php if ($descricao !== ''): ?><br>Descrição: <?= eventos_arquivos_e($descricao) ?><?php endif; ?>
                        </div>
                    </div>
                    <div style="display:flex; gap:0.3rem; flex-wrap:wrap;">
                        <span class="badge <?= ($visivel && !$campo_interno) ? 'badge-visible' : 'badge-hidden' ?>">
                            <?= ($visivel && !$campo_interno) ? 'Visível ao cliente' : 'Oculto do cliente' ?>
                        </span>
                        <?php if ($campo_interno): ?>
                        <span class="badge badge-hidden">Uso interno</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="item-actions">
                    <?php if ($public_url !== ''): ?>
                    <a class="btn btn-primary" href="<?= eventos_arquivos_e($public_url) ?>" target="_blank" rel="noopener noreferrer">Abrir arquivo</a>
                    <?php endif; ?>

                    <?php if (!$campo_interno): ?>
                    <form method="POST" style="display:inline-flex;">
                        <input type="hidden" name="meeting_id" value="<?= (int)$meeting_id ?>">
                        <input type="hidden" name="action" value="toggle_visibilidade_arquivo">
                        <input type="hidden" name="arquivo_id" value="<?= $arquivo_id ?>">
                        <input type="hidden" name="visivel_cliente" value="<?= $visivel ? '0' : '1' ?>">
                        <button type="submit" class="btn btn-secondary">
                            <?= $visivel ? 'Ocultar do cliente' : 'Exibir para cliente' ?>
                        </button>
                    </form>
                    <?php endif; ?>

                    <form method="POST" style="display:inline-flex;" onsubmit="return confirm('Deseja remover este arquivo?');">
                        <input type="hidden" name="meeting_id" value="<?= (int)$meeting_id ?>">
                        <input type="hidden" name="action" value="excluir_arquivo">
                        <input type="hidden" name="arquivo_id" value="<?= $arquivo_id ?>">
                        <button type="submit" class="btn btn-danger">Excluir</button>
                    </form>
                </div>
            </article>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="panel">
        <h3>🧾 Log de anexos</h3>
        <div class="panel-subtitle">Histórico de uploads, substituições, exclusões e mudanças de visibilidade.</div>

        <div class="arquivos-list">
            <?php if (empty($logs_arquivos)): ?>
            <div class="empty">Sem registros de log até o momento.</div>
            <?php else: ?>
            <?php foreach ($logs_arquivos as $log): ?>
            <?php
                $log_data = trim((string)($log['created_at'] ?? ''));
                $log_data_fmt = $log_data !== '' ? date('d/m/Y H:i:s', strtotime($log_data)) : '-';
                $log_label = trim((string)($log['action_label'] ?? $log['action_type'] ?? 'Ação'));
                $log_note = trim((string)($log['action_note'] ?? ''));
                $log_campo = trim((string)($log['campo_titulo'] ?? ''));
                $log_arquivo = trim((string)($log['arquivo_nome'] ?? ''));
                $log_actor = trim((string)($log['actor_user_nome'] ?? ''));
                $log_actor_type = trim((string)($log['actor_type'] ?? 'interno'));
                if ($log_actor === '') {
                    $log_actor = $log_actor_type;
                }
            ?>
            <article class="item-card">
                <div class="item-head">
                    <div>
                        <div class="item-title"><?= eventos_arquivos_e($log_label) ?></div>
                        <div class="item-meta">
                            Data/hora: <strong><?= eventos_arquivos_e($log_data_fmt) ?></strong> • Responsável: <strong><?= eventos_arquivos_e($log_actor) ?></strong><br>
                            <?php if ($log_campo !== ''): ?>Campo: <?= eventos_arquivos_e($log_campo) ?><?php endif; ?>
                            <?php if ($log_campo !== '' && $log_arquivo !== ''): ?> • <?php endif; ?>
                            <?php if ($log_arquivo !== ''): ?>Arquivo: <?= eventos_arquivos_e($log_arquivo) ?><?php endif; ?>
                            <?php if ($log_note !== ''): ?><br><?= eventos_arquivos_e($log_note) ?><?php endif; ?>
                        </div>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php endSidebar(); ?>
