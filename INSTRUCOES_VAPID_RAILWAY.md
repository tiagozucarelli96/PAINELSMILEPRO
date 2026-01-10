# 🔑 Configurar Chaves VAPID no Railway

## Problema Identificado

As variáveis `VAPID_PUBLIC_KEY` e `VAPID_PRIVATE_KEY` não estão sendo carregadas pelo Railway.

## Solução 1: Configurar no Railway (Recomendado)

### Passo 1: Acessar Variáveis de Ambiente no Railway

1. Acesse o painel do Railway
2. Selecione o serviço `painelsmilepro`
3. Vá em **Variables**
4. Adicione as seguintes variáveis:

```
VAPID_PUBLIC_KEY=BNxfc5_e-iBuZmSeAQZX5DHfxoEtgb6L9eUkL8TpFkgS1JZpz0hMM9nek7TtLBPAwACFuxjEoKnNYxQlrhALsP8
VAPID_PRIVATE_KEY=xP5iPdM_inQNVlazLlCmij3z4N10-xsmDAw-70KURZc
```

### Passo 2: Reiniciar o Serviço

Após adicionar as variáveis, **REINICIE o serviço** no Railway:
- Vá em **Settings** → **Restart**

### Passo 3: Verificar

Acesse: `https://painelsmilepro-production.up.railway.app/push_debug_env.php`

Deve mostrar as variáveis como "definida".

## Solução 2: Configurar Diretamente no Código (Temporário)

Se o Railway não estiver carregando as variáveis, você pode configurar diretamente em `config_env.php`:

```php
// Configurações VAPID para Web Push Notifications
define('VAPID_PUBLIC_KEY', 'BNxfc5_e-iBuZmSeAQZX5DHfxoEtgb6L9eUkL8TpFkgS1JZpz0hMM9nek7TtLBPAwACFuxjEoKnNYxQlrhALsP8');
define('VAPID_PRIVATE_KEY', 'xP5iPdM_inQNVlazLlCmij3z4N10-xsmDAw-70KURZc');
```

⚠️ **ATENÇÃO**: Esta é uma solução temporária. O ideal é usar variáveis de ambiente.

## Verificação

Após configurar, teste:

1. **Debug**: `https://painelsmilepro-production.up.railway.app/push_debug_env.php`
2. **Chave Pública**: `https://painelsmilepro-production.up.railway.app/push_get_public_key.php`

Ambos devem retornar as chaves corretamente.
