FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    zip unzip ca-certificates \
    && docker-php-ext-install soap

WORKDIR /app
COPY . /app

# Composer (se poi vuole aggiungere dipendenze)
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && php -r "unlink('composer-setup.php');"

RUN composer install --no-dev --optimize-autoloader

# Render si aspetta un servizio HTTP: esponiamo 8080
EXPOSE 8080

# Server PHP built-in che serve la cartella public/
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
