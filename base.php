<?php

class Base
{
    static $redisObj;
    static $mysqlObj;

    static function conRedis($config = [])
    {
        if(self::$redisObj){
            return self::$redisObj;
        }else{
            self::$redisObj = new \Redis();
            self::$redisObj->connect('172.1.13.11',6379);
            return self::$redisObj;
        }
    }

    static function conMySql($config = [])
    {
        if(self::$mysqlObj){
            return self::$mysqlObj;
        }else{
            $table_name = 'ms_stock'; //秒杀库存表
            self::$mysqlObj = new \PDO('mysql:host=192.168.1.111;dbname=last12','root','123456');
            self::$mysqlObj->query('set names utf8');
            $tables = self::conMySql()->query('show tables from last12')->fetchAll(PDO::FETCH_COLUMN);
            var_export($tables);
            if(!in_array($table_name, $tables)){
                $createSql = <<<EOF
CREATE TABLE if not exists `{$table_name}`(
    `id` int(11) unsigned not null auto_increment comment 'id',
    `product_id` int(11) unsigned not null comment '产品库存编号',
    `number` int(11) unsigned not null comment '库存数量',
    `update_time` datetime not null default '2000-01-01 00:00:00' comment '最后修改时间',
    primary key (`id`)
) comment '秒杀库存表' ENGINE=InnoDB default charset=utf8;
EOF;
                self::$mysqlObj->exec($createSql);
                self::$mysqlObj->exec("insert {$table_name} (`product_id`,`number`,`update_time`) values (1,1000,'2000-01-01 00:00:00')");
            }
            return self::$mysqlObj;
        }
    }

    static function output($data = [], $errNo = 0, $errMsg = 'ok')
    {
        echo json_encode([
            'errno' => $errNo,
            'errmsg' => $errMsg,
            'data' => $data
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

}