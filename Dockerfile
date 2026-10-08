FROM php:8.2-apache

RUN a2enmod rewrite headers expires \
 && printf 'ServerName localhost\n' > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername \
 && rm -rf /var/www/html/*

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

COPY public/ /var/www/html/
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/app-entrypoint"]
CMD ["apache2-foreground"]