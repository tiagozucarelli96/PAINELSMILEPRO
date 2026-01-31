<?php
/**
 * portal_decoracao.php
 * Painel do Portal Decoração (acesso externo)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';

// Verificar login
if (empty($_SESSION['portal_decoracao_logado']) || $_SESSION['portal_decoracao_logado'] !== true) {
    header('Location: index.php?page=portal_decoracao_login');
    exit;
}

$fornecedor_id = $_SESSION['portal_decoracao_fornecedor_id'];
$nome = $_SESSION['portal_decoracao_nome'];

// Logout
if (isset($_GET['logout'])) {
    if (!empty($_SESSION['portal_decoracao_token'])) {
        $stmt = $pdo->prepare("UPDATE eventos_fornecedores_sessoes SET ativo = FALSE WHERE token = :token");
        $stmt->execute([':token' => $_SESSION['portal_decoracao_token']]);
    }
    
    unset($_SESSION['portal_decoracao_logado']);
    unset($_SESSION['portal_decoracao_fornecedor_id']);
    unset($_SESSION['portal_decoracao_nome']);
    unset($_SESSION['portal_decoracao_token']);
    
    header('Location: index.php?page=portal_decoracao_login');
    exit;
}

// Buscar eventos vinculados
$eventos = [];
try {
    $stmt = $pdo->prepare("
        SELECT r.*, v.allowed_sections, v.can_download_attachments,
               (r.me_event_snapshot->>'data') as data_evento,
               (r.me_event_snapshot->>'nome') as nome_evento,
               (r.me_event_snapshot->>'local') as local_evento,
               (r.me_event_snapshot->>'hora_inicio') as hora_evento,
               (r.me_event_snapshot->'cliente'->>'nome') as cliente_nome
        FROM eventos_reunioes r
        JOIN eventos_fornecedores_vinculos v ON v.meeting_id = r.id
        WHERE v.supplier_id = :fid
        AND (r.me_event_snapshot->>'data')::date >= CURRENT_DATE - INTERVAL '7 days'
        ORDER BY (r.me_event_snapshot->>'data')::date ASC
    ");
    $stmt->execute([':fid' => $fornecedor_id]);
    $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Erro portal Decoracao: " . $e->getMessage());
}

// Ver detalhes
$evento_selecionado = null;
$secao_decoracao = null;
$anexos = [];

if (!empty($_GET['evento'])) {
    $evento_id = (int)$_GET['evento'];
    
    foreach ($eventos as $ev) {
        if ((int)$ev['id'] === $evento_id) {
            $evento_selecionado = $ev;
            $secao_decoracao = eventos_reuniao_get_secao($pdo, $evento_id, 'decoracao');
            $anexos = eventos_reuniao_get_anexos($pdo, $evento_id, 'decoracao');
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal Decoração - Minha Agenda</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8fafc;
            color: #1e293b;
            min-height: 100vh;
        }
        .header {
            background: linear-gradient(135deg, #059669 0%, #10b981 100%);
            color: white;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 {
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .header-user {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .header-user span {
            font-size: 0.875rem;
            opacity: 0.9;
        }
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }
        .btn-light {
            background: rgba(255,255,255,0.2);
            color: white;
        }
        .btn-primary {
            background: #059669;
            color: white;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }
        .section-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 1rem;
        }
        .events-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
        }
        .event-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #059669;
            transition: all 0.2s;
        }
        .event-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        .event-card.past {
            opacity: 0.6;
            border-left-color: #94a3b8;
        }
        .event-name {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }
        .event-meta {
            font-size: 0.875rem;
            color: #64748b;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        .event-date {
            display: inline-block;
            background: #d1fae5;
            color: #065f46;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-top: 0.75rem;
        }
        .event-actions {
            margin-top: 1rem;
        }
        .detail-panel {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }
        .detail-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .content-box {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 1.5rem;
            min-height: 200px;
        }
        .content-box h3 {
            font-size: 0.95rem;
            color: #374151;
            margin-bottom: 1rem;
        }
        .anexos-list {
            margin-top: 1.5rem;
        }
        .anexo-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            background: #f1f5f9;
            border-radius: 6px;
            margin-bottom: 0.5rem;
        }
        .anexo-item a {
            color: #059669;
            text-decoration: none;
            font-size: 0.875rem;
        }
        .anexo-item a:hover {
            text-decoration: underline;
        }
        .images-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        .image-item {
            border-radius: 8px;
            overflow: hidden;
            aspect-ratio: 1;
        }
        .image-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #64748b;
        }
        @media (max-width: 768px) {
            .container { padding: 1rem; }
            .header { flex-direction: column; gap: 1rem; text-align: center; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🎨 Portal Decoração</h1>
        <div class="header-user">
            <span>Olá, <?= htmlspecialchars($nome) ?></span>
            <a href="?page=portal_decoracao&logout=1" class="btn btn-light">Sair</a>
        </div>
    </div>
    
    <div class="container">
        <?php if ($evento_selecionado): ?>
        <a href="?page=portal_decoracao" class="btn btn-primary" style="margin-bottom: 1rem;">← Voltar à lista</a>
        
        <div class="detail-panel">
            <div class="detail-header">
                <div>
                    <h2><?= htmlspecialchars($evento_selecionado['nome_evento']) ?></h2>
                    <p style="color: #64748b; margin-top: 0.25rem;">
                        📅 <?= date('d/m/Y', strtotime($evento_selecionado['data_evento'])) ?> às <?= htmlspecialchars($evento_selecionado['hora_evento'] ?: '-') ?>
                        • 📍 <?= htmlspecialchars($evento_selecionado['local_evento'] ?: '-') ?>
                        • 👤 <?= htmlspecialchars($evento_selecionado['cliente_nome'] ?: '-') ?>
                    </p>
                </div>
            </div>
            
            <div class="content-box">
                <h3>🎨 Decoração</h3>
                <?php if ($secao_decoracao && $secao_decoracao['content_html']): ?>
                <div><?= $secao_decoracao['content_html'] ?></div>
                <?php else: ?>
                <p style="color: #64748b; font-style: italic;">Nenhuma informação cadastrada ainda.</p>
                <?php endif; ?>
            </div>
            
            <?php 
            $imagens = array_filter($anexos, fn($a) => $a['file_kind'] === 'imagem');
            $outros = array_filter($anexos, fn($a) => $a['file_kind'] !== 'imagem');
            ?>
            
            <?php if (!empty($imagens)): ?>
            <div class="anexos-list">
                <h3 style="font-size: 0.95rem; color: #374151; margin-bottom: 0.75rem;">🖼️ Imagens</h3>
                <div class="images-grid">
                    <?php foreach ($imagens as $img): ?>
                    <a href="<?= htmlspecialchars($img['public_url'] ?: '#') ?>" target="_blank" class="image-item">
                        <img src="<?= htmlspecialchars($img['public_url'] ?: '') ?>" alt="<?= htmlspecialchars($img['original_name']) ?>">
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($outros)): ?>
            <div class="anexos-list">
                <h3 style="font-size: 0.95rem; color: #374151; margin-bottom: 0.75rem;">📎 Outros Anexos</h3>
                <?php foreach ($outros as $a): ?>
                <div class="anexo-item">
                    <span>📄</span>
                    <a href="<?= htmlspecialchars($a['public_url'] ?: '#') ?>" target="_blank">
                        <?= htmlspecialchars($a['original_name']) ?>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <?php else: ?>
        <h2 class="section-title">📅 Meus Eventos</h2>
        
        <?php if (empty($eventos)): ?>
        <div class="empty-state">
            <p style="font-size: 2rem;">🎨</p>
            <p><strong>Nenhum evento atribuído</strong></p>
            <p>Aguarde a equipe vincular você a novos eventos.</p>
        </div>
        <?php else: ?>
        <div class="events-grid">
            <?php foreach ($eventos as $ev): 
                $is_past = strtotime($ev['data_evento']) < strtotime('today');
            ?>
            <div class="event-card <?= $is_past ? 'past' : '' ?>">
                <div class="event-name"><?= htmlspecialchars($ev['nome_evento']) ?></div>
                <div class="event-meta">
                    <span>📍 <?= htmlspecialchars($ev['local_evento'] ?: 'Local não definido') ?></span>
                    <span>👤 <?= htmlspecialchars($ev['cliente_nome'] ?: 'Cliente') ?></span>
                </div>
                <div class="event-date">
                    📅 <?= date('d/m/Y', strtotime($ev['data_evento'])) ?> às <?= htmlspecialchars($ev['hora_evento'] ?: '-') ?>
                </div>
                <div class="event-actions">
                    <a href="?page=portal_decoracao&evento=<?= $ev['id'] ?>" class="btn btn-primary">Ver Detalhes</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
