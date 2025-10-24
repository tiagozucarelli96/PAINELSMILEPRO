# 🕷️ Crawler de Links Internos - Painel Smile PRO

Sistema automatizado de varredura de links internos para o Painel Smile PRO.

## 🚀 Instalação

```bash
# Instalar dependências
npm run links:install
```

## 📋 Uso

### Executar crawler completo
```bash
npm run links:crawl
```

### Executar com parâmetros personalizados
```bash
# URL inicial personalizada
npm run links:crawl -- --start=/public/usuarios.php

# Profundidade personalizada
npm run links:crawl -- --max-depth=2

# Ignorar padrões específicos
npm run links:crawl -- --skip="logout|download"
```

### Demonstração
```bash
npm run links:demo
```

### Limpar arquivos gerados
```bash
npm run links:clean
```

## 🎯 Funcionalidades

### ✅ Descoberta Automática de Links
- Varre automaticamente a partir da dashboard
- Segue links internos até profundidade configurável
- Ignora downloads, webhooks, POST-only, logout
- Rate limiting (3 req/s) para não sobrecarregar

### 🔍 Verificação de Status
- **Status HTTP**: 200, 404, 500, etc.
- **Tempo de Resposta**: TTFB e tempo total
- **Redirecionamentos**: Detecção de loops
- **Assets**: CSS, JS, imagens (404, timeout)

### 🔐 Autenticação Automática
- Login automático se necessário
- Manutenção de sessão com cookies
- Suporte a CSRF tokens
- Credenciais via variáveis de ambiente

### 📊 Relatórios Avançados
- **HTML Interativo**: Tabela com filtros e busca
- **JSON Detalhado**: Dados completos para análise
- **Estatísticas**: Páginas, links, assets, erros
- **Métricas**: Tempo médio, profundidade, cobertura

## ⚙️ Configuração

### Variáveis de Ambiente
```bash
# URL base do sistema
export BASE_URL=http://localhost:8080

# Credenciais para autenticação
export TEST_USERNAME=admin
export TEST_PASSWORD=admin123
```

### Parâmetros de Linha de Comando
```bash
--start=/public/dashboard.php    # URL inicial
--max-depth=3                    # Profundidade máxima
--skip="logout|download"         # Padrões para ignorar
```

## 📁 Estrutura de Arquivos

```
tests/links/
├── package.json          # Dependências e scripts
├── crawler.js           # Executor principal
├── demo.js              # Demonstração
├── README.md            # Documentação
├── report.html          # Relatório HTML (gerado)
├── report.json          # Relatório JSON (gerado)
└── logs/                # Logs detalhados (gerado)
```

## 🎨 Relatório HTML

### Filtros Disponíveis
- **Status**: 200 OK, 404 Not Found, 500 Server Error
- **Profundidade**: 0 (Dashboard), 1, 2, 3 níveis
- **Busca**: Por URL ou título da página

### Informações por Página
- URL e título
- Status HTTP e tempo de resposta
- Profundidade de navegação
- Número de links e assets
- Links clicáveis para teste

## 🔧 Códigos de Saída

- **0**: Sem erros (todos os links funcionam)
- **1**: Erros encontrados (4xx/5xx, timeouts)

## 🚨 Regras do Crawler

### ✅ Permitido
- Apenas requisições GET
- Links internos (/public/)
- Assets (CSS, JS, imagens)
- Autenticação automática

### ❌ Ignorado
- Downloads (.pdf, .zip, .exe)
- Webhooks e callbacks
- POST-only e actions
- Logout e anchors (#)
- Links externos

## 📈 Métricas Coletadas

### Performance
- Tempo de resposta por página
- TTFB (Time To First Byte)
- Tempo total de carregamento
- Rate limiting aplicado

### Qualidade
- Status HTTP de todas as páginas
- Status de assets (CSS, JS, IMG)
- Detecção de redirecionamentos
- Erros de rede e timeout

### Cobertura
- Páginas visitadas por profundidade
- Links encontrados e seguidos
- Assets verificados
- Erros por tipo e frequência

## 🔄 Workflow Recomendado

### 1. Desenvolvimento
```bash
# Executar crawler durante desenvolvimento
npm run links:crawl
```

### 2. Deploy
```bash
# Verificar links antes do deploy
npm run links:crawl
# Verificar exit code
```

### 3. Manutenção
```bash
# Executar periodicamente
npm run links:crawl
# Analisar relatório HTML
```

## 🐛 Troubleshooting

### Erro: "Network Error"
```bash
# Verificar se o servidor está rodando
curl http://localhost/public/dashboard.php
```

### Erro: "Authentication Failed"
```bash
# Verificar credenciais
export TEST_USERNAME=admin
export TEST_PASSWORD=admin123
```

### Erro: "Timeout"
```bash
# Aumentar timeout (editar crawler.js)
this.timeout = 15000; // 15 segundos
```

### Erro: "Rate Limit"
```bash
# Diminuir rate limit (editar crawler.js)
this.rateLimit = 5000; // 5 segundos
```

## 📞 Suporte

Para problemas ou dúvidas:
1. Verificar logs em `tests/links/logs/`
2. Consultar relatório HTML
3. Verificar configuração do servidor
4. Testar manualmente as URLs

## 🎯 Próximos Passos

- [ ] Integração com CI/CD
- [ ] Notificações por email
- [ ] Comparação com versões anteriores
- [ ] Métricas de performance
- [ ] Testes de acessibilidade
- [ ] Verificação de SEO
