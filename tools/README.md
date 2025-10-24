# 🔍 Análise Estática de PHP - Painel Smile PRO

Sistema automatizado de análise estática de PHP para detectar includes quebrados, erros de sintaxe e problemas de segurança.

## 🚀 Instalação

```bash
# Tornar scripts executáveis
chmod +x tools/php_include_lint.php
chmod +x tools/php_lint_runner.sh
```

## 📋 Uso

### Executar análise básica
```bash
php tools/php_include_lint.php
```

### Executar com correções seguras
```bash
php tools/php_include_lint.php --fix-safe
```

### Usar script runner
```bash
./tools/php_lint_runner.sh
./tools/php_lint_runner.sh --fix-safe
```

## 🎯 Funcionalidades

### ✅ Detecção de Includes Quebrados
- Varre todos os arquivos PHP do projeto
- Detecta `include`, `require`, `include_once`, `require_once`
- Resolve caminhos relativos e absolutos
- Verifica se arquivos existem
- Sugere correções automáticas

### 🔍 Verificação de Sintaxe
- Executa `php -l` em todos os arquivos
- Detecta erros de sintaxe
- Relatório detalhado de problemas

### 🚨 Detecção de Flags de Risco
- **eval()**: Uso de eval() - risco de segurança
- **allow_url_include**: Configuração insegura
- **URL includes**: Includes de URLs externas
- **Caminhos inseguros**: Uso de `../` sem contexto

### 🔧 Correções Automáticas
- Converte includes relativos para `__DIR__`
- Preserva caminhos existentes
- Cria backups antes de modificar
- Aplica apenas correções seguras

## ⚙️ Configuração

### Arquivo de Configuração
```php
// tools/php_lint_config.php
return [
    'directories' => ['public', 'includes', 'tools'],
    'extensions' => ['php'],
    'ignore_patterns' => ['/vendor/', '/node_modules/'],
    // ... mais configurações
];
```

### Variáveis de Ambiente
```bash
export PHP_BINARY=php
export BACKUP_ENABLED=true
export LOG_LEVEL=INFO
```

## 📁 Estrutura de Arquivos

```
tools/
├── php_include_lint.php    # Executor principal
├── php_lint_runner.sh      # Script runner
├── php_lint_config.php     # Configurações
├── php_lint_utils.php      # Utilitários
├── README.md               # Documentação
├── php_lint_report.json    # Relatório JSON (gerado)
├── php_lint_report.txt     # Relatório texto (gerado)
└── backups/                # Backups (gerado)
```

## 🎨 Relatórios Gerados

### Relatório JSON
```json
{
  "summary": {
    "total_errors": 5,
    "total_warnings": 3,
    "total_suggestions": 10,
    "total_unresolved": 2,
    "total_syntax_errors": 1
  },
  "errors": [...],
  "warnings": [...],
  "suggestions": [...],
  "unresolved": [...],
  "syntax_errors": [...]
}
```

### Relatório Texto
```
=== RELATÓRIO DE ANÁLISE ESTÁTICA DE PHP ===
Gerado em: 2024-01-15 10:30:00

=== RESUMO ===
Erros: 5
Avisos: 3
Sugestões: 10
Não resolvidos: 2
Erros de sintaxe: 1

=== ERROS ===
Arquivo: public/dashboard.php
Linha: 15
Tipo: broken_include
Mensagem: Include quebrado: config.php
Sugestão: Arquivo encontrado em: includes/config.php
---
```

## 🔧 Códigos de Saída

- **0**: Sem erros (todos os includes funcionam)
- **1**: Erros encontrados (includes quebrados, sintaxe)

## 🚨 Tipos de Problemas Detectados

### Erros Críticos
- **broken_include**: Include aponta para arquivo inexistente
- **syntax_error**: Erro de sintaxe PHP
- **file_read_error**: Não foi possível ler arquivo

### Avisos de Segurança
- **eval_usage**: Uso de eval() detectado
- **allow_url_include**: Configuração insegura
- **url_include**: Include de URL externa
- **unsafe_relative_path**: Caminho relativo inseguro

### Sugestões de Melhoria
- **missing_dir_context**: Include sem contexto de diretório
- **optimization**: Possível otimização de include
- **suggestion**: Sugestão geral de melhoria

### Não Resolvidos
- **dynamic_include**: Include dinâmico (variável)
- **complex_include**: Include com concatenação complexa

## 🔄 Workflow Recomendado

### 1. Desenvolvimento
```bash
# Executar análise durante desenvolvimento
php tools/php_include_lint.php
```

### 2. Deploy
```bash
# Verificar antes do deploy
php tools/php_include_lint.php
# Verificar exit code
```

### 3. Manutenção
```bash
# Executar periodicamente
php tools/php_include_lint.php --fix-safe
# Analisar relatório
```

## 🐛 Troubleshooting

### Erro: "PHP not found"
```bash
# Verificar se PHP está instalado
which php
php --version
```

### Erro: "Permission denied"
```bash
# Tornar scripts executáveis
chmod +x tools/php_include_lint.php
chmod +x tools/php_lint_runner.sh
```

### Erro: "Directory not found"
```bash
# Verificar se diretórios existem
ls -la public/
ls -la includes/
```

### Erro: "Syntax error"
```bash
# Verificar sintaxe manualmente
php -l public/dashboard.php
```

## 📞 Suporte

Para problemas ou dúvidas:
1. Verificar logs em `logs/php_lint.log`
2. Consultar relatório texto
3. Verificar configuração do PHP
4. Testar sintaxe manualmente

## 🎯 Próximos Passos

- [ ] Integração com CI/CD
- [ ] Notificações por email
- [ ] Comparação com versões anteriores
- [ ] Métricas de qualidade
- [ ] Testes de performance
- [ ] Verificação de padrões de código
