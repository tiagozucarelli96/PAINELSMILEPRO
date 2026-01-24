# Ajustes Implementados no Módulo de Vendas

## Resumo dos Ajustes

Este documento descreve os ajustes implementados no módulo de Vendas conforme especificações fornecidas.

---

## ✅ Ajuste 1 — Local do evento NÃO é texto livre

**Status:** ✅ Implementado

**Mudanças:**
- Criada função `vendas_buscar_locais_mapeados()` em `vendas_helper.php` que busca apenas locais com status `MAPEADO` da tabela `logistica_me_locais`
- Formulários públicos agora usam dropdown com apenas locais mapeados
- Validação no backend bloqueia envio se local não estiver mapeado
- Mensagem clara: "Local não mapeado. Ajuste em Logística > Conexão."

**Arquivos modificados:**
- `public/vendas_helper.php` (novo)
- `public/vendas_form_base.php`
- `public/vendas_form_casamento.php`

---

## ✅ Ajuste 2 — Texto livre somente para PACOTE/PLANO

**Status:** ✅ Confirmado

**Mudanças:**
- Campo `pacote_contratado` (ou `pacote_plano` no formulário público) é o único campo texto livre relacionado a produto
- Este campo é salvo no Painel mas **nunca enviado para a ME**
- Confirmado que nenhum outro campo texto livre está sendo usado para Local do evento

**Arquivos verificados:**
- `public/vendas_pre_contratos.php`
- `public/vendas_me_helper.php`

---

## ⚠️ Ajuste 3 — Separar "Lançamento Presencial" de "Administração Vendas"

**Status:** ⚠️ Parcialmente implementado

**O que foi feito:**
- A página `vendas_pre_contratos.php` continua como listagem geral
- A ação "Aprovar e Criar na ME" está disponível apenas para admin (usando `vendas_is_admin()`)

**O que falta:**
- Criar página específica `vendas_lancamento_presencial.php` para lançamento rápido
- Criar página específica `vendas_administracao.php` para administração (Tiago)
- Adicionar campo `origem` na tabela (já criado na migration SQL)

**Próximos passos:**
- Criar `vendas_lancamento_presencial.php` com todos os campos do formulário público + campos internos
- Criar `vendas_administracao.php` com foco em aprovação e criação na ME
- Atualizar menu Comercial > Vendas para incluir essas novas páginas

---

## ✅ Ajuste 4 — Aprovação somente para Tiago/admin

**Status:** ✅ Implementado

**Mudanças:**
- Criada função centralizada `vendas_is_admin()` em `vendas_helper.php`
- Função verifica:
  - `perm_administrativo` na sessão
  - ID do usuário === 1
  - Login === 'admin'
  - Flag `is_admin` na sessão
- Todas as verificações de admin agora usam `vendas_is_admin()`

**Arquivos modificados:**
- `public/vendas_helper.php` (novo)
- `public/vendas_pre_contratos.php`

---

## ✅ Ajuste 5 — Kanban: colunas editáveis, mas "Criado na ME" é obrigatória

**Status:** ✅ Implementado

**Mudanças:**
- Adicionada verificação no início de `vendas_kanban.php` que garante que a coluna "Criado na ME" sempre existe
- Se não existir, cria automaticamente na posição 0
- Ajusta posições das outras colunas automaticamente

**Arquivos modificados:**
- `public/vendas_kanban.php`

---

## ✅ Ajuste 6 — Upload de arquivos via Magalu

**Status:** ✅ Confirmado

**Mudanças:**
- Sistema já usa `MagaluUpload` class para uploads
- Validação de tipo e tamanho implementada
- Nome único gerado automaticamente
- Referência salva no banco (`vendas_anexos`)

**Arquivos verificados:**
- `public/vendas_pre_contratos.php` (usa `MagaluUpload`)
- `public/upload_magalu.php` (classe existente)

---

## ✅ Ajuste 7 — Uso obrigatório do mapeamento Logística > Conexão

**Status:** ✅ Implementado

**Mudanças:**
- Função `vendas_obter_me_local_id()` busca `me_local_id` do mapeamento
- `vendas_me_criar_evento()` agora usa `idlocalevento` do mapeamento em vez de texto livre
- Validação antes de criar evento: se local não estiver mapeado, bloqueia aprovação

**Arquivos modificados:**
- `public/vendas_helper.php` (novo)
- `public/vendas_me_helper.php`

---

## ✅ Ajuste 8 — Campos exatos do Link Público Casamento

**Status:** ✅ Implementado

**Mudanças:**
- Criado `vendas_form_casamento.php` completo com todos os campos especificados:
  - **Cliente:** nome, email, telefone, CPF, RG, endereço completo (CEP, endereço, número, complemento, bairro, cidade, estado, país), Instagram
  - **Evento:** data, hora início/término, local (dropdown mapeado), nome dos noivos, nº convidados, como conheceu (lista + "outro")
  - **Pacote:** texto livre (interno, não vai para ME)
- Validações implementadas:
  - CPF válido (dígitos verificadores)
  - Telefone com máscara
  - Data não pode ser passada
  - Hora término > hora início
  - Local obrigatório (apenas mapeados)
  - Convidados numérico > 0

**Arquivos criados/modificados:**
- `public/vendas_form_casamento.php` (reescrito completamente)
- `sql/042_vendas_ajustes.sql` (adiciona campos novos na tabela)

---

## ⚠️ Ajuste 9 — Campos do Link Privado (Lançamento Presencial)

**Status:** ⚠️ Pendente

**O que falta:**
- Criar página `vendas_lancamento_presencial.php` com:
  - Todos os campos do formulário público (Casamento)
  - Campos internos adicionais:
    - Forma de pagamento
    - Valor negociado
    - Desconto
    - Adicionais (tabela dinâmica)
    - Total (cálculo automático)
    - Upload orçamento/proposta
    - Responsável comercial (auto = usuário logado)
    - Observações internas
- Salvar com `origem = 'presencial'`
- Não permitir aprovação nesta página

**Próximos passos:**
- Criar arquivo `vendas_lancamento_presencial.php`
- Adicionar rota em `public/index.php`
- Adicionar link no menu Comercial > Vendas

---

## Migration SQL

**Arquivo:** `sql/042_vendas_ajustes.sql`

**Mudanças:**
- Adiciona campo `origem` (publico/presencial)
- Adiciona campos novos para Casamento:
  - `rg`, `cep`, `endereco_completo`, `numero`, `complemento`, `bairro`, `cidade`, `estado`, `pais`
  - `instagram`, `nome_noivos`, `num_convidados`, `como_conheceu`, `como_conheceu_outro`
  - `forma_pagamento`, `observacoes_internas`, `responsavel_comercial_id`
- Remove constraint antiga de `unidade`
- Adiciona índice para `origem`

**Para executar:**
```bash
psql -h localhost -p 5432 -U tiagozucarelli -d painel_smile -f sql/042_vendas_ajustes.sql
```

---

## Como Acessar

### Links Públicos
- **Casamento:** `index.php?page=vendas_form_casamento`
- **Infantil:** `index.php?page=vendas_form_infantil` (ainda usa base antiga)
- **PJ:** `index.php?page=vendas_form_pj` (ainda usa base antiga)

### Páginas Internas (requer login + perm_comercial)
- **Pré-contratos (Listagem):** `index.php?page=vendas_pre_contratos`
- **Kanban:** `index.php?page=vendas_kanban`
- **Lançamento Presencial:** ⚠️ Ainda não criado
- **Administração Vendas:** ⚠️ Ainda não criado (usar `vendas_pre_contratos` por enquanto)

---

## Checklist de Verificação

### ✅ Implementado
- [x] Local do evento como dropdown mapeado
- [x] Validação de local mapeado antes de criar evento
- [x] Texto livre só para Pacote/Plano
- [x] Aprovação somente para admin (função centralizada)
- [x] Kanban garante coluna "Criado na ME"
- [x] Upload via Magalu confirmado
- [x] Uso de mapeamento logistica_conexao para idlocalevento
- [x] Campos exatos do Link Público Casamento

### ⚠️ Parcialmente Implementado
- [ ] Separar Lançamento Presencial de Administração Vendas
- [ ] Criar página Lançamento Presencial com todos os campos

### 📋 Próximos Passos
1. Criar `vendas_lancamento_presencial.php`
2. Criar `vendas_administracao.php` (ou ajustar `vendas_pre_contratos.php` para separar funções)
3. Atualizar menu Comercial > Vendas
4. Testar fluxo completo:
   - Envio link público → aparece pré-contrato
   - Lançamento presencial → aparece pré-contrato
   - Admin aprova → valida conflito → cria cliente/evento na ME → cria card no Kanban
   - Upload Magalu → reabrir e ver anexo

---

## Notas Importantes

1. **Campo `origem`:** Adicionado na tabela, mas ainda não está sendo usado em todos os lugares. Quando criar Lançamento Presencial, usar `origem = 'presencial'`.

2. **Formulários Infantil e PJ:** Ainda usam `vendas_form_base.php` antigo. Podem ser atualizados seguindo o padrão de `vendas_form_casamento.php`.

3. **Nome do evento na ME:** Para casamento, usa `nome_noivos`. Para outros tipos, usa `nome_completo - tipo_evento`.

4. **Validação de local:** Sempre verifica se local está mapeado antes de criar evento na ME. Se não estiver, bloqueia aprovação com mensagem clara.

---

**Data:** 2026-01-23
**Versão:** 1.0
