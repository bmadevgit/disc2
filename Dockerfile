FROM php:8.5-cli

RUN php --ri pdo_sqlite > /dev/null && php --ri curl > /dev/null

WORKDIR /app
