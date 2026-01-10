# 📋 INVENTÁRIO COMPLETO - MÓDULO ESTOQUE + LISTA DE COMPRAS
## Data: <?= date('d/m/Y H:i:s') ?>

---

## 🎯 OBJETIVO
Remover completamente o módulo de Estoque e Lista de Compras do Painel Smile PRO, mantendo o restante do sistema funcionando.

---

## 📁 ETAPA A - MAPEAMENTO COMPLETO

### 1. ARQUIVOS PHP - ESTOQUE

#### Páginas Principais:
- ✅ `public/estoque_kardex.php` - Tela de Kardex
- ✅ `public/estoque_kardex_v2.php` - Versão 2 do Kardex
- ✅ `public/estoque_contagens.php` - Lista de contagens
- ✅ `public/estoque_contar.php` - Criar/editar contagem
- ✅ `public/estoque_alertas.php` - Alertas de estoque
- ✅ `public/estoque_sugestao.php` - Sugestões de compra
- ✅ `public/estoque_desvios.php` - Desvios de estoque
- ✅ `public/estoque_logistico.php` - Dashboard logístico de estoque
- ✅ `public/setup_kardex.php` - Setup do módulo Kardex

**Total: 9 arquivos**

---

### 2. ARQUIVOS PHP - LISTA DE COMPRAS

#### Páginas Principais:
- ✅ `public/lc_index.php` - Índice principal de listas
- ✅ `public/lc_index_novo.php` - Versão nova do índice
- ✅ `public/lc_index_old.php` - Versão antiga (backup)
- ✅ `public/lista_compras.php` - Gerar lista de compras
- ✅ `public/lista_compras_gerar.php` - Gerador de listas
- ✅ `public/lista_compras_submit.php` - Submissão de listas
- ✅ `public/lista_compras_lixeira.php` - Lixeira de listas
- ✅ `public/lc_ver.php` - Visualizar lista
- ✅ `public/lc_pdf.php` - Gerar PDF de lista
- ✅ `public/lc_excluir.php` - Excluir lista
- ✅ `public/gerar_lista_compras.php` - Gerador alternativo
- ✅ `public/pdf_compras.php` - PDF de compras
- ✅ `public/pdf_encomendas.php` - PDF de encomendas
- ✅ `public/logistico.php` - Página logístico (redireciona para lc_index)

**Total: 14 arquivos**

---

### 3. ARQUIVOS PHP - FICHAS TÉCNICAS / INSUMOS

#### Páginas de Configuração:
- ✅ `public/config_insumos.php` - Configuração de insumos
- ✅ `public/config_fichas.php` - Configuração de fichas técnicas
- ✅ `public/config_itens.php` - Configuração de itens
- ✅ `public/config_itens_fixos.php` - Configuração de itens fixos
- ✅ `public/fichas_tecnicas.php` - Lista de fichas técnicas
- ✅ `public/ficha_tecnica.php` - Visualizar/editar ficha
- ✅ `public/ficha_tecnica_ajax.php` - AJAX para fichas
- ✅ `public/ficha_tecnica_simple.php` - Versão simples
- ✅ `public/xhr_ficha.php` - XHR para fichas
- ✅ `public/create_lc_itens_fixos.php` - Criar itens fixos
- ✅ `public/setup_recipes_web.php` - Setup de receitas

**Total: 11 arquivos**

---

### 4. HELPERS E SERVIÇOS

#### Helpers Específicos:
- ✅ `public/lc_anexos_helper.php` - Helper de anexos de listas
- ✅ `public/lc_calc.php` - Cálculos de listas
- ✅ `public/lc_config_helper.php` - Configurações de listas
- ✅ `public/lc_config_avancadas.php` - Configurações avançadas
- ✅ `public/lc_movimentos_helper.php` - Helper de movimentos
- ✅ `public/lc_permissions_helper.php` - Permissões de listas
- ✅ `public/lc_permissions_enhanced.php` - Permissões melhoradas
- ✅ `public/lc_substitutes_helper.php` - Helper de substitutos
- ✅ `public/lc_units_helper.php` - Helper de unidades
- ✅ `public/debug_generation.php` - Debug de geração

**Total: 10 arquivos**

---

### 5. ROTAS E MENUS

#### Em `public/index.php`:
```php
'logistico' => 'lc_index.php',
'lc_index' => 'lc_index.php',
'lista' => 'lista_compras.php',
'lista_compras' => 'lista_compras.php',
'lc_ver' => 'ver.php',
'lc_pdf' => 'lc_pdf.php',
'estoque' => 'estoque_logistico.php',
'estoque_logistico' => 'estoque_logistico.php',
'estoque_kardex' => 'estoque_kardex.php',
'kardex' => 'estoque_kardex.php',
'estoque_contagens' => 'estoque_contagens.php',
'contagens' => 'estoque_contagens.php',
'estoque_alertas' => 'estoque_alertas.php',
'alertas' => 'estoque_alertas.php',
```

#### Em `public/sidebar_unified.php`:
- Link "Logístico" → `index.php?page=logistico`
- Verifica permissão `perm_logistico`

#### Em `public/permissoes_map.php`:
- `'logistico' => 'perm_logistico'`
- `'lc_index' => 'perm_logistico'`
- `'lista_compras' => 'perm_logistico'`
- `'lista' => 'perm_logistico'`
- `'lc_ver' => 'perm_logistico'`
- `'lc_pdf' => 'perm_logistico'`
- `'estoque' => 'perm_logistico'`
- `'estoque_logistico' => 'perm_logistico'`
- `'estoque_kardex' => 'perm_logistico'`
- `'kardex' => 'perm_logistico'`
- `'estoque_contagens' => 'perm_logistico'`
- `'contagens' => 'perm_logistico'`
- `'estoque_alertas' => 'perm_logistico'`
- `'alertas' => 'perm_logistico'`
- `'ver' => 'perm_logistico'`

---

### 6. PERMISSÕES

#### Permissões Relacionadas:
- ✅ `perm_logistico` - Permissão principal do módulo
- ✅ `perm_estoque_logistico` - Permissão específica de estoque

#### Onde são usadas:
- `public/permissoes_boot.php` - Carregamento de permissões
- `public/permissoes_map.php` - Mapeamento de rotas
- `public/sidebar_unified.php` - Exibição no menu
- `public/usuarios.php` - Gerenciamento de usuários
- `public/usuarios_new.php` - Novo usuário
- `public/limpar_e_recriar_permissoes.php` - Limpeza de permissões
- `public/habilitar_todas_permissoes.php` - Habilitar todas
- `public/check_permissions_mismatch.php` - Verificação
- `public/index.php` - Verificação de push notifications
- `public/login.php` - Verificação de push notifications
- `public/push_block_screen.php` - Verificação de push notifications

---

### 7. TABELAS DO BANCO DE DADOS

#### Tabelas de Estoque:
- ✅ `estoque_contagens` - Contagens de estoque
- ✅ `estoque_contagem_itens` - Itens das contagens
- ✅ `lc_movimentos_estoque` - Movimentos de estoque (Kardex)
- ✅ `lc_eventos_baixados` - Baixas por evento
- ✅ `lc_ajustes_estoque` - Ajustes manuais
- ✅ `lc_perdas_devolucoes` - Perdas e devoluções
- ✅ `lc_config_estoque` - Configurações do módulo

#### Tabelas de Lista de Compras:
- ✅ `lc_listas` - Listas de compras
- ✅ `lc_listas_eventos` - Eventos vinculados às listas
- ✅ `lc_compras_consolidadas` - Compras consolidadas
- ✅ `lc_encomendas_itens` - Itens de encomendas
- ✅ `lc_encomendas_overrides` - Overrides de encomendas
- ✅ `lc_config` - Configurações do sistema

#### Tabelas de Fichas Técnicas / Insumos:
- ✅ `lc_fichas` - Fichas técnicas (receitas)
- ✅ `lc_ficha_componentes` - Componentes das fichas
- ✅ `lc_itens` - Itens (preparos e comprados)
- ✅ `lc_itens_fixos` - Itens fixos
- ✅ `lc_insumos` - Insumos (MATERIA-PRIMA)
- ✅ `lc_insumos_substitutos` - Substitutos de insumos
- ✅ `lc_categorias` - Categorias
- ✅ `lc_unidades` - Unidades de medida

#### Views:
- ✅ `v_kardex_completo` - View do Kardex completo
- ✅ `v_resumo_movimentos_insumo` - Resumo de movimentos

#### Funções:
- ✅ `lc_calcular_saldo_insumo(INT, TIMESTAMP)` - Calcular saldo
- ✅ `lc_calcular_saldo_insumo_data(INT, TIMESTAMP, TIMESTAMP)` - Calcular saldo por período

#### Triggers:
- ✅ `tr_auditar_movimento` - Auditoria de movimentos

**Total: 18 tabelas + 2 views + 2 funções + 1 trigger**

---

### 8. SQL / MIGRATIONS

#### Arquivos SQL:
- ✅ `sql/008_estoque_contagem.sql` - Schema de contagem
- ✅ `sql/009_kardex_movimentos.sql` - Schema de Kardex
- ✅ `create_tables.sql` - Tabelas de lista de compras
- ✅ `create_all_tables.sql` - Todas as tabelas
- ✅ `sql/schema_completo_painel_smile.sql` - Schema completo (contém referências)

---

### 9. REFERÊNCIAS EM OUTROS MÓDULOS

#### Arquivos que podem referenciar (verificar):
- ✅ `public/magalu_integration_helper.php` - Pode ter uploads de anexos
- ✅ `public/configuracoes.php` - Pode ter links
- ✅ `public/cadastros.php` - Pode ter links
- ✅ `public/sistema_unificado.php` - Pode ter cards/menus
- ✅ `public/dashboard_*.php` - Pode ter widgets
- ✅ `public/relatorio_analise_sistema.php` - Pode ter relatórios

---

### 10. INCLUSÕES E IMPORTS

#### Verificar includes em:
- ✅ `public/sidebar_unified.php` - Inclui páginas
- ✅ `public/index.php` - Router principal
- ✅ `public/configuracoes.php` - Pode incluir configs
- ✅ `public/cadastros.php` - Pode incluir cadastros

---

## 📊 RESUMO ESTATÍSTICO

### Arquivos PHP:
- Estoque: **9 arquivos**
- Lista de Compras: **14 arquivos**
- Fichas/Insumos: **11 arquivos**
- Helpers: **10 arquivos**
- **TOTAL: 44 arquivos PHP**

### Tabelas do Banco:
- Estoque: **7 tabelas**
- Lista de Compras: **6 tabelas**
- Fichas/Insumos: **5 tabelas**
- Views: **2 views**
- Funções: **2 funções**
- Triggers: **1 trigger**
- **TOTAL: 18 tabelas + estruturas auxiliares**

### Rotas:
- **14 rotas** no `index.php`
- **14 rotas** no `permissoes_map.php`

### Permissões:
- **2 permissões** (`perm_logistico`, `perm_estoque_logistico`)

---

## ⚠️ ATENÇÃO - COMPARTILHADOS

### Tabelas que PODEM ser compartilhadas (verificar antes):
- ⚠️ `fornecedores` - Pode ser usado por outros módulos
- ⚠️ `usuarios` - Sistema principal
- ⚠️ `categorias` - Verificar se é usado em outros lugares

### Arquivos que PODEM ser compartilhados:
- ⚠️ `public/config_fornecedores.php` - Verificar se é só do módulo
- ⚠️ `public/fornecedores.php` - Verificar se é só do módulo
- ⚠️ `public/config_categorias.php` - Verificar se é só do módulo

---

## ✅ PRÓXIMOS PASSOS

1. **ETAPA B** - Desacoplar menus e rotas
2. **ETAPA C** - Remover código PHP
3. **ETAPA D** - Remover tabelas do banco
4. **ETAPA E** - Limpar referências e permissões
5. **ETAPA F** - Validação final

---

**Status:** ✅ INVENTÁRIO COMPLETO - PRONTO PARA EXECUÇÃO
