# 📋 Módulo de Vendas - Resumo de Implementação

## ✅ O que foi criado

### 1. Banco de Dados (SQL)
**Arquivo:** `sql/041_modulo_vendas.sql`

**Tabelas criadas:**
- `vendas_pre_contratos` - Pré-contratos vindos dos formulários públicos
- `vendas_adicionais` - Itens adicionais de cada pré-contrato
- `vendas_anexos` - Anexos de orçamentos/propostas
- `vendas_kanban_boards` - Quadros do Kanban
- `vendas_kanban_colunas` - Colunas do Kanban (8 colunas padrão criadas automaticamente)
- `vendas_kanban_cards` - Cards do Kanban
- `vendas_kanban_historico` - Histórico de movimentação dos cards
- `vendas_logs` - Logs de todas as ações do sistema

### 2. Páginas Públicas (3 links)
**Arquivos:**
- `public/vendas_form_casamento.php` - Formulário para Casamento
- `public/vendas_form_infantil.php` - Formulário para Festa Infantil
- `public/vendas_form_pj.php` - Formulário para Evento Corporativo (PJ)
- `public/vendas_form_base.php` - Base reutilizável (usada pelos 3 acima)

**Características:**
- ✅ Sem necessidade de login
- ✅ Proteção anti-spam (rate limit: 3 envios por hora por IP)
- ✅ Validação de campos obrigatórios
- ✅ Máscaras para CPF e telefone
- ✅ Validação de data (não permite passado)
- ✅ Validação de horários (término após início)

### 3. Painel Interno
**Arquivo:** `public/vendas_pre_contratos.php`

**Funcionalidades:**
- ✅ Lista de pré-contratos com filtros (status, tipo, busca)
- ✅ Edição de dados comerciais (pacote, valor, desconto)
- ✅ Tabela dinâmica de adicionais
- ✅ Upload de orçamento/proposta
- ✅ Cálculo automático do valor total
- ✅ Sistema de aprovação (somente admin)
- ✅ Detecção de conflito de agenda
- ✅ Detecção de duplicidade de cliente
- ✅ Modal de aprovação com resolução de conflitos

### 4. Kanban de Acompanhamento
**Arquivo:** `public/vendas_kanban.php`
**API:** `public/vendas_kanban_api.php`

**Funcionalidades:**
- ✅ Visualização estilo Trello
- ✅ Drag & drop para mover cards entre colunas
- ✅ 8 colunas padrão criadas automaticamente
- ✅ Cards criados automaticamente quando evento é aprovado
- ✅ Histórico de movimentação

### 5. Helper ME API
**Arquivo:** `public/vendas_me_helper.php`

**Funções:**
- ✅ `vendas_me_buscar_cliente()` - Busca cliente por CPF, email, telefone ou nome
- ✅ `vendas_me_criar_cliente()` - Cria novo cliente na ME
- ✅ `vendas_me_atualizar_cliente()` - Atualiza cliente existente na ME
- ✅ `vendas_me_buscar_eventos()` - Busca eventos por data e unidade
- ✅ `vendas_me_verificar_conflito_agenda()` - Verifica conflitos com regras por unidade
- ✅ `vendas_me_criar_evento()` - Cria evento na ME
- ✅ `vendas_me_listar_tipos_evento()` - Lista tipos de evento (com cache em sessão)

### 6. Integrações
- ✅ Rotas adicionadas em `public/index.php`
- ✅ Permissões configuradas em `public/permissoes_map.php`
- ✅ Link adicionado na landing do Comercial (`public/comercial_landing.php`)

## 🔗 Como acessar

### Links Públicos (sem login)
1. **Casamento:** 
   - URL: `https://painelsmilepro-production.up.railway.app/vendas_form_casamento.php`
   - Ou: `https://painelsmilepro-production.up.railway.app/index.php?page=vendas_form_casamento`
2. **Infantil:** 
   - URL: `https://painelsmilepro-production.up.railway.app/vendas_form_infantil.php`
   - Ou: `https://painelsmilepro-production.up.railway.app/index.php?page=vendas_form_infantil`
3. **PJ:** 
   - URL: `https://painelsmilepro-production.up.railway.app/vendas_form_pj.php`
   - Ou: `https://painelsmilepro-production.up.railway.app/index.php?page=vendas_form_pj`

### Painel Interno (requer login e permissão comercial)
1. **Pré-contratos:** 
   - `https://painelsmilepro-production.up.railway.app/index.php?page=vendas_pre_contratos`
   - Ou através do menu: **Comercial > Vendas > Pré-contratos**
2. **Kanban:** 
   - `https://painelsmilepro-production.up.railway.app/index.php?page=vendas_kanban`
   - Ou através do menu: **Comercial > Vendas > Acompanhamento de Contratos**

**Acesso rápido:** Menu Comercial > Vendas (card laranja na landing)

## 📋 Checklist de Verificação

### ⚠️ IMPORTANTE: Executar SQL primeiro!
**Antes de testar, execute o arquivo SQL:**
```sql
-- Executar no banco de dados PostgreSQL
\i sql/041_modulo_vendas.sql
```

Ou copie e cole o conteúdo do arquivo `sql/041_modulo_vendas.sql` no cliente SQL.

### Etapa 1: Executar SQL
- [ ] Executar `sql/041_modulo_vendas.sql` no banco de dados
- [ ] Verificar se as tabelas foram criadas (8 tabelas)
- [ ] Verificar se o quadro padrão "Acompanhamento de Contratos" foi criado
- [ ] Verificar se as 8 colunas padrão foram criadas

### Etapa 2: Testar Formulários Públicos
- [ ] Acessar link de Casamento e preencher formulário
- [ ] Verificar se pré-contrato foi criado no painel
- [ ] Testar rate limit (tentar enviar mais de 3 vezes)
- [ ] Testar validações (campos obrigatórios, datas passadas, etc)

### Etapa 3: Testar Painel Interno
- [ ] Acessar Pré-contratos e verificar se aparece o novo registro
- [ ] Abrir edição de um pré-contrato
- [ ] Preencher dados comerciais (pacote, valor, desconto)
- [ ] Adicionar itens adicionais
- [ ] Verificar cálculo automático do total
- [ ] Fazer upload de orçamento
- [ ] Salvar e verificar se status mudou para "Pronto para aprovação"

### Etapa 4: Testar Aprovação (como admin)
- [ ] Clicar em "Aprovar e Criar na ME"
- [ ] **Caso 1:** Cliente novo, sem conflito
  - [ ] Verificar se cliente foi criado na ME
  - [ ] Verificar se evento foi criado na ME
  - [ ] Verificar se card foi criado no Kanban
  - [ ] Verificar se status mudou para "Aprovado / Criado na ME"
- [ ] **Caso 2:** Cliente com CPF existente, divergência de telefone
  - [ ] Verificar se modal mostra divergências
  - [ ] Testar opção "Manter dados atuais da ME"
  - [ ] Testar opção "Atualizar dados na ME"
  - [ ] Testar opção "Atualizar apenas no Painel"
- [ ] **Caso 3:** Conflito de agenda (Lisbon, menos de 2h)
  - [ ] Verificar se modal mostra eventos conflitantes
  - [ ] Testar "Voltar e ajustar"
  - [ ] Testar "Forçar criação (override)" com motivo
  - [ ] Verificar se override foi registrado no log
- [ ] **Caso 4:** Upload de orçamento
  - [ ] Fazer upload de arquivo
  - [ ] Verificar se arquivo foi salvo no Magalu
  - [ ] Reabrir pré-contrato e verificar se anexo aparece

### Etapa 5: Testar Kanban
- [ ] Acessar Kanban de Acompanhamento
- [ ] Verificar se colunas padrão aparecem
- [ ] Verificar se cards criados na aprovação aparecem
- [ ] Testar drag & drop (mover card entre colunas)
- [ ] Verificar se histórico foi registrado

## 🔧 Configurações Necessárias

### Variáveis de Ambiente (já devem estar configuradas)
- `ME_BASE_URL` - URL base da API ME Eventos
- `ME_API_KEY` - Chave da API ME Eventos
- `MAGALU_BUCKET` - Bucket do Magalu
- `MAGALU_ACCESS_KEY` - Chave de acesso Magalu
- `MAGALU_SECRET_KEY` - Chave secreta Magalu
- `MAGALU_ENDPOINT` - Endpoint do Magalu
- `MAGALU_REGION` - Região do Magalu

## 📝 Regras de Conflito de Agenda

- **Lisbon:** 2 horas de distância mínima entre término e início
- **Diverkids:** 1h30 de distância mínima entre término e início
- **Garden:** 3 horas de distância mínima entre término e início
- **Cristal:** 3 horas de distância mínima entre término e início

## 🔐 Permissões

- **Pré-contratos:** Requer `perm_comercial`
- **Kanban:** Requer `perm_comercial`
- **Aprovação:** Requer `perm_administrativo` (somente Tiago/admin)
- **Formulários públicos:** Sem permissão necessária

## 📊 Status dos Pré-contratos

1. **aguardando_conferencia** - Recém recebido do formulário público
2. **pronto_aprovacao** - Dados comerciais preenchidos, pronto para aprovar
3. **aprovado_criado_me** - Aprovado e criado na ME (cliente + evento)
4. **cancelado_nao_fechou** - Cancelado ou não fechou negócio

## 🎯 Próximos Passos (Opcional)

- Adicionar notificações por email quando pré-contrato é criado
- Adicionar relatórios de vendas
- Adicionar exportação de dados
- Melhorar UI do Kanban (mais funcionalidades estilo Trello)
- Adicionar comentários nos cards do Kanban
- Adicionar anexos nos cards do Kanban
