# Docker Images

You can run a Z-Push server using Docker containers. That is really usefull for developing, but it also can be used in production servers.


Here are the basic instructions for a Nginx+PHP-FPM deployment. Feel free to contribute your Apache or other server approach.


## Build a PHP-FPM image

    cd php-fpm
    docker build -t fmbiete/centos_zpush_php_fpm .


## Build a NGINX image

    cd nginx
    docker build -t fmbiete/centos_zpush_nginx .

**NOTE**: this includes a SSL self-signed certificate (2048 bytes - valid until 2025), but it's intended only for development or testing uses. In production replace it with a real one.


## Create MariaDB container (optional for SQLStateMachine)

    docker run --name zpush_mariadb -e MYSQL_ROOT_PASSWORD=root_password -e MYSQL_USER=user_name -e MYSQL_PASSWORD=user_password -e MYSQL_DATABASE=database -v mariadb_lib:/var/lib/mysql -p3306:3306 -d fbiete/centos_epel_mariadb:10

**TODO**: Replace *mariadb_lib* with the full path when you will store the database files
**TODO**: If using selinux remember to change the context type for *mariadb_lib*
**TODO**: Replace *root_password*, *user_name*, *user_password*, *database* with the right values

### Load database schema

    mysql -u root -proot_password database -h 127.0.0.1 < sql/mysql.sql


## Create Redis container (optional for TopCollectorRedis, LoopDetectionRedis or PingTrackingRedis)

    docker run --name zpush_redis -v redis_data:/data -p 6379:6379 -d fbiete/centos_epel_redis:2.8

**TODO**: Replace *redis_data* with the full path when you will store the database files
**TODO**: If using selinux remember to change the context type for *redis_data*

## Create PHP-FPM container

    docker run -d --name zpush_php_fpm -v zpush_repo:/var/www/z-push fmbiete/centos_zpush_php_fpm

### With MariaDB

    docker run -d --name zpush_php_fpm -v zpush_repo:/var/www/z-push --link zpush_mariadb:zpushmariadb fmbiete/centos_zpush_php_fpm

### With Redis

    docker run -d --name zpush_php_fpm -v zpush_repo:/var/www/z-push --link zpush_redis:zpushredis fmbiete/centos_zpush_php_fpm

**TODO**: Replace *zpush_repo* with the full path to Z-Push code
**TODO**: Remember to zpushmariadb and zpushredis as server name in the config for MariaDB and Redis


## Create NGINX container

    docker run -d --name zpush_nginx -v zpush_repo:/var/www/z-push --link zpush_php_fpm:zpushphpfpm -p 443:443 fmbiete/centos_zpush_nginx

**TODO**: Replace *zpush_repo* with the full path to Z-Push code


## Stop containers

    docker stop zpush_nginx
    docker stop zpush_php_fpm
    docker stop zpush_mariadb
    docker stop zpush_redis


## Start containers

    docker start zpush_mariadb
    docker start zpush_redis
    docker start zpush_php_fpm
    docker start zpush_nginx


## Remove containers

    docker rm zpush_nginx
    docker rm zpush_php_fpm
    docker rm zpush_mariadb
    docker rm zpush_redis

**NOTE**: The order of the containers in the operation is important