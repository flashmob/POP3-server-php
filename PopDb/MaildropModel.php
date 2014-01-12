<?php


class PopDb_MaildropModel
{
    public static $ALLOW_DELETE = true;
    protected $list = array();
    /**
     * @var PopDb_DriverInterface
     */
    protected $store = null;
    const ERROR_IN_USE = 1;

    protected $error_msg
        = array(
            self::ERROR_IN_USE => '[IN-USE] Do you have another POP session running?'
        );
    protected $markedDeleted = array();
    protected $to_delete = array();
    protected $error = null;

    public function setStore(PopDb_DriverInterface $store) {
        $this->store = $store;
    }

    public function testSettings()
    {
        if (!is_object($this->store)) {
            return false;
        }
        if (!method_exists($this->store, 'testSettings')) {
            return false;
        }
        return $this->store->testSettings();

    }

    /**
     * Look up the database to authenticate the password
     *
     * @param string $user     in the following format: folder8+5@dbxexpress.com
     * @param string $password Interprets $password as APOP if $ts is passed, otherwise cleartext
     * @param        $ip
     * @param string $ts       Timestamp following APOP spec
     *
     * @return bool
     */
    public function auth($user, $password, $ip, $ts = '')
    {
        $valid = false;
        $inbox = $this->getInbox($user, $ip);
        if (!$inbox) {
            return false;
        }
        // apop else plain auth
        if ($ts && (md5($ts . $inbox['pass']) == $password)) {
            $valid = true;
        } elseif (!$ts && ($inbox['pass'] == $password)) {
            $valid = true;
        }
        log_line('auth stat:' . $valid);
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
     * Returns an array of 'messages' with 'id', 'octets' (size), and address_id
     * empty array if no messages.
     *
     * @param string $username
     * @param string $pop_id
     *
     * @internal param int|string $message_id if given, returns a single message. false if not found
     *
     * @return bool|array
     */
    public function getList($username, $pop_id = '')
    {
        $ret = array();
        $pop_id = (int)$pop_id;
        $inbox = $this->getInbox($username);
        if (!$inbox) {
            return false;
        }
        $address_id = $inbox['address_id'];
        $mail_id = null;
        if ($pop_id) {
            if (false === ($mail_id = $this->mapId($pop_id))) {
                return false;
            }
        }
        $list = $this->store->getInboxList($address_id, $mail_id);
        if (!empty($list)) {
            $total_size = 0;
            $i = 1;
            foreach ($list as $row) {
                $this->list[$i] = array(
                    'mid' => $row['mail_id'],
                    'h'   => $row['hash']
                );
                $total_size += $row['size'];
                $ret['messages'][] = array(
                    'id'       => $i, //$row['mail_id'],//$row['pop_id'],
                    'octets'   => $row['size'],
                    'checksum' => $row['hash']
                );
                $i++;
            }
            $ret['octets'] = $total_size;
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
     * @param $username string
     * @param $pop_id
     *
     * @internal param $message_id
     *
     * @return int 0 if not found, 1 if found
     */
    public function MsgMarkDel($username, $pop_id)
    {
        if (!static::$ALLOW_DELETE) {
            return 0;
        }
        $inbox = $this->getInbox($username);
        if (!$inbox) {
            return false;
        }
        $address_id = $inbox['address_id'];
        $pop_id = (int)$pop_id;
        if (false === ($mail_id = $this->mapId($pop_id))) {
            return false;
        }
        $count = $this->store->isMsgExists($address_id, $mail_id);
        if ($count) {
            $this->markedDeleted[$username][] = $mail_id;
        }
        return $count;
    }

    /**
     * Abandon delete
     *
     * @param string $username
     *
     * @return bool|void
     */
    public function resetDeleted($username)
    {
        $this->markedDeleted[$username] = array();
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

        $inbox = $this->getInbox($username);
        if (!$inbox) {
            return false;
        }
        $address_id = $inbox['address_id'];
        $pop_id = (int)$pop_id;
        if (false === ($mail_id = $this->mapId($pop_id))) {
            return false;
        }
        return $this->store->fetchRawEmail($address_id, $mail_id);
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
        $inbox = $this->getInbox($username);
        if (!$inbox) {
            return false;
        }
        $address_id = $inbox['address_id'];
        if (empty($this->markedDeleted[$username])) {
log_line("mark del empty", 1);
            return true;
        }
        $affected = $this->store->deleteMarked($address_id, $this->markedDeleted[$username]);
        unset ($this->markedDeleted[$username]);
        return $affected;
    }

    public function getError()
    {
        return $this->error;
    }

    public function getErrorMsg()
    {
        if (!empty($error_msg[$this->error])) {
            return $error_msg[$this->error];
        }
        return false;
    }

    protected function getInbox($username, $ip='')
    {
        return $this->store->getInbox($username, $ip);
        /*
        return array(
            'pass'       => '', // password as stored in the db, can be hashed
            'item_count' => 0, // number of messages
            'size'       => '', // total in bytes
            'address_id' => '', // user's id

        );
        */
    }

    private function mapId($pop_id)
    {
        $mail_id = false;
        if (!empty($this->list[$pop_id])) {
            $mail_id = $this->list[$pop_id]['mid'];
        }
        return $mail_id;
    }
}