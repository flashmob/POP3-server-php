<?php
class PopDb_Mapper
{

    private static $instance;

    private function __construct()
    {

    }

    /**
     * @param string $driver
     *
     * @return PopDb_DriverInterface
     */
    public static function getInstance($driver = 'Mysql')
    {
        if (isset(self::$instance)) {
            return self::$instance;
        } else {
            $class = 'PopDb_Driver_' . $driver;
            if (class_exists($class)) {
                self::$instance = new $class();
            } else {
                self::$instance = new PopDb_Driver_Mysql();
            }
        }

        return self::$instance;

    }

}