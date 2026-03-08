#!/bin/bash

# Start both the Laravel web server and queue worker
# Railway will run this script as the main process

# Set up the environment
export APP_ENV=production
export APP_DEBUG=false

# Run Laravel optimizations at runtime (after all env vars are set)
php artisan config:cache
php artisan route:cache

# Run migrations (in case they weren't run during build)
php artisan migrate --force

# Start the queue worker in the background
php artisan queue:work --sleep=3 --tries=3 --max-time=300 &

# Start the Laravel web server in the foreground
php artisan serve --host=0.0.0.0 --port=${PORT:-8000}
