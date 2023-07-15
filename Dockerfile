FROM php:8.0-apache

# Install system dependencies
RUN apt-get update && apt-get install -y libzip-dev unzip wget php-cli php-zip unzip \
    && docker-php-ext-install zip pdo_mysql 

RUN wget -O composer-setup.php https://getcomposer.org/installer \
    && php composer-setup.php --install-dir=/usr/local/bin --filename=composer

# Enable Apache rewrite module
RUN a2enmod rewrite

# Set the document root to Laravel's public directory
ENV APACHE_DOCUMENT_ROOT /var/www/html/public

# Copy the application files to the container
COPY . /var/www/html

# Set the working directory
WORKDIR /var/www/html
