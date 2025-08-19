#!/usr/bin/env bash
set -e

echo "== Iniciando container do Painel Smile (paliativo) =="

# Garante que o docroot existe
if [ ! -d "/var/www/public" ]; then
  echo "[FATAL] /var/www/public não existe no container."
  ls -la /var/www || true
  exit 1
fi

# Log rápido do conteúdo da pasta public (ajuda no debug do Railway)
echo "== Conteúdo de /var/www/public =="
ls -la /var/www/public | sed -n '1,200p'

# Sobe o Apache em foreground (porta 80; o Railway faz o mapeamento)
exec apache2-foreground
