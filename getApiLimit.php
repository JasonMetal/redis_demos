<?php
#redis限定接口调用次数
#INCR


$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redis->auth("123456");

$key = get_real_ip();


//限制次数为5
$limit = 5;
$check = $redis->exists($key);
if($check){
    $redis->incr($key);
    $count = $redis->get($key);
    if($count > 5){
        exit('请求太频繁，请稍后再试！');
    }
}else{
    $redis->incr($key);
    //限制时间为60秒
    $redis->expire($key,60);
}
$count = $redis->get($key);
echo '第 '.$count.' 次请求';


function get_real_ip()
{
    static $realip;
    if (isset($_SERVER)) {
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $realip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $realip = $_SERVER['HTTP_CLIENT_IP'];
        } else {
            $realip = $_SERVER['REMOTE_ADDR'];
        }
    } else {
        if (getenv('HTTP_X_FORWARDED_FOR')) {
            $realip = getenv('HTTP_X_FORWARDED_FOR');
        } else if (getenv('HTTP_CLIENT_IP')) {
            $realip = getenv('HTTP_CLIENT_IP');
        } else {
            $realip = getenv('REMOTE_ADDR');
        }
    }
    return $realip;
}