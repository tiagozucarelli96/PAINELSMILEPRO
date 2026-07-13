<?php
/**
 * contratos_modelos_helper.php
 * Estrutura base para modelos de contratos e tags de preenchimento.
 */

if (!function_exists('contratos_modelos_runtime_schema_enabled')) {
    function contratos_modelos_runtime_schema_enabled(): bool
    {
        return !function_exists('painel_runtime_schema_setup_enabled') || painel_runtime_schema_setup_enabled();
    }
}

if (!function_exists('contratos_modelos_default_tag_options')) {
    function contratos_modelos_default_tag_options(): array
    {
        return [
            'cliente.nome' => ['tag' => '#NOME#', 'nome' => 'Nome do cliente', 'origem_tipo' => 'cliente', 'origem_campo' => 'nome_completo'],
            'cliente.cpf' => ['tag' => '#CPF#', 'nome' => 'CPF do cliente', 'origem_tipo' => 'cliente', 'origem_campo' => 'documento_numero'],
            'cliente.rg' => ['tag' => '#RG#', 'nome' => 'RG do cliente', 'origem_tipo' => 'cliente', 'origem_campo' => 'rg'],
            'cliente.email' => ['tag' => '#EMAIL#', 'nome' => 'E-mail do cliente', 'origem_tipo' => 'cliente', 'origem_campo' => 'email'],
            'cliente.telefone' => ['tag' => '#TELEFONE#', 'nome' => 'Telefone/WhatsApp do cliente', 'origem_tipo' => 'cliente', 'origem_campo' => 'telefone_whatsapp'],
            'cliente.cep' => ['tag' => '#CEP#', 'nome' => 'CEP do cliente', 'origem_tipo' => 'cliente', 'origem_campo' => 'cep'],
            'cliente.endereco' => ['tag' => '#ENDERECO#', 'nome' => 'Endereço do cliente', 'origem_tipo' => 'cliente', 'origem_campo' => 'endereco_logradouro'],
            'cliente.numero' => ['tag' => '#NUMERO#', 'nome' => 'Número do endereço', 'origem_tipo' => 'cliente', 'origem_campo' => 'endereco_numero'],
            'cliente.complemento' => ['tag' => '#COMPLEMENTO#', 'nome' => 'Complemento do endereço', 'origem_tipo' => 'cliente', 'origem_campo' => 'endereco_complemento'],
            'cliente.bairro' => ['tag' => '#BAIRRO#', 'nome' => 'Bairro do cliente', 'origem_tipo' => 'cliente', 'origem_campo' => 'endereco_bairro'],
            'cliente.cidade' => ['tag' => '#CIDADE#', 'nome' => 'Cidade do cliente', 'origem_tipo' => 'cliente', 'origem_campo' => 'endereco_cidade'],
            'cliente.estado' => ['tag' => '#ESTADO#', 'nome' => 'Estado do cliente', 'origem_tipo' => 'cliente', 'origem_campo' => 'endereco_estado'],
            'evento.nome' => ['tag' => '#NOME_EVENTO#', 'nome' => 'Nome do evento', 'origem_tipo' => 'evento', 'origem_campo' => 'nome_evento'],
            'evento.id' => ['tag' => '#ID_EVENTO#', 'nome' => 'ID do evento', 'origem_tipo' => 'evento', 'origem_campo' => 'id'],
            'evento.data' => ['tag' => '#DATA_EVENTO#', 'nome' => 'Data do evento', 'origem_tipo' => 'evento', 'origem_campo' => 'data_evento'],
            'evento.horario' => ['tag' => '#HORARIO_EVENTO#', 'nome' => 'Horário do evento', 'origem_tipo' => 'evento', 'origem_campo' => 'hora_inicio'],
            'evento.local' => ['tag' => '#LOCAL_EVENTO#', 'nome' => 'Local do evento', 'origem_tipo' => 'evento', 'origem_campo' => 'local_evento'],
            'evento.unidade' => ['tag' => '#UNIDADE#', 'nome' => 'Unidade do evento', 'origem_tipo' => 'evento', 'origem_campo' => 'space_visivel'],
            'evento.convidados' => ['tag' => '#CONVIDADOS#', 'nome' => 'Quantidade de convidados', 'origem_tipo' => 'evento', 'origem_campo' => 'convidados'],
            'evento.pacote' => ['tag' => '#PACOTE#', 'nome' => 'Pacote contratado', 'origem_tipo' => 'evento', 'origem_campo' => 'pacote'],
            'evento.itens' => ['tag' => '#ITENS_CONTRATADOS#', 'nome' => 'Itens contratados', 'origem_tipo' => 'evento', 'origem_campo' => 'itens_contratados'],
            'financeiro.total' => ['tag' => '#VALOR_TOTAL#', 'nome' => 'Valor total contratado', 'origem_tipo' => 'financeiro', 'origem_campo' => 'valor_total'],
            'financeiro.recebido' => ['tag' => '#VALOR_RECEBIDO#', 'nome' => 'Valor recebido', 'origem_tipo' => 'financeiro', 'origem_campo' => 'valor_recebido'],
            'financeiro.a_receber' => ['tag' => '#VALOR_A_RECEBER#', 'nome' => 'Valor a receber', 'origem_tipo' => 'financeiro', 'origem_campo' => 'valor_a_receber'],
            'formatura.nome_formando' => ['tag' => '#NOME_FORMANDO#', 'nome' => 'Nome do formando', 'origem_tipo' => 'formatura', 'origem_campo' => 'nome_formando'],
            'formatura.convidados' => ['tag' => '#CONVIDADOS_FORMANDO#', 'nome' => 'Convidados do formando', 'origem_tipo' => 'formatura', 'origem_campo' => 'convidados'],
            'formatura.criancas_meia' => ['tag' => '#CRIANCAS_MEIA_FORMANDO#', 'nome' => 'Crianças 5 a 8 anos/meia do formando', 'origem_tipo' => 'formatura', 'origem_campo' => 'criancas_meia'],
            'formatura.mesas' => ['tag' => '#MESAS_FORMANDO#', 'nome' => 'Mesas do formando', 'origem_tipo' => 'formatura', 'origem_campo' => 'mesas'],
            'formatura.valor_mesa' => ['tag' => '#VALOR_MESA#', 'nome' => 'Valor unitário da mesa', 'origem_tipo' => 'formatura', 'origem_campo' => 'valor_mesa'],
            'formatura.pessoas_por_mesa' => ['tag' => '#PESSOAS_POR_MESA#', 'nome' => 'Pessoas por mesa', 'origem_tipo' => 'formatura', 'origem_campo' => 'pessoas_por_mesa'],
            'formatura.convidados_adicionais' => ['tag' => '#CONVIDADOS_ADICIONAIS#', 'nome' => 'Convidados adicionais do formando', 'origem_tipo' => 'formatura', 'origem_campo' => 'convidados_adicionais'],
            'formatura.valor_convidado_adicional' => ['tag' => '#VALOR_CONVIDADO_ADICIONAL#', 'nome' => 'Valor por convidado adicional', 'origem_tipo' => 'formatura', 'origem_campo' => 'valor_convidado_adicional'],
            'formatura.valor_crianca_meia' => ['tag' => '#VALOR_CRIANCA_MEIA#', 'nome' => 'Valor criança 5 a 8 anos/meia', 'origem_tipo' => 'formatura', 'origem_campo' => 'valor_crianca_meia'],
            'formatura.valor_mesas' => ['tag' => '#VALOR_MESAS_FORMATURA#', 'nome' => 'Total das mesas do formando', 'origem_tipo' => 'formatura', 'origem_campo' => 'valor_base_mesas'],
            'formatura.valor_adicionais' => ['tag' => '#VALOR_ADICIONAIS_FORMATURA#', 'nome' => 'Total dos adicionais do formando', 'origem_tipo' => 'formatura', 'origem_campo' => 'valor_adicionais'],
            'formatura.valor_criancas_meia' => ['tag' => '#VALOR_CRIANCAS_MEIA_FORMATURA#', 'nome' => 'Total crianças 5 a 8 anos/meia', 'origem_tipo' => 'formatura', 'origem_campo' => 'valor_criancas_meia'],
            'formatura.responsavel' => ['tag' => '#RESPONSAVEL_FORMANDO#', 'nome' => 'Responsável do formando', 'origem_tipo' => 'formatura', 'origem_campo' => 'cliente_nome'],
            'formatura.valor' => ['tag' => '#VALOR_FORMANDO#', 'nome' => 'Valor lançado do formando', 'origem_tipo' => 'formatura', 'origem_campo' => 'valor_formando'],
            'formatura.parcelas' => ['tag' => '#PARCELAS_FORMANDO#', 'nome' => 'Parcelas do formando', 'origem_tipo' => 'formatura', 'origem_campo' => 'parcelas_formando'],
            'sistema.data_hoje' => ['tag' => '#DATA_HOJE#', 'nome' => 'Data de hoje', 'origem_tipo' => 'sistema', 'origem_campo' => 'data_hoje'],
        ];
    }
}

if (!function_exists('contratos_modelos_ensure_schema')) {
    function contratos_modelos_ensure_schema(PDO $pdo): void
    {
        if (!contratos_modelos_runtime_schema_enabled()) {
            return;
        }

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS contrato_tags (
                    id BIGSERIAL PRIMARY KEY,
                    tag_codigo VARCHAR(80) NOT NULL UNIQUE,
                    nome VARCHAR(160) NOT NULL,
                    origem_tipo VARCHAR(40) NOT NULL,
                    origem_campo VARCHAR(120) NOT NULL,
                    descricao TEXT NULL,
                    ativo BOOLEAN NOT NULL DEFAULT TRUE,
                    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
                )
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS contrato_modelos (
                    id BIGSERIAL PRIMARY KEY,
                    nome VARCHAR(180) NOT NULL,
                    conteudo_html TEXT NOT NULL DEFAULT '',
                    ativo BOOLEAN NOT NULL DEFAULT TRUE,
                    created_by INTEGER NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
                )
            ");

            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_contrato_modelos_nome ON contrato_modelos(LOWER(nome))");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_contrato_tags_origem ON contrato_tags(origem_tipo, origem_campo)");

            $stmt = $pdo->prepare("
                INSERT INTO contrato_tags (tag_codigo, nome, origem_tipo, origem_campo, descricao, ativo, created_at, updated_at)
                VALUES (:tag_codigo, :nome, :origem_tipo, :origem_campo, :descricao, TRUE, NOW(), NOW())
                ON CONFLICT (tag_codigo) DO NOTHING
            ");
            foreach (contratos_modelos_default_tag_options() as $option) {
                $stmt->execute([
                    ':tag_codigo' => $option['tag'],
                    ':nome' => $option['nome'],
                    ':origem_tipo' => $option['origem_tipo'],
                    ':origem_campo' => $option['origem_campo'],
                    ':descricao' => 'Tag padrão para modelos de contrato.',
                ]);
            }
        } catch (Throwable $e) {
            error_log('contratos_modelos_ensure_schema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('contratos_modelos_normalize_tag')) {
    function contratos_modelos_normalize_tag(string $tag): string
    {
        $tag = strtoupper(trim($tag));
        $tag = preg_replace('/[^A-Z0-9_#]/', '_', $tag) ?: '';
        $tag = trim($tag, '#');
        return $tag !== '' ? '#' . $tag . '#' : '';
    }
}
