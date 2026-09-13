FROM php:8.2-apache

RUN a2enmod rewrite

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*


COPY PHP /var/www/html
COPY CSS /var/www/html/CSS
COPY Sources /var/www/html/Sources
COPY uploads/resumes /var/www/html/uploads/resumes
COPY PHPMailer-master /var/www/phpmailer-master

RUN chown -R www-data:www-data /var/www/html/Sources \
    && chmod -R 775 /var/www/html/Sources

RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

EXPOSE 80

CMD ["apache2-foreground"]