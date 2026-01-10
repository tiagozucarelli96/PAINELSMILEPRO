# 🚀 Como Configurar Resend no Railway

## Passo a Passo Completo

### 1. Obter API Key do Resend

1. Acesse: https://resend.com/
2. Faça login na sua conta
3. Vá em **API Keys** (ou **Settings** → **API Keys**)
4. Clique em **Create API Key**
5. Dê um nome (ex: "Painel Smile PRO")
6. Copie a API key (começa com `re_`)

### 2. Adicionar no Railway

1. Acesse o painel do Railway: https://railway.app/
2. Selecione seu projeto **PAINELSMILEPRO**
3. Vá na aba **Variables** (ou clique no serviço e depois em **Variables**)
4. Clique em **+ New Variable**
5. Preencha:
   - **Name:** `RESEND_API_KEY`
   - **Value:** Cole sua API key do Resend (ex: `re_VfaDARxN_8iLJjmKYHmYXinCFG1SQ3eFn`)
6. Clique em **Add**

### 3. Fazer Deploy

Após adicionar a variável:
1. O Railway pode fazer deploy automático, OU
2. Vá em **Deployments** e clique em **Redeploy** para garantir que a variável seja carregada

### 4. Verificar se Funcionou

1. Acesse: `index.php?page=config_email_global`
2. Na seção **"Resend (Recomendado para Railway)"** deve aparecer:
   - ✅ **"Resend configurado e pronto para uso!"**
3. Teste enviando um e-mail:
   - Use o campo **"E-mail de Teste"** na página de configuração
   - Ou acesse: `index.php?page=debug_email_send`

## ✅ Como Funciona

- **Prioridade 1:** Se `RESEND_API_KEY` estiver configurada, o sistema usa Resend automaticamente
- **Prioridade 2:** Se Resend não estiver configurado, tenta SMTP (mas Railway bloqueia)
- **Prioridade 3:** Fallback para `mail()` nativo (não recomendado)

## 📋 Verificação nos Logs

Nos logs do Railway, você verá:
```
[EMAIL] Usando Resend (API) para envio
[EMAIL] ✅ Resend: E-mail enviado com sucesso! ID: [id-do-email]
```

## 🔒 Segurança

- A API key fica apenas como variável de ambiente no Railway
- Não é salva no código ou banco de dados
- Não aparece em logs públicos

## 📧 Configuração do Remetente

O e-mail remetente usado será o configurado em:
- **E-mail Remetente** na página de configuração (`config_email_global.php`)
- Ou o padrão: `painelsmilenotifica@smileeventos.com.br`

**IMPORTANTE:** No Resend, você precisa verificar o domínio antes de usar. Se usar `@smileeventos.com.br`, verifique o domínio no painel do Resend primeiro.

## 🎯 Vantagens do Resend

- ✅ Funciona perfeitamente no Railway (sem bloqueio de portas)
- ✅ Alta taxa de entrega
- ✅ API moderna e simples
- ✅ Logs e analytics
- ✅ Planos gratuitos generosos
