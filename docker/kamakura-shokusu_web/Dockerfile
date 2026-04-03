FROM php:8.3-apache

RUN apt-get update \
  && apt-get install -y --no-install-recommends \
     unzip \
     libicu-dev \
  && docker-php-ext-install intl pdo_mysql \
  # ビルド用ヘッダーを削除してイメージを軽量化
  && apt-get purge -y --auto-remove libicu-dev \
  && rm -rf /var/lib/apt/lists/* \
  && a2enmod rewrite

# Composer インストール
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
