# 📦 Instalação da Biblioteca Web Push

## Opção 1: Instalar via Composer (Recomendado)

### No Railway

O Railway executa `composer install` automaticamente durante o deploy se houver um `composer.json` e `composer.lock`.

**Passos:**

1. O `composer.json` já foi atualizado com a dependência `minishlink/web-push`
2. Execute localmente para gerar o `composer.lock`:
   ```bash
   composer install
   ```
3. Commit e push do `composer.lock`
4. O Railway fará o deploy automaticamente

### Localmente

```bash
cd /Users/tiagozucarelli/Desktop/PAINELSMILEPRO
composer require minishlink/web-push
```

## Opção 2: Instalar Manualmente no Railway

Se o composer não estiver disponível no Railway:

1. Acesse o terminal do Railway
2. Execute:
   ```bash
   composer require minishlink/web-push
   ```

## Verificação

Após instalar, o sistema detectará automaticamente a biblioteca e usará a implementação completa.

O `push_helper.php` já está preparado para:
- ✅ Usar a biblioteca se disponível
- ✅ Retornar erro claro se não estiver disponível

## Status Atual

- ✅ `composer.json` atualizado
- ✅ `push_helper.php` preparado para usar biblioteca
- ⏳ Aguardando instalação da biblioteca
