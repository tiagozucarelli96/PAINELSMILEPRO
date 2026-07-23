<?php

declare(strict_types=1);

require_once __DIR__ . '/../public/eventos_reuniao_helper.php';

$cases = [
    ['vazio', [], false, false],
    ['rascunho_marcado', ['draft_saved_at' => '2026-07-16 18:25:21'], false, true],
    ['rascunho_com_conteudo', ['draft_content_html_snapshot' => '<p>Resposta</p>'], false, true],
    ['envio_final', ['submitted_at' => '2026-07-20 10:00:00'], false, true],
    ['envio_com_conteudo', ['content_html_snapshot' => '<p>Resposta final</p>'], false, true],
    ['somente_anexo', [], true, true],
];

foreach ($cases as [$name, $link, $temAnexo, $expected]) {
    $actual = eventos_cliente_portal_link_tem_dados_cliente($link, $temAnexo);
    if ($actual !== $expected) {
        fwrite(STDERR, sprintf(
            "Falha em %s: esperado %s, recebido %s\n",
            $name,
            var_export($expected, true),
            var_export($actual, true)
        ));
        exit(1);
    }
}

$source = file_get_contents(__DIR__ . '/../public/eventos_reuniao_helper.php');
if ($source === false
    || str_contains($source, 'content_html_snapshot = NULL,' . "\n" . '                    draft_content_html_snapshot = NULL')
) {
    fwrite(STDERR, "A reaplicação do modelo ainda contém limpeza destrutiva dos snapshots.\n");
    exit(1);
}

echo "eventos_portal_preservacao_rascunho_test: OK\n";
