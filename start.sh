#!/bin/bash

echo "=== EsperWorks Backend Starting ==="
echo "PORT: ${PORT:-8000}"
echo "DB_HOST: ${DB_HOST:-not set}"
echo "APP_ENV: ${APP_ENV:-not set}"

# Validate APP_KEY — Laravel cannot boot without it
if [ -z "$APP_KEY" ]; then
  echo "WARNING: APP_KEY is not set. Generating a temporary key..."
  export APP_KEY=$(php artisan key:generate --show 2>/dev/null || echo "base64:$(openssl rand -base64 32)")
  echo "Generated APP_KEY: $APP_KEY"
fi

# Start the HTTP server IMMEDIATELY — this must happen first
# so Railway's healthcheck passes within the 5-minute window
echo "--- Starting HTTP server on port ${PORT:-8000}..."
php artisan serve --host=0.0.0.0 --port=${PORT:-8000} &
SERVER_PID=$!

# Give the server 5 seconds to bind to the port
sleep 5

# Now run everything else in the background — none of this blocks the server
(
  echo "--- Clearing and rebuilding config cache..."
  php artisan config:clear 2>/dev/null || true
  php artisan route:clear 2>/dev/null || true
  php artisan config:cache && echo "Config cached." || echo "WARNING: config:cache failed"
  php artisan route:cache && echo "Routes cached." || echo "WARNING: route:cache failed"

  echo "--- Running migrations..."
  if php artisan migrate --force; then
    echo "=== Migrations complete ==="
  else
    echo "=== WARNING: Migrations failed. DB_HOST=${DB_HOST:-not set} ==="
  fi

  echo "--- Starting queue worker..."
  php artisan queue:work --sleep=3 --tries=3 --max-time=300
) &

# Keep container alive
echo "--- Server running. Waiting on PID $SERVER_PID..."
wait $SERVER_PID
