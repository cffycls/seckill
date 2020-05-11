<?php
error_reporting(E_ERROR | E_WARNING | E_PARSE);
/** 【注意】
 * 1. php.ini apcu默认不支持cli运行
 *      apc.enable_cli=1
 * 2. 注意$request->server['request_uri'] == '/favicon.ico'的拦截处理
 * 3. nginx配置：proxy_pass    http://192.168.1.111:9501; #交给swoole代理
 * 4. 容器redis-cli命令行: monitor，即时监听redis执行语句
 */

//var_dump(apcu_add('ss', 1), apcu_fetch('ss'));

include_once 'base.php';
class Api extends Base
{
    //共享信息存储在redis中，一hash表形式存储，%s变量代表的是商品id
    static $userId;
    static $productId;

    static $REDIS_REMOTE_HT_KEY = 'product_%s';             //共享信息key
    static $REDIS_REMOTE_TOTAL_COUNT = 'total_count_%s';      //商品总库存
    static $REDIS_REMOTE_USE_COUNT = 'used_count_%s';       //已售库存
    static $REDIS_REMOTE_QUEUE = 'c_order_queue';           //创建订单队列

    static $APC_LOCAL_STOCK = 'apcu_stock_%s';              //总共剩余库存
    static $APC_LOCAL_USE = 'apcu_stock_use_%s';            //本地已售库存
    static $APC_LOCAL_COUNT = 'apcu_stock_count_%s';        //本地分库分摊总数

    public function __construct($productId, $userId)
    {
        self::$productId = $productId;
        self::$userId = $userId;
        self::$REDIS_REMOTE_HT_KEY = sprintf(self::$REDIS_REMOTE_HT_KEY, $productId);
        self::$APC_LOCAL_STOCK = sprintf(self::$APC_LOCAL_STOCK, $productId);
        self::$REDIS_REMOTE_TOTAL_COUNT = sprintf(self::$REDIS_REMOTE_TOTAL_COUNT, $productId);
        self::$REDIS_REMOTE_USE_COUNT = sprintf(self::$REDIS_REMOTE_USE_COUNT, $productId);
        self::$APC_LOCAL_COUNT = sprintf(self::$APC_LOCAL_COUNT, $productId);
        self::$APC_LOCAL_USE = sprintf(self::$APC_LOCAL_USE, $productId);
    }

    //清空缓存
    public function clear()
    {
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
            apcu_dec(self::$APC_LOCAL_STOCK, $localUse); //本地剩余库存
            apcu_dec(self::$APC_LOCAL_COUNT, $localUse); //本地预售库存
        }
        self::output([$res,$localUse], 0, '同步成功');
    }

    //查询库存
    public function getStock()
    {
        $stockNum = apcu_fetch(self::$APC_LOCAL_STOCK);
        if ($stockNum === false){
            $stockNum = self::initStock();
        }
	    print_r(['$APC_LOCAL_STOCK' => apcu_fetch(self::$APC_LOCAL_STOCK)]);
        //self::output(['stock_num'=>$stockNum]);
    }

    // 抢购-减库存
    public function buy()
    {
        $localStockNum = apcu_fetch(self::$APC_LOCAL_STOCK);
        if ($localStockNum === false){
            $localStockNum = self::initStock();
            self::output([1], -1, '该商品已售完');
            return 1;
        }
        print_r([
            self::$APC_LOCAL_USE => apcu_fetch(self::$APC_LOCAL_USE),
            self::$APC_LOCAL_STOCK => apcu_fetch(self::$APC_LOCAL_STOCK),
            self::$APC_LOCAL_COUNT =>apcu_fetch(self::$APC_LOCAL_COUNT),
            self::$REDIS_REMOTE_TOTAL_COUNT =>apcu_fetch(self::$REDIS_REMOTE_TOTAL_COUNT),

        ]);
        //$localStockCount = apcu_fetch(self::$APC_LOCAL_COUNT);
        $localUse = apcu_inc(self::$APC_LOCAL_USE,1); //已卖 +1

        if($localUse > $localStockNum){ //抢购失败，大部分流量分流拦截在此
            self::output([1], -1, '该商品已售完');
            return 1;
        }

        //同步已售库存 +1
        if(!$this->incUseCount()){ //如果改失败，返回已售完
            self::output(['*'], -1, '该商品已售完');
            return 1;
        }

        //写入创建订单队列
        self::conRedis()->lPush(self::$REDIS_REMOTE_QUEUE, json_encode([
            'user_id' => self::$userId,
            'product_id' => self::$productId
        ]));
        //返回抢购成功
        print_r([
            self::$APC_LOCAL_USE => apcu_fetch(self::$APC_LOCAL_USE),
            self::$APC_LOCAL_STOCK => apcu_fetch(self::$APC_LOCAL_STOCK),
            self::$APC_LOCAL_COUNT =>apcu_fetch(self::$APC_LOCAL_COUNT),
            self::$REDIS_REMOTE_TOTAL_COUNT =>apcu_fetch(self::$REDIS_REMOTE_TOTAL_COUNT),
        ]);
        self::output([], 0, '抢购成功，请从订单中心查看订单');
    }

    static function initStock()
    {
        echo '初始化库存。。。。。。。。。。'.PHP_EOL;
        $data = self::conRedis()->hMGet(self::$REDIS_REMOTE_HT_KEY,
            [self::$REDIS_REMOTE_TOTAL_COUNT, self::$REDIS_REMOTE_USE_COUNT, 'server_num']
        );
        $num = $data[self::$REDIS_REMOTE_TOTAL_COUNT] - $data[self::$REDIS_REMOTE_USE_COUNT];
        $stock = round($num / ($data['server_num'] - 1));
        apcu_add(self::$REDIS_REMOTE_TOTAL_COUNT, $data[self::$REDIS_REMOTE_TOTAL_COUNT]); //总剩余库存
	    $effectively = apcu_add(self::$APC_LOCAL_STOCK, $num); //总剩余库存分布到本地额度
        apcu_add(self::$APC_LOCAL_COUNT, $stock);
        apcu_add(self::$APC_LOCAL_USE, 0);
	    //var_dump([self::$APC_LOCAL_STOCK=> $num, $effectively,'$APC_LOCAL_STOCK' => apcu_fetch(self::$APC_LOCAL_STOCK)]);
		//var_dump(apcu_cache_info());
	    ($GLOBALS['table'])->set(self::$REDIS_REMOTE_HT_KEY, [
		        'redis_key' => self::$REDIS_REMOTE_HT_KEY,
		        'total_count' => $num,
		        'local_count' => $stock,
		        'use_num' => 0,
		        'server_num' => $data['server_num'],
	    ]);

        return $num;
    }
	//test
	public function test()
	{
		var_dump(['$APC_LOCAL_STOCK' => apcu_fetch(self::$APC_LOCAL_STOCK)]);
		$key = sprintf(Api::$REDIS_REMOTE_HT_KEY, self::$productId);
		$res = Base::conRedis()->hMget($key, [Api::$REDIS_REMOTE_TOTAL_COUNT, Api::$REDIS_REMOTE_USE_COUNT, 'server_num']);

		$data = ($GLOBALS['table'])->get(self::$REDIS_REMOTE_HT_KEY);
		var_dump($res, $data);
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
        var_dump($a = self::$REDIS_REMOTE_HT_KEY,
            self::conRedis()->eval("return redis.call('hgetall','product_1')"),
            self::conRedis()->eval($script, [
                self::$REDIS_REMOTE_HT_KEY,
                self::$REDIS_REMOTE_TOTAL_COUNT,
                self::$REDIS_REMOTE_USE_COUNT
            ], 3)
        );/*exit;
        return $a;*/
        var_dump($a);
        return self::conRedis()->eval($script, [
            self::$REDIS_REMOTE_HT_KEY,
            self::$REDIS_REMOTE_TOTAL_COUNT,
            self::$REDIS_REMOTE_USE_COUNT
        ], 3);
    }
}

Swoole\Runtime::enableCoroutine($flags = SWOOLE_HOOK_ALL);
$http = new Swoole\Http\Server("0.0.0.0", 9501);
$http->set([
	'enable_coroutine' => true,
	'worker_num' => swoole_cpu_num(),
	'pid_file' => './sw.pid',
	'open_tcp_nodelay' => true,
	'max_coroutine' => 100000,
	'max_request' => 100000,
	'socket_buffer_size' => 2 * 1024 * 1024,
]);
$table = new Swoole\Table(1024);
$table->column('total_count', Swoole\Table::TYPE_INT);
$table->column('local_count', Swoole\Table::TYPE_INT);
$table->column('use_num', Swoole\Table::TYPE_INT);
$table->column('server_num', Swoole\Table::TYPE_INT);
$table->create();
$http->set(['dispatch_mode' => 1]);
//$http->table = $table;
$GLOBALS['table'] = &$table;

$http->on('request', function ($request, $response) {
    if($request->server['request_uri'] == '/favicon.ico') {
        $response->status(404);
        $response->end();
        return 0;
    }
    ini_set('apc.enable_cli', 1);

    try {
        $header = $request->header;
        $server = $request->server;
        $cookie = $request->cookie;
        $get = $request->get;
        //print_r(['$header'=>$header, '$server'=>$server, '$get'=>$get, '$cookie'=>$cookie]);
        parse_str($server['query_string'], $req);
	    //var_dump($server['query_string'], $req);
        if($req['act'] && $req['product_id'] && $req['user_id']){
            $method = $req['act'];
            $rm = new \ReflectionMethod('Api',$method);
            if($rm->isStatic()){
                (new Api($req['product_id'], $req['user_id']))::$method();
            }else{
                (new Api($req['product_id'], $req['user_id']))->$method();
            }
        }else{
            echo '初始化---------'.PHP_EOL;
            apcu_clear_cache();

            $data = [$req['product_id'] ?? 1, $req['user_id'] ?? 1];
            $key = sprintf(Api::$REDIS_REMOTE_HT_KEY, $data[1]);
            $res = Base::conRedis()->hMset($key, [Api::$REDIS_REMOTE_TOTAL_COUNT=>1000, Api::$REDIS_REMOTE_USE_COUNT=>0, 'server_num'=>3]);
            var_dump($res);
            (new Api($data[0], $data[1]))::initStock();
        }

	    $response->header("Content-Type", "text/plain");
        $response->end("<h1>Hello Swoole. #".rand(1000, 9999)."</h1>");
    } catch (\Exception $e) { //程序异常
        var_dump($e->getMessage());
    }
});
$http->start();

