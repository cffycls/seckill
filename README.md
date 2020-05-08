# seckill以及压测过程  

搭建测试环境  
文章地址： https://segmentfault.com/a/1190000021388999  

[2019.12.29 18:40] 更新  
1、增加swoole版测试脚本，部分逻辑改写；  
  
[2019.12.27 17:22] 建立 
1、问题分析，实例化调试，并发测试；  
2、相关函数
```
apcu_add; apcu_inc; apcu_store; apcu_dec  
redis：使用eval执行lua脚本
```

测试过程
====
php api2.php 挂起[9501]
原始的php-fpm测试代码：
```markdown
1、查库存
curl 'http://192.168.1.111:8084/seckill/api.php?act=getStock&product_id=1&user_id=1'
ab -n1000 -c50 'http://192.168.1.111:8084/seckill/api.php?act=getStock&product_id=1&user_id=1'


2、扣库存
curl 'http://192.168.1.111:8084/seckill/api.php?act=seckill&product_id=1&user_id=1'
ab -n1000 -c50 'http://192.168.1.111:8084/seckill/api.php?act=seckill&product_id=1&user_id=1'


3、同步库存至本地缓存
curl 'http://192.168.1.111:8084/seckill/api.php?act=sync&product_id=1&user_id=1'


4、清空本地缓存
curl 'http://192.168.1.111:8084/seckill/api.php?act=clear&product_id=1&user_id=1'

5、重置数据
curl 'http://192.168.1.111:8080/seckill/api.php'



1、查库存
curl 'http://192.168.1.111:9500/seckill/api.php?act=getStock&product_id=1&user_id=1'
ab -n1000 -c50 'http://192.168.1.111:9500/seckill/api.php?act=getStock&product_id=1&user_id=1'

2、扣库存
curl 'http://192.168.1.111:9500/seckill/api.php?act=buy&product_id=1&user_id=1'
ab -n1000 -c50 'http://192.168.1.111:9500/seckill/api.php?act=buy&product_id=1&user_id=1'

3、同步库存至本地缓存
curl 'http://192.168.1.111:9500/seckill/api.php?act=sync&product_id=1&user_id=1'

4、清空本地缓存
curl 'http://192.168.1.111:9500/seckill/api.php?act=clear&product_id=1&user_id=1'

5、重置数据
curl 'http://192.168.1.111:9500/seckill/api.php'
```