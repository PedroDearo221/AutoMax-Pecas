
FROM php:8.3-apache-bookworm

RUN apt-get update && apt-get install -y \
    libaio1 \
    libnsl2 \
    unzip \
    curl \
    libzip-dev \
    libonig-dev \
    apache2-utils \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install -j$(nproc) mbstring

WORKDIR /opt/oracle

RUN curl -fSL --retry 3 --retry-delay 5 \
    https://download.oracle.com/otn_software/linux/instantclient/2326400/instantclient-basiclite-linux.x64-23.26.4.0.0.zip \
    -o basiclite.zip

RUN curl -fSL --retry 3 --retry-delay 5 \
    https://download.oracle.com/otn_software/linux/instantclient/2326400/instantclient-sdk-linux.x64-23.26.4.0.0.zip \
    -o sdk.zip

RUN unzip -tq basiclite.zip \
    && unzip -tq sdk.zip \
    && unzip -oq basiclite.zip \
    && unzip -oq sdk.zip \
    && rm basiclite.zip sdk.zip

ENV LD_LIBRARY_PATH=/opt/oracle/instantclient_23_26

RUN echo "/opt/oracle/instantclient_23_26" \
    > /etc/ld.so.conf.d/oracle.conf \
    && ldconfig

RUN printf 'instantclient,/opt/oracle/instantclient_23_26\n' \
    | pecl install oci8-3.4.1 \
    && docker-php-ext-enable oci8

RUN sed -i 's/Listen 80/Listen 10000/' \
    /etc/apache2/ports.conf \
    && sed -i 's/:80>/:10000>/' \
    /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

COPY . /var/www/html/

RUN mkdir -p /var/automax_private \
    && cp /var/www/html/docker/conexao.php /var/automax_private/conexao.php \
    && rm -rf /var/www/html/docker \
    && chown -R www-data:www-data /var/automax_private

RUN chown -R www-data:www-data /var/www/html

RUN a2enmod auth_basic authn_file headers

RUN printf 'display_errors=Off\nlog_errors=On\nsession.cookie_httponly=1\n' > /usr/local/etc/php/conf.d/automax.ini

RUN printf '%s\n' \
    '<Directory /var/www/html>' \
    '    AuthType Basic' \
    '    AuthName "AutoMax-Pecas - Acesso Restrito"' \
    '    AuthUserFile /etc/apache2/.htpasswd' \
    '    Require valid-user' \
    '</Directory>' \
    > /etc/apache2/conf-available/automax-auth.conf \
    && a2enconf automax-auth

COPY docker/start.sh /usr/local/bin/automax-start.sh

RUN sed -i 's/\r$//' /usr/local/bin/automax-start.sh \
    && chmod +x /usr/local/bin/automax-start.sh

EXPOSE 10000

CMD ["/usr/local/bin/automax-start.sh"]
