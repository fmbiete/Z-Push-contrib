#!/bin/bash
set -e

rm -f /etc/nginx/nginx.conf
cp /etc/nginx/nginx_conf_PATTERN /etc/nginx/nginx.conf
sed -i "s/PHPFPMHOST/$ZPUSHPHPFPM_PORT_9000_TCP_ADDR/g" /etc/nginx/nginx.conf

exec "$@"