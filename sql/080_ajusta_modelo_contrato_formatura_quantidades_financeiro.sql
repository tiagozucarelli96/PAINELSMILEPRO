-- Torna quantidades e resumo financeiro do contrato de formatura dinâmicos.
-- As substituições são restritas ao modelo CONTRATO FORMATURA e idempotentes.

UPDATE contrato_modelos
SET conteudo_html = REGEXP_REPLACE(
    conteudo_html,
    '<tr style="background: #f8fafc;">[[:space:]]*<td colspan="3"><strong>Total lan&ccedil;ado do formando</strong></td>[[:space:]]*<td style="text-align: right;"><strong>#VALOR_FORMANDO#</strong></td>[[:space:]]*</tr>[[:space:]]*<tr>[[:space:]]*<td>Condi&ccedil;&atilde;o / parcelas</td>',
    '<tr style="background: #f8fafc;">
<td colspan="3"><strong>Valor total da contrata&ccedil;&atilde;o individual</strong></td>
<td style="text-align: right;"><strong>#VALOR_FORMANDO#</strong></td>
</tr>
<tr>
<td colspan="3">Valor recebido (entrada e/ou pagamentos compensados)</td>
<td style="text-align: right;">#VALOR_RECEBIDO#</td>
</tr>
<tr>
<td colspan="3"><strong>Saldo a receber</strong></td>
<td style="text-align: right;"><strong>#VALOR_A_RECEBER#</strong></td>
</tr>
<tr>
<td>Condi&ccedil;&atilde;o, vencimentos e parcelas</td>'
)
WHERE UPPER(TRIM(nome)) = 'CONTRATO FORMATURA';

UPDATE contrato_modelos
SET conteudo_html = REPLACE(
    conteudo_html,
    'a) mesa com 8 lugares, sendo <strong>1 formando(a) + 7 convidados</strong>;',
    'a) <strong>#MESAS_FORMANDO# mesa(s)</strong>, cada uma com capacidade de <strong>#PESSOAS_POR_MESA# pessoas</strong>, nas quantidades individualmente indicadas no Quadro Resumo;'
)
WHERE UPPER(TRIM(nome)) = 'CONTRATO FORMATURA';

UPDATE contrato_modelos
SET conteudo_html = REPLACE(
    conteudo_html,
    '5.2. Cada formando ter&aacute; direito a <strong>1 mesa com 8 lugares</strong>, sendo composta por <strong>1 formando + 7 convidados</strong>.',
    '5.2. Cada formando ter&aacute; direito &agrave; quantidade de mesas e &agrave; capacidade por mesa indicadas no Quadro Resumo da Contrata&ccedil;&atilde;o Individual, que integra este contrato para todos os fins.'
)
WHERE UPPER(TRIM(nome)) = 'CONTRATO FORMATURA';

UPDATE contrato_modelos
SET conteudo_html = REPLACE(
    conteudo_html,
    '8.1. Cada mesa contratada contempla 8 lugares, sendo <strong>1 formando + 7 convidados</strong>.',
    '8.1. O CONTRATANTE adquire <strong>#MESAS_FORMANDO# mesa(s)</strong>, com capacidade de <strong>#PESSOAS_POR_MESA# pessoas por mesa</strong>, conforme discriminado no Quadro Resumo da Contrata&ccedil;&atilde;o Individual.'
)
WHERE UPPER(TRIM(nome)) = 'CONTRATO FORMATURA';

UPDATE contrato_modelos
SET conteudo_html = REPLACE(
    conteudo_html,
    '10.1. O valor da mesa contratada &eacute; de <strong>#VALOR_MESA#</strong>, referente ao formando e seus 7 convidados.',
    '10.1. O valor unit&aacute;rio de cada mesa &eacute; de <strong>#VALOR_MESA#</strong>. Foram contratadas <strong>#MESAS_FORMANDO# mesa(s)</strong>, no total de <strong>#VALOR_MESAS_FORMATURA#</strong>, sem preju&iacute;zo dos convidados adicionais discriminados no Quadro Resumo.'
)
WHERE UPPER(TRIM(nome)) = 'CONTRATO FORMATURA';

UPDATE contrato_modelos
SET conteudo_html = REPLACE(
    conteudo_html,
    '<p style="line-height: 100%; margin-top: 0.49cm; margin-bottom: 0.49cm;" align="justify">10.2. O pagamento poder&aacute; ser realizado conforme uma das modalidades abaixo:</p>',
    '<p style="line-height: 100%; margin-top: 0.49cm; margin-bottom: 0.49cm;" align="justify">10.2. O valor total da contrata&ccedil;&atilde;o individual, o valor de entrada e/ou pagamentos j&aacute; recebidos, o saldo a receber, os vencimentos e a situa&ccedil;&atilde;o de cada parcela constam no Quadro Resumo, prevalecendo os valores ali preenchidos para esta contrata&ccedil;&atilde;o.</p><p style="line-height: 100%; margin-top: 0.49cm; margin-bottom: 0.49cm;" align="justify">As modalidades comerciais dispon&iacute;veis s&atilde;o:</p>'
)
WHERE UPPER(TRIM(nome)) = 'CONTRATO FORMATURA';

UPDATE contrato_modelos
SET updated_at = NOW()
WHERE UPPER(TRIM(nome)) = 'CONTRATO FORMATURA';
