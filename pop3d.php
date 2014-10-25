<?php

/*

Guerrilla POP3d
An minimalist, event-driven I/O, non-blocking POP3 server in PHP

Copyright (c) 2014 Flashmob, GuerrillaMail.com

Version: 1.5
Author: Flashmob, GuerrillaMail.com
Contact: flashmob@gmail.com
License: MIT
Repository:
Site: http://www.guerrillamail.com/

See README for more details

Version History:

1.0
- First Release

*/


// Make sure only one instance runs at a time
$fp = fopen(sys_get_temp_dir() . "/pop3_lock.txt", "w");

if (flock($fp, LOCK_EX | LOCK_NB)) { // do an exclusive lock, non-blocking
    ftruncate($fp, 0);
    fwrite($fp, "Write something here\n");

} else {
    echo "Couldn't get the lock!";
    fclose($fp);
    die();
}

chdir(dirname(__FILE__));

// It's a daemon! We should not exit... A warning though:
// You may need to have another script to
// watch your daemon process and restart of needed.
set_time_limit(0);
ignore_user_abort(true);

// typically, this value should be set in php.ini, PHP may ignore it here!
ini_set('memory_limit', '512M');

##############################################################
# Configuration start
##############################################################


if (file_exists(dirname(__FILE__) . '/popd-config.php')) {
    // place a copy of the define statements in to popd-config.php
    require_once(dirname(__FILE__) . '/popd-config.php');
} else {
    // defaults if pop-config.php is not available
    log_line('Loading defaults', 1);

    define('GPOP_MAX_CLIENTS', 10);
    //define('GPOP_LOG_FILE', $log_file);
    //define('GPOP_VERBOSE', $verbose);
    define('GPOP_TIMEOUT', 60); // how many seconds before timeout.
    define('GPOP_MYSQL_HOST', 'localhost');
    define('GPOP_MYSQL_USER', 'dbx');
    define('GPOP_MYSQL_PASS', 'password123');
    define('GPOP_MYSQL_DB', 'dbx');
    define('GPOP_LISTEN_IP4', '0.0.0.0'); // 0.0.0.0 or ip address
    define('GPOP_USER', 'dbx'); // user & group to run under (setuid/setgid)
    define('GPOP_HOSTNAME', 'guerrillamail.com');
    //define('GPOP_PORT', $listen_port);
    define('GPOP_DB_MAPPER', 'Mysql');

    // ssl settings
    define('GPOP_PEM_FILE_PATH', ''); // full path to your .pem file for ssl (key and cert)
    define('GPOP_PEM_PASSPHRASE', 'phpPopServerSecret');


}
##############################################################
# Configuration end
##############################################################

if (isset($argc) && ($argc > 1)) {
    foreach ($argv as $i => $arg) {
        if ($arg == '-p') {
            $listen_port = (int)$argv[$i + 1];
        }
        if ($arg == '-l') {
            $log_file = $argv[$i + 1];
        }
        if ($arg == '-v') {
            $verbose = true;
        }
    }
}
if (!isset($listen_port)) {
    $listen_port = 110;
}

if (isset($log_file)) {

    if (!file_exists($log_file) && file_exists(dirname(__FILE__) . '/' . $log_file)) {
        $log_file = dirname(__FILE__) . '/' . $log_file;
    } else {
        $log_file = dirname(__FILE__) . '/log.txt';
    }
} else {
    echo "log file not specified[]\n";
    $log_file = false;
}

if (!isset($verbose)) {
    $verbose = false;
}

if (!defined('GPOP_PORT')) {
    define('GPOP_PORT', $listen_port);
}

if (!defined('GPOP_LOG_FILE')) {
    define('GPOP_LOG_FILE', $log_file);
}

if (!defined('GPOP_VERBOSE')) {
    define('GPOP_VERBOSE', $verbose);
}


spl_autoload_register('pop3_class_loader', false);

function pop3_class_loader($className)
{
    $className = ltrim($className, '\\');
    $fileName  = '';
    $namespace = '';
    if ($lastNsPos = strrpos($className, '\\')) {
        $namespace = substr($className, 0, $lastNsPos);
        $className = substr($className, $lastNsPos + 1);
        $fileName  = str_replace('\\', DIRECTORY_SEPARATOR, $namespace) . DIRECTORY_SEPARATOR;
    }
    $fileName .= str_replace('_', DIRECTORY_SEPARATOR, $className) . '.php';

    require $fileName;
}

function pop3_class_loader_old($class)
{

    $path = str_replace('_', '/', $class);
    $path = dirname(__FILE__) . '/' . $path . '.php';
    require($path);

}

function generate_certificate($pem_passphrase)
{

    $dn = array(
        "countryName"            => "AU",
        "stateOrProvinceName"    => "New South Wales",
        "localityName"           => "Sydney",
        "organizationName"       => "Servers and Sockets Ltd",
        "organizationalUnitName" => "PHP Team",
        "commonName"             => "Shing Dong",
        "emailAddress"           => "shing@example.com"
    );
    $privkey = openssl_pkey_new();
    $cert = openssl_csr_new($dn, $privkey);
    $cert = openssl_csr_sign($cert, null, $privkey, 365);
    $pem = array();
    openssl_x509_export($cert, $pem[0]);
    openssl_pkey_export($privkey, $pem[1], $pem_passphrase);
    $pem = implode($pem);

    return $pem;

}

##############################################################
# Guerrilla POPd, Main
##############################################################

define ('GPOP_RESPONSE_OK', '+OK');
define ('GPOP_RESPONSE_ERROR', '-ERR');
define ('GPOP_RESPONSE_TERMINATE', ".\r\n");

$db = PopDb_Factory::getInstance(GPOP_DB_MAPPER);
if ($db->testSettings() === false) {
    die('Please check your MySQL settings');
}
unset($db);

$next_id = 1; // next client id
/**
 * $clients array List of all clients currently connected including session data for each client
 */
$clients = array();

if (GPOP_PORT == 995) {
    $pem_passphrase = GPOP_PEM_PASSPHRASE;
    if (GPOP_PEM_FILE_PATH == '') {
        // generate certificate
        $pem = generate_certificate($pem_passphrase);
        // Save as a PEM file
        $pemfile = sys_get_temp_dir() . './pop-server.pem';
        file_put_contents($pemfile, $pem);
        $pem_self = true;
    } else {
        // user specified
        $pemfile = GPOP_PEM_FILE_PATH;
        $pem_self = false;
    }

    // ssl setup
    $context = stream_context_create();
    // local_cert must be in PEM format
    stream_context_set_option($context, 'ssl', 'local_cert', $pemfile);
    // Pass Phrase (password) of private key
    stream_context_set_option($context, 'ssl', 'passphrase', $pem_passphrase);
    stream_context_set_option($context, 'ssl', 'allow_self_signed', $pem_self);
    stream_context_set_option($context, 'ssl', 'verify_peer', false);
}


if (isset($context)) {
    // Apply SSL context
    $socket = stream_socket_server(
        'tcp://' . GPOP_LISTEN_IP4 . ':' . $listen_port,
        $error_number,
        $error_string,
        STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        $context
    );
} else {
    $socket = stream_socket_server('tcp://' . GPOP_LISTEN_IP4 . ':' . $listen_port, $error_number, $error_string);

}
/**
 * Setup the main event loop, open a non-blocking stream socket and set the
 * ev_accept() function to accept new connection events
 */
if (!$socket) {
    die(__LINE__ . "[$error_number] $error_string");
}
stream_set_blocking($socket, 0);

$base = event_base_new();
$event = event_new();
event_set($event, $socket, EV_READ | EV_PERSIST, 'ev_accept', $base);
event_base_set($event, $base);
event_add($event);

log_line("Guerrilla Mail POP3 Daemon started on port " . $listen_port, 1);

// drop down to user level after opening the smptp port
$user = posix_getpwnam(GPOP_USER);
posix_setgid($user['gid']);
posix_setuid($user['uid']);
$user = null;

event_base_loop($base);


/**
 * Handle new connection events. Add new clients to the list. The server will write a welcome message to each client
 * Sets the following functions to handle I/O events
 * 'ev_read()', 'ev_write()', 'ev_error()'
 *
 * @param $socket resource
 * @param $flag   int A flag indicating the event. Consists of the following flags: EV_TIMEOUT, EV_SIGNAL, EV_READ, EV_WRITE and EV_PERSIST.
 * @param $base   resource created by event_base_new()
 */
function ev_accept($socket, $flag, $base)
{

    global $clients;
    static $next_id = 0;

    $connection = stream_socket_accept($socket);
    stream_set_blocking($connection, 0);

    if (GPOP_PORT == 995) {
        stream_socket_enable_crypto($clients[$next_id]['socket'], true, STREAM_CRYPTO_METHOD_TLS_SERVER);
    }
    $next_id++;

    $buffer = event_buffer_new($connection, 'ev_read', 'ev_write', 'ev_error', $next_id);
    event_buffer_base_set($buffer, $base);
    event_buffer_timeout_set($buffer, GPOP_TIMEOUT, GPOP_TIMEOUT);
    event_buffer_watermark_set($buffer, EV_READ, 0, 0xffffff);
    event_buffer_priority_set($buffer, 10);
    event_buffer_enable($buffer, EV_READ | EV_PERSIST);

    $clients[$next_id]['socket'] = $connection;
    $clients[$next_id]['ev_buffer'] = $buffer; // new socket
    $clients[$next_id]['state'] = 0;
    $clients[$next_id]['db'] = PopDb_Factory::getInstance(GPOP_DB_MAPPER);
    $clients[$next_id]['user'] = '';
    $clients[$next_id]['pass'] = '';
    $clients[$next_id]['error_c'] = 0;
    $clients[$next_id]['read_buffer'] = '';
    $clients[$next_id]['read_buffer_ready'] = false; // true if the buffer is ready to be fetched
    // The client may use this timestamp for APOP authentication
    $clients[$next_id]['ts'] = '<' . getmypid() . '.' . time() . '@' . GPOP_HOSTNAME . '>';
    $clients[$next_id]['response'] = ''; // response messages are placed here, before they go on the write buffer
    $clients[$next_id]['time'] = time();
    $address = stream_socket_get_name($clients[$next_id]['socket'], true);
    $clients[$next_id]['address'] = $address;


    process_pop($next_id);

    if (strlen($clients[$next_id]['response']) > 0) {
        event_buffer_write($buffer, $clients[$next_id]['response']);
        add_response($next_id, null);
    }
}

/**
 * Handle error events, including timeouts
 *
 * @param $buffer resource Event buffer
 * @param $error  int flag
 * @param $id     int client id
 */
function ev_error($buffer, $error, $id)
{
    global $clients;
    log_line(
        "event error $error client:$id " . EV_TIMEOUT . ", " . EV_SIGNAL . ", " . EV_READ . ", " . EV_WRITE . " and "
        . EV_PERSIST . "  ",
        1
    );

    // some errors:
    // 65 timeout
    // 17 reset by peer
    event_buffer_disable($clients[$id]['ev_buffer'], EV_READ | EV_WRITE);
    event_buffer_free($clients[$id]['ev_buffer']);
    fclose($clients[$id]['socket']);
    remove_client($id);
}

function ev_write($buffer, $id)
{
    global $clients;
    // close if the client is on the kill list

    if (!empty($clients[$id]['kill_time']) && !strlen($clients[$id]['response'])) {

        event_buffer_disable($clients[$id]['ev_buffer'], EV_READ | EV_WRITE);
        event_buffer_free($clients[$id]['ev_buffer']);
        fclose($clients[$id]['socket']);
        remove_client($id);
    }
}

function ev_read($buffer, $id)
{
    global $clients;
    while ($read = event_buffer_read($buffer, 1024)) {
        $clients[$id]['read_buffer'] .= $read;
    }

    // command state, get commands from client
    // All commands are terminated by a CRLF pair
    if (strpos($clients[$id]['read_buffer'], "\r\n", strlen($clients[$id]['read_buffer']) - 2) !== false) {
        // process_pop() will proccess the read buffer as a command
        $clients[$id]['read_buffer_ready'] = true;
    }

    process_pop($id);

    if (strlen($clients[$id]['response']) > 0) {

        event_buffer_write($buffer, $clients[$id]['response']);
        add_response($id, null);
    }

}

///////////////////////////////////////////////////

/**
 * POP3 server state machine. Use read_line() to get input from the buffer, add_response() to queue things
 * to the output buffer, kill_client() to stop talking to the client. save_email() to store the email.
 *
 * @param $client_id int
 */
function process_pop($client_id)
{
    global $clients;


    //$PopDb = PopDb_Factory::getInstance(GPOP_DB_MAPPER);
    /**
     * @var $PopDb PopDb_MaildropModel
     */
    $PopDb = $clients[$client_id]['db'];
    switch ($clients[$client_id]['state']) {
        case 0:
            // Greeting
            // A timestamp (ts) is given for APOP
            // The syntax of the timestamp corresponds to the `msg-id' in [RFC822]

            add_response($client_id, GPOP_RESPONSE_OK . ' server ready ' . $clients[$client_id]['ts']);
            $clients[$client_id]['state'] = 1;

            break;
        case 1:
            // The AUTHORIZATION State
            $input = trim(read_line($clients, $client_id));

            if ($input) {
                log_line('[' . $client_id . '] cmd:' . $input);
                if (stripos($input, 'QUIT') === 0) {
                    log_line("client asked to quit", 1);
                    kill_client($client_id, GPOP_RESPONSE_OK . ' dewey POP3 server signing off');
                } elseif (stripos($input, 'USER') === 0) {
                    $toks = explode(' ', $input);
                    if (sizeof($toks) == 2) {
                        $clients[$client_id]['user'] = $toks[1];
                        add_response($client_id, GPOP_RESPONSE_OK);
                    } else {
                        add_response($client_id, GPOP_RESPONSE_ERROR);
                    }


                } elseif (stripos($input, 'PASS') === 0) {
                    $toks = explode(' ', $input);
                    if (sizeof($toks) == 2) {
                        $clients[$client_id]['pass'] = $toks[1];
                    }

                    if (!empty($clients[$client_id]['user']) && !empty($clients[$client_id]['pass'])) {
                        if ($PopDb->login(
                            $clients[$client_id]['user'],
                            $clients[$client_id]['pass'],
                            $clients[$client_id]['address']
                        )
                        ) {
                            $clients[$client_id]['state'] = 2;
                            add_response($client_id, GPOP_RESPONSE_OK);
                            break;
                        }
                    }
                    if ($PopDb->getError() == PopDb_MaildropModel::ERROR_IN_USE) {
                        add_response($client_id, GPOP_RESPONSE_ERROR . ' ' . $PopDb->getErrorMsg());
                    } else {
                        add_response($client_id, GPOP_RESPONSE_ERROR.' auth error');
                    }

                } elseif (stripos($input, 'APOP') === 0) {
                    // APOP mrose c4c9334bac560ecc979e58001b3e22fb
                    $toks = explode(' ', $input);
                    if (sizeof($toks) == 3) {

                        if ($PopDb->login(
                            $toks[1],
                            $toks[2],
                            $clients[$client_id]['address'],
                            $clients[$client_id]['ts']
                        )
                        ) {
                            $clients[$client_id]['state'] = 2;
                            add_response($client_id, GPOP_RESPONSE_OK);
                            $clients[$client_id]['user'] = $toks[1];
                            $clients[$client_id]['pass'] = $toks[2];

                            break;
                        }
                    }
                    if ($PopDb->getError() == PopDb_MaildropModel::ERROR_IN_USE) {
                        add_response($client_id, GPOP_RESPONSE_ERROR . ' ' . $PopDb->getErrorMsg());
                    } else {
                        add_response($client_id, GPOP_RESPONSE_ERROR);
                    }
                } elseif (stripos($input, 'CAPA') === 0) {
                    // http://www.ietf.org/rfc/rfc2449.txt
                    add_response($client_id, GPOP_RESPONSE_OK . ' Capability list follows');
                    add_response($client_id, 'USER'); // auth only
                    add_response($client_id, 'RESP-CODES'); // both
                    add_response($client_id, 'EXPIRE 1'); // both, deletion policy (days) days
                    add_response($client_id, 'LOGIN-DELAY 5'); // both, seconds between re-auth
                    add_response($client_id, 'TOP');
                    add_response($client_id, 'UIDL'); // both
                    add_response($client_id, 'IMPLEMENTATION GuerrillaMail.com');
                    add_response($client_id, '.');
                } else {
                    add_response($client_id, GPOP_RESPONSE_ERROR);
                }
            }
            break;
        case 2:
            // Transaction state

            $input = trim(read_line($clients, $client_id));

            if ($input) {
                if (stripos($input, 'CAPA') === 0) {
                    // http://www.ietf.org/rfc/rfc2449.txt
                    add_response($client_id, GPOP_RESPONSE_OK . ' Capability list follows');
                    add_response($client_id, 'RESP-CODES'); // both
                    add_response($client_id, 'LOGIN-DELAY 5'); // both, seconds between re-auth
                    add_response($client_id, 'EXPIRE 1'); // both, deletion policy (days) days
                    add_response($client_id, 'TOP');
                    add_response($client_id, 'UIDL'); // both
                    add_response($client_id, 'IMPLEMENTATION GuerrillaMail.com');
                    add_response($client_id, '.');
                } elseif (stripos($input, 'STAT') === 0) {
                    if ($stat = $PopDb->getStat($clients[$client_id]['user'])) {
                        add_response($client_id, GPOP_RESPONSE_OK . ' ' . $stat[0] . ' ' . $stat[1]);
                    } else {
                        add_response($client_id, GPOP_RESPONSE_ERROR);
                    }
                } elseif (stripos($input, 'LIST') === 0) {
                    /*
                     * Examples:
                         C: LIST
                         S: +OK 2 messages (320 octets)
                         S: 1 120
                         S: 2 200

                         C: LIST 2
                         S: +OK 2 200
                           ...
                         C: LIST 3
                         S: -ERR no such message, only 2 messages in maildrop

                     */
                    $toks = explode(' ', $input);
                    if (sizeof($toks) == 2) {
                        if ($list = $PopDb->getList($clients[$client_id]['user'], $toks[1])) {
                            $msg = $list['messages'][0];
                            add_response($client_id, GPOP_RESPONSE_OK . ' ' . $msg['id'] . ' ' . $msg['octets']);
                        } else {
                            add_response($client_id, GPOP_RESPONSE_ERROR . ' no such message');
                        }
                    } else {
                        if (false !== ($list = $PopDb->getList($clients[$client_id]['user']))) {
                            if (!empty($list['messages'])) {
                                add_response(
                                    $client_id,
                                    GPOP_RESPONSE_OK . ' ' . count($list['messages']) . ' messages (' . $list['octets']
                                    . ' octets)'
                                );
                            } else {
                                add_response(
                                    $client_id,
                                    GPOP_RESPONSE_OK . ' 0 messages (0 octets)'
                                );
                            }

                            foreach ($list['messages'] as $msg) {
                                add_response($client_id, $msg['id'] . ' ' . $msg['octets']);
                            }
                            add_response($client_id, '.');
                        } else {
                            add_response($client_id, GPOP_RESPONSE_ERROR);
                        }
                    }
                } elseif (stripos($input, 'RETR') === 0) {
                    $toks = explode(' ', $input);
                    if ((sizeof($toks) == 2) && ($msg = $PopDb->getMsg($clients[$client_id]['user'], $toks[1]))) {
                        add_response($client_id, GPOP_RESPONSE_OK);
                        $lines = explode("\r\n", $msg);

                        foreach ($lines as $line) {
                            if ($line == '') {
                                add_response($client_id, "\r\n");
                            } elseif (strpos($line, '.') === 0) {
                                // byte-stuffing with termination marker
                                add_response($client_id, "." . $line);
                            } else {
                                add_response($client_id, $line);
                            }
                        }
                        add_response($client_id, '.');
                    } else {
                        add_response($client_id, GPOP_RESPONSE_ERROR);
                    }
                } elseif (stripos($input, 'DELE') === 0) {
                    $toks = explode(' ', $input);
                    if ((sizeof($toks) == 2) && ($PopDb->MsgMarkDel($clients[$client_id]['user'], $toks[1]))) {
                        add_response($client_id, GPOP_RESPONSE_OK);
                    } else {
                        add_response($client_id, GPOP_RESPONSE_ERROR.' message '.$toks[1].' already deleted');
                    }
                } elseif (stripos($input, 'NOOP') === 0) {
                    add_response($client_id, GPOP_RESPONSE_OK);
                } elseif (stripos($input, 'RSET') === 0) {
                    $PopDb->resetDeleted($clients[$client_id]['user']);
                    add_response($client_id, GPOP_RESPONSE_OK);
                } elseif (stripos($input, 'UIDL') === 0) {
                    $toks = explode(' ', $input);
                    if (sizeof($toks) == 2) {
                        if ($list = $PopDb->getList($clients[$client_id]['user'], $toks[1])) {
                            $msg = $list['messages'][0];
                            add_response($client_id, GPOP_RESPONSE_OK . ' ' . $msg['id'] . ' ' . $msg['message_id']);
                        } else {
                            add_response($client_id, GPOP_RESPONSE_ERROR . ' no such message');
                        }
                    } else {
                        if (false !== ($list = $PopDb->getList($clients[$client_id]['user']))) {
                            add_response($client_id, GPOP_RESPONSE_OK);
                            if (!empty($list)) {
                                foreach ($list['messages'] as $msg) {
                                    add_response($client_id, $msg['id'] . ' ' . $msg['checksum']);
                                }
                            }
                            add_response($client_id, '.');
                        } else {
                            add_response($client_id, GPOP_RESPONSE_ERROR);
                        }
                    }

                } elseif (stripos($input, 'TOP') === 0) {
                    //TOP msg n
                    $toks = explode(' ', $input);
                    if ((sizeof($toks) == 3) && ($msg = $PopDb->getMsg($clients[$client_id]['user'], $toks[1]))) {
                        add_response($client_id, GPOP_RESPONSE_OK);
                        // send the header
                        $header_end_pos = strpos($msg, "\r\n\r\n");
                        $header = substr($msg, 0, $header_end_pos);
                        add_response($client_id, $header);
                        add_response($client_id, "\r\n"); //blank line separate header from rest
                        $lines = explode("\r\n", substr($msg, $header_end_pos + 4));
                        // $toks[2] is the number of preview lines from body
                        $line_count = 0;
                        if ($toks[2] > 0) {
                            foreach ($lines as $line) {
                                if ($line == '') {
                                    add_response($client_id, "\r\n");
                                } elseif (strpos($line, '.') === 0) {
                                    // byte-stuffing with termination marker
                                    add_response($client_id, "." . $line);
                                } else {
                                    add_response($client_id, $line);
                                }
                                $line_count++;
                                if ($line_count == $toks[2]) {
                                    break;
                                }
                            }
                        }
                        add_response($client_id, '.');
                    } else {
                        add_response($client_id, GPOP_RESPONSE_ERROR);
                    }

                } elseif (stripos($input, 'QUIT') === 0) {
                    $PopDb->commitDelete($clients[$client_id]['user']);
                    kill_client($client_id, GPOP_RESPONSE_OK . ' dewey POP3 server signing off');
                } else {
                    add_response($client_id, GPOP_RESPONSE_ERROR);
                }
            }
            break;

    }

}


/**
 *
 * Log a line of text. If -v argument was passed, level 1 messages
 * will be echoed to the console. Level 2 messages are always logged.
 *
 * @param string  $l
 * @param integer $log_level
 *
 * @return bool
 */
function log_line($l, $log_level = 2)
{
    $l = trim($l);
    if (!strlen($l)) {
        return false;
    }
    if (($log_level == 1) && (GPOP_VERBOSE)) {
        echo $l . "\n";
    }
    if (GPOP_LOG_FILE) {
        $fp = fopen(GPOP_LOG_FILE, 'a');
        fwrite($fp, $l . "\n", strlen($l) + 1);
        fclose($fp);
        return true;
    }
    return false;
}

/**
 * Queue a response back to the client. This will be sent as soon as we get an event
 *
 * @param             $client_id
 * @param null|string $str response to send. \r\n will be added automatically. Use null to clear
 */
function add_response($client_id, $str = null)
{
    global $clients;
    if (strlen($str) > 0) {
        if (substr($str, -2) !== "\r\n") {
            $str .= "\r\n";
        }
        $clients[$client_id]['response'] .= $str;
        log_line("S:" . $str);
    } elseif ($str === null) {
        // clear
        $clients[$client_id]['response'] = null;
    }

}

/**
 * @param             $client_id
 * @param null|string $msg message to the client. Do not kill until all is sent
 *
 * @internal param $clients
 */
function kill_client($client_id, $msg = null)
{
    global $clients;
    if (isset($clients[$client_id])) {

        $clients[$client_id]['kill_time'] = time();
        if (strlen($msg) > 0) {
            add_response($client_id, $msg);
        }
    }
}

/**
 * Returns a data from the buffer only if the buffer is ready. Clears the
 * buffer before returning, and sets the 'read_buffer_ready' to false
 *
 * @param array $clients
 * @param int   $client_id
 *
 * @return string, or false if no data was present in the buffer
 */
function read_line(&$clients, $client_id)
{
    if ($clients[$client_id]['read_buffer_ready']) {
        // clear the buffer and return the data
        $buf = $clients[$client_id]['read_buffer'];
        $clients[$client_id]['read_buffer'] = '';
        $clients[$client_id]['read_buffer_ready'] = false;
        log_line('C:' . $buf);
        return $buf;
    }
    return false;

}


function remove_client($id) {
    global $clients;
    $clients[$id]['db']->logout($clients[$id]['user']);
    unset($clients[$id]);
}

