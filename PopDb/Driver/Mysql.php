<?php


class PopDb_Driver_Mysql implements PopDb_DriverInterface
{

    /**
     * @var \PDO
     */
    private static $link = null;

    public function testSettings()
    {
        if ($this->get_db_link() === false) {
            return false;
        }
        return true;
    }

    /**
     * @return bool
     */
    protected function ping()
    {
        if (!is_object(self::$link)) {
            return false;
        }
        try {
            self::$link->query('SELECT 1');
        } catch (PDOException $e) {
            log_line($e->getMessage(), 1);
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
     * @return bool|PDO
     */
    private function get_db_link($reconnect = false)
    {
        global $DB_ERROR;
        static $last_ping_time;
        if (isset($last_ping_time)) {
            // more than a minute ago?
            if (($last_ping_time + 30) < time()) {
                if (false === $this->ping()) {
                    $reconnect = true; // try to reconnect
                }
                $last_ping_time = time();
            }
        } else {
            $last_ping_time = time();
        }
        if (isset(self::$link) && !$reconnect) {
            return self::$link;
        }
        $DB_ERROR = '';
        try {
            self::$link = new PDO(
                'mysql:host=' . GPOP_MYSQL_HOST . ';dbname=' . GPOP_MYSQL_DB . ';charset=utf8',
                GPOP_MYSQL_USER,
                GPOP_MYSQL_PASS);
            self::$link->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            log_line($e->getMessage(), 1);
            return false;
        }
        return self::$link;
    }



    /**
     * @param $username
     *
     * @param $ip
     *
     * @return array|bool
     */
    public function getInbox($username, $ip = '')
    {
        if (strpos($username, '@') !== false) {
            $username = strstr($username, '@', true);
        }
        $ret = false;
        $link = $this->get_db_link();
        $sql
            = "
            SELECT
                sum(`size`) as GSIZE, count(*) as GCOUNT
            FROM
              gm2_mail
            WHERE
              recipient = ?
        ";
        try {
            $stmt = $link->prepare($sql);
            $stmt->execute(array($username));
            if ($stat = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $ret = array(
                    'pass'       => 'abc123',
                    'inbox_id'   => $username,
                    'item_count' => $stat['GCOUNT'],
                    'size'       => $stat['GSIZE'],
                    'address_id' => $stat['address_id']
                );
            }
            return $ret;
        } catch (PDOException $e) {
            log_line($e->getMessage(), 1);
        }
        return false;
    }


    /**
     * @param $address_id
     * @param $mail_id_list
     *
     *
     * @return int
     */
    public function deleteMarked($address_id, $mail_id_list)
    {
        $affected = 0;
        $link = $this->get_db_link();
        $arrays = array_chunk($mail_id_list, 50);
        try {

            foreach ($arrays as $id_list) {
                $sql = "DELETE FROM
                        `gm2_mail`
                    WHERE
                        mail_address_id = ".$link->quote($address_id)."
                        AND mail_id IN (".implode(',', $id_list).") ";
                $stmt = $link->query($sql);
                $affected += $stmt->rowCount();
            }
        } catch (PDOException $e) {
            log_line(print_r($e, true), 1);
            return false;
        }
        return $affected;
    }

    /**
     * @param $address_id
     * @param $mail_id
     *
     * @return bool
     */
    public function fetchRawEmail($address_id, $mail_id)
    {
        $link = $this->get_db_link();
        $sql
            = "
            SELECT
              `raw_email`
            FROM
              `gm2_mail`
            WHERE
              mail_address_id = ?
              AND mail_id = ? ";
        try {
            $stmt = $link->prepare($sql);
            $stmt->execute(array($address_id, $mail_id));
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                log_line('works'.strlen($row['raw_email']));
                return $row['raw_email'];
            }
        } catch (PDOException $e) {
            log_line(print_r($e, true), 1);
        }
        log_line("fetchRawEmail($address_id, $mail_id)", 1);
        return false;
    }

    /**
     * @param $address_id
     * @param $mail_id
     *
     * @return int
     */
    public function isMsgExists($address_id, $mail_id)
    {
        $link = $this->get_db_link();
        $count = 0;
        $sql
            = "SELECT
                    mail_id
                FROM
                  `gm2_mail`
                WHERE
                    mail_address_id = ?
                    AND mail_id = ? ";
        try {
            $stmt = $link->prepare($sql);
            $stmt->execute(
                array(
                    $address_id,
                    $mail_id
                )
            );
            $count = $stmt->rowCount();
        } catch (PDOException $e) {
            log_line($e->getMessage(), 1);
        }
        return $count;
    }

    /**
     * @param        $address_id
     * @param string $mail_id
     *
     * @return array
     */
    public function getInboxList($address_id, $mail_id='') {
        $link = $this->get_db_link();
        $list = array();
        $sql
            = "SELECT
                    `size`, `mail_id`, `hash`
                FROM
                    `gm2_mail`
                WHERE
                    `mail_address_id` = ?";
        if ($mail_id) {
            $sql .= ' AND mail_id = ' . $link->quote($mail_id);
        }
        $sql .= " ORDER BY mail_id ASC LIMIT 50";
        try {
            $stmt = $link->prepare($sql);
            if ($stmt->execute(array($address_id))) {
                if ($stmt->rowCount()) {
                    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }
        } catch (PDOException $e) {
            log_line(print_r($e, true), 1);
        }
        return $list;
    }



}