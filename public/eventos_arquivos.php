<?php
/**
 * eventos_arquivos.php
 * Gestão interna de upload de PDF do evento.
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

// Mantém campos de sistema para garantir existência do campo de PDF.
eventos_arquivos_seed_campos_sistema($pdo, $meeting_id, $user_id);

$feedback_ok = '';
$feedback_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action !== 'upload_resumo_evento') {
            $feedback_error = 'Ação não permitida nesta página.';
        } else {
            $descricao = trim((string)($_POST['descricao_resumo_evento'] ?? ''));
            $file = $_FILES['arquivo_resumo_evento'] ?? null;
            if (!$file || !is_array($file)) {
                $feedback_error = 'Selecione um arquivo PDF para envio.';
            } else {
                $upload_error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($upload_error !== UPLOAD_ERR_OK) {
                    $feedback_error = eventos_arquivos_upload_error_message($upload_error);
                } elseif ((int)($file['size'] ?? 0) > 500 * 1024 * 1024) {
                    $feedback_error = 'Arquivo muito grande. Limite máximo: 500MB.';
                } else {
                    $campo_resumo = eventos_arquivos_buscar_campo_por_chave($pdo, $meeting_id, 'resumo_evento', true);
                    if (!$campo_resumo || (int)($campo_resumo['id'] ?? 0) <= 0) {
                        $feedback_error = 'Campo de upload PDF não encontrado.';
                    } else {
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
                                ? 'PDF atualizado com sucesso (versão anterior substituída).'
                                : 'PDF anexado com sucesso.';
                        } else {
                            $feedback_error = (string)($saved['error'] ?? 'Falha ao salvar o PDF.');
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('eventos_arquivos.php POST: ' . $e->getMessage());
        $feedback_error = 'Erro interno ao processar a solicitação.';
    }
}

$campo_resumo_evento = eventos_arquivos_buscar_campo_por_chave($pdo, $meeting_id, 'resumo_evento', true);
$campo_resumo_evento_id = (int)($campo_resumo_evento['id'] ?? 0);
$resumo_evento_arquivo_atual = null;

if ($campo_resumo_evento_id > 0) {
    $resumo_evento_arquivos = eventos_arquivos_listar($pdo, $meeting_id, false, $campo_resumo_evento_id, true);
    $resumo_evento_arquivo_atual = $resumo_evento_arquivos[0] ?? null;
} elseif ($feedback_error === '') {
    $feedback_error = 'Campo de upload PDF não está disponível para este evento.';
}

includeSidebar('Upload de PDF');
?>

<style>
    .arquivos-container {
        padding: 2rem;
        max-width: 820px;
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

    .panel {
        background: #fff;
        border: 1px solid #dbe3ef;
        border-radius: 12px;
        padding: 1rem;
    }

    .panel h2 {
        margin: 0;
        color: #0f172a;
        font-size: 1.04rem;
    }

    .panel-subtitle {
        margin-top: 0.35rem;
        color: #64748b;
        font-size: 0.84rem;
    }

    .event-chip {
        margin-top: 0.85rem;
        padding: 0.55rem 0.7rem;
        border-radius: 8px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        font-size: 0.84rem;
        color: #334155;
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

    .field input[type="file"],
    .field textarea {
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

    .hint {
        color: #64748b;
        font-size: 0.76rem;
        margin-top: 0.1rem;
    }

    .arquivo-atual {
        margin-top: 0.95rem;
    }

    .empty {
        color: #64748b;
        font-size: 0.86rem;
        font-style: italic;
        padding: 0.6rem 0;
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

    .item-actions {
        margin-top: 0.6rem;
        display: flex;
        gap: 0.45rem;
        flex-wrap: wrap;
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

    .badge-hidden {
        color: #6b7280;
        background: #f3f4f6;
        border-color: #d1d5db;
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
            <h1 class="page-title">Upload de PDF</h1>
            <div class="page-subtitle">Esta página mantém somente o envio de PDF do evento.</div>
        </div>
        <a href="index.php?page=eventos_organizacao&id=<?= (int)$meeting_id ?>" class="btn btn-secondary">Voltar à organização</a>
    </div>

    <?php if (!empty($feedback_ok)): ?>
    <div class="alert alert-success"><?= eventos_arquivos_e($feedback_ok) ?></div>
    <?php endif; ?>

    <?php if (!empty($feedback_error)): ?>
    <div class="alert alert-error"><?= eventos_arquivos_e($feedback_error) ?></div>
    <?php endif; ?>

    <section class="panel" id="resumo-evento">
        <h2>Upload de PDF</h2>
        <div class="panel-subtitle">Somente PDF, com substituição automática da versão anterior.</div>

        <div class="event-chip">
            Evento: <strong><?= eventos_arquivos_e($nome_evento) ?></strong> | Data: <strong><?= eventos_arquivos_e($data_evento_fmt) ?></strong>
        </div>

        <form method="POST" enctype="multipart/form-data" class="form-grid">
            <input type="hidden" name="meeting_id" value="<?= (int)$meeting_id ?>">
            <input type="hidden" name="action" value="upload_resumo_evento">

            <div class="field">
                <label for="arquivoResumoEvento">Arquivo</label>
                <input type="file" id="arquivoResumoEvento" name="arquivo_resumo_evento" accept=".pdf,application/pdf" required>
                <div class="hint">Ao anexar novo arquivo, o anterior é substituído automaticamente e fica registrado no log.</div>
            </div>

            <div class="field">
                <label for="descricaoResumoEvento">Descrição (opcional)</label>
                <textarea id="descricaoResumoEvento" name="descricao_resumo_evento" placeholder="Ex.: Contrato assinado atualizado em 28/02/2026."></textarea>
            </div>

            <div>
                <button type="submit" class="btn btn-primary">Salvar PDF</button>
            </div>
        </form>

        <div class="arquivo-atual">
            <?php if (!$resumo_evento_arquivo_atual): ?>
            <div class="empty">Nenhum PDF anexado ainda.</div>
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
    </section>
</div>

<?php endSidebar(); ?>
