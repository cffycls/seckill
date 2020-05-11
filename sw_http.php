<?php
$http = new Swoole\Http\Server("0.0.0.0", 9501);
$http->set([
	'enable_coroutine' => true,
	// 'worker_num' => swoole_cpu_num(),
	'worker_num' => 4,
	'pid_file' => './sw.pid',
	'open_tcp_nodelay' => true,
	'max_coroutine' => 100000,
	'open_http2_protocol' => true,
	'max_request' => 100000,
	'socket_buffer_size' => 2 * 1024 * 1024,
]);

$http->on('request', function ($request, $response) {
	var_dump($request->get);//, $request->post
	$response->header("Content-Type", "text/html; charset=utf-8");
	$response->end("<h1>Hello Swoole. #".rand(1000, 9999)."</h1>");
});

$http->start();