# Como Verificar e Corrigir a Chave Asaas

## Passo 1: Acessar o Painel Asaas

1. Acesse: https://www.asaas.com/
2. Faça login na sua conta
3. Vá em **Integrações > Chaves de API**

## Passo 2: Verificar Status da Chave

No painel, você verá uma lista de chaves. Para cada chave, verifique:

- ✅ **Status: ATIVA** (se mostrar "Desabilitada" ou "Expirada", esse é o problema!)
- ✅ **Tipo: Produção** (se for Sandbox, você precisa usar `api-sandbox.asaas.com`)
- ✅ **Última utilização**: Se mostrou "há mais de 3 meses", pode ter sido desabilitada automaticamente

## Passo 3: Comparar Chaves

1. **No painel Asaas:**
   - Clique na chave que você acredita estar usando
   - Copie a chave COMPLETA (começando com `$aact_prod_` ou `$aact_hmlg_`)

2. **No Railway:**
   - Vá em Variables
   - Veja o valor de `ASAAS_API_KEY`
   - Compare CARACTER POR CARACTER com a chave do painel

## Passo 4: Se a Chave Estiver Desabilitada/Expirada

### Opção A: Reabilitar (se desabilitada por inatividade)
1. No painel Asaas, clique em "Reabilitar" na chave
2. Aguarde alguns minutos
3. Teste novamente

### Opção B: Gerar Nova Chave (se expirada ou não pode reabilitar)
1. No painel Asaas: **Integrações > Chaves de API > Gerar Nova Chave**
2. **COPIE A CHAVE IMEDIATAMENTE** (ela só aparece uma vez!)
3. Cole no Railway na variável `ASAAS_API_KEY`
4. Faça redeploy do serviço

## Passo 5: Verificar Ambiente

- **Produção**: URL `https://api.asaas.com/v3` + Chave `$aact_prod_...`
- **Sandbox**: URL `https://api-sandbox.asaas.com/v3` + Chave `$aact_hmlg_...`

Se você estiver usando URL de produção mas chave de sandbox (ou vice-versa), isso causará erro `invalid_environment`.

## Verificação Final

Use a página de debug para ver exatamente o que está sendo enviado:
```
https://painelsmilepro-production.up.railway.app/index.php?page=test_asaas_debug
```

Esta página mostrará:
- A chave completa que está sendo usada
- Os headers exatos enviados
- A resposta completa da API

