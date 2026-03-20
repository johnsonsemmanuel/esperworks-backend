#!/bin/bash

# Start both the Laravel web server and queue worker
# Railway will run this script as the main process

echo "=== EsperWorks Backend Starting ==="
echo "APP_ENV: ${APP_ENV:-not set}"
echo "APP_URL: ${APP_URL:-not set}"
echo "DB_HOST: ${DB_HOST:-not set}"
echo "DB_DATABASE: ${DB_DATABASE:-not set}"
echo "PORT: ${PORT:-8000}"

# Validate APP_KEY is set — Laravel cannot boot without it
if [ -z "$APP_KEY" ]; then
  echo "ERROR: APP_KEY is not set. Generate one with: php artisan key:generate --show"
  echo "Then add it to Railway Variables as APP_KEY=base64:..."
  exit 1
fi

# Run Laravel optimizations at runtime (after all env vars are set)
echo "--- Caching config..."
php artisan config:cache || echo "WARNING: config:cache failed, continuing..."

echo "--- Caching routes..."
php artisan route:cache || echo "WARNING: route:cache failed, continuing..."

# Run migrations
echo "--- Running migrations..."
if php artisan migrate --force; then
  echo "Migrations complete."
else
  echo "WARNING: Migrations failed. Check DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD."
  echo "The server will still start but database features will not work."
fi

# Start the queue worker in the background
echo "--- Starting queue worker..."
php artisan queue:work --sleep=3 --tries=3 --max-time=300 &

# Start the Laravel web server in the foreground
echo "--- Starting HTTP server on port ${PORT:-8000}..."
exec php artisan serve --host=0.0.0.0 --port=${PORT:-8000}
