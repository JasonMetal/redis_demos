# For ThinkPHP5 Ext
# Log Driver use Redis

```PHP
    /**
     * 实时写入日志信息 并支持行为
     * @access public
     * @param  mixed  $msg   调试信息
     * @param  string $type  信息类型
     * @param  bool   $force 是否强制写入
     * @return bool
     */
    public function log(){
        $msg = '测试日志信息，这是警告级别，并且实时写入';
        $ret = Log::write($msg, $type = 'notice', $force = false);
        return $ret;
    }

    /**
     * @Notes  : redisLog 模块
     * ->@Notes  : 消费日志存入DB中
     * @return :int|string|void
     * @user   : XiaoMing
     * @time   : 2020/6/20_14:26
     */
    public function logPopToDb()
    {
        $objRedis = (new RedisDr());
        $ret =  $objRedis->logPopToDb('test');
        $objRedis->close();
        return $ret;
    }
```