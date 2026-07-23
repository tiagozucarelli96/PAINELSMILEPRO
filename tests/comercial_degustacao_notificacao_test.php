<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/comercial_degustacao_notificacao_helper.php';

function degustacao_notificacao_test_ok(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
}

$message = degustacao_notificacao_montar_mensagem([
    'hora_inicio' => '19:30:00',
    'local' => 'Espaço Garden: R. Padre Eugênio, 511 - Jardim Jacinto, Jacareí - SP, 12322-690',
]);

degustacao_notificacao_test_ok(str_contains($message, 'Hoje é o grande dia!'), 'abertura da mensagem');
degustacao_notificacao_test_ok(str_contains($message, 'Horário: 19h30'), 'formatação do horário');
degustacao_notificacao_test_ok(str_contains($message, 'Local: Lisbon Garden'), 'nome público do local');
degustacao_notificacao_test_ok(
    str_contains($message, 'Endereço: R. Padre Eugênio, 511, Jardim Jacinto, Jacareí/SP — 12322-690'),
    'endereço da degustação'
);
degustacao_notificacao_test_ok(substr_count($message, '• ') === 2, 'duas orientações em lista');

$antes = degustacao_notificacao_data_hora('2026-07-23 08:59:00');
$depois = degustacao_notificacao_data_hora('2026-07-23 09:00:00');
degustacao_notificacao_test_ok(!degustacao_notificacao_horario_liberado($antes), 'bloqueio antes das 9h');
degustacao_notificacao_test_ok(degustacao_notificacao_horario_liberado($depois), 'liberação às 9h');
degustacao_notificacao_test_ok($depois->getTimezone()->getName() === DEGUSTACAO_NOTIFICACAO_TIMEZONE, 'timezone');

echo "comercial_degustacao_notificacao_test: OK\n";
