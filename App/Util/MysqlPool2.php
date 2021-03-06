<?php

namespace App\Util;

use EasySwoole\Config;
use EasySwoole\Core\Component\Pool\AbstractInterface\Pool;
use EasySwoole\Core\Component\Trigger;
use EasySwoole\Core\Swoole\Coroutine\Client\Mysql;

class MysqlPool2 extends Pool
{
    public function getObj($timeOut = 0.1):?Mysql
    {
        return parent::getObj($timeOut); // TODO: Change the autogenerated stub
    }
    protected function createObject()
    {
        // TODO: Implement createObject() method.
        try {
            $conf = Config::getInstance()->getConf('MYSQL');
            $db = new Mysql([
                'host' => $conf['HOST'],
                'username' => $conf['USER'],
                'password' => $conf['PASSWORD'],
                'db' => $conf['DB_NAME'],
                'port' => $conf['PORT']
            ]);
            // $db->rawQuery("SET SESSION sql_mode='TRADITIONAL,NO_AUTO_VALUE_ON_ZERO,ONLY_FULL_GROUP_BY'");
            $db->rawQuery('SET SESSION sql_mode="STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION"');
            return $db;
        } catch (\Throwable $throwable) {
            Trigger::throwable($throwable);
            return null;
        }
    }
}