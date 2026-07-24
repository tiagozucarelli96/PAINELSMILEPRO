<?php

declare(strict_types=1);

require_once __DIR__ . '/../public/comercial_degustacao_cancelamento_helper.php';

function cancelamento_test_ok(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(
    'CREATE TABLE comercial_inscricoes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        degustacao_id INTEGER NOT NULL,
        nome TEXT NOT NULL,
        email TEXT NOT NULL,
        status TEXT NOT NULL,
        pagamento_status TEXT NOT NULL,
        criado_em TEXT NOT NULL,
        atualizado_em TEXT
    )'
);

$inserir = $pdo->prepare(
    'INSERT INTO comercial_inscricoes
        (degustacao_id, nome, email, status, pagamento_status, criado_em)
     VALUES
        (1, :nome, :email, :status, :pagamento_status, :criado_em)'
);

$casos = [
    ['antiga aguardando', 'antiga@teste.com', 'confirmado', 'aguardando', '2026-07-21 11:59:59'],
    ['limite exato', 'limite@teste.com', 'lista_espera', 'aguardando', '2026-07-21 12:00:00'],
    ['recente', 'recente@teste.com', 'confirmado', 'aguardando', '2026-07-21 12:00:01'],
    ['paga', 'paga@teste.com', 'confirmado', 'pago', '2026-07-20 10:00:00'],
    ['gratuita', 'gratis@teste.com', 'confirmado', 'nao_aplicavel', '2026-07-20 10:00:00'],
    ['ja cancelada', 'cancelada@teste.com', 'cancelado', 'aguardando', '2026-07-20 10:00:00'],
];

foreach ($casos as [$nome, $email, $status, $pagamentoStatus, $criadoEm]) {
    $inserir->execute([
        ':nome' => $nome,
        ':email' => $email,
        ':status' => $status,
        ':pagamento_status' => $pagamentoStatus,
        ':criado_em' => $criadoEm,
    ]);
}

$previa = degustacao_cancelamento_processar($pdo, [
    'dry_run' => true,
    'ref_datetime' => '2026-07-23 12:00:00',
]);
cancelamento_test_ok($previa['candidatos'] === 2, 'prévia deve encontrar somente pendências com 48 horas');
cancelamento_test_ok($previa['cancelados'] === 0, 'prévia não deve cancelar registros');
cancelamento_test_ok(
    (int)$pdo->query("SELECT COUNT(*) FROM comercial_inscricoes WHERE status = 'cancelado'")->fetchColumn() === 1,
    'prévia não deve alterar o banco'
);

$resultado = degustacao_cancelamento_processar($pdo, [
    'ref_datetime' => '2026-07-23 12:00:00',
]);
cancelamento_test_ok($resultado['cancelados'] === 2, 'duas inscrições vencidas devem ser canceladas');

$linhas = $pdo->query(
    'SELECT email, status, pagamento_status FROM comercial_inscricoes ORDER BY id'
)->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

cancelamento_test_ok($linhas['antiga@teste.com']['status'] === 'cancelado', 'pendência antiga cancelada');
cancelamento_test_ok($linhas['limite@teste.com']['status'] === 'cancelado', 'prazo exato de 48 horas cancelado');
cancelamento_test_ok($linhas['antiga@teste.com']['pagamento_status'] === 'expirado', 'cobrança deve expirar');
cancelamento_test_ok($linhas['recente@teste.com']['status'] === 'confirmado', 'pendência recente preservada');
cancelamento_test_ok($linhas['paga@teste.com']['status'] === 'confirmado', 'inscrição paga preservada');
cancelamento_test_ok($linhas['gratis@teste.com']['status'] === 'confirmado', 'inscrição gratuita preservada');

echo "comercial_degustacao_cancelamento_test: OK\n";
