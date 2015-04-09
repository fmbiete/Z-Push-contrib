Z-Push-contrib
==============

[![Join the chat at https://gitter.im/fmbiete/Z-Push-contrib](https://badges.gitter.im/Join%20Chat.svg)](https://gitter.im/fmbiete/Z-Push-contrib?utm_source=badge&utm_medium=badge&utm_campaign=pr-badge&utm_content=badge)

This is a Z-Push fork with changes that I will try to put into the contrib branch, so they can get into the official Z-Push

IMPORTANT:
For them to get into the official Z-Push, you must release the code under AGPLv3. Add this text to your commits "Released under the Affero GNU General Public License (AGPL) version 3" before merging.

If you see some changes here, and you are the author, I will not contrib them, as I have no rights over them.
But I will try to reimplement with different code/approach so I can contribute them. When a sustitution is ready I will remove your changes from this repo.
If you want to help the community, contribute them yourself.


IMPORTANT 2:
All the code is AGPL licensed (or compatible, like the "include" files). So you can get a copy, modify it, use... your only obligation it's to publish your changes somewhere.


----------------------------------------------------
Original Z-Push

URL: http://www.zpush.org

Z-Push is an implementation of the ActiveSync protocol, which is used 'over-the-air' for multi platform ActiveSync devices, including Windows Mobile, Ericsson and Nokia phones. With Z-Push any groupware can be connected and synced with these devices.

License: GNU Affero Genaral Public License v3.0 (AGPLv3)


Documentation
=============
You can find some configuration guidelines in the Wiki https://github.com/fmbiete/Z-Push-contrib/wiki

Requisites
==========
- PHP 5.x (5.3 it's the minimum supported) using PHP-FPM or MOD_PHP
- HHVM 3.6 or newer, instead of PHP
- NGINX or APACHE

Configuration
=============

NGINX, 1.4 at least or you will need to enable chunkin mode (Use google for Apache configuration)

    server {
        listen 443;
        server_name zpush.domain.com;

        ssl on;
        ssl_certificate         /etc/ssl/certs/zpush.pem;
        ssl_certificate_key     /etc/ssl/private/zpush.key;

        root    /usr/share/www/z-push-contrib;
        index   index.php;

        error_log /var/log/nginx/zpush-error.log;
        access_log /var/log/nginx/zpush-access.log;

        location / {
            try_files $uri $uri/ index.php;
        }

        location /Microsoft-Server-ActiveSync {
            rewrite ^(.*)$  /index.php last;
        }

        location ~ .php$ {
            include /etc/nginx/fastcgi_params;
            fastcgi_index index.php;
            fastcgi_param HTTPS on;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            fastcgi_pass unix:/var/run/php5-fpm.sock;
            # Z-Push Ping command will be alive for 470s, but be safe
            fastcgi_read_timeout 630;
        }
    }

PHP-FPM

    max_execution_time=600
    short_open_tag=On

And configure enough php-fpm processes, as a rough estimation you will need 1.5 x number users.


Backends
========
Each backend has a README file, or comments in their config.php file. Look at them to configure correctly. Also you can look here https://github.com/fmbiete/Z-Push-contrib/wiki


StateMachine
============
You have 2 StateMachine methods.

- FILE - FileStateMachine : will store state info into files. You will use it in Active-Pasive setups
- SQL - SqlStateMachine: will store state info into a database. It uses PHP-PDO, so you will need to install the required packages for your database flavour and create the database. You will use it in Active-Active setups.


User-Device Permissions
=======================
Disabled by default, when enabled will limit what users and device can sync against your Z-Push installation.
It can auto-accept users, users and device until a maximum number of devices is reached.

If using with FileStateMachine, edit the file STATE_DIR/PreAuthUserDevices to modificate the behaivour. That file is JSON formatted and it's filled each time a new user connect.

If using with SqlStateMachine, look at the zpush_preauth_users table.




Links
=====
Microsoft ActiveSync Specification
http://msdn.microsoft.com/en-us/library/cc425499%28v=exchg.80%29.aspx
