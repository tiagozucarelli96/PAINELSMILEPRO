# 📋 ANÁLISE DETALHADA DAS PERMISSÕES DA SIDEBAR

## 🎯 RESUMO EXECUTIVO

Este documento apresenta uma análise minuciosa das permissões do sistema relacionadas **exclusivamente aos botões da sidebar**. O sistema utiliza permissões booleanas (TRUE/FALSE) armazenadas na tabela `usuarios` para controlar a visibilidade de cada módulo na sidebar.

---

## 📊 MAPEAMENTO DE PERMISSÕES DA SIDEBAR

### 1. 🏠 **Dashboard**
- **Permissão:** Nenhuma (sempre visível para usuários logados)
- **Coluna no Banco:** Não possui
- **Comportamento:** O botão Dashboard sempre aparece na sidebar para todos os usuários logados
- **Localização no Código:** `sidebar_unified.php` linha 1673-1676

### 2. 📅 **Agenda**
- **Permissão:** `perm_agenda`
- **Coluna no Banco:** `usuarios.perm_agenda` (BOOLEAN, DEFAULT FALSE)
- **Comportamento:** Botão aparece apenas se `$_SESSION['perm_agenda']` for TRUE
- **Verificação:** `<?php if (!empty($_SESSION['perm_agenda'])): ?>`
- **Localização no Código:** `sidebar_unified.php` linha 1678-1683

### 3. 📝 **Demandas**
- **Permissão:** `perm_demandas`
- **Coluna no Banco:** `usuarios.perm_demandas` (BOOLEAN, DEFAULT FALSE)
- **Comportamento:** Botão aparece apenas se `$_SESSION['perm_demandas']` for TRUE
- **Verificação:** `<?php if (!empty($_SESSION['perm_demandas'])): ?>`
- **Localização no Código:** `sidebar_unified.php` linha 1685-1690

### 4. 📦 **Logístico**
- **Permissão:** `perm_logistico`
- **Coluna no Banco:** `usuarios.perm_logistico` (BOOLEAN, DEFAULT FALSE)
- **Comportamento:** Botão aparece apenas se `$_SESSION['perm_logistico']` for TRUE
- **Verificação:** `<?php if (!empty($_SESSION['perm_logistico'])): ?>`
- **Localização no Código:** `sidebar_unified.php` linha 1699-1704

### 5. ⚙️ **Configurações**
- **Permissão:** `perm_configuracoes`
- **Coluna no Banco:** `usuarios.perm_configuracoes` (BOOLEAN, DEFAULT FALSE)
- **Comportamento:** Botão aparece apenas se `$_SESSION['perm_configuracoes']` for TRUE
- **Verificação:** `<?php if (!empty($_SESSION['perm_configuracoes'])): ?>`
- **Localização no Código:** `sidebar_unified.php` linha 1706-1711

### 6. 📝 **Cadastros**
- **Permissão:** `perm_cadastros`
- **Coluna no Banco:** `usuarios.perm_cadastros` (BOOLEAN, DEFAULT FALSE)
- **Comportamento:** Botão aparece apenas se `$_SESSION['perm_cadastros']` for TRUE
- **Verificação:** `<?php if (!empty($_SESSION['perm_cadastros'])): ?>`
- **Localização no Código:** `sidebar_unified.php` linha 1713-1718

### 7. 💰 **Financeiro**
- **Permissão:** `perm_financeiro`
- **Coluna no Banco:** `usuarios.perm_financeiro` (BOOLEAN, DEFAULT FALSE)
- **Comportamento:** Botão aparece apenas se `$_SESSION['perm_financeiro']` for TRUE
- **Verificação:** `<?php if (!empty($_SESSION['perm_financeiro'])): ?>`
- **Localização no Código:** `sidebar_unified.php` linha 1720-1725

### 8. 👥 **Administrativo**
- **Permissão:** `perm_administrativo`
- **Coluna no Banco:** `usuarios.perm_administrativo` (BOOLEAN, DEFAULT FALSE)
- **Comportamento:** Botão aparece apenas se `$_SESSION['perm_administrativo']` for TRUE
- **Verificação:** `<?php if (!empty($_SESSION['perm_administrativo'])): ?>`
- **Localização no Código:** `sidebar_unified.php` linha 1727-1732

### 9. 👔 **RH**
- **Permissão:** `perm_rh`
- **Coluna no Banco:** `usuarios.perm_rh` (BOOLEAN, DEFAULT FALSE)
- **Comportamento:** Botão aparece apenas se `$_SESSION['perm_rh']` for TRUE
- **Verificação:** `<?php if (!empty($_SESSION['perm_rh'])): ?>`
- **Localização no Código:** `sidebar_unified.php` linha 1734-1739

### 10. 🏦 **Banco Smile**
- **Permissão:** `perm_banco_smile`
- **Coluna no Banco:** `usuarios.perm_banco_smile` (BOOLEAN, DEFAULT FALSE)
- **Comportamento:** Botão aparece apenas se `$_SESSION['perm_banco_smile']` for TRUE
- **Verificação:** `<?php if (!empty($_SESSION['perm_banco_smile'])): ?>`
- **Localização no Código:** `sidebar_unified.php` linha 1741-1746

---

## 🔄 FLUXO DE CARREGAMENTO DAS PERMISSÕES

### 1. **Login do Usuário**
- Arquivo: `public/login.php`
- Ação: Após autenticação bem-sucedida, redireciona para `index.php?page=dashboard`

### 2. **Carregamento das Permissões na Sessão**
- Arquivo: `public/permissoes_boot.php`
- Processo:
  1. Busca o registro completo do usuário na tabela `usuarios`
  2. Para cada permissão listada em `$permKeys`, verifica se a coluna existe no banco
  3. Converte o valor para boolean usando a função `truthy()`
  4. Armazena em `$_SESSION['perm_*']`
  5. Se nenhuma permissão for encontrada mas o usuário for admin, libera todas as permissões

### 3. **Verificação na Sidebar**
- Arquivo: `public/sidebar_unified.php`
- Processo: Para cada botão da sidebar, verifica se `$_SESSION['perm_*']` está definido e não vazio
- Se TRUE: Botão aparece
- Se FALSE ou não definido: Botão não aparece

---

## 📝 LISTA COMPLETA DE PERMISSÕES DA SIDEBAR

| # | Módulo | Permissão | Coluna no Banco | Padrão | Visível na Sidebar? |
|---|--------|-----------|----------------|--------|---------------------|
| 1 | Dashboard | Nenhuma | - | - | ✅ Sempre |
| 2 | Agenda | `perm_agenda` | `usuarios.perm_agenda` | FALSE | ⚠️ Se TRUE |
| 3 | Demandas | `perm_demandas` | `usuarios.perm_demandas` | FALSE | ⚠️ Se TRUE |
| 4 | Logístico | `perm_logistico` | `usuarios.perm_logistico` | FALSE | ⚠️ Se TRUE |
| 5 | Configurações | `perm_configuracoes` | `usuarios.perm_configuracoes` | FALSE | ⚠️ Se TRUE |
| 6 | Cadastros | `perm_cadastros` | `usuarios.perm_cadastros` | FALSE | ⚠️ Se TRUE |
| 7 | Financeiro | `perm_financeiro` | `usuarios.perm_financeiro` | FALSE | ⚠️ Se TRUE |
| 8 | Administrativo | `perm_administrativo` | `usuarios.perm_administrativo` | FALSE | ⚠️ Se TRUE |
| 9 | RH | `perm_rh` | `usuarios.perm_rh` | FALSE | ⚠️ Se TRUE |
| 10 | Banco Smile | `perm_banco_smile` | `usuarios.perm_banco_smile` | FALSE | ⚠️ Se TRUE |

---

## 🔍 OBSERVAÇÕES IMPORTANTES

### ✅ **Comportamento Atual (CORRETO)**
- Cada botão da sidebar verifica individualmente sua permissão
- Se o usuário não tiver a permissão, o botão **simplesmente não aparece**
- Não há mensagens de erro ou avisos - apenas ocultação silenciosa

### ⚠️ **Permissões Adicionais (NÃO usadas na sidebar)**
O sistema possui outras permissões que **NÃO** controlam botões da sidebar, mas são usadas em outras partes do sistema:
- `perm_comercial` - Existe na sidebar mas não foi mencionada pelo usuário
- `perm_banco_smile_admin` - Permissão administrativa do Banco Smile
- `perm_usuarios` - Usada dentro do módulo Configurações
- `perm_pagamentos` - Usada dentro de outros módulos
- `perm_tarefas` - Usada em funcionalidades específicas
- `perm_lista` - Usada no módulo Logístico
- `perm_notas_fiscais` - Usada em módulos financeiros
- `perm_estoque_logistico` - Usada no módulo Logístico
- `perm_dados_contrato` - Usada em módulos comerciais
- `perm_uso_fiorino` - Funcionalidade específica
- `perm_agenda_ver`, `perm_agenda_meus`, `perm_agenda_relatorios` - Permissões específicas dentro da Agenda
- `perm_forcar_conflito`, `perm_gerir_eventos_outros` - Permissões específicas da Agenda

### 📌 **Módulo Comercial**
- **Permissão:** `perm_comercial`
- **Status:** Existe na sidebar (linha 1692-1697) mas **NÃO** foi mencionado pelo usuário
- **Recomendação:** Verificar se deve ser mantido ou removido conforme solicitação do usuário

---

## 🛠️ COMO FUNCIONA A VERIFICAÇÃO

### Código PHP na Sidebar:
```php
<?php if (!empty($_SESSION['perm_agenda'])): ?>
    <a href="index.php?page=agenda" class="nav-item">
        <span class="nav-item-icon">📅</span>
        Agenda
    </a>
<?php endif; ?>
```

### Lógica:
1. `!empty($_SESSION['perm_agenda'])` verifica se:
   - A chave existe na sessão
   - O valor não é vazio
   - O valor não é FALSE, 0, NULL, ou string vazia

2. Se a condição for TRUE → Botão aparece
3. Se a condição for FALSE → Botão não aparece (código não é renderizado)

---

## 📊 RESUMO FINAL

### Total de Módulos na Sidebar: **10**
1. ✅ Dashboard (sempre visível)
2. ⚠️ Agenda (requer `perm_agenda`)
3. ⚠️ Demandas (requer `perm_demandas`)
4. ⚠️ Logístico (requer `perm_logistico`)
5. ⚠️ Configurações (requer `perm_configuracoes`)
6. ⚠️ Cadastros (requer `perm_cadastros`)
7. ⚠️ Financeiro (requer `perm_financeiro`)
8. ⚠️ Administrativo (requer `perm_administrativo`)
9. ⚠️ RH (requer `perm_rh`)
10. ⚠️ Banco Smile (requer `perm_banco_smile`)

### Módulo Adicional Encontrado:
- ⚠️ Comercial (requer `perm_comercial`) - **Verificar se deve ser mantido**

---

## ✅ CONCLUSÃO

O sistema de permissões da sidebar está **funcionando corretamente**. Cada botão verifica sua permissão individual e só aparece se o usuário tiver acesso. Não há necessidade de alterações no comportamento atual, apenas verificar se o módulo "Comercial" deve ser mantido ou removido conforme a solicitação do usuário.

---

**Data da Análise:** 2024
**Arquivos Analisados:**
- `public/sidebar_unified.php`
- `public/permissoes_boot.php`
- `public/usuarios_new.php`
- `sql/fix_usuarios_table_completo.sql`
