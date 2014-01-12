<?php
class PopDb_Factory
{

    private static $instance;


    /**
     * @param string $driver
     *
     * @return PopDb_DriverInterface
     */
    public static function getInstance($driver = 'Mysql')
    {
        $class = 'PopDb_Driver_' . $driver;
        static $d;
        if (!isset($d)) {
            if (class_exists($class)) {
                $d = new $class();
            } else {
                $d = new PopDb_Driver_Mysql();
            }
        }
        self::$instance = new PopDb_MaildropModel();
        self::$instance->setStore($d);
        return self::$instance;
    }

}