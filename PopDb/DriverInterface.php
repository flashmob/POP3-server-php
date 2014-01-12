<?php

interface PopDb_DriverInterface
{
    public function testSettings();

    public function getInbox($username, $ip = '');

    public function deleteMarked($address_id, $mail_id_list);

    public function fetchRawEmail($address_id, $mail_id);

    public function isMsgExists($address_id, $mail_id);

    public function getInboxList($address_id, $mail_id='');

}
