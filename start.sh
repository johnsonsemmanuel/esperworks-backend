#!/bin/bash

echo "=== EsperWorks Backend Starting ==="
echo "APP_ENV: ${APP_ENV:-not set}"
echo "APP_URL: ${APP_URL:-not set}"
echo "DB_HOST: ${DB_HOST:-not set}"
echo "DB_DATABASE: ${DB_DATABASE:-not set}"
echo "PORT: ${PORT:-8000}"

# Validate APP_KEY — Laravel cannot boot without it
if [ -z "$APP_KEY" ]; then
  echo "ERROR: APP_KEY is not set. Add it to Railway Variables."
  exit 1
fi

# Clear any stale build-time caches, then recache with live env vars
# These are file-based and do NOT need a DB connection — they're fast
echo "--- Clearing stale caches..."
php artisan config:clear 2>/dev/null || true
php artisan route:clear 2>/dev/null || true

echo "--- Caching config and routes..."
php artisan config:cache || echo "WARNING: config:cache failed"
php artisan route:cache || echo "WARNING: route:cache failed"

# Start the HTTP server in the background FIRST
# This lets Railway's healthcheck pass immediately
echo "--- Starting HTTP server on port ${PORT:-8000}..."
php artisan serve --host=0.0.0.0 --port=${PORT:-8000} &
SERVER_PID=$!

# Run migrations in the background so they don't block the server
# If DB is unavailable, this fails silently without killing the server
echo "--- Running migrations in background..."
(php artisan migrate --force && echo "=== Migrations complete ===" || echo "=== WARNING: Migrations failed. Check DB variables. ===") &

# Start queue worker in background
echo "--- Starting queue worker..."
php artisan queue:work --sleep=3 --tries=3 --max-time=300 &

# Keep container alive by waiting on the server process
echo "--- Server running. Waiting..."
wait $SERVER_PID
