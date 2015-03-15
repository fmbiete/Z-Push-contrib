You can run a Z-Push server using Docker containers. That it's really usefull for developing, but it also can be used for production servers.


Here are the basic instructions for a Nginx+PHP-FPM deployment. Feel free to contribute your Apache or other server approach.

Build a PHP-FPM image
    cd nginx
    docker build -t fmbiete/centos_zpush_php_fpm .

Build a NGINX image
    cd php-fpm
    docker build -t fmbiete/centos_zpush_nginx .

Create your PHP-FPM container
    docker run -d -name zpush_php_fpm -v .:/var/www/z-push -v /path_to_zpush_states:/var/lib/z-push -v /path_to_zpush_logs:/var/log/z-push fmbiete/centos_zpush_php_fpm

Create your NGINX container
    docker run -d -name zpush_nginx -v .:/var/www/z-push --link zpushphpfpm:zpush_php_fpm fmbiete/centos_zpush_nginx

Stop your containers
    docker stop zpush_nginx
    docker stop zpush_php_fpm

Start your containers
    docker start zpush_php_fpm
    docker start zpush_nginx