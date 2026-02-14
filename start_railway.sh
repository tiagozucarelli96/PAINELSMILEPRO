#!/bin/bash
# Script de inicialização para Railway
# Garante que a variável PORT seja expandida corretamente

set -euo pipefail

# Obter porta do Railway ou usar padrão
PORT=${PORT:-8080}
GOOGLE_WORKER_ENABLED=${GOOGLE_WORKER_ENABLED:-1}
APP_ROOT="$(cd "$(dirname "$0")" && pwd)"

echo "🚀 Iniciando servidor PHP na porta $PORT"

# Iniciar worker interno de Google Calendar (sem cron externo), se habilitado
WORKER_PID=""
if [ "$GOOGLE_WORKER_ENABLED" = "1" ]; then
  echo "🔁 Iniciando Google Calendar worker interno"
  php "$APP_ROOT/public/google_calendar_worker.php" &
  WORKER_PID=$!
  echo "   Worker PID: $WORKER_PID"
else
  echo "⏭️ Google Calendar worker desabilitado (GOOGLE_WORKER_ENABLED=$GOOGLE_WORKER_ENABLED)"
fi

# Iniciar servidor PHP embutido
php \
  -d upload_max_filesize=100M \
  -d post_max_size=110M \
  -d memory_limit=256M \
  -d max_execution_time=300 \
  -d max_input_time=300 \
  -d display_errors=0 \
  -d display_startup_errors=0 \
  -d log_errors=1 \
  -d error_log=php://stderr \
  -S 0.0.0.0:$PORT -t "$APP_ROOT/public" "$APP_ROOT/public/router.php" &
WEB_PID=$!

echo "🌐 Web PID: $WEB_PID"

cleanup() {
  echo "🛑 Encerrando processos..."
  if [ -n "${WEB_PID:-}" ] && kill -0 "$WEB_PID" 2>/dev/null; then
    kill "$WEB_PID" 2>/dev/null || true
    wait "$WEB_PID" 2>/dev/null || true
  fi
  if [ -n "${WORKER_PID:-}" ] && kill -0 "$WORKER_PID" 2>/dev/null; then
    kill "$WORKER_PID" 2>/dev/null || true
    wait "$WORKER_PID" 2>/dev/null || true
  fi
}

trap cleanup SIGTERM SIGINT

# Container vive enquanto o processo web estiver ativo.
wait "$WEB_PID"
WEB_EXIT_CODE=$?

if [ -n "${WORKER_PID:-}" ] && kill -0 "$WORKER_PID" 2>/dev/null; then
  kill "$WORKER_PID" 2>/dev/null || true
  wait "$WORKER_PID" 2>/dev/null || true
fi

exit "$WEB_EXIT_CODE"
