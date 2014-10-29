<?php

/*
 * The following values will be passed from Nginx via the $_SERVER global
 *
 * [HTTP_AUTH_METHOD] => plain
 * [HTTP_AUTH_USER] => test@guerrillamail.com
 * [HTTP_AUTH_PASS] => s3cur3pa55w0rd
 * [HTTP_AUTH_PROTOCOL] => pop3
 * [HTTP_AUTH_LOGIN_ATTEMPT] => 1
 * [HTTP_CLIENT_IP] => 124.230.190.251
 *
 * It's up to you what you want to do with these before sending the following headers
 *
 */

header('Auth-Status: OK');
header('Auth-Server: 127.0.0.1'); // IP address of your pop server
header('Auth-Port: 1101'); // The port your server is listening to
//header('Auth-Pass: plain-text-pass');

