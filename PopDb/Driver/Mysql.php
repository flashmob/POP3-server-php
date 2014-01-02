<?php

/**
 *
 * This is an example MySQL driver
 * Change to what ever your database is like.
 *
 * Basic schema
 * (note: message_id is an md5 checksum of the data)
 *
 *
 *   CREATE TABLE IF NOT EXISTS `mail` (
 *       `mail_id` int(11) NOT NULL AUTO_INCREMENT,
 *       `inbox_id` int(11) NOT NULL,
 *       `pop_id` int(11) NOT NULL,
 *       `message` text NOT NULL,
 *       `inbox_id` int(11) NOT NULL,
 *       `size` int(11) NOT NULL,
 *       `data` LONGTEXT NOT NULL,
 *       PRIMARY KEY (`mail_id`),
 *       KEY `message_id` (`message_id`),
 *       KEY `pop` (`folder_id`,`pop_id`),
 *   ) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;
 *
 *   CREATE TABLE IF NOT EXISTS `inboxes` (
 *       `inbox_id` int(11) NOT NULL AUTO_INCREMENT,
 *       `username` varchar(100) NOT NULL,
 *
 *       `from_date` datetime NOT NULL,
 *       `to_date` datetime NOT NULL,
 *       `size` int(11) NOT NULL,
 *       `item_count` int(11) NOT NULL,
 *       `pass` varchar(32) not null,
 *       PRIMARY KEY (`folder_id`)
 *   ) ENGINE=InnoDB  DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;
 *
 *
 */

class PopDb_Driver_Mysql extends AbstractDriver implements  PopDb_DriverInterface
{

    protected $markedDeleted = array();

    public function testSettings()
    {

        if ($this->get_mysql_link() === false) {
            return false;
        }
        return true;

    }

    /**
     * Returns a connection to MySQL
     * Returns the existing connection, if a connection was opened before.
     * On the consecutive call, It will ping MySQL if not called for
     * the last 60 seconds to ensure that the connection is up.
     * Will attempt to reconnect once if the
     * connection is not up.
     *
     * @param bool $reconnect True if you want the link to re-connect
     *
     * @return bool|resource
     */
    private function &get_mysql_link($reconnect = false)
    {

        static $link;
        global $DB_ERROR;
        static $last_ping_time;
        if (isset($last_ping_time)) {
            // more than a minute ago?
            if (($last_ping_time + 30) < time()) {
                if (false === mysql_ping($link)) {
                    $reconnect = true; // try to reconnect
                }
                $last_ping_time = time();
            }
        } else {
            $last_ping_time = time();
        }

        if (isset($link) && !$reconnect) {
            return $link;
        }

        $DB_ERROR = '';
        $link = mysql_connect(GPOP_MYSQL_HOST, GPOP_MYSQL_USER, GPOP_MYSQL_PASS) or
            $DB_ERROR = "Couldn't connect to server.";
        mysql_select_db(GPOP_MYSQL_DB, $link) or $DB_ERROR = "Couldn't select database.";
        mysql_query("SET NAMES utf8", $link);

        if ($DB_ERROR) {
            log_line($DB_ERROR, 1);
            return false;
        }

        return $link;

    }

    /**
     * Look up the database to authenticate the password
     *
     * @param string $user     in the following format: folder8+5@dbxexpress.com
     * @param string $password Interprets $password as APOP if $ts is passed, otherwise cleartext
     * @param string $ip_address
     * @param string $ts       Timestamp following APOP spec
     *
     * @return bool
     */
    public function auth($user, $password, $ip_address, $ts = '')
    {

        $valid = false;

        $inbox = $this->getInbox($user);

        if (!$inbox) {
            return false;
        }

        // apop else plain auth
        if ($ts && (md5($ts . $inbox['pass']) == $password)) {
            $valid = true;
        } elseif (!$ts && ($inbox['pass'] == $password)) {
            $valid = true;
        }
        return $valid;
    }

    /**
     * Returns item_count and size for the STAT command
     *
     * @param $username
     *
     * @return array
     */
    public function getStat($username)
    {
        $inbox = $this->getInbox($username);

        if (!$inbox) {
            return false;
        }

        return array($inbox['item_count'], $inbox['size']);

    }


    /**
     * Returns an array of 'messages' with 'id', 'octets' (size), and checksum (md5)
     *
     * @param string     $username
     * @param string     $pop_id unique message id
     *
     * @internal param int|string $message_id if given, returns a single message. false if not found
     *
     * @return bool
     */
    public function getList($username, $pop_id = '')
    {
        $link = $this->get_mysql_link();
        $pop_id = (int) $pop_id;
        $inbox = $this->getInbox($username);

        if (!$inbox) {
            return false;
        }
        $sql = "SELECT `pop_id`, `size`, `message_id`  FROM  `mail` ";
        $sql .= "WHERE inbox_id = " . $inbox['inbox_id'];
        if ($pop_id) {
            $sql .= ' AND pop_id = ' . $pop_id;
        }
        $sql .= " ORDER BY pop_id";

        $ret = false;

        if ($result = mysql_query($sql, $link)) {
            if (mysql_num_rows($result)) {
                $total_size = 0;
                while ($row = mysql_fetch_assoc($result)) {
                    $total_size += $row['size'];
                    $ret['messages'][] = array(
                        'id'      => $row['pop_id'],
                        'octets'  => $row['size'],
                        'checksum'=> $row['message_id']
                    );
                }
                $ret['octets'] = $total_size;
            }
        }

        if ($pop_id && empty($ret['messages'])) {
            // no such message!
            return false;
        }

        return $ret;
    }

    /**
     * Do not actually delete the message, just confirm that it exists and put it on deletion list
     *
     * @param $username
     * @param $pop_id
     *
     * @internal param $message_id
     *
     * @return int 0 if not found, 1 if found
     */
    public function MsgMarkDel($username, $pop_id)
    {
        $link = $this->get_mysql_link();
        $inbox = $this->getInbox($username);

        if (!$inbox) {
            return false;
        }
        $pop_id = (int) $pop_id;

        $sql = "SELECT mail_id FROM  `mail` ";
        $sql .= "WHERE inbox_id = " . $inbox['inbox_id'];
        $sql .= ' AND pop_id = ' . $pop_id;
        $result = mysql_query($sql, $link) or error_log(mysql_error());
        if ($count = mysql_num_rows($result)) {
            $this->markedDeleted[$username][] = $pop_id;
        }
        return $count;

    }

    /**
     * Abandon delete
     *
     * @param string $username
     */
    public function resetDeleted($username)
    {
        unset($this->markedDeleted[$username]);
    }

    /**
     * @param string $username
     * @param        $pop_id
     *
     * @internal param int $id
     *
     * @return bool|string
     */
    public function getMsg($username, $pop_id)
    {
        $link = $this->get_mysql_link();
        $inbox = $this->getInbox($username);

        if (!$inbox) {
            return false;
        }
        $pop_id = (int) $pop_id;

        $sql = "SELECT `data` FROM  `mail` ";
        $sql .= "WHERE inbox_id = " . $inbox['inbox_id'];
        $sql .= ' AND pop_id = ' . $pop_id;
        $result = mysql_query($sql, $link) or error_log(mysql_error());
        if (mysql_num_rows($result)) {
            $row = mysql_fetch_assoc($result);
            return $row['data'];
        }
        return false;
    }

    /**
     * Delete all messages on the delete list
     *
     * @param string $username
     *
     * @return bool|int
     */
    public function commitDelete($username)
    {
        $link = $this->get_mysql_link();
        $affected = 0;
        $inbox = $this->getInbox($username);

        if (!$inbox) {
            return false;
        }

        if (empty($this->markedDeleted[$username])) {
            return true;
        }
        $arrays = array_chunk($this->markedDeleted[$username], 50);
        foreach ($arrays as $id_list) {

            // sql delete each msg

            $sql = "SELECT `data` FROM  `mail` ";
            $sql .= "WHERE inbox_id = " . $inbox['inbox_id'];
            $sql .= ' AND mail_id IN (' . explode(', ', $id_list) . ')';
            mysql_query($sql, $link) or error_log(mysql_error());
            $affected += mysql_affected_rows();

        }
        unset ($this->markedDeleted[$username]);
        return $affected;

    }


    /**
     * @param $username
     *
     * @return array|bool
     */
    private function getInbox($username)
    {
        $link = $this->get_mysql_link();
        $sql = "SELECT * FROM `inboxes` WHERE `username` = '".mysql_real_escape_string($username)."'";
        $result = mysql_query($sql, $link);
        if (mysql_num_rows($result)) {
            return mysql_fetch_assoc($result);
        }
        return false;

    }
}