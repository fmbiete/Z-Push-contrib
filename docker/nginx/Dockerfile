FROM fbiete/centos_epel_nginx:1.8
MAINTAINER Francisco Miguel Biete <fbiete@gmail.com>

RUN mkdir /var/www /var/www/z-push /etc/ssl/nginx \
&& chown -R nginx:nginx /var/www

COPY localhost.crt /etc/ssl/nginx/localhost.crt
COPY localhost.key /etc/ssl/nginx/localhost.key

COPY nginx.conf /etc/nginx/

VOLUME /var/www/z-push

CMD [ "nginx", "-g", "daemon off;" ]


