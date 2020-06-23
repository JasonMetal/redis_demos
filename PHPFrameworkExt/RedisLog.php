<?php
// +----------------------------------------------------------------------
// redis 记录日志
//+----------------------------------------------------------------------
namespace think\log\driver;

use think\App;
use think\Db;
use think\Exception;
use think\Request;
//2020-6-23 17:42:20
ini_set('memory_limit', '-1');

/**
 * 本地化调试输出到文件
 */
class RedisLog
{
    var            $redis;
    public         $log_key  = 'log';
    public         $host     = "";
    public         $password = "";
    public         $port     = "";
    protected      $config
                             = [
            'time_format' => ' c ',
            'single'      => false,
            'file_size'   => 2097152,
            'path'        => LOG_PATH,
            'apart_level' => [],
            'max_files'   => 0,
            'json'        => false,
        ];
    private static $_instance;

    public function __construct()
    {
        $this->host     = Config("REDIS_HOST");
        $this->password = Config("REDIS_AUTH");
        $this->port     = Config("REDIS_PORT");
        $this->redis    = new \Redis();
        $this->redis->connect($this->host, $this->port);
//        $auth = $this->redis->auth($password);
        return $this->redis;
    }

    static public function getInstance()
    {
        if (FALSE == (self::$_instance instanceof self)) {
            self::$_instance = new self(Config("REDIS_HOST"), Config("REDIS_AUTH"), Config("REDIS_PORT"));
        }
        return self::$_instance;
    }

    private function __clone()
    {
    }

    public function lPop($key)
    {
        return $this->redis->lPop($key);
    }

    public function rPush($key, $value)
    {
        return $this->redis->rPush($key, $value);
    }

    /**
     * 当类中不存在该方法时候，直接调用call 实现调用底层redis相关的方法
     * @param  [type] $name      [description]
     * @param  [type] $arguments [description]
     * @return [type]            [description]
     */
    public function __call($name, $arguments)
    {
        return $this->redis->$name(...$arguments);
    }

    public function close()
    {
        return $this->redis->close();
    }

    /**
     * 日志写入接口
     * @access public
     * @param array $log 日志信息
     * @param bool $append 是否追加请求信息
     * @param bool $apart false 增加额外的调试信息
     * @return bool
     */
    public function save(array $log = [], $append = false, $apart = false)
    {
        $request = Request::instance();
        // 获取信息
        try {
            $requestInfo = [
                'url'        => $request->url(true),
                'domain'     => $request->domain(),
                'root'       => $request->root(),
                'baseFile'   => $request->baseFile(),
                'file'       => $request->file(),
                'module'     => $request->module(),
                'controller' => $request->controller(),
                'action'     => $request->action(),
                'routeInfo'  => $request->routeInfo(),
                'baseurl'    => $request->baseurl(),
                'pathinfo'   => $request->pathinfo(),
                'dispatch'   => $request->dispatch(),
                'ext'        => $request->ext(),
                'isAjax'     => var_export($request->isAjax(), true),
                'ip'         => $request->ip(),
                'method'     => $request->method(),
                'host'       => $request->host(),
                'uri'        => $request->url(),
                'user-agent' => $request->header(),
                'agent'      => $_SERVER['HTTP_USER_AGENT'],
                'body'       => json_encode(input('param.'), true),
                'time'       => date('Y-m-d H:i:s', time()),
            ];
             $info        = [];
            foreach ($log as $type => $val) {
                foreach ($val as $msg) {
                    if (!is_string($msg)) {
                        $msg = var_export($msg, true);
                    }
                    $info[$type][] = $this->config['json'] ? $msg : '[ ' . $type . ' ] ' . $msg;
                }

            }
            // 日志信息封装
            $info['timestamp'] = date($this->config['time_format']);
            foreach ($info as $type => $msg) {
                $info[$type] = is_array($msg) ? implode("\r\n", $msg) : $msg;
            }
            if (PHP_SAPI == 'cli') {
                $message = $this->parseCliLog($info);
            } else {
                // 添加调试日志
                $this->getDebugLog($info, $append, $apart);
                $message = $this->parseLog($info);
            }
            // 完毕
            $arr_msg  = ['request_info' => $requestInfo, 'msg' => $message];
            $now_time = date("Y-m-d H:i:s");
            $ret      = $this->rPush($this->log_key, json_encode($arr_msg, true) . "%" . $now_time);
            $this->close();
        } catch (\RedisException $exception) {
            throw new Exception($exception->getMessage());
        }

        if (!empty($ret)) {
            var_dump("入队的结果", $ret);
            return $ret;
        } else {
            return "入队失败了！";
        }
//        return true;
    }


    /**
     * @Notes  : RedisLog 模块
     * ->@Notes  : 处理日志中的 控制器等
     * @param $task
     * @return :mixed
     * @user   : XiaoMing
     * @time   : 2020/6/20_14:06
     */
    public function returnConArr($task)
    {
        $log_info = json_decode($task, true);
        $log      = $log_info['request_info'];
        for ($i = 0; $i < strlen($log['controller']); $i++) {
            $firstA = ord($log['controller'][$i]) >= ord('A');
            $firstZ = ord($log['controller'][$i]) <= ord('Z');
            if ($firstA && $firstZ && $i !== 0) {
                $log['controller'] = substr_replace($log['controller'], '_', $i, 0);
                $i++;
            }
        }
        $log_info['request_info'] = $log;
        return $log_info;
    }

    /**
     * @Notes  : RedisLog 模块
     * ->@Notes  : 日志出栈
     * @param $table
     * @user   : XiaoMing
     * @time   : 2020/6/19_17:33
     */
    public function batchPopToMongoDb($table)
    {
        // 获取现有消息队列的长度
        $count = 0;
        $max   = $this->lLen($this->log_key);
        // 回滚数组
        $roll_back_arr = [];
        $arr_tmp       = [];
        $arr_in        = [];
        while ($count < $max) {
            $log_info        = $this->lPop($this->log_key);
            $roll_back_arr[] = $log_info;
            if ($log_info == 'nil' || !isset($log_info)) {
                break;
            }
            // 切割出时间和info
            $log_info_arr = explode("%", $log_info);
            $arr_tmp[]
                          = ['log_json' => $log_info_arr[0], 'create_time' => $log_info_arr[1]];
            $count++;
        }
        // 判定存在数据，批量入库
        if ($count != 0) {
            foreach ($arr_tmp as $k => $v) {
                $arr['log_json']    = $v['log_json'];
                $arr['create_time'] = $v['create_time'];
                $arr_in[]           = $arr;
            }
            $res = Db::table($table)->insertAll($arr_in);
            var_dump('$res', $res);
            // 输出入库log和入库结果;
            echo date("Y-m-d H:i:s") . " insert " . $count . " log info result:";
            echo json_encode($res);
            echo "</br>\n";
            echo execute_time();
            // 数据库插入失败回滚
            if (!$res) {
                foreach ($roll_back_arr as $value) {
                    $this->rPush($this->log_key, $value);
                }
            }
        }
        // 释放redis
        $this->close();
    }


    public function batchPopToDb($table)
    {
        // 获取现有消息队列的长度
        $count = 0;
        $max   = $this->lLen($this->log_key);
        // 回滚数组
        $roll_back_arr = [];
        $arr_tmp       = [];
        $arr_in        = [];
        while ($count < $max) {
            $log_info        = $this->lPop($this->log_key);
            $roll_back_arr[] = $log_info;
            if ($log_info == 'nil' || !isset($log_info)) {
                break;
            }
            // 切割出时间和info
            $log_info_arr = explode("%", $log_info);
            $arr_tmp[] =
                ['log_json' => $log_info_arr[0], 'create_time' => $log_info_arr[1]];
            $count++;
        }
        // 判定存在数据，批量入库
        if ($count != 0) {
            foreach ($arr_tmp as $k => $v) {
                $arr['log_json']    = $v['log_json'];
                $arr['create_time'] = $v['create_time'];
                $arr_in[]           = $arr;
            }
            $res = Db::connect(Config('mysql_db'))->table($table)->insertAll($arr_in);
            var_dump('$res', $res);
            // 输出入库log和入库结果;
            echo date("Y-m-d H:i:s") . " insert " . $count . " log info result:";
            echo json_encode($res);
            echo "</br>\n";
            echo execute_time();
            // 数据库插入失败回滚
            if (!$res) {
                foreach ($roll_back_arr as $value) {
                    $this->rPush($this->log_key, $value);
                }
            }
        }
        // 释放redis
        $this->close();
    }

    /**
     * @Notes  : RedisLog 模块
     * ->@Notes  : 逐个入库
     * @param $table
     * @return :int|string
     * @throws \think\Exception
     * @throws \think\exception\PDOException
     * @user   : XiaoMing
     * @time   : 2020/6/20_17:34
     */
    public function logPopToDb($table)
    {
        $count = 0;
        while (true) {
            var_dump("队列长度为" . $this->lLen($this->log_key) . '开始----');
            try {
                if ($this->lLen($this->log_key) > 0) {
                    var_dump("出栈开始----");
                    $task = $this->lPop($this->log_key);
                    if ($task == 'nil' || !isset($task)) {
                        sleep(3);
                        break;
                    }
                    sleep(1);
                    if (!empty($task)) {
                        $inse_data['log_json'] = $task;
                        var_dump("出队的值$count", $task);
                        $res1 = \think\Db::table('test')->insertGetId($inse_data);
//                        $res1                = false;
                        // 数据库插入失败回滚
                        if (!$res1) {
                            $this->rPush($this->log_key, $task);
                            continue;
                        }
                        var_dump($this->lLen($this->log_key));
                        $sql                 = \think\Db::table('test')->getLastSql();
                        $log_sql ['log_sql'] = $sql;
                        $res2                = \think\Db::table('test')->where('id', '=', $res1)->update($log_sql);
                    }
                } else {
                    sleep(1);
                    return "队列长度为" . $this->lLen($this->log_key) . " 出队列完毕----！";
//                    break;
                }
            } catch (\RedisException $exception) {
                echo $exception->getMessage();
            }
            $count++;
        }
        return $count;
    }

    /**
     * 获取主日志文件名
     * @access public
     * @return string
     */
    protected function getMasterLogFile()
    {
        if ($this->config['single']) {
            $name        = is_string($this->config['single']) ? $this->config['single'] : 'single';
            $destination = $this->config['path'] . $name . '.log';
        } else {
            $cli = PHP_SAPI == 'cli' ? '_cli' : '';
            if ($this->config['max_files']) {
                $filename = date('Ymd') . $cli . '.log';
                $files    = glob($this->config['path'] . '*.log');
                try {
                    if (count($files) > $this->config['max_files']) {
                        unlink($files[0]);
                    }
                } catch (\Exception $e) {
                }
            } else {
                $filename = date('Ym') . DIRECTORY_SEPARATOR . date('d') . $cli . '.log';
            }
            $destination = $this->config['path'] . $filename;
        }
        return $destination;
    }

    /**
     * 获取独立日志文件名
     * @access public
     * @param string $path 日志目录
     * @param string $type 日志类型
     * @return string
     */
    protected function getApartLevelFile($path, $type)
    {
        $cli = PHP_SAPI == 'cli' ? '_cli' : '';
        if ($this->config['single']) {
            $name = is_string($this->config['single']) ? $this->config['single'] : 'single';
            $name .= '_' . $type;
        } elseif ($this->config['max_files']) {
            $name = date('Ymd') . '_' . $type . $cli;
        } else {
            $name = date('d') . '_' . $type . $cli;
        }
        return $path . DIRECTORY_SEPARATOR . $name . '.log';
    }

    /**
     * 日志写入
     * @access protected
     * @param array $message 日志信息
     * @param string $destination 日志文件
     * @param bool $apart 是否独立文件写入
     * @param bool $append 是否追加请求信息
     * @return bool
     */
    protected function write($message, $destination, $apart = false, $append = false)
    {
        // 检测日志文件大小，超过配置大小则备份日志文件重新生成
        $this->checkLogSize($destination);
        // 日志信息封装
        $info['timestamp'] = date($this->config['time_format']);
        foreach ($message as $type => $msg) {
            $info[$type] = is_array($msg) ? implode("\r\n", $msg) : $msg;
        }
        if (PHP_SAPI == 'cli') {
            $message = $this->parseCliLog($info);
        } else {
            // 添加调试日志
            $this->getDebugLog($info, $append, $apart);
            $message = $this->parseLog($info);
        }
        return error_log($message, 3, $destination);
    }

    /**
     * 检查日志文件大小并自动生成备份文件
     * @access protected
     * @param string $destination 日志文件
     * @return void
     */
    protected function checkLogSize($destination)
    {
        if (is_file($destination) && floor($this->config['file_size']) <= filesize($destination)) {
            try {
                rename($destination, dirname($destination) . DIRECTORY_SEPARATOR . time() . '-' . basename($destination));
            } catch (\Exception $e) {
            }
        }
    }

    /**
     * CLI日志解析
     * @access protected
     * @param array $info 日志信息
     * @return string
     */
    protected function parseCliLog($info)
    {
        if ($this->config['json']) {
            $message = json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\r\n";
        } else {
            $now = $info['timestamp'];
            unset($info['timestamp']);
            $message = implode("\r\n", $info);
            $message = "[{$now}]" . $message . "\r\n";
        }
        return $message;
    }

    /**
     * 解析日志
     * @access protected
     * @param array $info 日志信息
     * @return string
     */
    protected function parseLog($info)
    {
        $request     = Request::instance();
        $requestInfo = [
            'ip'     => $request->ip(),
            'method' => $request->method(),
            'host'   => $request->host(),
            'uri'    => $request->url(),
        ];
        if ($this->config['json']) {
            $info = $requestInfo + $info;
            return json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\r\n";
        }
        array_unshift($info, "[{$info['timestamp']}] {$requestInfo['ip']} {$requestInfo['method']} {$requestInfo['host']}{$requestInfo['uri']}");
        unset($info['timestamp']);
        return implode("\r\n", $info) . "\r\n";
    }

    protected function getDebugLog(&$info, $append, $apart)
    {
        if (App::$debug && $append) {
            if ($this->config['json']) {
                // 获取基本信息
                $runtime    = round(microtime(true) - THINK_START_TIME, 10);
                $reqs       = $runtime > 0 ? number_format(1 / $runtime, 2) : '∞';
                $memory_use = number_format((memory_get_usage() - THINK_START_MEM) / 1024, 2);
                $info       = [
                        'runtime' => number_format($runtime, 6) . 's',
                        'reqs'    => $reqs . 'req/s',
                        'memory'  => $memory_use . 'kb',
                        'file'    => count(get_included_files()),
                    ] + $info;
            } elseif (!$apart) {
                // 增加额外的调试信息
                $runtime    = round(microtime(true) - THINK_START_TIME, 10);
                $reqs       = $runtime > 0 ? number_format(1 / $runtime, 2) : '∞';
                $memory_use = number_format((memory_get_usage() - THINK_START_MEM) / 1024, 2);
                $time_str   = '[运行时间：' . number_format($runtime, 6) . 's] [吞吐率：' . $reqs . 'req/s]';
                $memory_str = ' [内存消耗：' . $memory_use . 'kb]';
                $file_load  = ' [文件加载：' . count(get_included_files()) . ']';
                array_unshift($info, $time_str . $memory_str . $file_load);
            }
        }
    }
}
