# 🔧 Como Corrigir os Problemas de Banco de Dados

## 🚨 Problema Atual
Você está vendo o erro:
```
Fatal error: Uncaught PDOException: SQLSTATE[42703]: Undefined column: 7 ERROR: column "perm_agenda_ver" does not exist
```

## 🛠️ Soluções Disponíveis

### **Opção 1: Via Terminal (Recomendada)**
```bash
# Execute no terminal:
php fix_db_terminal.php
```

### **Opção 2: Via Navegador**
1. **Inicie o servidor local:**
   ```bash
   ./start_server.sh
   ```
   
2. **Acesse no navegador:**
   ```
   http://localhost:8000/corrigir_banco_agora.php
   ```

### **Opção 3: Via Servidor Existente**
Se você já tem um servidor web rodando, acesse:
```
http://seu-dominio.com/public/corrigir_banco_agora.php
```

## 📋 O que os scripts fazem:

1. **Verificam se a coluna `perm_agenda_ver` existe**
2. **Criam a coluna se não existir**
3. **Verificam outras colunas de permissão necessárias**
4. **Criam tabelas essenciais se não existirem**
5. **Criam índices necessários**
6. **Mostram relatório de correções aplicadas**

## 🎯 Resultado Esperado

Após executar qualquer uma das opções, você deve ver:
- ✅ Coluna perm_agenda_ver criada
- ✅ Outras colunas de permissão verificadas/criadas
- ✅ Tabelas essenciais verificadas/criadas
- ✅ Índices verificados/criados

## 🚀 Próximos Passos

1. **Execute uma das opções acima**
2. **Verifique se não há erros**
3. **Tente acessar o dashboard novamente**
4. **Se ainda houver problemas, execute novamente**

## 📞 Se nada funcionar

Se nenhuma das opções funcionar, verifique:
- Se o banco de dados está rodando
- Se as credenciais em `public/conexao.php` estão corretas
- Se o PHP está instalado e funcionando

## 🔍 Verificação Manual

Para verificar se as correções funcionaram, execute:
```sql
SELECT perm_agenda_ver FROM usuarios LIMIT 1;
```

Se não der erro, a correção foi bem-sucedida!
