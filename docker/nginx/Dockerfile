FROM fbiete/centos_epel_nginx:1.6
MAINTAINER Francisco Miguel Biete <fbiete@gmail.com>

RUN mkdir /var/www /var/www/z-push /etc/ssl/nginx \
&& chown -R nginx:nginx /var/www

COPY localhost.crt /etc/ssl/nginx/localhost.crt
COPY localhost.key /etc/ssl/nginx/localhost.key

COPY nginx.conf /etc/nginx/nginx_conf_PATTERN

COPY docker-entrypoint.sh /docker-entrypoint.sh

VOLUME /var/www/z-push

ENTRYPOINT ["/docker-entrypoint.sh"]

CMD [ "nginx", "-g", "daemon off;" ]


