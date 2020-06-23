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

```PHP
namespace app\index\controller;
use think\Config;
use think\Controller;
use MongoDB\Driver\Manager;
use MongoDB\Collection;
use think\Db;
/**
 * Class MongoTest
 * @package app\index\controller
 */
class MongoTest extends Controller
{
    protected $mongoManager;
    protected $mongoCollection;
    /**
     * MongoTest constructor
     */
    public function __construct()
    {
        $this->mongoManager    = new Manager($this->getUri());
        $this->mongoCollection = new Collection($this->mongoManager, "redis_log", "test");
    }
    /**
     * @Notes  : xx 模块
     * ->@Notes  : 获取 getUri
     * @return :string
     * @user   : XiaoMing
     * @time   : 2020/6/23_14:21
     */
    protected function getUri()
    {
        return Config::get('database.MONGODB_URI') ?: 'mongodb://' . Config::get('database.hostname') . ':' . Config::get('database.hostport');
    }
    /**
     * @Notes  : xx 模块
     * ->@Notes  : 获取 xx
     * @return :string
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * @user   : XiaoMing
     * @time   : 2020/6/23_14:08
     */
    public function testMongoORMysql()
    {
        // 读取一条数据
        $data = $this->mongoCollection->findOne();
        p($data);
        echo "--------我是通过mysql查询出来的数据----------<br/>";
        $res1 = Db::connect(Config('mysql_db'))->table('test')
            ->field('id')
            ->where('log_json', 'like', '%aaaa%')
            ->select();
        p($res1);
        return "成功完成";
    }
    /**
     * @Notes  : xx 模块
     * ->@Notes  : 获取 xx
     * @user   : XiaoMing
     * @time   : 2020/6/23_14:09
     */
    public function testMongoDb()
    {
//        $res = Db::name('test')
//            ->field('*')
////            ->where('log_json LIKE :log_json ',['log_json'=>'%5210690730389%'])
//            ->where('log_json', 'like', '%php%')
//            ->select();
        //db.col.find({title:/教/})
        $filter1 = ['log_json' => ['$regex' => 'mongodb2']];
        $filter2 = ['log_json' => ['$regex' => '^{"a:{"b"']];
        $filter_end = ['log_json' => ['$regex' => 'RedisLog$']];
        $filter_time =   ['create_time' =>['$lt' => '2020-06-22 16:11:23']];
        $options = [
            'projection' => ['_id' => 0],
            'sort' => ['x' => -1],
        ];
        //执行开始时间
        proStartTime();
        $cursor = $this->mongoCollection->find($filter1)->toArray();
        $cursor_end = $this->mongoCollection->find($filter_end)->toArray();
//        $cursor_time = $this->mongoCollection->find($filter_time)->toArray();
        //执行结束时间
        $exe_time2 =  proEndTime();
//        $cursor = $this->mongoCollection->aggregate($filter1)->toArray();
        $exe_time = execute_time();
        p($exe_time);
        p($exe_time2);
        p($cursor_end);
        dd($cursor);
        die;
    }
    public function example()
    {
        $user = ['name' => 'caleng', 'email' => '[email protected]'];
        // 新增
        $this->mongoCollection->insert($user);
        $newdata = ['$set' => ['email' => '[email protected]']];
        // 修改
        $this->mongoCollection->update([name => caleng], $newdata);
        $this->mongoCollection->remove(['name' => 'caleng'], [justOne => true]);
        // 删除
        $cursor = $this->mongoCollection->find();
        // 查找
        var_dump($cursor);
        $user = $this->mongoCollection->findOne(['name' => 'caleng'], ['email']);
        // 查找一条
        var_dump($user);
    }
}



```