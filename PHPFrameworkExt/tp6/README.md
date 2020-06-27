```PHP
namespace app\controller;

use app\BaseController;

use think\facade\Log;
use think\Container;
use MongoDB\Driver\Manager;
use MongoDB\Collection;
use think\facade\Db;
/**
 * Class MongoTest
 * @package app\index\controller
 */
class MongoTest extends BaseController
{
    protected $mongoManager;
    protected $mongoCollection;

    public function __construct()
    {
        $this->mongoManager = new Manager($this->getUri());

        $this->mongoCollection = new Collection($this->mongoManager, "redis_log", "test");
    }

    public function test()
    {
        // 读取一条数据
        p($this->mongoCollection);
        $data = $this->mongoCollection->findOne();
        p($data);
    }

    protected function getUri()
    {
        return getenv('MONGODB_URI') ?: 'mongodb://127.0.0.1:27017';
    }

    public function testMongoORMysql()
    {
        p(env('mongodb.type', ''));
        p(env('mongodb.database', ''));
        p(env('mongodb.hostname', ''));
        p(env('mongodb.username', ''));
        p(env('mongodb.password', ''));
        p(env('mongodb.hostport', ''));
        // 读取一条数据
        $data = $this->mongoCollection->findOne();
//        dd(\think\facade\Db::connect('mongo')->table('test')->value('log_json'));
        p($data);
        echo "--------我是通过mysql查询出来的数据----------<br/>";
        $res1 = Db::connect('mysql')->table('test')
            ->field('id')
//            ->where('log_json', 'like', '%aaaa%')
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
        //db.col.find({title:/教/})
        $filter1 = ['log_json' => ['$regex' => 'aa']];
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