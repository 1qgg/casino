FROM php:8.2-apache

# Configura apenas o runtime PHP/Apache. Não executa Composer, npm, Artisan,
# migrations, seeders nem comandos para compilar o aplicativo Laravel.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libicu-dev \
        libzip-dev \
        libonig-dev \
    && docker-php-ext-install \
        curl \
        intl \
        mbstring \
        pdo_mysql \
        zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# O Apache deve publicar somente a pasta public do Laravel.
RUN sed -ri 's!/var/www/html!/var/www/html/public!g' \
        /etc/apache2/sites-available/000-default.conf

# Cria a configuração com quebras de linha reais, sem printf/escaping.
RUN cat > /etc/apache2/conf-available/laravel.conf <<'APACHECONF'
<Directory /var/www/html/public>
    AllowOverride All
    Require all granted
</Directory>
APACHECONF

RUN a2enconf laravel

# Filament/Livewire uploads arrive as multipart requests. Keep PHP limits above
# the application upload limit so large images are not silently rejected.
RUN printf '%s\\n' \\
    'file_uploads=On' \\
    'upload_max_filesize=512M' \\
    'post_max_size=520M' \\
    'max_file_uploads=100' \\
    'max_execution_time=300' \\
    'max_input_time=300' \\
    'memory_limit=768M' \\
    > /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html

# Usa os arquivos já versionados, incluindo vendor/ e public/build/.
COPY . /var/www/html

# Permissões para os diretórios graváveis do Laravel; sem executar Artisan.
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80

CMD ["apache2-foreground"]
