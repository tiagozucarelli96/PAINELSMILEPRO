<?php
declare(strict_types=1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/eventos_financeiro_helper.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; img-src 'self' https://pixgo.org data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");

$paymentId = trim((string)($_GET['payment'] ?? ''));
if ($paymentId === '' || !preg_match('/^[A-Za-z0-9_-]{12,120}$/', $paymentId) || !$pdo instanceof PDO) {
    http_response_code(404);
    exit('Cobrança não encontrada.');
}

eventos_financeiro_ensure_schema($pdo);
$payment = null;
$stmt = $pdo->prepare("
    SELECT descricao, valor, status, pixgo_payment_id, pixgo_qr_code, pixgo_qr_image_url,
           pixgo_expires_at::text AS pixgo_expires_at
    FROM eventos_financeiro_receitas
    WHERE pixgo_payment_id = :payment_id
    LIMIT 1
");
$stmt->execute([':payment_id' => $paymentId]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

if (!$payment) {
    try {
        $stmt = $pdo->prepare("
            SELECT descricao, valor, status, pixgo_payment_id, pixgo_qr_code, pixgo_qr_image_url,
                   pixgo_expires_at::text AS pixgo_expires_at
            FROM eventos_formatura_financeiro
            WHERE pixgo_payment_id = :payment_id
            LIMIT 1
        ");
        $stmt->execute([':payment_id' => $paymentId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $payment = null;
    }
}
if (!$payment) {
    http_response_code(404);
    exit('Cobrança não encontrada.');
}

$status = (string)($payment['status'] ?? 'pendente');
$expiresAt = trim((string)($payment['pixgo_expires_at'] ?? ''));
$expiredByTime = $expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) < time();
if ($status === 'pendente' && $expiredByTime) {
    $status = 'vencido';
}
$statusLabels = [
    'pendente' => 'Aguardando pagamento',
    'pago' => 'Pagamento confirmado',
    'vencido' => 'Cobrança expirada',
    'cancelado' => 'Cobrança cancelada/estornada',
];
$qrImageUrl = trim((string)($payment['pixgo_qr_image_url'] ?? ''));
$qrHost = strtolower((string)parse_url($qrImageUrl, PHP_URL_HOST));
if ($qrHost !== 'pixgo.org' && !str_ends_with($qrHost, '.pixgo.org')) {
    $qrImageUrl = '';
}
$qrCode = trim((string)($payment['pixgo_qr_code'] ?? ''));
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if ($status === 'pendente'): ?><meta http-equiv="refresh" content="10"><?php endif; ?>
    <title>Pagamento PIX — Smile Eventos</title>
    <style>
        :root { color-scheme: light; font-family: Inter, system-ui, -apple-system, sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: #f1f5f9; color: #172033; }
        main { width: min(100%, 520px); background: #fff; border-radius: 22px; padding: 28px; box-shadow: 0 18px 50px rgba(15, 23, 42, .12); }
        h1 { margin: 0 0 6px; font-size: 1.55rem; }
        .description { color: #64748b; margin: 0 0 18px; }
        .amount { font-size: 2rem; font-weight: 800; margin: 10px 0 18px; }
        .status { border-radius: 12px; padding: 12px 14px; font-weight: 700; background: #fff7ed; color: #9a3412; }
        .status.pago { background: #ecfdf5; color: #047857; }
        .status.vencido, .status.cancelado { background: #fef2f2; color: #b91c1c; }
        .qr { display: block; width: min(100%, 300px); margin: 24px auto; border-radius: 12px; }
        textarea { width: 100%; min-height: 96px; resize: none; padding: 12px; border: 1px solid #cbd5e1; border-radius: 10px; font: 12px/1.4 ui-monospace, monospace; }
        button { width: 100%; margin-top: 10px; border: 0; border-radius: 10px; padding: 13px 16px; background: #16a34a; color: #fff; font-weight: 800; cursor: pointer; }
        .expires, .notice { margin-top: 16px; color: #64748b; font-size: .88rem; line-height: 1.5; }
        .notice { padding: 12px; background: #f8fafc; border-radius: 10px; }
    </style>
</head>
<body>
<main>
    <h1>Pagamento PIX</h1>
    <p class="description"><?= h((string)$payment['descricao']) ?></p>
    <div class="amount">R$ <?= number_format((float)$payment['valor'], 2, ',', '.') ?></div>
    <div class="status <?= h($status) ?>"><?= h($statusLabels[$status] ?? ucfirst($status)) ?></div>

    <p class="notice">Ao efetuar o pagamento, o valor será processado via PIX pelos parceiros financeiros da PixGo e convertido em DEPIX, ativo digital pareado ao Real, para liquidação ao recebedor em carteira Liquid.</p>

    <?php if ($status === 'pendente'): ?>
        <?php if ($qrImageUrl !== ''): ?><img class="qr" src="<?= h($qrImageUrl) ?>" alt="QR Code PIX"><?php endif; ?>
        <?php if ($qrCode !== ''): ?>
            <label for="pixCode"><strong>PIX Copia e Cola</strong></label>
            <textarea id="pixCode" readonly><?= h($qrCode) ?></textarea>
            <button type="button" id="copyPix">Copiar código PIX</button>
        <?php endif; ?>
        <?php if ($expiresAt !== ''): ?><p class="expires">Válido até <?= h(date('d/m/Y H:i', strtotime($expiresAt))) ?>. Esta página atualiza automaticamente.</p><?php endif; ?>
    <?php endif; ?>

</main>
<script>
document.getElementById('copyPix')?.addEventListener('click', async () => {
    const field = document.getElementById('pixCode');
    try {
        await navigator.clipboard.writeText(field.value);
    } catch (_) {
        field.select();
        document.execCommand('copy');
    }
    document.getElementById('copyPix').textContent = 'Código copiado';
});
</script>
</body>
</html>
