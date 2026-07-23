<?php

declare(strict_types=1);

require_once __DIR__ . '/../public/eventos_checklist_planejamento_helper.php';

$cases = [
    ['sem_data', 0, '2026-07-24', '2026-01-01', null, null],
    ['dia_evento', 0, '2026-07-24', '2026-01-01', null, '2026-07-24'],
    ['antes_evento', 30, '2026-07-24', '2026-01-01', null, '2026-06-24'],
    ['depois_evento', 3, '2026-07-24', '2026-01-01', null, '2026-07-27'],
    ['depois_cadastro', 5, '2026-07-24', '2026-01-01', null, '2026-01-06'],
    ['depois_insercao', 4, '2026-07-24', '2026-01-01', '2026-02-10', '2026-02-14'],
];

foreach ($cases as [$rule, $days, $eventDate, $createdDate, $insertedDate, $expected]) {
    $actual = eventos_checklist_planejamento_calcular_vencimento(
        $rule,
        $days,
        $eventDate,
        $createdDate,
        $insertedDate
    );
    if ($actual !== $expected) {
        fwrite(STDERR, sprintf(
            "Falha em %s: esperado %s, recebido %s\n",
            $rule,
            var_export($expected, true),
            var_export($actual, true)
        ));
        exit(1);
    }
}

echo "eventos_checklist_planejamento_test: OK\n";
