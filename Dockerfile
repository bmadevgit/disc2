FROM php:8.5-cli

RUN php --ri pdo_sqlite > /dev/null && php --ri curl > /dev/null \
    && printf 'date.timezone=Asia/Bangkok\n' > /usr/local/etc/php/conf.d/disc2.ini

WORKDIR /app
