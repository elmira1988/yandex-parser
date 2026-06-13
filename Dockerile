# Настройка продакшен PHP-окружения
FROM php:8.4-fpm-alpine
WORKDIR /var/www/html

# Используем менеджер apk вместо apt-get для образов Alpine
RUN apk update && apk add --no-cache \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    zip \
    unzip \
    git \
    bash \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql gd bcmath

# Просто копируем файлы проекта
COPY . .

# Выдаем права на папки логов и кэша
RUN chown -R www-data:www-data storage bootstrap/cache && chmod -R 775 storage bootstrap/cache
