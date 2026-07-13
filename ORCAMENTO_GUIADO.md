# Orçamento guiado

## Publicação

1. Execute `sql/101_orcamento_guiado.sql` no PostgreSQL.
2. Abra **Administrativo > Orçamento Guiado**.
3. Cadastre os pacotes e envie o PDF no próprio formulário. O painel fará o upload ao Magalu e salvará a URL automaticamente. Também é possível informar uma URL já existente.
4. Configure `ORCAMENTO_WHATSAPP` somente com DDI, DDD e número.
5. Divulgue `/index.php?page=orcamento_guiado`.

Os PDFs são abertos em um visualizador na própria página. O servidor de arquivos precisa enviar `Content-Type: application/pdf`, aceitar visualização inline e permitir incorporação. No iPhone/Safari, o usuário ainda poderá usar os controles nativos do navegador.

## Cadastros

- **Unidades:** capacidade, eventos atendidos, descrição e vídeo.
- **Pacotes:** unidade, evento, perfil, alimentação, faixa, PDF, vídeo e prioridade.
- **Status:** pacotes e unidades inativos nunca são carregados no atendimento.
- **Leads:** ficam no painel com respostas completas em JSON e identificador anônimo da sessão.

Não publique arquivos do iCloud diretamente: envie-os ao Object Storage/CDN e cadastre a URL resultante. Ao trocar um catálogo, altere apenas a URL no painel.

## Testes

Execute `php tests/orcamento_recomendador_test.php`.
