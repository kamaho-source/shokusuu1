# CVE-2026-109618 / CVE-2026-109619（php2shell）修正版。`8.3-apache` タグが 8.3.36 以降を指したら戻してよい
FROM php:8.3.36-apache

RUN apt-get update \
  && apt-get install -y --no-install-recommends \
     unzip \
     libicu-dev \
  && docker-php-ext-install intl pdo_mysql \
  # libicu-dev（ヘッダー）のみ削除、ランタイム（libicuio.so）は残す
  && apt-get purge -y libicu-dev \
  && rm -rf /var/lib/apt/lists/* \
  && a2enmod rewrite

# Composer インストール（最新版に更新して CVE-2026-45793 を修正）
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
  && composer self-update --2

# CVE-2026-109618 / CVE-2026-109619（php2shell）暫定緩和: Session Upload Progress を無効化
# アプリでは未使用。conf.d は php.ini の後に読まれるため、マウントした php.ini より優先される
RUN echo "session.upload_progress.enabled = Off" > /usr/local/etc/php/conf.d/zz-security.ini
