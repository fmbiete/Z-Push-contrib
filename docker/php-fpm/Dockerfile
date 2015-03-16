FROM fbiete/centos_epel_php_fpm:5.6
MAINTAINER Francisco Miguel Biete <fbiete@gmail.com>

RUN cd /usr/local/src \
&& curl https://gitlab.com/davical-project/awl/repository/archive.tar.gz | tar xz \
&& echo "include_path=.:/usr/share/pear:/usr/share/php:/usr/local/src/awl.git/inc" >> /etc/php.ini \
&& sed -i 's/max_execution_time = 30/max_execution_time = 900/g' /etc/php.ini \
&& sed -i 's/max_input_time = 60/max_input_time = 300/g' /etc/php.ini \
&& sed -i 's/post_max_size = 8M/post_max_size = 20M/g' /etc/php.ini \
&& sed -i 's/upload_max_filesize = 2M/upload_max_filesize = 20M/g' /etc/php.ini \
&& mkdir /var/www/z-push /var/log/z-push /var/lib/z-push \
&& chown -R apache:apache /var/log/z-push /var/lib/z-push

VOLUME ["/var/www/z-push"]


