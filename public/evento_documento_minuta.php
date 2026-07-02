<?php
/**
 * evento_documento_minuta.php
 * Visualização pública da minuta de documentos gerais do evento.
 */

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/eventos_documentos_helper.php';

eventos_documentos_ensure_schema($pdo);

$token = trim((string)($_GET['token'] ?? ''));
$documento = null;
if ($token !== '') {
    $stmt = $pdo->prepare("
        SELECT d.*, e.nome_evento
        FROM eventos_documentos d
        JOIN logistica_eventos_espelho e ON e.id = d.evento_id
        WHERE d.minuta_token = :token
          AND d.deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([':token' => $token]);
    $documento = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Minuta do Documento</title>
    <style>
        body { margin:0; background:#f4f7fb; color:#1f2937; font-family:Arial,sans-serif; }
        .wrap { max-width:960px; margin:32px auto; padding:0 18px; }
        .header { background:#fff; border:1px solid #dbe5f0; border-radius:10px; padding:18px 20px; margin-bottom:18px; }
        .header h1 { margin:0; font-size:24px; color:#17357a; }
        .header p { margin:6px 0 0; color:#64748b; }
        .paper { background:#fff; border:1px solid #dbe5f0; border-radius:10px; padding:28px; box-shadow:0 16px 42px rgba(31,50,82,.08); }
        .empty { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; border-radius:10px; padding:18px; font-weight:700; }
    </style>
</head>
<body>
<main class="wrap">
    <?php if (!$documento): ?>
        <div class="empty">Documento não encontrado.</div>
    <?php else: ?>
        <section class="header">
            <h1><?= eventos_documentos_e((string)$documento['titulo']) ?></h1>
            <p><?= eventos_documentos_e((string)($documento['nome_evento'] ?? 'Evento')) ?></p>
        </section>
        <article class="paper">
            <?= (string)$documento['conteudo_html'] ?>
        </article>
    <?php endif; ?>
</main>
</body>
</html>
