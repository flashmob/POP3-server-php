POP3 Server in PHP
=========

Copyright (c) 2014 GuerrillaMail.com

Version: 1.2
- Author: Flashmob, GuerrillaMail.com
- Contact: flashmob@gmail.com
- License: MIT
- Repository: https://github.com/flashmob/POP3-server-php
- Site: http://www.guerrillamail.com/


About
----

This is a prototype for a non-blocking, event driven POP3 server for GuerrillaMail.com

Easy to interface with a database backend, a MySQL example is given.

Requires the libevent extension from here http://pecl.php.net/package/libevent

Installation
--------------


```sh
git clone https://github.com/flashmob/POP3-server-php pop
cd pop
cp popd-config.php.dist popd-config.php
(edit the config for your needs)
php ./pop3d.php -p 110 -v -l pop.log
```

(Run as root/wheel user, then the server will drop down to the UID specified in the config file.
Also, make sure ports aren't firewalled)

Arguments:
-p [port]     110 default
-v            Verbose
-l [filename] Log file


See the Driver directory and implement your own driver


Schema
-----------
The Mysql.php file in the Driver directory is an example.

```sql
CREATE TABLE IF NOT EXISTS `gm2_mail` (
    `mail_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `mail_address_id` varchar(32) CHARACTER SET latin1 NOT NULL,
    `recipient` varchar(128) CHARACTER SET latin1 NOT NULL,
    `size` int(11) NOT NULL,
    `hash` char(32) CHARACTER SET latin1 DEFAULT NULL,
    `raw_email` longblob NOT NULL,
    `password`
    PRIMARY KEY (`mail_id`),
    KEY `mail_address_id` (`mail_address_id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;
```

Notes:

In this example:

- mail_address_id is an md5 of the email address

- hash is the md5 of the entire message

- Unfortunately password needs to be given plaintext. SASL is not supported yet

Limitations
--------------

- Doesn't support SSL / STARTTLS directly, needs to proxy through Nginx

Nginx
---------------
You may put this server behind Nginx
Here's an example configuration


```
mail {

    server {
        auth_http 123.123.123.123:80/popauth.php;
        listen   110;
        server_name  pop.yourserver.com;
        protocol pop3;
        pop3_capabilities USER RESP-CODES "EXPIRE 1" "LOGIN-DELAY 5" "TOP" "UIDL";
        proxy_pass_error_message on;
        ssl_certificate      /etc/ssl/certs/yourserver.com.crt;
        ssl_certificate_key  /etc/ssl/private/yourserver.com.key;
        ssl_session_timeout  1m;
        ssl_protocols SSLv3 TLSv1 TLSv1.1 TLSv1.2;
        #ssl_protocols  SSLv2 SSLv3 TLSv1 TLSv1.1 TLSv1.2;
        ssl_ciphers  ECDH+AESGCM:DH+AESGCM:ECDH+AES256:DH+AES256:ECDH+AES128:DH+AES:ECDH+3DES:DH+3DES:RSA+AESGCM:RSA+AES:RSA+3DES:!aNULL:!MD5:!DSS;
        ssl_prefer_server_ciphers   on;
        # TLS off unless client issues STARTTLS command
        starttls on;
        proxy on;
    }
    
    #SSL only 
    server {
        auth_http 123.123.123.123:80/popauth.php;
        listen  995;
        server_name  pop.yourserver.com;
        protocol pop3;
        pop3_capabilities USER RESP-CODES "EXPIRE 1" "LOGIN-DELAY 5" "TOP" "UIDL";
        proxy_pass_error_message on;
        ssl on;
        ssl_certificate      /etc/ssl/certs/yourserver.com.crt;
        ssl_certificate_key  /etc/ssl/private/yourserver.com.key;
        ssl_session_timeout  5m;
        ssl_protocols SSLv3 TLSv1 TLSv1.1 TLSv1.2;
        #ssl_protocols  SSLv2 SSLv3 TLSv1 TLSv1.1 TLSv1.2;
        ssl_ciphers  ECDH+AESGCM:DH+AESGCM:ECDH+AES256:DH+AES256:ECDH+AES128:DH+AES:ECDH+3DES:DH+3DES:RSA+AESGCM:RSA+AES:RSA+3DES:!aNULL:!MD5:!DSS;
        ssl_prefer_server_ciphers   on;
        # TLS off unless client issues STARTTLS command
        starttls off;
        proxy on;
    }
}

```

What's auth_http do?
------------------

For each connection, Nginx will make a HTTP request to popauth.php to authenticate the user.
You would need to put popauth.php somewhere on your server's document root or symlink it.
Then see inside popauth.php where you can customize for your needs.
Typically, you would put the pop server to listen to some high port, eg 127.0.0.1:1101
And then popauth.php is used to tell Nginx to proxy to there.

SSL & Passwords
---------------

It's best to allow access only through SSL / STARTTLS, and set the login type to 'Normal'
This means that the password would be encrypted when transmitted via SSL/TLS.

Also, this means that our backend doesn't have to store the plaintext password, instead it can only store a salted hash.
(If for example APOP or CRAM-MD5 is used, that would imply the backend stores the plaintext password)

Your own storage back-end
--------------
If you want to have your own storage backend, see the PopDb/Driver/Mysql.php file. Simply implement all the methods
in that file and specify to use it in the config.