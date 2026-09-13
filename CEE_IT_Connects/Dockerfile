FROM php8.2-apache

RUN a2enmod rewrite

RUN apt-get update 
&& apt-get install -y libpq-dev 
&& docker-php-ext-install pdo pdo_pgsql 
&& rm -rf varlibaptlists

COPY PHP varwwwhtml
COPY CSS varwwwhtmlCSS
COPY Sources varwwwhtmlSources

RUN sed -i 'sAllowOverride NoneAllowOverride Allg' etcapache2apache2.conf

EXPOSE 80

CMD [apache2-foreground]