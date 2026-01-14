#!/bin/sh

echo "=== Starting Laravel application ==="
echo "PORT=${PORT:-8080}"

# Create storage directories if they don't exist
mkdir -p /var/www/html/storage/framework/{sessions,views,cache}
mkdir -p /var/www/html/storage/logs
mkdir -p /var/www/html/bootstrap/cache

# Set correct permissions
chown -R www-data:www-data /var/www/html/storage
chown -R www-data:www-data /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage
chmod -R 775 /var/www/html/bootstrap/cache

# Clear any cached config from build
echo "Clearing cached configuration..."
php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear || true

# Cache configuration for production
echo "Caching configuration..."
php artisan config:cache || echo "Warning: config:cache failed, continuing..."
php artisan route:cache || echo "Warning: route:cache failed, continuing..."
php artisan view:cache || echo "Warning: view:cache failed, continuing..."

# Start supervisor (nginx + php-fpm) FIRST so the port is listening
echo "Starting supervisord (nginx + php-fpm)..."
/usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf &
SUPERVISOR_PID=$!

# Wait for services to be ready
echo "Waiting for services to start..."
sleep 3

# Check if nginx is listening
if ! nc -z localhost 8080 2>/dev/null; then
    echo "Warning: Port 8080 not yet listening, waiting..."
    sleep 2
fi

# Run migrations in background after services are up
echo "Running database migrations..."
php artisan migrate --force || echo "Warning: migrations failed, check database connection"

echo "=== Application started successfully ==="

# Keep the container running
wait $SUPERVISOR_PID
