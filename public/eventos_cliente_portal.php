<?php
/**
 * eventos_cliente_portal.php
 * Portão público do cliente (visão por cards configuráveis)
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
$link_observacoes = null;
$links_dj_portal = [];
$convidados_resumo = ['total' => 0, 'checkin' => 0, 'pendentes' => 0];
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

function eventos_cliente_portal_upload_error_message(int $code): string {
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

            $tipo_evento_real = eventos_reuniao_normalizar_tipo_evento_real((string)($reuniao['tipo_evento_real'] ?? ($snapshot['tipo_evento_real'] ?? '')));
            eventos_arquivos_seed_campos_por_tipo($pdo, (int)$reuniao['id'], $tipo_evento_real, 0);

            $links_dj = eventos_reuniao_listar_links_cliente($pdo, (int)$reuniao['id'], 'cliente_dj');
            $links_observacoes = eventos_reuniao_listar_links_cliente($pdo, (int)$reuniao['id'], 'cliente_observacoes');

            $dj_has_slot_rules = false;
            foreach ($links_dj as $dj_link_item) {
                if (!empty($dj_link_item['portal_configured'])) {
                    $dj_has_slot_rules = true;
                    break;
                }
            }

            if ($dj_has_slot_rules) {
                foreach ($links_dj as $dj_link_item) {
                    if (empty($dj_link_item['is_active'])) {
                        continue;
                    }
                    if (empty($dj_link_item['portal_visible'])) {
                        continue;
                    }
                    $links_dj_portal[] = $dj_link_item;
                }
            } else {
                foreach ($links_dj as $dj_link_item) {
                    if (!empty($dj_link_item['is_active'])) {
                        $links_dj_portal[] = $dj_link_item;
                    }
                }
            }

            $link_observacoes = !empty($links_observacoes) ? $links_observacoes[0] : null;
            $convidados_resumo = eventos_convidados_resumo($pdo, (int)$reuniao['id']);

            $campos_arquivos = eventos_arquivos_listar_campos($pdo, (int)$reuniao['id'], true);
            $arquivos_portal = eventos_arquivos_listar($pdo, (int)$reuniao['id'], true);
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

$visivel_reuniao = !empty($portal['visivel_reuniao']);
$editavel_reuniao = !empty($portal['editavel_reuniao']);
$visivel_dj = !empty($portal['visivel_dj']);
$editavel_dj = !empty($portal['editavel_dj']);
$visivel_convidados = !empty($portal['visivel_convidados']);
$editavel_convidados = !empty($portal['editavel_convidados']);
$visivel_arquivos = !empty($portal['visivel_arquivos']);
$editavel_arquivos = !empty($portal['editavel_arquivos']);

if ($error === '' && $reuniao && $_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'upload_arquivo_cliente') {
    if (!$visivel_arquivos || !$editavel_arquivos) {
        $form_error = 'Uploads de arquivos não estão habilitados para este portal.';
    } else {
        $file = $_FILES['arquivo'] ?? null;
        if (!$file || !is_array($file)) {
            $form_error = 'Selecione um arquivo para enviar.';
        } else {
            $upload_error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($upload_error !== UPLOAD_ERR_OK) {
                $form_error = eventos_cliente_portal_upload_error_message($upload_error);
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
                        $campos_arquivos = eventos_arquivos_listar_campos($pdo, (int)$reuniao['id'], true);
                        $arquivos_portal = eventos_arquivos_listar($pdo, (int)$reuniao['id'], true);
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
                    error_log('eventos_cliente_portal upload arquivo: ' . $e->getMessage());
                    $form_error = 'Falha ao enviar arquivo. Tente novamente.';
                }
            }
        }
    }
}

$cards_visiveis_total =
    ($visivel_reuniao ? 1 : 0) +
    ($visivel_dj ? 1 : 0) +
    ($visivel_convidados ? 1 : 0) +
    ($visivel_arquivos ? 1 : 0);
$view = trim((string)($_GET['view'] ?? $_POST['view'] ?? 'dashboard'));
$view = $view === '' ? 'dashboard' : $view;
$view_arquivos_ativo = ($view === 'arquivos' && $visivel_arquivos);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal do Cliente - Organização do Evento</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8fafc;
            color: #1e293b;
            line-height: 1.6;
        }

        .header {
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
            color: #fff;
            padding: 2rem 1rem;
            text-align: center;
        }

        .header img {
            max-width: 170px;
            margin-bottom: 0.9rem;
        }

        .header h1 {
            font-size: 1.6rem;
            margin-bottom: 0.35rem;
        }

        .header p {
            opacity: 0.92;
            font-size: 0.95rem;
        }

        .container {
            max-width: 1320px;
            margin: 0 auto;
            padding: 1.4rem;
        }

        .alert {
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
            border: 1px solid transparent;
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

        .event-box {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            padding: 1.1rem;
            margin-bottom: 1.2rem;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
        }

        .event-box h2 {
            color: #1e3a8a;
            margin-bottom: 0.6rem;
            font-size: 1.3rem;
        }

        .event-meta {
            display: grid;
            gap: 0.6rem;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            color: #334155;
            font-size: 0.92rem;
        }

        .cards-grid {
            display: grid;
            gap: 1.1rem;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            align-items: start;
        }

        .portal-card {
            min-height: 250px;
            border-radius: 22px;
            padding: 1.55rem;
            color: #fff;
            box-shadow: 0 18px 38px rgba(15, 23, 42, 0.16);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .portal-card::before {
            content: '';
            position: absolute;
            width: 240px;
            height: 240px;
            border-radius: 999px;
            top: -120px;
            right: -90px;
            background: rgba(255, 255, 255, 0.14);
            pointer-events: none;
        }

        .portal-card-theme-reuniao {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
        }

        .portal-card-theme-dj {
            background: linear-gradient(135deg, #06b6d4 0%, #0284c7 100%);
        }

        .portal-card-theme-convidados {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .portal-card-theme-arquivos {
            background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
        }

        .card-icon {
            font-size: 2.7rem;
            line-height: 1;
            margin-bottom: 1.25rem;
            position: relative;
            z-index: 1;
        }

        .card-title {
            position: relative;
            z-index: 1;
        }

        .card-title h3 {
            font-size: 2.2rem;
            line-height: 1.1;
            margin-bottom: 0.65rem;
            font-weight: 800;
            letter-spacing: -0.01em;
        }

        .card-subtitle {
            font-size: 0.98rem;
            line-height: 1.35;
            opacity: 0.9;
            max-width: 92%;
        }

        .card-meta {
            margin-top: 0.7rem;
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.01em;
            opacity: 0.92;
        }

        .card-actions {
            margin-top: 1rem;
            display: flex;
            gap: 0.6rem;
            flex-wrap: wrap;
            position: relative;
            z-index: 1;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            padding: 0.6rem 0.95rem;
            border-radius: 10px;
            border: 1px solid transparent;
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
        }

        .btn-primary {
            background: #1d4ed8;
            color: #fff;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.22);
            border-color: rgba(255, 255, 255, 0.35);
            color: #fff;
        }

        .empty-text {
            color: #64748b;
            font-style: italic;
            font-size: 0.88rem;
        }

        .portal-card .btn-primary {
            background: #fff;
            color: #1e293b;
            border-color: rgba(255, 255, 255, 0.65);
        }

        .portal-card .btn-secondary {
            background: rgba(255, 255, 255, 0.18);
            border-color: rgba(255, 255, 255, 0.45);
            color: #fff;
        }

        .btn-disabled {
            cursor: default;
            opacity: 0.82;
        }

        .module-header {
            margin-bottom: 0.9rem;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.6rem;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            background: #fff;
            border: 1px solid #dbe3ef;
            border-radius: 10px;
            padding: 0.55rem 0.8rem;
            font-size: 0.82rem;
            font-weight: 700;
            color: #1e3a8a;
        }

        .module-panel {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 1rem;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.07);
        }

        .module-panel h3 {
            color: #0f172a;
            font-size: 1.1rem;
            margin-bottom: 0.2rem;
        }

        .module-subtitle {
            color: #64748b;
            font-size: 0.85rem;
            margin-bottom: 0.9rem;
        }

        .section-block {
            margin-top: 0.85rem;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 0.85rem;
            background: #f8fafc;
        }

        .section-block h4 {
            margin-bottom: 0.45rem;
            font-size: 0.95rem;
            color: #334155;
        }

        .upload-form {
            margin-top: 0.25rem;
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

        .attachments-list ul {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 0.55rem;
        }

        .attachments-list li {
            border: 1px solid #dbe3ef;
            border-radius: 8px;
            background: #fff;
            padding: 0.55rem 0.65rem;
            font-size: 0.84rem;
        }

        .attachments-list a {
            color: #1d4ed8;
            text-decoration: none;
        }

        .attachments-list a:hover {
            text-decoration: underline;
        }

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

        .arquivo-link:hover {
            text-decoration: underline;
        }

        .arquivo-meta {
            color: #64748b;
            margin-top: 0.25rem;
            font-size: 0.78rem;
            line-height: 1.4;
        }

        .campo-status {
            margin-top: 0.8rem;
            display: grid;
            gap: 0.45rem;
        }

        .campo-status-item {
            border: 1px solid #dbe3ef;
            border-radius: 8px;
            background: #fff;
            padding: 0.55rem 0.65rem;
            font-size: 0.82rem;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 0.16rem 0.45rem;
            font-size: 0.7rem;
            font-weight: 700;
            border: 1px solid transparent;
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

        @media (max-width: 760px) {
            .container {
                padding: 1rem 0.8rem 1.2rem;
            }

            .cards-grid {
                grid-template-columns: 1fr;
            }

            .portal-card {
                min-height: auto;
                padding: 1.25rem;
            }

            .card-title h3 {
                font-size: 1.85rem;
            }

            .card-subtitle {
                max-width: 100%;
            }

            .module-header {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <img src="logo.png" alt="Grupo Smile" onerror="this.style.display='none'">
        <h1>🧩 Portal do Cliente</h1>
        <p>Organização do evento</p>
    </div>

    <div class="container">
        <?php if ($error !== ''): ?>
        <div class="alert alert-error">
            <strong>Erro:</strong> <?= htmlspecialchars($error) ?>
        </div>
        <?php else: ?>
        <?php if ($success_message !== ''): ?>
        <div class="alert alert-success">
            <?= htmlspecialchars($success_message) ?>
        </div>
        <?php endif; ?>
        <?php if ($form_error !== ''): ?>
        <div class="alert alert-error">
            <strong>Erro:</strong> <?= htmlspecialchars($form_error) ?>
        </div>
        <?php endif; ?>
        <div class="event-box">
            <h2><?= htmlspecialchars($evento_nome) ?></h2>
            <div class="event-meta">
                <div><strong>📅 Data:</strong> <?= htmlspecialchars($data_evento_fmt) ?></div>
                <div><strong>⏰ Horário:</strong> <?= htmlspecialchars($horario_evento) ?></div>
                <div><strong>📍 Local:</strong> <?= htmlspecialchars($local_evento) ?></div>
                <div><strong>👤 Cliente:</strong> <?= htmlspecialchars($cliente_nome) ?></div>
            </div>
        </div>

        <div class="cards-grid">
            <?php if ($visivel_reuniao): ?>
            <section class="portal-card portal-card-theme-reuniao">
                <div>
                    <div class="card-icon">📝</div>
                    <div class="card-title">
                        <h3>Reunião Final</h3>
                        <div class="card-subtitle">Organize os detalhes finais do evento com praticidade.</div>
                        <div class="card-meta"><?= $editavel_reuniao ? 'Editável pelo cliente' : 'Somente visualização' ?></div>
                    </div>
                </div>
                <div class="card-actions">
                    <?php if (!empty($link_observacoes['token'])): ?>
                    <a class="btn btn-primary" href="index.php?page=eventos_cliente_dj&token=<?= urlencode((string)$link_observacoes['token']) ?>">
                        <?= $editavel_reuniao ? 'Abrir reunião final' : 'Visualizar reunião final' ?>
                    </a>
                    <?php else: ?>
                    <span class="btn btn-secondary btn-disabled">Sem link disponível</span>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($visivel_dj): ?>
            <section class="portal-card portal-card-theme-dj">
                <div>
                    <div class="card-icon">🎧</div>
                    <div class="card-title">
                        <h3>DJ e Protocolos</h3>
                        <div class="card-subtitle">Acesse os quadros do DJ e os materiais do protocolo.</div>
                        <div class="card-meta"><?= count($links_dj_portal) ?> quadro(s) liberado(s)</div>
                    </div>
                </div>
                <div class="card-actions">
                    <?php if (!empty($links_dj_portal)): ?>
                    <?php foreach ($links_dj_portal as $dj_link_portal): ?>
                    <?php
                        $dj_slot = max(1, (int)($dj_link_portal['slot_index'] ?? 1));
                        $dj_title = trim((string)($dj_link_portal['form_title'] ?? ''));
                        if ($dj_title === '') {
                            $dj_title = 'Formulário DJ';
                        }
                    ?>
                    <a class="btn btn-primary" href="index.php?page=eventos_cliente_dj&token=<?= urlencode((string)$dj_link_portal['token']) ?>">
                        <?= htmlspecialchars($dj_title) ?> (<?= $dj_slot ?>)
                    </a>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <span class="btn btn-secondary btn-disabled">Nenhum quadro disponível</span>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($visivel_convidados): ?>
            <section class="portal-card portal-card-theme-convidados">
                <div>
                    <div class="card-icon">👥</div>
                    <div class="card-title">
                        <h3>Convidados</h3>
                        <div class="card-subtitle">Visualize e acompanhe lista de convidados e check-ins.</div>
                        <div class="card-meta">
                            <?= (int)$convidados_resumo['total'] ?> total • <?= (int)$convidados_resumo['checkin'] ?> check-ins
                        </div>
                    </div>
                </div>
                <div class="card-actions">
                    <a class="btn btn-primary" href="index.php?page=eventos_cliente_convidados&token=<?= urlencode($token) ?>">
                        <?= $editavel_convidados ? 'Gerenciar convidados' : 'Visualizar convidados' ?>
                    </a>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($visivel_arquivos): ?>
            <section class="portal-card portal-card-theme-arquivos">
                <div>
                    <div class="card-icon">📁</div>
                    <div class="card-title">
                        <h3>Arquivos</h3>
                        <div class="card-subtitle">Entre na área de arquivos para enviar e acompanhar materiais.</div>
                        <div class="card-meta">
                            <?= (int)$arquivos_resumo['arquivos_cliente'] ?> enviados • <?= (int)$arquivos_resumo['campos_pendentes'] ?> pendente(s)
                        </div>
                    </div>
                </div>
                <div class="card-actions">
                    <?php if ($view_arquivos_ativo): ?>
                    <span class="btn btn-secondary btn-disabled">Área de arquivos aberta abaixo</span>
                    <?php else: ?>
                    <a class="btn btn-primary" href="index.php?page=eventos_cliente_portal&token=<?= urlencode($token) ?>&view=arquivos">Abrir área de arquivos</a>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <?php if (!$visivel_reuniao && !$visivel_dj && !$visivel_convidados && !$visivel_arquivos): ?>
            <section class="module-panel">
                <h3>Conteúdo indisponível</h3>
                <div class="module-subtitle">Ainda não há cards habilitados para visualização neste portal.</div>
            </section>
            <?php endif; ?>
        </div>

        <?php if ($view_arquivos_ativo): ?>
        <div class="module-header">
            <div>
                <strong>Área de Arquivos</strong>
            </div>
            <a class="back-link" href="index.php?page=eventos_cliente_portal&token=<?= urlencode($token) ?>">Voltar ao painel</a>
        </div>

        <section class="module-panel">
            <h3>Envios e documentos do evento</h3>
            <div class="module-subtitle">Painel completo de upload e acompanhamento de materiais.</div>

            <?php if ($editavel_arquivos): ?>
            <form method="POST" enctype="multipart/form-data" class="upload-form">
                <input type="hidden" name="action" value="upload_arquivo_cliente">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="view" value="arquivos">

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
            <div class="section-block">
                <div class="empty-text">Uploads desativados para este portal.</div>
            </div>
            <?php endif; ?>

            <div class="section-block">
                <h4>Campos solicitados</h4>
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
            </div>

            <div class="section-block">
                <h4>Arquivos disponíveis</h4>
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
            </div>
        </section>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
