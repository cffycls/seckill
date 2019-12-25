<?php

include 'base.php';

class Api extends Base
{
    //共享信息存储在redis中，一hash表形式存储，%s变量代表的是商品id
    static $userId;
    static $productId;

    static $REDIS_REMOTE_HT_KEY = 'product_%s';             //共享信息key
    static $REDIS_REMOTE_TOTAL_COUNT = 'total_count';      //商品总库存
    static $REDIS_REMOTE_USE_COUNT = 'used_count';       //已售库存
    static $REDIS_REMOTE_QUEUE = 'c_order_queue';           //创建订单队列

    static $APCU_LOCAL_STOCK = 'apcu_stock_%s';              //总共剩余库存
    static $APC_LOCAL_USE = 'apcu_stock_use_%s';            //本地已售库存
    static $APC_LOCAL_COUNT = 'apcu_stock_count_%s';        //本地分库分摊总数

    public function __construct($productId, $userId)
    {
        self::$REDIS_REMOTE_HT_KEY = sprintf(self::$REDIS_REMOTE_HT_KEY, $productId);
        self::$APCU_LOCAL_STOCK = sprintf(self::$APCU_LOCAL_STOCK, $productId);
    }

    //清空缓存
    public function clear()
    {
        //var_export(apcu_cache_info()); //运行时缓存
        apcu_clear_cache();
        self::output();
    }
    //数据同步
    public function sync()
    {
        $res = 0;
        $localUse = apcu_fetch(self::$APC_LOCAL_USE);

        //与redis同步，redis库的存量和已售减去本地缓存预售的量，去本地化
        if($localUse > 0){
            showInfo();
            $script = <<<eof
    local key = KEYS[1]
    local field1 = KEYS[2]
    local field2 = KEYS[3]
    local field1_res = redis.call('HINCRBY', key, field1, 0 - ARGV[1])

    if(field1_res) then
        return redis.call('HINCRBY', key, field2, 0 - ARGV[1])
    end
    return 0
eof;
            $res = self::conRedis()->eval($script, [
                self::$REDIS_REMOTE_HT_KEY,
                self::$REDIS_REMOTE_TOTAL_COUNT,
                self::$REDIS_REMOTE_USE_COUNT,
                $localUse
            ], 3);
            apcu_store(self::$APC_LOCAL_USE, 0);
            apcu_dec(self::$APCU_LOCAL_STOCK, $localUse); //本地剩余库存
        }
        self::output([$res,$localUse], 0, '同步成功');
    }

    //查询库存
    public function getStock()
    {
        $stockNum = apcu_fetch(self::$APCU_LOCAL_STOCK);
        var_dump(self::$APCU_LOCAL_STOCK. '-2', $stockNum);
        if ($stockNum === false){
            $stockNum = self::initStock();
        }
        self::output(['stock_num'=>$stockNum]);
    }

    // 抢购-减库存
    public function buy()
    {
        $localStockNum = apcu_fetch(self::$APCU_LOCAL_STOCK);
        if ($localStockNum === false){
            $localStockNum = self::init();
        }

        $localUse = apcu_inc(self::$APC_LOCAL_USE); //已卖 +1
        if($localUse > $localStockNum){ //抢购失败，大部分流量分流拦截在此
            self::output([1], -1, '该商品已售完');
        }

        //同步已售库存 +1
        if(!$this->incUseCount()){ //如果改失败，返回已售完
            self::output(['*'], -1, '该商品已售完');
        }

        //写入创建订单队列
        self::conRedis()->lPush(self::$REDIS_REMOTE_QUEUE, json_encode([
            'user_id' => self::$userId,
            'product_id' => self::$productId
        ]));
        //返回抢购成功
        self::output([], 0, '抢购成功，请从订单中心查看订单');
    }

    //初始化本地数据
    static function init()
    {
        apcu_add(self::$APC_LOCAL_USE, 0);
        return 0;
    }
    static function initStock()
    {
        $data = self::conRedis()->hMGet(self::$REDIS_REMOTE_HT_KEY,
            [self::$REDIS_REMOTE_TOTAL_COUNT, self::$REDIS_REMOTE_USE_COUNT, 'server_num']
        );
        $num = $data[self::$REDIS_REMOTE_TOTAL_COUNT] - $data[self::$REDIS_REMOTE_USE_COUNT];
        $stock = round($num / ($data['server_num'] - 1));
        apcu_add(self::$REDIS_REMOTE_TOTAL_COUNT, $data[self::$REDIS_REMOTE_TOTAL_COUNT]); //总剩余库存
        apcu_add(self::$APCU_LOCAL_STOCK, $num); //总剩余库存分布到本地额度
        var_dump(self::$APCU_LOCAL_STOCK .'-1', $stock);
        return $stock;
    }

    //私有方法，库存同步: 给总预售数 +1
    private function incUseCount()
    {
        $script = <<<eof
    local key = KEYS[1]
    local field1 = KEYS[2]
    local field2 = KEYS[3]
    local field1_val = redis.call('hget', key, field1) + 0
    local field2_val = redis.call('hget', key, field2) + 0

    if(field1_val > field2_val) then
        return redis.call('HINCRBY', key, field2, 1)
    end
    return 0
eof;
        /*var_dump(self::$REDIS_REMOTE_HT_KEY,
            self::conRedis()->eval("return redis.call('hgetall','product_1')"),
            self::conRedis()->eval($script, [
                self::$REDIS_REMOTE_HT_KEY,
                self::$REDIS_REMOTE_TOTAL_COUNT,
                self::$REDIS_REMOTE_USE_COUNT
            ], 3)
        );exit;*/
        return self::conRedis()->eval($script, [
            self::$REDIS_REMOTE_HT_KEY,
            self::$REDIS_REMOTE_TOTAL_COUNT,
            self::$REDIS_REMOTE_USE_COUNT
        ], 3);
    }
}


echo '<pre>';
parse_str($_SERVER['QUERY_STRING'], $req);
if($req['act'] && $req['product_id'] && $req['user_id']){
    $method = $req['act'];
    print_r($req);
    $rm = new \ReflectionMethod('Api',$method);
    if($rm->isStatic()){
        (new Api($req['product_id'], $req['user_id']))::$method();
    }else{
        (new Api($req['product_id'], $req['user_id']))->$method();
    }
}else{
    echo '初始化---------<br/>';
    apcu_clear_cache();
    showInfo();

    $data = [$req['product_id'] ?? 1, $req['user_id'] ?? 1];
    $key = sprintf(Api::$REDIS_REMOTE_HT_KEY, $data[1]);
    $res = Base::conRedis()->hMset($key, [Api::$REDIS_REMOTE_TOTAL_COUNT=>1000, Api::$REDIS_REMOTE_USE_COUNT=>0, 'server_num'=>3]);
    var_dump($res);
    (new Api($data[0], $data[1]))::initStock();
    Base::output();
}

function showInfo(){
    $serverInfo = [
        'gethostbyname'=>gethostbyname(gethostname().'.'),
        'REQUEST_TIME'=>date("Y-m-d H:i:s", $_SERVER['REQUEST_TIME']),
        'HTTP_COOKIE'=>$_SERVER['HTTP_COOKIE'],
        'HTTP_HOST'=>$_SERVER['HTTP_HOST'],
        'SERVER_SOFTWARE'=>$_SERVER['SERVER_SOFTWARE'],
        'SERVER_ADDR'=>$_SERVER['SERVER_ADDR'],
        'SERVER_PORT'=>$_SERVER['SERVER_PORT'],
        'REMOTE_ADDR'=>$_SERVER['REMOTE_ADDR'],
        'REMOTE_PORT'=>$_SERVER['REMOTE_PORT'],
    ];
    var_export(array_merge_recursive(['session_id'=>session_id()], $serverInfo));
}
