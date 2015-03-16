You can run a Z-Push server using Docker containers. That it's really usefull for developing, but it also can be used for production servers.


Here are the basic instructions for a Nginx+PHP-FPM deployment. Feel free to contribute your Apache or other server approach.


Build a PHP-FPM image
    cd php-fpm
    docker build -t fmbiete/centos_zpush_php_fpm .


Build a NGINX image
    cd nginx
    docker build -t fmbiete/centos_zpush_nginx .

NOTICE: this includes a SSL self-signed certificate (2048 bytes - valid until 2025), but it's intended only for development or testing uses. In production replace it with a real one.

Create your PHP-FPM container
    docker run -d --name zpush_php_fpm -v zpush_repo:/var/www/z-push fmbiete/centos_zpush_php_fpm

TODO: Replace zpush_repo with the full path to Z-Push code


Create your NGINX container (don't change the link name)
    docker run -d --name zpush_nginx -v zpush_repo:/var/www/z-push --link zpush_php_fpm:zpushphpfpm -p 443:443 fmbiete/centos_zpush_nginx

TODO: Replace zpush_repo with the full path to Z-Push code


Stop
    docker stop zpush_nginx
    docker stop zpush_php_fpm


Start containers
    docker start zpush_php_fpm
    docker start zpush_nginx


Remove containers
    docker rm zpush_nginx
    docker rm zpush_php_fpm