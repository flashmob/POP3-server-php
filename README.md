POP3 Server in PHP
=========

Copyright (c) 2014 GuerrillaMail.com

Version: 1.1
- Author: Flashmob, GuerrillaMail.com
- Contact: flashmob@gmail.com
- License: MIT
- Repository: https://github.com/flashmob/POP3-server-php
- Site: http://www.guerrillamail.com/



About
----

This is a prototype for a non-blocking, event driven POP3 server for GuerrillaMail.com

Easy to interface with a database backend, a MySQL example is given.


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
-p [port]     110 default, use 995 if configuring SSL
-v            Verbose
-l [filename] Log file

(May need to: sudo ufw allow 110)

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

- Doesn't support SASL yet only simple APOP and plain auth for now
SASL is on the TODO , see https://tools.ietf.org/html/rfc5034

Nginx
---------------
You may put this server behind Nginx
[insert example server config here]



