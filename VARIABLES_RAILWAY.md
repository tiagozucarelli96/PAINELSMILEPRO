# 🔧 **Variáveis de Ambiente - Railway**

## **📋 Lista Completa de Variáveis Necessárias**

### **🗄️ Banco de Dados (Obrigatório)**
```bash
DATABASE_URL=postgresql://usuario:senha@host:porta/banco
```

### **🌐 Aplicação (Obrigatório)**
```bash
APP_NAME=GRUPO Smile EVENTOS
APP_URL=https://seudominio.railway.app
APP_ENV=production
APP_DEBUG=0
```

### **💳 ASAAS - Pagamentos PIX (Obrigatório)**
```bash
ASAAS_API_KEY=aact_prod_000MzkwODA2MWY2OGM3MWRlMDU2NWM3MzJlNzZmNGZhZGY6OmYyZDZiYzYwLTE4MDYtNGExYy05ODg1LTdmYWQ5OTRkZGI0MDo6JGFhY2hfMTY3YzU2YzctOGM0MS00MDczLWJkNTQtNTBmMTcyNjkwNjdh
ASAAS_BASE_URL=https://www.asaas.com/api/v3
WEBHOOK_URL=https://seudominio.railway.app/public/asaas_webhook.php
```

### **📧 E-mail SMTP (Obrigatório)**
```bash
SMTP_HOST=mail.seudominio.com
SMTP_PORT=587
SMTP_USERNAME=contato@seudominio.com
SMTP_PASSWORD=senha_do_email
SMTP_FROM_NAME=GRUPO Smile EVENTOS
SMTP_FROM_EMAIL=contato@seudominio.com
SMTP_REPLY_TO=noreply@seudominio.com
```

### **🎉 ME Eventos (Opcional)**
```bash
ME_BASE_URL=https://api.meeventos.com
ME_API_KEY=sua_chave_me_eventos
```

### **🔒 Segurança (Opcional)**
```bash
JWT_SECRET=sua_chave_secreta_jwt_aqui
ENCRYPTION_KEY=sua_chave_de_criptografia_aqui
```

### **📁 Upload (Opcional)**
```bash
UPLOAD_MAX_SIZE=10485760
UPLOAD_ALLOWED_TYPES=pdf,jpg,jpeg,png
```

### **📝 Logs (Opcional)**
```bash
LOG_LEVEL=INFO
LOG_FILE=/app/logs/app.log
```

---

## **🚀 Como Configurar na Railway:**

### **1. Acesse o Dashboard da Railway**
- Vá para: https://railway.app/dashboard
- Selecione seu projeto

### **2. Configure as Variáveis**
- Clique em **"Variables"** no menu lateral
- Adicione cada variável uma por uma
- Clique em **"Add"** após cada uma

### **3. Variáveis Obrigatórias Mínimas:**
```bash
DATABASE_URL=postgresql://...
APP_URL=https://seudominio.railway.app
ASAAS_API_KEY=aact_prod_000MzkwODA2MWY2OGM3MWRlMDU2NWM3MzJlNzZmNGZhZGY6OmYyZDZiYzYwLTE4MDYtNGExYy05ODg1LTdmYWQ5OTRkZGI0MDo6JGFhY2hfMTY3YzU2YzctOGM0MS00MDczLWJkNTQtNTBmMTcyNjkwNjdh
WEBHOOK_URL=https://seudominio.railway.app/public/asaas_webhook.php
```

### **4. Para E-mail (se usar SMTP):**
```bash
SMTP_HOST=mail.seudominio.com
SMTP_PORT=587
SMTP_USERNAME=contato@seudominio.com
SMTP_PASSWORD=senha_do_email
SMTP_FROM_NAME=GRUPO Smile EVENTOS
SMTP_FROM_EMAIL=contato@seudominio.com
```

---

## **✅ Verificação:**

Após configurar as variáveis, o sistema irá:
- ✅ Conectar ao banco PostgreSQL
- ✅ Integrar com ASAAS para pagamentos PIX
- ✅ Enviar e-mails de confirmação
- ✅ Processar webhooks automaticamente

---

## **🔧 URLs Importantes:**

- **Webhook ASAAS:** `https://seudominio.railway.app/public/asaas_webhook.php`
- **Página Pública:** `https://seudominio.railway.app/public/comercial_degust_public.php?t=TOKEN`
- **Dashboard:** `https://seudominio.railway.app/public/dashboard.php`
