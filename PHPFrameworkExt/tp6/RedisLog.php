<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace think\log\driver;

use MongoDB\Collection;
use MongoDB\Driver\Manager;
use think\App;
use think\contract\LogHandlerInterface;
use think\Exception;
use think\facade\Request;
use think\facade\Db;

/**
 * 本地化调试输出到文件
 */
class RedisLog implements LogHandlerInterface
{
    var    $redis;
    public $log_key  = 'logtp6';
    public $host     = "";
    public $password = "";
    public $port     = "";

    protected $mongoManager;
    protected $mongoCollection;

    private static $_instance;
    /**
     * 配置参数
     * @var array
     */
    public $config
        = [
            'time_format'  => 'c',
            'single'       => false,
            'file_size'    => 2097152,
            'path'         => '',
            'apart_level'  => [],
            'max_files'    => 0,
            'json'         => false,
            'json_options' => JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            'format'       => '[%s][%s] %s',
        ];

    // 实例化并传入参数
    public function __construct($config = [])
    {
        $this->host     = env('redis.hostname', '127.0.0.1');
        $this->password = env('redis.auth', '123456');
        $this->port     = env('redis.port', 6379);
        $this->redis    = new \Redis();
        $this->redis->connect($this->host, $this->port);
        $auth                  = $this->redis->auth($this->password);
        $this->mongoManager    = new Manager($this->getUri());
        $this->mongoCollection = new Collection($this->mongoManager, "redis_log", "test");
        return $this->redis;
    }

    protected function getUri()
    {
        return getenv('MONGODB_URI') ?: 'mongodb://127.0.0.1:27017';
    }

    static public function getInstance()
    {
        if (FALSE == (self::$_instance instanceof self)) {

            self::$_instance = new self(env('redis.hostname', '127.0.0.1'), env('redis.port', 6379), env('redis.auth', '123456'));
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
     * @return bool
     */
    public function save(array $log): bool
    {
        $request     = Request::instance();
        $requestInfo = [
            'url'        => $request->url(true),
            'domain'     => $request->domain(),
            'root'       => $request->root(),
            'baseFile'   => $request->baseFile(),
            'file'       => $request->file(),
            //            'module' => $request->module(),
            'controller' => $request->controller(),
            'action'     => $request->action(),
            //            'routeInfo' => $request->routeInfo(),
            'query'      => $request->query(),
            'baseurl'    => $request->baseurl(),
            'pathinfo'   => $request->pathinfo(),
            //            'dispatch' => $request->dispatch(),
            'ext'        => $request->ext(),
            'isAjax'     => var_export($request->isAjax(), true),
            'ip'         => $request->ip(),
            'method'     => $request->method(),
            'host'       => $request->host(),
            'uri'        => $request->url(),
            'user-agent' => Request::header(),
            'agent'      => $_SERVER['HTTP_USER_AGENT'],
            'body'       => Request::param(),
            'time'       => date('Y-m-d H:i:s', time()),
            'server_ip'  => $_SERVER['SERVER_ADDR'],
        ];
        try {
            $destination = $this->getMasterLogFile();
            $path        = dirname($destination);
//        !is_dir($path) && mkdir($path, 0755, true);
            $info = [];
            // 日志信息封装
            $time = \DateTime::createFromFormat('0.u00 U', microtime())->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format($this->config['time_format']);
            foreach ($log as $type => $val) {
                $message = [];
                foreach ($val as $msg) {
                    if (!is_string($msg)) {
                        $msg = var_export($msg, true);
                    }
                    $message[] = $this->config['json']
                        ?
                        json_encode(['time' => $time, 'type' => $type, 'msg' => $msg], $this->config['json_options'])
                        :
                        sprintf($this->config['format'], $time, $type, $msg);
                }
                if (true === $this->config['apart_level'] || in_array($type, $this->config['apart_level'])) {
                    // 独立记录的日志级别
                    $filename = $this->getApartLevelFile($path, $type);
                    $this->write($message, $filename);
                    continue;
                }
                $info[$type] = $message;
            }
            // 完毕
            $arr_msg    = ['request_info' => $requestInfo, 'msg' => $message];
            $now_time   = date("Y-m-d H:i:s");
            $toRedisStr = json_encode($arr_msg, $this->config['json_options']) . "%" . $now_time;

            $ret = $this->rPush($this->log_key, $toRedisStr);
            $this->close();
        } catch (\RedisException $exception) {
            throw new Exception($exception->getMessage());
        }
        if (!empty($ret)) {
            var_dump("入队的结果", $ret);
            return (boolean)$ret ? true : false;
        } else {
            return "入队失败了！";
        }
        if ($info) {
//            return $this->write($info, $destination);
        }
//
        return true;
    }


    /**
     * @Notes  : RedisLog 模块 同一台服务测试用
     * 若多台部署到es3项目中
     * ->@Notes  : 日志出栈到MongoDB
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
        while ($count < $max) {
            $log_info        = $this->lPop($this->log_key);
            $roll_back_arr[] = $log_info;
            if ($log_info == 'nil' || !isset($log_info)) {
                break;
            }
            // 切割出时间和info
            // lrem data_list 0 test_function
            $log_info_arr = explode("%", $log_info);

            //{\"request_info\":
            $pre_log_info     = substr($log_info_arr[0], 0, 16);
            $request_info     = $log_info_arr[0];
            $arr_request_info = json_decode($request_info, true);

            $method     = $arr_request_info['request_info']['method'];
            $ip         = $arr_request_info['request_info']['ip'];
            $server_ip  = $arr_request_info['request_info']['server_ip'];
            $time       = $arr_request_info['request_info']['time'];
            $domain     = $arr_request_info['request_info']['domain'];
            $host       = $arr_request_info['request_info']['host'];
            $uri        = $arr_request_info['request_info']['uri'];
            $pathinfo   = $arr_request_info['request_info']['pathinfo'];
            $isAjax     = $arr_request_info['request_info']['isAjax'];
            $user_agent = $arr_request_info['request_info']['user-agent'];
            $body       = $arr_request_info['request_info']['body'];
            $arr_tmp[]
                        = [
                'method'          => $method,
                'ip'              => $ip,
                'server_ip'       => $server_ip,
                'time'            => $time,
                'domain'          => $domain,
                'host'            => $host,
                'uri'             => $uri,
                'pathinfo'        => $pathinfo,
                'is_ajax'         => $isAjax,
                'user_agent'      => $user_agent,
                'body'            => $body,
                'prefix_log_json' => $pre_log_info,
                'log_json'        => $log_info_arr[0],
                'create_time'     => $log_info_arr[1],
            ];
            $count++;
        }
        // 判定存在数据，批量入库
        if ($count != 0) {

            $res = $this->mongoCollection->insertMany($arr_tmp);
            // 输出入库log和入库结果;
            echo date("Y-m-d H:i:s") . " insert " . $count . " log info result:";
            echo json_encode($res);
            echo "</br>\n";
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
     * @Notes  : xx 模块
     * ->@Notes  : 日志出栈到MySQL
     * @param $table
     * @user   : XiaoMing
     * @time   : 2020/6/28_13:35
     */
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
            //{\"request_info\":
            $pre_log_info     = substr($log_info_arr[0], 0, 16);
            $request_info     = $log_info_arr[0];
            $arr_request_info = json_decode($request_info, true);

            $method     = $arr_request_info['request_info']['method'];
            $ip         = $arr_request_info['request_info']['ip'];
            $server_ip  = $arr_request_info['request_info']['server_ip'];
            $time       = $arr_request_info['request_info']['time'];
            $domain     = $arr_request_info['request_info']['domain'];
            $host       = $arr_request_info['request_info']['host'];
            $uri        = $arr_request_info['request_info']['uri'];
            $pathinfo   = $arr_request_info['request_info']['pathinfo'];
            $isAjax     = $arr_request_info['request_info']['isAjax'];
            $user_agent = $arr_request_info['request_info']['user-agent'];
            $body       = $arr_request_info['request_info']['body'];
            $arr_tmp[]
                        = [
                'method'          => $method,
                'ip'              => $ip,
                'server_ip'       => $server_ip,
                'time'            => $time,
                'domain'          => $domain,
                'host'            => $host,
                'uri'             => $uri,
                'pathinfo'        => $pathinfo,
                'is_ajax'         => $isAjax,
                'user_agent'      => json_encode($user_agent),
                'body'            => json_encode($body),
                'prefix_log_json' => $pre_log_info,
                'log_json'        => $log_info_arr[0],
                'create_time'     => $log_info_arr[1],
            ];
            $count++;
        }

        // 判定存在数据，批量入库
        if ($count != 0) {
            $res = Db::connect('mysql')->table($table)->insertAll($arr_tmp);
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
                        $inse_data['log_json']    = $task;
                        $inse_data['create_time'] = date("Y-m-d H:i:s");
//                        var_dump("出队的值$count", $task);
//                        $in_sql = "INSERT INTO test (log_json) VALUES ($task)";
//                        $res1 =  Db::execute($in_sql);//更新插入删除
//                        $res1 = Db::table('test')->insertGetId($inse_data);
//                        $res1                = false;
                        var_dump($inse_data);
                        var_dump('长度剩下', $this->lLen($this->log_key));
                        // 数据库插入失败回滚
//                        if (!$res1) {
//                            $this->rPush($this->log_key, $task);
//                            continue;
//                        }
//                        $sql = Db::table('test')->getLastSql();
//                        $log_sql ['log_sql'] = $sql;
//                        $res2 = Db::table('test')->where('id', '=', $res1)->update($log_sql);
                    }
                } else {
                    sleep(1);
                    return "队列长度为" . $this->lLen($this->log_key) . " 出队列完毕----！";
//                    break;
                }
            } catch (\RedisException $exception) {
//                echo $exception->getMessage();
            }
            $count++;
        }
        return $count;
    }

    /**
     * 日志写入
     * @access protected
     * @param array $message 日志信息
     * @param string $destination 日志文件
     * @return bool
     */
    protected function write(array $message, string $destination): bool
    {
        // 检测日志文件大小，超过配置大小则备份日志文件重新生成
        $this->checkLogSize($destination);

        $info = [];

        foreach ($message as $type => $msg) {
            $info[$type] = is_array($msg) ? implode(PHP_EOL, $msg) : $msg;
        }

        $message = implode(PHP_EOL, $info) . PHP_EOL;

        return error_log($message, 3, $destination);
    }

    /**
     * 获取主日志文件名
     * @access public
     * @return string
     */
    protected function getMasterLogFile(): string
    {

        if ($this->config['max_files']) {
            $files = glob($this->config['path'] . '*.log');

            try {
                if (count($files) > $this->config['max_files']) {
                    unlink($files[0]);
                }
            } catch (\Exception $e) {
                //
            }
        }

        if ($this->config['single']) {
            $name        = is_string($this->config['single']) ? $this->config['single'] : 'single';
            $destination = $this->config['path'] . $name . '.log';
        } else {

            if ($this->config['max_files']) {
                $filename = date('Ymd') . '.log';
            } else {
                $filename = date('Ym') . DIRECTORY_SEPARATOR . date('d') . '.log';
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
    protected function getApartLevelFile(string $path, string $type): string
    {

        if ($this->config['single']) {
            $name = is_string($this->config['single']) ? $this->config['single'] : 'single';

            $name .= '_' . $type;
        } elseif ($this->config['max_files']) {
            $name = date('Ymd') . '_' . $type;
        } else {
            $name = date('d') . '_' . $type;
        }

        return $path . DIRECTORY_SEPARATOR . $name . '.log';
    }

    /**
     * 检查日志文件大小并自动生成备份文件
     * @access protected
     * @param string $destination 日志文件
     * @return void
     */
    protected function checkLogSize(string $destination): void
    {
        if (is_file($destination) && floor($this->config['file_size']) <= filesize($destination)) {
            try {
                rename($destination, dirname($destination) . DIRECTORY_SEPARATOR . time() . '-' . basename($destination));
            } catch (\Exception $e) {
                //
            }
        }
    }
}
