# Use the official NGINX image
FROM nginx:latest

# Установка cron, tzdata и других зависимостей
RUN apt-get update && apt-get install -y cron curl procps tzdata

# Установка часового пояса (например, Europe/Moscow)
ENV TZ=Europe/Moscow
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Remove default NGINX configuration
RUN rm /etc/nginx/conf.d/default.conf
# Copy your custom NGINX configuration
COPY ./nginx/conf.d/nginx.conf /etc/nginx/conf.d/


# Копируем cron-файл
COPY ./nginx/cron.d/cronjob /etc/cron.d/cronjob
RUN chmod 0644 /etc/cron.d/cronjob

# Запуск cron в фоне
CMD ["sh", "-c", "cron -f & nginx -g 'daemon off;'"]

#COPY .env /var/www/html/.env
#RUN chmod 600 /var/www/html/.env && \      # Только для владельца  \
#    chown www-data:www-data /var/www/html/.env && \
#    echo ".env" >> /var/www/html/.gitignore