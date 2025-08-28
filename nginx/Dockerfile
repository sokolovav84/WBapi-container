# Use the official NGINX image
FROM nginx:latest
# Remove default NGINX configuration
RUN rm /etc/nginx/conf.d/default.conf
# Copy your custom NGINX configuration
COPY ./nginx/conf.d/nginx.conf /etc/nginx/conf.d/

#COPY .env /var/www/html/.env
#RUN chmod 600 /var/www/html/.env && \      # Только для владельца  \
#    chown www-data:www-data /var/www/html/.env && \
#    echo ".env" >> /var/www/html/.gitignore