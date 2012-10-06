<?php

interface PopDb_DriverInterface
{
    public function testSettings();

    // verify USER, PASS or APOP
    public function auth($user, $password, $ts = '');

    // STAT command
    public function getStat($username);

    // LIST command
    public function getList($username, $message_id = '');

    // DELE command
    public function MsgMarkDel($username, $message_id);

    // RSET command
    public function resetDeleted($username);

    // for TOP and RETR commands
    public function getMsg($username, $id);

    // QUIT - delete all on the delete list
    public function commitDelete($username);

}