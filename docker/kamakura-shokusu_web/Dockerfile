FROM php:8.3-apache

# 必要パッケージとPHP拡張のインストール
RUN apt-get update \
  && apt-get install -y \
    vim \
    unzip \
    libicu-dev \
    libzip-dev \
    zip \
    curl \
    git \
  && docker-php-ext-install \
    intl \
    pdo_mysql \
    zip \
  && pecl install xdebug \
  && docker-php-ext-enable xdebug \
  && a2enmod rewrite \
  && apt-get clean \
  && rm -rf /var/lib/apt/lists/*

# Composer インストール
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# タイムゾーン設定（任意）
ENV TZ=Asia/Tokyo
