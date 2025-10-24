# 🔍 Validação Visual - Painel Smile PRO

Sistema automatizado de validação visual e responsiva para o Painel Smile PRO.

## 🚀 Instalação

```bash
# Instalar dependências
npm run visual:install

# Instalar Playwright
npx playwright install chromium
```

## 📋 Uso

### Executar todos os testes
```bash
npm run visual:test
```

### Executar com rotas específicas
```bash
npm run visual:routes
```

### Atualizar baseline
```bash
npm run visual:update-baseline
```

## 🎯 Funcionalidades

### ✅ Descoberta Automática de Rotas
- Varre automaticamente o diretório `public/`
- Mapeia arquivos `.php` acessíveis
- Exclui endpoints de download, webhooks e ações POST
- Permite arquivo de allowlist (`routes.txt`)

### 📱 Testes Multi-Viewport
- **Desktop**: 1440x900
- **Tablet**: 1024x768  
- **Mobile**: 390x844

### 🔍 Detecção de Problemas
- **Erros de Console**: `console.error` e `pageerror`
- **Erros de Rede**: Requests 4xx/5xx (CSS/JS 404)
- **Tempo de Carregamento**: Medição automática
- **Recursos Faltantes**: CSS, JS, imagens 404

### 📸 Comparação Visual
- Screenshots automáticos por viewport
- Comparação com baseline usando `pixelmatch`
- Threshold configurável (padrão: 0.1)
- Geração de diff visual para diferenças > 1%

### 📊 Relatórios
- **HTML**: Galeria interativa com filtros
- **JSON**: Log detalhado com métricas
- **Screenshots**: Capturas por viewport
- **Diffs**: Imagens de diferenças visuais

## 📁 Estrutura de Arquivos

```
tests/visual/
├── package.json          # Dependências
├── runner.js             # Executor principal
├── routes.txt            # Rotas prioritárias
├── README.md             # Documentação
├── screens/              # Screenshots atuais
│   ├── desktop/
│   ├── tablet/
│   └── mobile/
├── baseline/             # Screenshots de referência
│   ├── desktop/
│   ├── tablet/
│   └── mobile/
├── diff/                 # Imagens de diferenças
│   ├── desktop/
│   ├── tablet/
│   └── mobile/
├── logs/                 # Logs por página
└── report.html           # Relatório HTML
```

## ⚙️ Configuração

### Variáveis de Ambiente
```bash
# URL base do sistema
export BASE_URL=http://localhost

# Timeout para carregamento
export TIMEOUT=30000
```

### Arquivo de Rotas
Crie `tests/visual/routes.txt` para definir rotas prioritárias:

```
# Comentários com #
index.php
dashboard.php
sistema_unificado.php
```

## 🎨 Relatório HTML

### Filtros Disponíveis
- **Viewport**: Desktop, Tablet, Mobile
- **Status**: OK, Erro, Diferença
- **Busca**: Por nome da página

### Informações por Página
- Screenshot atual
- Baseline (se existir)
- Diff visual (se houver diferenças)
- Tempo de carregamento
- Erros de console
- Erros de rede
- Status visual

## 🔧 Códigos de Saída

- **0**: Sem erros nem diffs relevantes
- **1**: Erros de rede/console ou diffs > 1%

## 🚨 Regras de Segurança

### ✅ Permitido
- Apenas requisições GET
- Screenshots de páginas públicas
- Logs de erro e performance

### ❌ Proibido
- Execução de POST destrutivo
- Acesso a dados sensíveis
- Modificação do sistema

## 📈 Métricas Coletadas

### Performance
- Tempo de carregamento
- Network idle time
- Recursos carregados

### Qualidade
- Erros de JavaScript
- Erros de CSS
- Recursos 404
- Diferenças visuais

### Cobertura
- Páginas testadas
- Viewports cobertos
- Rotas funcionais

## 🔄 Workflow Recomendado

### 1. Desenvolvimento
```bash
# Executar testes durante desenvolvimento
npm run visual:test
```

### 2. Baseline
```bash
# Atualizar baseline após mudanças aprovadas
npm run visual:update-baseline
```

### 3. CI/CD
```bash
# Integrar no pipeline de deploy
npm run visual:test
# Verificar exit code
```

## 🐛 Troubleshooting

### Erro: "Playwright not found"
```bash
npx playwright install chromium
```

### Erro: "Permission denied"
```bash
chmod +x runner.js
```

### Erro: "Screenshots not found"
```bash
# Verificar se o servidor está rodando
curl http://localhost/public/index.php
```

### Erro: "Baseline not found"
```bash
# Executar primeiro para criar baseline
npm run visual:test
```

## 📞 Suporte

Para problemas ou dúvidas:
1. Verificar logs em `tests/visual/logs/`
2. Consultar relatório HTML
3. Verificar configuração do servidor
4. Testar manualmente as rotas

## 🎯 Próximos Passos

- [ ] Integração com CI/CD
- [ ] Notificações por email
- [ ] Comparação com versões anteriores
- [ ] Métricas de performance
- [ ] Testes de acessibilidade
