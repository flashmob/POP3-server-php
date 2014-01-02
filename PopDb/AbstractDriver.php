<?php


class AbstractDriver implements  PopDb_DriverInterface  {

    const ERROR_IN_USE = 1;

    protected $error_msg = array (
        self::ERROR_IN_USE => '[IN-USE] Do you have another POP session running?'
    );
    protected $markedDeleted = array();
    protected $to_delete = array();
    protected $error = null;

    public function testSettings()
    {
        // TODO: Implement testSettings() method.
    }

    public function auth($username, $password, $ip_address, $ts = '')
    {
        return true;
    }

    public function getStat($username)
    {
        return array();
    }

    public function getList($username, $message_id = '')
    {
        return false;
    }

    public function MsgMarkDel($username, $message_id)
    {
        return false;
    }

    public function resetDeleted($username)
    {
        return false;
    }

    public function getMsg($username, $id)
    {
        return false;
    }

    public function commitDelete($username)
    {
        return false;
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
}