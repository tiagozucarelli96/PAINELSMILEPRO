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

$token = trim((string)($_GET['token'] ?? ''));
$error = '';

$portal = null;
$reuniao = null;
$snapshot = [];
$secao_decoracao = null;
$secao_observacoes = null;
$secao_dj = null;
$anexos_dj = [];
$link_dj = null;
$link_observacoes = null;

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

            $secao_decoracao = eventos_reuniao_get_secao($pdo, (int)$reuniao['id'], 'decoracao');
            $secao_observacoes = eventos_reuniao_get_secao($pdo, (int)$reuniao['id'], 'observacoes_gerais');
            $secao_dj = eventos_reuniao_get_secao($pdo, (int)$reuniao['id'], 'dj_protocolo');
            $anexos_dj = eventos_reuniao_get_anexos($pdo, (int)$reuniao['id'], 'dj_protocolo');

            $links_dj = eventos_reuniao_listar_links_cliente($pdo, (int)$reuniao['id'], 'cliente_dj');
            $links_observacoes = eventos_reuniao_listar_links_cliente($pdo, (int)$reuniao['id'], 'cliente_observacoes');
            $link_dj = !empty($links_dj) ? $links_dj[0] : null;
            $link_observacoes = !empty($links_observacoes) ? $links_observacoes[0] : null;
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

$decoracao_content = trim((string)($secao_decoracao['content_html'] ?? ''));
$observacoes_content = trim((string)($secao_observacoes['content_html'] ?? ''));
$dj_content = trim((string)($secao_dj['content_html'] ?? ''));
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
            max-width: 960px;
            margin: 0 auto;
            padding: 1.2rem;
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

        .event-box {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 1rem;
            margin-bottom: 1rem;
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
            gap: 1rem;
        }

        .card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1rem;
        }

        .card h3 {
            color: #1f2937;
            font-size: 1.1rem;
            margin-bottom: 0.35rem;
        }

        .card-subtitle {
            color: #64748b;
            font-size: 0.86rem;
        }

        .card-actions {
            margin-top: 0.75rem;
            display: flex;
            gap: 0.55rem;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            padding: 0.58rem 0.95rem;
            border-radius: 8px;
            border: none;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
        }

        .btn-primary {
            background: #1e3a8a;
            color: #fff;
        }

        .btn-secondary {
            background: #f1f5f9;
            border: 1px solid #dbe3ef;
            color: #334155;
        }

        .section-block {
            margin-top: 0.95rem;
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

        .section-content {
            color: #334155;
            font-size: 0.9rem;
            line-height: 1.55;
        }

        .section-content p:first-child {
            margin-top: 0;
        }

        .section-content p:last-child {
            margin-bottom: 0;
        }

        .empty-text {
            color: #64748b;
            font-style: italic;
            font-size: 0.88rem;
        }

        .attachments-list {
            margin-top: 0.8rem;
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
            <section class="card">
                <h3>📝 Reunião Final</h3>
                <div class="card-subtitle">Decoração e observações gerais.</div>
                <div class="card-actions">
                    <?php if ($editavel_reuniao && !empty($link_observacoes['token'])): ?>
                    <a class="btn btn-primary" href="index.php?page=eventos_cliente_dj&token=<?= urlencode((string)$link_observacoes['token']) ?>">Editar Observações</a>
                    <?php else: ?>
                    <span class="btn btn-secondary" style="cursor:default;">Somente visualização</span>
                    <?php endif; ?>
                </div>

                <div class="section-block">
                    <h4>🎨 Decoração</h4>
                    <div class="section-content">
                        <?= $decoracao_content !== '' ? $decoracao_content : '<div class="empty-text">Sem conteúdo disponível.</div>' ?>
                    </div>
                </div>

                <div class="section-block">
                    <h4>📝 Observações Gerais</h4>
                    <div class="section-content">
                        <?= $observacoes_content !== '' ? $observacoes_content : '<div class="empty-text">Sem conteúdo disponível.</div>' ?>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($visivel_dj): ?>
            <section class="card">
                <h3>🎧 DJ / Protocolos</h3>
                <div class="card-subtitle">Formulários e materiais de apoio do DJ.</div>
                <div class="card-actions">
                    <?php if ($editavel_dj && !empty($link_dj['token'])): ?>
                    <a class="btn btn-primary" href="index.php?page=eventos_cliente_dj&token=<?= urlencode((string)$link_dj['token']) ?>">Abrir Formulário DJ</a>
                    <?php else: ?>
                    <span class="btn btn-secondary" style="cursor:default;">Somente visualização</span>
                    <?php endif; ?>
                </div>

                <div class="section-block">
                    <h4>Conteúdo atual</h4>
                    <div class="section-content">
                        <?= $dj_content !== '' ? $dj_content : '<div class="empty-text">Sem conteúdo disponível.</div>' ?>
                    </div>
                </div>

                <?php if (!empty($anexos_dj)): ?>
                <div class="section-block attachments-list">
                    <h4>📎 Anexos</h4>
                    <ul>
                        <?php foreach ($anexos_dj as $anexo): ?>
                        <li>
                            <?php if (!empty($anexo['public_url'])): ?>
                            <a href="<?= htmlspecialchars((string)$anexo['public_url']) ?>" target="_blank" rel="noopener noreferrer">
                                <?= htmlspecialchars((string)($anexo['original_name'] ?? 'arquivo')) ?>
                            </a>
                            <?php else: ?>
                            <span><?= htmlspecialchars((string)($anexo['original_name'] ?? 'arquivo')) ?></span>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if (!$visivel_reuniao && !$visivel_dj): ?>
            <section class="card">
                <h3>Conteúdo indisponível</h3>
                <div class="card-subtitle">Ainda não há cards habilitados para visualização neste portal.</div>
            </section>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
