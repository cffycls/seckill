#原生php项目代码swoole化改造


1、添加一键协程化，swoole_table
====
```markdown
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
```
bug.1 fix:
----
```markdown
ERROR	php_swoole_server_rshutdown (ERRNO 503): Fatal error: Uncaught Swoole\Error: Socket#41 has already been bound to another coroutine#2, reading of the same socket in coroutine#1 at the same time is not allowed in /var/www/html/seckill/sw_api.php:148
```
实例化对象数据共享问题?
[Master 进程、Reactor 线程、Worker 进程、Task 进程、Manager 进程的区别与联系](https://wiki.swoole.com/#/learn?id=diff-process)
```markdown
apcu_cache_info(): No APC info available.  Perhaps APC is not enabled
php --ri apcu
apc.enable_cli => Disabled 默认值

apc.enable_cli integer 
Mostly for testing and debugging. Setting this enables APCfor the CLI version of PHP. Under normal circumstances, it isnot ideal to create, populate and destroy the APC cache on everyCLI request, but for various test scenarios it is useful to beable to enable APC for the CLI version of PHP easily.
php.ini添加 apc.enable_cli=1 重启容器
```
在正常情况下，在每个CLI请求上创建、填充和销毁APC缓存并不理想，但是对于各种测试场景，能够为CLI版本的PHP轻松启用APC是非常有用的。 
[APCU运行时配置](https://www.php.net/manual/zh/apc.configuration.php)
建议使用swoole-table
```markdown
$table = new Swoole\Table(1024);
$table->column('total_count', Swoole\Table::TYPE_INT);
$table->column('local_count', Swoole\Table::TYPE_INT);
$table->column('use_num', Swoole\Table::TYPE_INT);
$table->column('server_num', Swoole\Table::TYPE_INT);
$table->create();
$GLOBALS['table'] = &$table;
    ($GLOBALS['table'])->set(self::$REDIS_REMOTE_HT_KEY, [
            'redis_key' => self::$REDIS_REMOTE_HT_KEY,
            'total_count' => $num,
            'local_count' => $stock,
            'use_num' => 0,
            'server_num' => $data['server_num'],
    ]);
    $data = ($GLOBALS['table'])->get(self::$REDIS_REMOTE_HT_KEY);
```
