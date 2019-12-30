<?php

$ss = apcu_fetch('apcu_stock_use_1');
if($ss == false){
    $ss = 0;
    apcu_add('apcu_stock_use_1', $ss);
}

echo '<pre>';
print_r($ss);
echo '<br/>';
echo '<br/>';
$aa1 = apcu_inc('apcu_stock_use_1');
var_dump($aa1, apcu_fetch('apcu_stock_use_1'));
echo '<br/>';
$aa2 = apcu_inc('apcu_stock_use_1');
var_dump($aa2, apcu_fetch('apcu_stock_use_1'));
echo '<br/>';
$aa3 = apcu_inc('apcu_stock_use_1');
var_dump($aa3, apcu_fetch('apcu_stock_use_1'));
echo '<br/>';
echo '<br/>';

print_r(apcu_fetch('apcu_stock_use_1'));
/**
6

7
8
9

9
 */

$http = new Swoole\Http\Server("0.0.0.0", 9501);
$http->on('request', function ($request, $response) {
    try {
        ob_start();
        echo '<pre>';
        $ss = apcu_fetch('apcu_stock_use_1');
        if($ss === false){
            $ss = 0;
            apcu_add('apcu_stock_use_1', 0);
            apcu_add('apcu_stock_1', 1000);
            apcu_add('total_count_1', 1000);
            apcu_add('apcu_stock_count_1', 500);
        }

        print_r($ss);
        echo PHP_EOL;
        $aa1 = apcu_inc('apcu_stock_use_1');
        print_r(apcu_fetch('apcu_stock_use_1'));
        echo PHP_EOL;
        $aa2 = apcu_inc('apcu_stock_use_1');
        print_r(apcu_fetch('apcu_stock_use_1'));
        echo PHP_EOL;
        $aa3 = apcu_inc('apcu_stock_use_1');
        print_r(apcu_fetch('apcu_stock_use_1'));
        echo PHP_EOL;

        apcu_inc('apcu_stock_use_1');
        print_r(apcu_fetch('apcu_stock_use_1'));
        echo PHP_EOL;
        print_r([
            'apcu_stock_use_1' => apcu_fetch('apcu_stock_use_1'),
            'apcu_stock_1' => apcu_fetch('apcu_stock_1'),
            'total_count_1' => apcu_fetch('total_count_1'),
            'apcu_stock_count_1' => apcu_fetch('apcu_stock_count_1'),
        ]);

        $res = ob_get_contents();
        ob_end_clean();
        $response->end("<h1>Hello Swoole. ****".rand(1000, 9999)."</h1>" .$res);
    } catch (\Exception $e) { //程序异常
        var_dump($e->getMessage());
    }
});
$http->start();
