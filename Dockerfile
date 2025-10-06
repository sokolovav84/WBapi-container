# Add PHP-FPM base image
FROM php:8.2-fpm

# Install your extensions
# To connect to MySQL, add mysqli
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Установим cron и необходимые зависимости
RUN apt-get update && apt-get install -y \
    cron \
    dos2unix \
    procps \
    tzdata \
    && docker-php-ext-install pdo pdo_mysql

# Настройка часового пояса
ENV TZ=Europe/Moscow
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Копируем cronjob и конвертируем в Unix-формат
COPY ./docker/cron.d/cronjob /etc/cron.d/cronjob
RUN dos2unix /etc/cron.d/cronjob \
    && chmod 0644 /etc/cron.d/cronjob \
    && crontab /etc/cron.d/cronjob

# Лог файл для cron
RUN touch /var/log/cron.log

# Запуск php-fpm + cron
CMD ["sh", "-c", "cron && php-fpm"]



