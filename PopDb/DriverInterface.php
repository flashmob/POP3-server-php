<?php

/**
 * Interface PopDb_DriverInterface
 * Implement these methods for your own PopDb/Driver class
 * See Mysql.php for an example implementation
 */
interface PopDb_DriverInterface
{
    public function testSettings();

    public function getInbox($username, $ip = '');

    public function deleteMarked($address_id, array $mail_id_list);

    public function fetchRawEmail($address_id, $mail_id);

    public function isMsgExists($address_id, $mail_id);

    public function getInboxList($address_id, $mail_id='');

}
