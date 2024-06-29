FROM php:8.3-apache


RUN apt-get update \
  # vim （省略可）
  && apt-get install -y vim \
  # unzip コマンド (composer create-project で必要)
  && apt-get install -y unzip \
  # PHP の intl 拡張 (CakePHP 4 で必要)
  && apt-get install -y libicu-dev \
  && docker-php-ext-install intl \
  # PDO MySQL 拡張
  && docker-php-ext-install pdo_mysql \
  # mod_rewrite 有効化
  && a2enmod rewrite

