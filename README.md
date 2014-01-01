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


