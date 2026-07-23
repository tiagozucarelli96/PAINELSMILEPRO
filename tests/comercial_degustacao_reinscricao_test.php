<?php

declare(strict_types=1);

require_once __DIR__ . '/../public/comercial_degustacao_inscricao_helper.php';

function reinscricao_test_ok(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Falha: {$message}\n");
        exit(1);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(
    'CREATE TABLE comercial_inscricoes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        degustacao_id INTEGER NOT NULL,
        status TEXT,
        pagamento_status TEXT,
        fechou_contrato TEXT NOT NULL,
        email TEXT NOT NULL,
        me_event_id INTEGER,
        me_cliente_cpf TEXT
    )'
);

$colunas = [
    'id',
    'degustacao_id',
    'status',
    'pagamento_status',
    'fechou_contrato',
    'email',
    'me_event_id',
    'me_cliente_cpf',
];

$inserir = $pdo->prepare(
    'INSERT INTO comercial_inscricoes
        (degustacao_id, status, pagamento_status, fechou_contrato, email, me_event_id, me_cliente_cpf)
     VALUES
        (:degustacao_id, :status, :pagamento_status, :fechou_contrato, :email, :me_event_id, :me_cliente_cpf)'
);

$inserir->execute([
    ':degustacao_id' => 10,
    ':status' => 'confirmado',
    ':pagamento_status' => 'pago',
    ':fechou_contrato' => 'nao',
    ':email' => 'Cliente@Exemplo.com ',
    ':me_event_id' => 500,
    ':me_cliente_cpf' => '12345678901',
]);

$buscar = static function (
    int $degustacaoId,
    string $fechouContrato,
    string $email,
    int $meEventId = 0,
    string $cpf = ''
) use ($pdo, $colunas): ?array {
    return degustacao_inscricao_buscar_bloqueio_anterior(
        $pdo,
        $degustacaoId,
        $fechouContrato,
        $email,
        $meEventId,
        $cpf,
        $colunas
    );
};

reinscricao_test_ok(
    $buscar(20, 'sim', ' cliente@exemplo.com ') !== null,
    'contrato atual fechado deve bloquear e-mail inscrito em outra degustação'
);
reinscricao_test_ok(
    $buscar(10, 'sim', 'cliente@exemplo.com') === null,
    'inscrição na própria degustação não deve acionar a regra entre degustações'
);
reinscricao_test_ok(
    $buscar(20, 'nao', 'cliente@exemplo.com') === null,
    'duas inscrições sem contrato fechado devem permanecer permitidas'
);
reinscricao_test_ok(
    $buscar(20, 'sim', 'outro@exemplo.com', 500) !== null,
    'evento validado na ME deve identificar o mesmo contrato mesmo com outro e-mail'
);
reinscricao_test_ok(
    $buscar(20, 'sim', 'outro@exemplo.com', 0, '123.456.789-01') !== null,
    'CPF validado deve identificar o mesmo cliente mesmo com outro e-mail'
);

$pdo->exec("UPDATE comercial_inscricoes SET status = 'cancelado'");
reinscricao_test_ok(
    $buscar(20, 'sim', 'cliente@exemplo.com', 500, '12345678901') === null,
    'inscrição cancelada não deve impedir nova participação'
);

$pdo->exec("UPDATE comercial_inscricoes SET status = 'confirmado', pagamento_status = 'cancelado'");
reinscricao_test_ok(
    $buscar(20, 'sim', 'cliente@exemplo.com', 500, '12345678901') === null,
    'inscrição com pagamento cancelado não deve impedir nova participação'
);

$pdo->exec("UPDATE comercial_inscricoes SET pagamento_status = 'pago', fechou_contrato = 'sim'");
reinscricao_test_ok(
    $buscar(20, 'nao', 'cliente@exemplo.com') !== null,
    'contrato fechado no histórico deve impedir contorno marcando não no formulário'
);

$pdo->exec('DELETE FROM comercial_inscricoes');
reinscricao_test_ok(
    $buscar(20, 'sim', 'cliente@exemplo.com', 500, '12345678901') === null,
    'inscrição excluída não deve impedir nova participação'
);

echo "comercial_degustacao_reinscricao_test: OK\n";
