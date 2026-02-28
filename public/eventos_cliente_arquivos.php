<?php
/**
 * eventos_cliente_arquivos.php
 * Área pública de arquivos do cliente (separada do portal principal)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';
require_once __DIR__ . '/upload_magalu.php';

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$error = '';
$form_error = '';
$success_message = '';

$portal = null;
$reuniao = null;
$snapshot = [];
$visivel_arquivos = false;
$editavel_arquivos = false;

$campos_arquivos = [];
$arquivos_portal = [];
$arquivos_campos_map = [];
$arquivos_resumo = [
    'campos_total' => 0,
    'campos_obrigatorios' => 0,
    'campos_pendentes' => 0,
    'arquivos_total' => 0,
    'arquivos_visiveis_cliente' => 0,
    'arquivos_cliente' => 0,
];

function eventos_cliente_arquivos_upload_error_message(int $code): string {
    switch ($code) {
        case UPLOAD_ERR_OK:
            return '';
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'Arquivo excede o limite permitido pelo servidor.';
        case UPLOAD_ERR_PARTIAL:
            return 'Upload incompleto. Tente novamente.';
        case UPLOAD_ERR_NO_FILE:
            return 'Selecione um arquivo para enviar.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Servidor sem pasta temporária para upload.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Falha ao gravar arquivo temporário.';
        case UPLOAD_ERR_EXTENSION:
            return 'Upload bloqueado por extensão do servidor.';
        default:
            return 'Erro desconhecido de upload.';
    }
}

if ($token === '') {
    $error = 'Link inválido.';
} else {
    $portal = eventos_cliente_portal_get_by_token($pdo, $token);
    if (!$portal || empty($portal['is_active'])) {
        $error = 'Portal não encontrado ou desativado.';
    } else {
        $reuniao = eventos_reuniao_get($pdo, (int)($portal['meeting_id'] ?? 0));
        if (!$reuniao) {
            $error = 'Reunião não encontrada.';
        } else {
            $snapshot = json_decode((string)($reuniao['me_event_snapshot'] ?? '{}'), true);
            if (!is_array($snapshot)) {
                $snapshot = [];
            }

            $visivel_arquivos = !empty($portal['visivel_arquivos']);
            $editavel_arquivos = !empty($portal['editavel_arquivos']);

            if (!$visivel_arquivos) {
                $error = 'A área de arquivos ainda não está habilitada para este evento.';
            }
        }
    }
}

if ($error === '' && $reuniao) {
    $tipo_evento_real = eventos_reuniao_normalizar_tipo_evento_real((string)($reuniao['tipo_evento_real'] ?? ($snapshot['tipo_evento_real'] ?? '')));
    eventos_arquivos_seed_campos_por_tipo($pdo, (int)$reuniao['id'], $tipo_evento_real, 0);
    eventos_arquivos_seed_campos_sistema($pdo, (int)$reuniao['id'], 0);

    $campos_arquivos = array_values(array_filter(
        eventos_arquivos_listar_campos($pdo, (int)$reuniao['id'], true, false),
        static fn(array $campo): bool => trim((string)($campo['chave_sistema'] ?? '')) === ''
    ));
    $arquivos_portal = eventos_arquivos_listar($pdo, (int)$reuniao['id'], true, null, false);
    $arquivos_resumo = eventos_arquivos_resumo($pdo, (int)$reuniao['id']);

    foreach ($arquivos_portal as $arquivo_item) {
        $campo_id = (int)($arquivo_item['campo_id'] ?? 0);
        if ($campo_id <= 0) {
            continue;
        }
        if (!isset($arquivos_campos_map[$campo_id])) {
            $arquivos_campos_map[$campo_id] = [];
        }
        $arquivos_campos_map[$campo_id][] = $arquivo_item;
    }
}

if ($error === '' && $reuniao && $_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'upload_arquivo_cliente') {
    if (!$editavel_arquivos) {
        $form_error = 'Uploads de arquivos não estão habilitados para este portal.';
    } else {
        $file = $_FILES['arquivo'] ?? null;
        if (!$file || !is_array($file)) {
            $form_error = 'Selecione um arquivo para enviar.';
        } else {
            $upload_error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($upload_error !== UPLOAD_ERR_OK) {
                $form_error = eventos_cliente_arquivos_upload_error_message($upload_error);
            } elseif ((int)($file['size'] ?? 0) > 500 * 1024 * 1024) {
                $form_error = 'Arquivo muito grande. Limite máximo: 500MB.';
            } else {
                $campo_id = (int)($_POST['campo_id'] ?? 0);
                $descricao = trim((string)($_POST['descricao_arquivo'] ?? ''));

                try {
                    $uploader = new MagaluUpload(500);
                    $upload_result = $uploader->upload($file, 'eventos/reunioes/' . (int)$reuniao['id'] . '/cliente_arquivos');
                    $saved = eventos_arquivos_salvar_item(
                        $pdo,
                        (int)$reuniao['id'],
                        $upload_result,
                        $campo_id > 0 ? $campo_id : null,
                        $descricao,
                        true,
                        'cliente',
                        null
                    );

                    if (!empty($saved['ok'])) {
                        $success_message = 'Arquivo enviado com sucesso.';
                        $campos_arquivos = array_values(array_filter(
                            eventos_arquivos_listar_campos($pdo, (int)$reuniao['id'], true, false),
                            static fn(array $campo): bool => trim((string)($campo['chave_sistema'] ?? '')) === ''
                        ));
                        $arquivos_portal = eventos_arquivos_listar($pdo, (int)$reuniao['id'], true, null, false);
                        $arquivos_resumo = eventos_arquivos_resumo($pdo, (int)$reuniao['id']);
                        $arquivos_campos_map = [];
                        foreach ($arquivos_portal as $arquivo_item) {
                            $campo_id_row = (int)($arquivo_item['campo_id'] ?? 0);
                            if ($campo_id_row <= 0) {
                                continue;
                            }
                            if (!isset($arquivos_campos_map[$campo_id_row])) {
                                $arquivos_campos_map[$campo_id_row] = [];
                            }
                            $arquivos_campos_map[$campo_id_row][] = $arquivo_item;
                        }
                    } else {
                        $form_error = (string)($saved['error'] ?? 'Não foi possível salvar o arquivo.');
                    }
                } catch (Throwable $e) {
                    error_log('eventos_cliente_arquivos upload arquivo: ' . $e->getMessage());
                    $form_error = 'Falha ao enviar arquivo. Tente novamente.';
                }
            }
        }
    }
}

$evento_nome = trim((string)($snapshot['nome'] ?? 'Seu Evento'));
$data_evento_raw = trim((string)($snapshot['data'] ?? ''));
$data_evento_fmt = $data_evento_raw !== '' ? date('d/m/Y', strtotime($data_evento_raw)) : '-';
$hora_inicio = trim((string)($snapshot['hora_inicio'] ?? $snapshot['hora'] ?? ''));
$hora_fim = trim((string)($snapshot['hora_fim'] ?? ''));
$horario_evento = $hora_inicio !== '' ? $hora_inicio : '-';
if ($hora_inicio !== '' && $hora_fim !== '') {
    $horario_evento .= ' - ' . $hora_fim;
}
$local_evento = trim((string)($snapshot['local'] ?? 'Local não informado'));
$cliente_nome = trim((string)($snapshot['cliente']['nome'] ?? 'Cliente'));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Arquivos - Portal do Cliente</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8fafc;
            color: #1e293b;
            line-height: 1.6;
        }

        .header {
            background: linear-gradient(135deg, #ea580c 0%, #f97316 100%);
            color: #fff;
            padding: 2rem 1rem;
            text-align: center;
        }

        .header img { max-width: 170px; margin-bottom: 0.8rem; }
        .header h1 { font-size: 1.55rem; margin-bottom: 0.3rem; }

        .container {
            max-width: 1080px;
            margin: 0 auto;
            padding: 1.2rem;
        }

        .alert {
            border-radius: 10px;
            padding: 0.9rem 1rem;
            margin-bottom: 1rem;
            border: 1px solid transparent;
            font-size: 0.9rem;
        }

        .alert-error {
            background: #fee2e2;
            border-color: #fca5a5;
            color: #991b1b;
        }

        .alert-success {
            background: #dcfce7;
            border-color: #86efac;
            color: #166534;
        }

        .event-box,
        .card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .event-box h2 {
            color: #1f2937;
            margin-bottom: 0.6rem;
        }

        .event-meta {
            display: grid;
            gap: 0.55rem;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            font-size: 0.92rem;
            color: #334155;
        }

        .actions-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
            margin-top: 0.75rem;
        }

        .card h3 {
            color: #0f172a;
            font-size: 1.06rem;
            margin-bottom: 0.45rem;
        }

        .card-subtitle {
            color: #64748b;
            font-size: 0.86rem;
            margin-bottom: 0.8rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            border: 1px solid transparent;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.86rem;
            font-weight: 700;
            padding: 0.56rem 0.9rem;
            gap: 0.45rem;
        }

        .btn-primary { background: #ea580c; color: #fff; }
        .btn-primary:hover { background: #c2410c; }
        .btn-secondary { background: #f1f5f9; border-color: #dbe3ef; color: #334155; }

        .upload-form {
            border: 1px solid #dbe3ef;
            border-radius: 10px;
            background: #f8fafc;
            padding: 0.85rem;
            display: grid;
            gap: 0.65rem;
        }

        .upload-form label {
            font-size: 0.82rem;
            color: #334155;
            font-weight: 700;
            display: grid;
            gap: 0.3rem;
        }

        .upload-form input[type="file"],
        .upload-form select,
        .upload-form textarea {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 0.56rem 0.64rem;
            font-size: 0.85rem;
            background: #fff;
            color: #1f2937;
        }

        .upload-form textarea {
            min-height: 76px;
            resize: vertical;
        }

        .upload-help {
            color: #64748b;
            font-size: 0.78rem;
            margin-top: -0.25rem;
        }

        .campo-status {
            margin-top: 0.4rem;
            display: grid;
            gap: 0.45rem;
        }

        .campo-status-item,
        .arquivo-item {
            border: 1px solid #dbe3ef;
            border-radius: 8px;
            background: #fff;
            padding: 0.6rem 0.65rem;
            font-size: 0.84rem;
        }

        .arquivo-item + .arquivo-item {
            margin-top: 0.5rem;
        }

        .arquivo-link {
            color: #1d4ed8;
            text-decoration: none;
            font-weight: 700;
        }

        .arquivo-link:hover { text-decoration: underline; }

        .arquivo-meta {
            color: #64748b;
            margin-top: 0.25rem;
            font-size: 0.78rem;
            line-height: 1.4;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 0.16rem 0.45rem;
            font-size: 0.7rem;
            font-weight: 700;
            border: 1px solid transparent;
            margin-left: 0.35rem;
        }

        .badge-ok {
            color: #065f46;
            background: #d1fae5;
            border-color: #6ee7b7;
        }

        .badge-warn {
            color: #92400e;
            background: #fef3c7;
            border-color: #fcd34d;
        }

        .empty-text {
            color: #64748b;
            font-style: italic;
            font-size: 0.88rem;
        }

        @media (max-width: 780px) {
            .container {
                padding: 1rem 0.8rem 1.2rem;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <img src="logo.png" alt="Grupo Smile" onerror="this.style.display='none'">
        <h1>📁 Arquivos do Evento</h1>
        <p>Envio e acompanhamento de materiais</p>
    </div>

    <div class="container">
        <?php if ($error !== ''): ?>
        <div class="alert alert-error"><strong>Erro:</strong> <?= htmlspecialchars($error) ?></div>
        <?php else: ?>
            <?php if ($success_message !== ''): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>
            <?php if ($form_error !== ''): ?>
            <div class="alert alert-error"><strong>Erro:</strong> <?= htmlspecialchars($form_error) ?></div>
            <?php endif; ?>

            <div class="event-box">
                <h2><?= htmlspecialchars($evento_nome) ?></h2>
                <div class="event-meta">
                    <div><strong>📅 Data:</strong> <?= htmlspecialchars($data_evento_fmt) ?></div>
                    <div><strong>⏰ Horário:</strong> <?= htmlspecialchars($horario_evento) ?></div>
                    <div><strong>📍 Local:</strong> <?= htmlspecialchars($local_evento) ?></div>
                    <div><strong>👤 Cliente:</strong> <?= htmlspecialchars($cliente_nome) ?></div>
                </div>
                <div class="actions-row">
                    <a class="btn btn-secondary" href="index.php?page=eventos_cliente_portal&token=<?= urlencode($token) ?>">← Voltar ao portal</a>
                </div>
            </div>

            <section class="card">
                <h3>Upload de arquivos</h3>
                <div class="card-subtitle">Arquivos enviados pelo cliente: <?= (int)$arquivos_resumo['arquivos_cliente'] ?>.</div>

                <?php if ($editavel_arquivos): ?>
                <form method="POST" enctype="multipart/form-data" class="upload-form">
                    <input type="hidden" name="action" value="upload_arquivo_cliente">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                    <label>
                        Arquivo
                        <input type="file"
                               name="arquivo"
                               accept=".png,.jpg,.jpeg,.gif,.webp,.heic,.heif,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx,.xlsm,.ppt,.pptx,.odt,.ods,.odp,.mp3,.wav,.ogg,.aac,.m4a,.mp4,.mov,.webm,.avi,.zip,.rar,.7z,.xml,.ofx"
                               required>
                    </label>

                    <label>
                        Tipo do arquivo (opcional)
                        <select name="campo_id">
                            <option value="">Selecionar...</option>
                            <?php foreach ($campos_arquivos as $campo_arquivo): ?>
                            <option value="<?= (int)($campo_arquivo['id'] ?? 0) ?>">
                                <?= htmlspecialchars((string)($campo_arquivo['titulo'] ?? 'Campo')) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        Descrição do arquivo
                        <textarea name="descricao_arquivo" placeholder="Ex.: Imagem final aprovada do convite."></textarea>
                    </label>

                    <button type="submit" class="btn btn-primary">Enviar arquivo</button>
                    <div class="upload-help">Limite de até 500MB por arquivo.</div>
                </form>
                <?php else: ?>
                <div class="empty-text">Uploads desativados para este portal.</div>
                <?php endif; ?>
            </section>

            <section class="card">
                <h3>Campos solicitados</h3>
                <div class="card-subtitle">Total de campos pendentes: <?= (int)$arquivos_resumo['campos_pendentes'] ?>.</div>

                <?php if (empty($campos_arquivos)): ?>
                <div class="empty-text">Nenhum campo solicitado no momento.</div>
                <?php else: ?>
                <div class="campo-status">
                    <?php foreach ($campos_arquivos as $campo_arquivo): ?>
                    <?php
                        $campo_id = (int)($campo_arquivo['id'] ?? 0);
                        $qtd_campo = isset($arquivos_campos_map[$campo_id]) ? count($arquivos_campos_map[$campo_id]) : 0;
                    ?>
                    <div class="campo-status-item">
                        <strong><?= htmlspecialchars((string)($campo_arquivo['titulo'] ?? 'Campo')) ?></strong>
                        <?php if (!empty($campo_arquivo['obrigatorio_cliente'])): ?>
                        <span class="badge <?= $qtd_campo > 0 ? 'badge-ok' : 'badge-warn' ?>">
                            <?= $qtd_campo > 0 ? 'Recebido' : 'Pendente' ?>
                        </span>
                        <?php endif; ?>
                        <?php if (!empty($campo_arquivo['descricao'])): ?>
                        <div class="arquivo-meta"><?= htmlspecialchars((string)$campo_arquivo['descricao']) ?></div>
                        <?php endif; ?>
                        <div class="arquivo-meta">
                            <?= $qtd_campo > 0 ? $qtd_campo . ' arquivo(s) enviado(s)' : 'Aguardando envio' ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>

            <section class="card">
                <h3>Arquivos disponíveis</h3>
                <div class="card-subtitle">Arquivos visíveis para o cliente: <?= (int)$arquivos_resumo['arquivos_visiveis_cliente'] ?>.</div>

                <?php if (empty($arquivos_portal)): ?>
                <div class="empty-text">Nenhum arquivo disponível ainda.</div>
                <?php else: ?>
                <?php foreach ($arquivos_portal as $arquivo_portal): ?>
                <?php
                    $arquivo_nome = trim((string)($arquivo_portal['original_name'] ?? 'arquivo'));
                    $arquivo_url = trim((string)($arquivo_portal['public_url'] ?? ''));
                    $arquivo_desc = trim((string)($arquivo_portal['descricao'] ?? ''));
                    $arquivo_campo = trim((string)($arquivo_portal['campo_titulo'] ?? ''));
                    $arquivo_data = trim((string)($arquivo_portal['uploaded_at'] ?? ''));
                    $arquivo_data_fmt = $arquivo_data !== '' ? date('d/m/Y H:i', strtotime($arquivo_data)) : '-';
                    $arquivo_autor = trim((string)($arquivo_portal['uploaded_by_type'] ?? 'interno'));
                ?>
                <div class="arquivo-item">
                    <?php if ($arquivo_url !== ''): ?>
                    <a class="arquivo-link" href="<?= htmlspecialchars($arquivo_url) ?>" target="_blank" rel="noopener noreferrer">
                        <?= htmlspecialchars($arquivo_nome) ?>
                    </a>
                    <?php else: ?>
                    <strong><?= htmlspecialchars($arquivo_nome) ?></strong>
                    <?php endif; ?>
                    <div class="arquivo-meta">
                        <?= $arquivo_campo !== '' ? 'Tipo: ' . htmlspecialchars($arquivo_campo) . ' • ' : '' ?>
                        Enviado por <?= htmlspecialchars($arquivo_autor) ?> em <?= htmlspecialchars($arquivo_data_fmt) ?>
                        <?php if ($arquivo_desc !== ''): ?><br><?= htmlspecialchars($arquivo_desc) ?><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</body>
</html>
