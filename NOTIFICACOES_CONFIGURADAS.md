# 🔔 Notificações Configuradas no Sistema

## ✅ O que ESTÁ configurado atualmente:

### 1. **Menções (@usuario) em Comentários**
- **Quando**: Alguém menciona você usando `@seu_nome` em um comentário de uma demanda
- **Tipo**: `mencao`
- **Mensagem**: "Você foi mencionado em um comentário"
- **Onde**: `public/demandas_trello_api.php` linha 488-504
- **Status**: ✅ Funcionando

### 2. **Atribuição de Tarefa/Card**
- **Quando**: Um card é criado E você é atribuído como responsável
- **Tipo**: `tarefa_atribuida`
- **Mensagem**: "Você foi atribuído ao card: {título do card}"
- **Onde**: `public/demandas_trello_api.php` linha 314-316
- **Status**: ✅ Funcionando

### 3. **Card Atualizado** ✨ NOVO
- **Quando**: Um card que você acompanha (é criador ou responsável) é modificado
- **Tipo**: `card_atualizado`
- **Mensagem**: "Card '{título}' foi atualizado: título, descrição, prazo..." (lista os campos alterados)
- **Notifica**: Criador do card + todos os responsáveis atribuídos (exceto quem fez a alteração)
- **Onde**: `public/demandas_trello_api.php` linha 435-474
- **Status**: ✅ Funcionando

### 4. **Novo Card Criado** ✨ NOVO
- **Quando**: Um novo card é criado em um board onde você tem cards (é responsável de outros cards)
- **Tipo**: `card_criado`
- **Mensagem**: "Novo card criado em {nome do board}: {título do card}"
- **Notifica**: Todos os responsáveis de outros cards no mesmo board (exceto criador e já atribuídos)
- **Onde**: `public/demandas_trello_api.php` linha 279-325
- **Status**: ✅ Funcionando

---

## ❌ O que NÃO está configurado (mas poderia ser adicionado):

### 1. **Card Concluído**
- **Quando**: Um card é marcado como concluído
- **Ação**: Notificar criador e responsáveis
- **Onde seria**: `public/demandas_trello_api.php` função `concluirCard`
- **Status**: ❌ Não implementado

### 4. **Card Reaberto**
- **Quando**: Um card concluído é reaberto
- **Ação**: Notificar criador e responsáveis
- **Onde seria**: `public/demandas_trello_api.php` função `reabrirCard`
- **Status**: ❌ Não implementado

### 5. **Card Movido entre Listas**
- **Quando**: Um card muda de lista (ex: "To Do" → "Doing")
- **Ação**: Notificar responsáveis sobre a mudança de status/lista
- **Onde seria**: `public/demandas_trello_api.php` função `moverCard` (linha 302)
- **Status**: ❌ Não implementado

### 6. **Anexo Adicionado**
- **Quando**: Um arquivo é anexado a um card
- **Ação**: Notificar responsáveis do card
- **Onde seria**: `public/demandas_trello_api.php` função `adicionarAnexo` (linha 541)
- **Status**: ❌ Não implementado

---

## 📝 Estrutura de Notificação

```php
criarNotificacao($pdo, $usuario_id, $tipo, $referencia_id, $mensagem);
```

- `$usuario_id`: ID do usuário que receberá a notificação
- `$tipo`: Tipo da notificação (`mencao`, `tarefa_atribuida`, etc.)
- `$referencia_id`: ID do card relacionado (para navegação)
- `$mensagem`: Mensagem da notificação

---

## 💡 Sugestão

Quer que eu implemente alguma dessas notificações? As mais úteis seriam:
1. **Card Atualizado** - quando alguém altera um card que você acompanha
2. **Card Concluído** - quando uma tarefa é finalizada
3. **Novo Card Criado** - quando um card é criado em um board que você acompanha

