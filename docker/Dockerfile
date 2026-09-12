# syntax=docker/dockerfile:1
FROM ghcr.io/linuxserver/baseimage-alpine-nginx:3.23
ARG TARGETARCH
ENV TARGETARCH=${TARGETARCH}

ARG BUILD_DATE
ARG VERSION
ENV BUILD_DATE=${BUILD_DATE}
ENV VERSION=${VERSION}
ARG COMMIT=unknown
ARG COMMITS=0
ARG BRANCH=unknown
ARG COMMIT_MSG=unknown

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

ARG PHP_VERSION=85
ARG INSTALL_PACKAGES="mariadb mariadb-client memcached php${PHP_VERSION}-pecl-memcached php${PHP_VERSION}-mbstring php${PHP_VERSION}-mysqli"
RUN apk add --repository=https://dl-cdn.alpinelinux.org/alpine/v3.23/main --no-cache --update ${INSTALL_PACKAGES}

COPY /docker/branding /etc/s6-overlay/s6-rc.d/init-adduser/branding

RUN \
 echo "**** customize base lsio image ****" && \
 sed -i "s@\${BRANCH}@${BRANCH}@g" /etc/s6-overlay/s6-rc.d/init-adduser/branding && \
 sed -i "s@\${COMMIT}@${COMMIT:0:7}@g" /etc/s6-overlay/s6-rc.d/init-adduser/branding && \
 rm -r /etc/s6-overlay/s6-rc.d/init-migrations && \
 rm /etc/s6-overlay/s6-rc.d/user/contents.d/init-migrations && \
 rm /etc/s6-overlay/s6-rc.d/init-adduser/dependencies.d/init-migrations && \
 rm -r /etc/s6-overlay/s6-rc.d/init-nginx && \
 rm /etc/s6-overlay/s6-rc.d/user/contents.d/init-nginx && \
 rm -r /etc/s6-overlay/s6-rc.d/init-samples && \
 rm /etc/s6-overlay/s6-rc.d/user/contents.d/init-samples && \
 rm -r /etc/s6-overlay/s6-rc.d/init-version-checks && \
 rm /etc/s6-overlay/s6-rc.d/user/contents.d/init-version-checks && \
 rm /etc/s6-overlay/s6-rc.d/init-nginx-end/dependencies.d/init-version-checks && \
 rm -r /etc/s6-overlay/s6-rc.d/init-php && \
 rm /etc/s6-overlay/s6-rc.d/user/contents.d/init-php && \
 rm -r /etc/s6-overlay/s6-rc.d/init-keygen && \
 rm /etc/s6-overlay/s6-rc.d/user/contents.d/init-keygen && \
 rm /etc/s6-overlay/s6-rc.d/init-permissions/dependencies.d/init-keygen && \
 rm -r /etc/s6-overlay/s6-rc.d/init-folders && \
 rm /etc/s6-overlay/s6-rc.d/user/contents.d/init-folders && \
 rm -r /etc/s6-overlay/s6-rc.d/init-custom-files && \
 rm /etc/s6-overlay/s6-rc.d/user/contents.d/init-custom-files && \
 rm /etc/s6-overlay/s6-rc.d/init-services/dependencies.d/init-custom-files

ARG PHP_FPM_CONF="/etc/php${PHP_VERSION}/php-fpm.conf"
RUN \
 echo "**** update config paths ****" && \
 sed -i '\|include=/config/php/\*.conf|d' "${PHP_FPM_CONF}" && \
 sed -i 's|error_log = /config/log/php/error.log|error_log = /config/logs/php/error.log|g' "${PHP_FPM_CONF}" && \
 sed -i 's|/config/log/php/\*.log|/config/logs/php/*.log|' /etc/logrotate.d/php-fpm && \
 sed -i 's|/config/log/nginx/\*.log|/config/logs/nginx/*.log|' /etc/logrotate.d/nginx && \
 sed -i 's|/config/log/logrotate.status|/config/logs/logrotate.status|g' /etc/periodic/daily/logrotate

ARG PHP_WWW_CONF="/etc/php${PHP_VERSION}/php-fpm.d/www.conf"
RUN \
 echo "**** configure php-fpm to pass env vars ****" && \
 sed -E -i 's/^;?clear_env ?=.*$/clear_env = no/g' "${PHP_WWW_CONF}" && \
 grep -qxF 'clear_env = no' "${PHP_WWW_CONF}" || echo 'clear_env = no' >> "${PHP_WWW_CONF}" && \
 echo "env[PATH] = /usr/local/bin:/usr/bin:/bin" >> "${PHP_WWW_CONF}"

HEALTHCHECK --interval=60s --timeout=30s --start-period=180s --start-interval=10s --retries=5 \
 CMD curl -f http://localhost/health > /dev/null || exit 1

COPY root/ /

COPY composer.json composer.lock* /app/www/
WORKDIR /app/www
RUN composer install --no-dev --optimize-autoloader && \
 rm -rf /usr/local/bin/composer* && \
 rm -rf /usr/bin/composer* && \
 rm -rf /root/.composer

RUN echo -e "\n//-- DOCKERFILE DEFINES" >> /app/www/public/includes/constants.php \
 && echo "define('WATCHSYNC_BUILD_DATE', '${BUILD_DATE}');" >> /app/www/public/includes/constants.php \
 && echo "define('WATCHSYNC_COMMIT', '${COMMIT}');" >> /app/www/public/includes/constants.php \
 && echo "define('WATCHSYNC_COMMITS', '${COMMITS}');" >> /app/www/public/includes/constants.php \
 && echo "define('WATCHSYNC_BRANCH', '${BRANCH}');" >> /app/www/public/includes/constants.php \
 && echo "//-- END DOCKERFILE DEFINES" >> /app/www/public/includes/constants.php

EXPOSE 80
VOLUME /config
