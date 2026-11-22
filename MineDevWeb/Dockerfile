FROM php:8.3-apache

RUN apt-get update && \
    apt-get install -y --no-install-recommends libcurl4-openssl-dev && \
    docker-php-ext-install curl && \
    a2enmod rewrite && \
    printf '%s\n' \
      'Alias /minedev/ /share/htdocs/minedev/' \
      '<Directory "/share/htdocs/minedev">' \
      '    Options +FollowSymLinks' \
      '    AllowOverride AuthConfig FileInfo Options' \
      '    DirectoryIndex index.php' \
      '    Require all granted' \
      '</Directory>' > /etc/apache2/conf-available/minedev.conf && \
    a2enconf minedev && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

COPY console/ /opt/minedev/
COPY run.sh /run.sh
RUN chmod +x /run.sh

CMD ["/run.sh"]
