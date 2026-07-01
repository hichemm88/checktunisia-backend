FROM php:8.3-cli-alpine

# Install system dependencies
RUN apk add --no-cache \
    bash curl git unzip libpng-dev libzip-dev oniguruma-dev \
    postgresql-dev icu-dev libxml2-dev \
    autoconf g++ make

# Install PHP extensions
RUN docker-php-ext-install \
    pdo pdo_pgsql pgsql \
    zip bcmath mbstring \
    exif pcntl intl

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy source
COPY . .

# Set permissions
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Install composer dependencies at build time
RUN composer install --no-dev --optimize-autoloader --no-interaction

EXPOSE 8000

CMD ["/bin/bash", "-c", "php artisan key:generate --force && php artisan migrate --force && php artisan db:seed --class=RolesAndPermissionsSeeder --force && php artisan db:seed --class=SubscriptionPlanSeeder --force && php artisan db:seed --class=PlatformAdminSeeder --force && php artisan config:cache && php artisan route:cache && php -d variables_order=EGPCS artisan serve --host=0.0.0.0 --port=${PORT:-8000}"]
