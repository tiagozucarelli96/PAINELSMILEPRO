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
- **Onde**: `public/demandas_trello_api.php` linha 275-283
- **Status**: ✅ Funcionando

---

## ❌ O que NÃO está configurado (mas poderia ser adicionado):

### 1. **Novo Card Criado**
- **Quando**: Um card novo é criado
- **Ação**: Notificar todos os usuários ou apenas responsáveis do board
- **Status**: ❌ Não implementado

### 2. **Card Atualizado**
- **Quando**: Título, descrição, prazo, prioridade, status ou categoria de um card é alterado
- **Ação**: Notificar responsáveis do card sobre mudanças
- **Onde seria**: `public/demandas_trello_api.php` função `atualizarCard` (linha 338)
- **Status**: ❌ Não implementado

### 3. **Card Concluído**
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

