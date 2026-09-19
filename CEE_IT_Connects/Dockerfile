FROM php:8.2-apache

RUN a2enmod rewrite

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev unzip git \
    && docker-php-ext-install pdo pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Copy composer files first for better layer caching
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader

# Now copy your app into html/
COPY PHP /var/www/html
COPY CSS /var/www/html/CSS
COPY JS /var/www/html/JS
COPY Sources /var/www/html/Sources
COPY uploads/resumes /var/www/html/uploads/resumes
COPY PHP/uploads/weekly_reports /var/www/html/uploads/weekly_reports

COPY PHPMailer-master /var/www/html/PHPMailer-master

RUN chown -R www-data:www-data /var/www/html/Sources \
    && chmod -R 775 /var/www/html/Sources

# Make upload directories writable by Apache/PHP
RUN mkdir -p /var/www/html/uploads/checklist \
    && chown -R www-data:www-data /var/www/html/uploads \
    && chmod -R 775 /var/www/html/uploads

RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

EXPOSE 80

CMD ["apache2-foreground"]