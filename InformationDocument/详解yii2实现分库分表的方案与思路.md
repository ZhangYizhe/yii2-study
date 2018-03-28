# 详解yii2实现分库分表的方案与思路
> 这篇文章主要介绍了利用yii2实现分库分表的方案与思路，在研究yii2如何分库分表之前，我先对yii2的核心概念和框架结构做了一个初步的探索，从而找到分库分表的思路。有需要的朋友可以参考借鉴，下面来一起看看吧。


## 前言

大家可以从任何一个gii生成model类开始代码上溯，会发现：yii2的model层基于ActiveRecord实现DAO访问数据库的能力。

而ActiveRecord的继承链可以继续上溯，最终会发现model其实是一个component，而component是yii2做IOC的重要组成部分，提供了behaviors，event的能力供继承者扩展。（IOC，component，behaviors，event等概念可以参考http://www.digpage.com/学习）

先不考虑上面的一堆概念，一个站点发展历程一般是1个库1个表，1个库N个表，M个库N个表这样走过来的，下面拿订单表为例，分别说说。

-------

### 1）1库1表
yii2默认采用PDO连接mysql，框架默认会配置一个叫做db的component作为唯一的mysql连接对象，其中dsn分配了数据库地址，数据库名称，配置如下：


```
'components' => [
 'db' => [
 'class' => 'yii\db\Connection',
 'dsn' => 'mysql:host=10.10.10.10;port=4005;dbname=wordpress',
 'username' => 'wp',
 'password' => '123',
 'charset' => 'utf8',
 ],
```
这就是yii2做IOC的一个典型事例，model层默认就会取这个db做为mysql连接对象，所以model访问都经过这个connection，可以从ActiveRecord类里看到。

```
class ActiveRecord extends BaseActiveRecord {
  
/**
 * Returns the database connection used by this AR class.
 * By default, the "db" application component is used as the database connection.
 * You may override this method if you want to use a different database connection.
 * @return Connection the database connection used by this AR class.
 */
public static function getDb()
{
 return Yii::$app->getDb();
}
```

追踪下去，最后会走yii2的ioc去创建名字叫做”db”的这个component返回给model层使用。


```
abstract class Application extends Module {
/**
 * Returns the database connection component.
 * @return \yii\db\Connection the database connection.
 */
public function getDb()
{
 return $this->get('db');
}
```

yii2上述实现决定了只能连接了1台数据库服务器，选择了其中1个database，那么具体访问哪个表，是通过在Model里覆写tableName这个static方法实现的，ActiveRecord会基于覆写的tableName来决定表名是什么。


```
class OrderInfo extends \yii\db\ActiveRecord
{
 /**
 * @inheritdoc
 * @return
 */
 public static function tableName()
 {
 return 'order_info';
 }
```

-------

###  2）1库N表
 因为orderInfo数据量变大，各方面性能指标有所下降，而单机硬件性能还有较大冗余，于是可以考虑分多张order_info表，均摊数据量。假设我们要份8张表，那么可以依据uid（用户ID）%8来决定订单存储在哪个表里。
 
然而1库1表的时候，`tableName()`返回是的order_info，于是理所应当的重载这个函数，提供一种动态变化的能力即可，例如：


```
class OrderInfo extends \yii\db\ActiveRecord
{
 private static $partitionIndex_ = null; // 分表ID
  
 /**
 * 重置分区id
 * @param unknown $uid
 */
 private static function resetPartitionIndex($uid = null) {
 $partitionCount = \Yii::$app->params['Order']['partitionCount'];
  
 self::$partitionIndex_ = $uid % $partitionCount;
 }
  
 /**
 * @inheritdoc
 */
 public static function tableName()
 {
 return 'order_info' . self::$partitionIndex_;
 }
```

提供一个`resetParitionIndex($uid)`函数，在每次操作model之前主动调用来标记分表的下标，并且重载tableName来为model层拼接生成本次操作的表名。

-------

### 3）M库N表
1库N表逐渐发展，单机存储和性能达到瓶颈，只能将数据分散到多个服务器存储，于是提出了分库的需求。但是从”1库1表”的框架实现逻辑来看，model层默认取db配置作为mysql连接的话，是没有办法访问多个mysql实例的，所以必须解决这个问题。

一般产生这个需求，产品已经进入中期稳步发展阶段。有2个思路解决M库问题，1种是yii2通过改造直连多个地址进行访问多库，1种是yii2仍旧只连1个地址，而这个地址部署了dbproxy，由dbproxy根据你访问的库名代理连接多个库。

如果此前没有熟练的运维过dbproxy，并且php集群规模没有大到单个mysql实例客户端连接数过多拒绝服务的境地，那么第1种方案就可以解决了。否则，应该选择第2种方案。

无论选择哪种方案，我们都应该进一步改造tableName()函数，为database名称提供动态变化的能力，和table动态变化类似。

```
class OrderInfo extends \yii\db\ActiveRecord {
  
private static $databaseIndex_ = null; // 分库ID
private static $partitionIndex_ = null; // 分表ID
  
 /**
 * 重置分区id
 * @param unknown $uid
 */
 private static function resetPartitionIndex($uid = null) {
 $databaseCount = \Yii::$app->params['Order']['databaseCount'];
 $partitionCount = \Yii::$app->params['Order']['partitionCount'];
  
 // 先决定分到哪一张表里
 self::$partitionIndex_ = $uid % $partitionCount;
 // 再根据表的下标决定分到哪个库里
 self::$databaseIndex_ = intval(self::$partitionIndex_ / ($partitionCount / $databaseCount));
 }
  
 /**
 * @inheritdoc
 */
 public static function tableName()
 {
 $database = 'wordpress' . self::$databaseIndex_;
 $table = 'order_info' . self::$partitionIndex_;
 return $database . '.' . $table;
 }
```

在分表逻辑基础上稍作改造，即可实现分库。假设分8张表，那么分别是00,01,02,03…07，然后决定分4个库，那么00，01表在00库，02，03表在01库，04，05表在02库，06，07表在03库，根据这个规律对应的计算代码如上。最终ActiveRecord生效的代码都会类似于”select * from wordpress0.order_info1″，这样就可以解决连接dbproxy访问多库的需求了。

那么yii直接访问多Mysql实例怎么做呢，其实类似tableName() ，我们只需要覆盖getDb()方法即可，同时要求我们首先配置好4个mysql实例，从而可以通过yii的application通过IOC设计来生成多个db连接，所有改动如下：

先配置好4个数据库，给予不同的component id以便区分，它们连接了不同的mysql实例，其中dsn里的dbname只要存在即可(防止PDO执行use database时候不存在报错)，真实的库名是通过tableName()动态变化的。


```
'db0' => [
 'class' => 'yii\db\Connection',
 'dsn' => 'mysql:host=10.10.10.10;port=6184;dbname=wordpress0',
 'username' => 'wp',
 'password' => '123',
 'charset' => 'utf8',
 // 'tablePrefix' => 'ktv_',
],
'db1' => [
 'class' => 'yii\db\Connection',
 'dsn' => 'mysql:host=10.10.10.11;port=6184;dbname=wordpress2',
 'username' => 'wp',
 'password' => '123',
 'charset' => 'utf8',
 // 'tablePrefix' => 'ktv_',
],
'db2' => [
 'class' => 'yii\db\Connection',
 'dsn' => 'mysql:host=10.10.10.12;port=6184;dbname=wordpress4',
 'username' => 'wp',
 'password' => '123',
 'charset' => 'utf8',
 // 'tablePrefix' => 'ktv_',
],
'db3' => [
 'class' => 'yii\db\Connection',
 'dsn' => 'mysql:host=10.10.10.13;port=6184;dbname=wordpress6',
 'username' => 'wp',
 'password' => '123',
 'charset' => 'utf8',
 // 'tablePrefix' => 'ktv_',
],
```

覆写getDb()方法，根据库下标返回不同的数据库连接即可。


```
class OrderInfo extends \yii\db\ActiveRecord
{
 private static $databaseIndex_ = null; // 分库ID
 private static $partitionIndex_ = null; // 分表ID
  
 /**
 * 重置分区id
 * @param unknown $uid
 */
 private static function resetPartitionIndex($uid = null) {
 $databaseCount = \Yii::$app->params['Order']['databaseCount'];
 $partitionCount = \Yii::$app->params['Order']['partitionCount'];
  
 // 先决定分到哪一张表里
  
 self::$partitionIndex_ = $uid % $partitionCount;
 // 再根据表的下标决定分到哪个库里
 self::$databaseIndex_ = intval(self::$partitionIndex_ / ($partitionCount / $databaseCount));
 }
  
 /**
 * 根据分库分表,返回库名.表名
 */
 public static function tableName()
 {
 $database = 'wordpress' . self::$databaseIndex_;
 $table = 'order_info' . self::$partitionIndex_;
 return $database . '.' . $table;
 }
  
 /**
 * 根据分库结果,返回不同的数据库连接
 */
 public static function getDb()
 {
 return \Yii::$app->get('db' . self::$databaseIndex_);
 }
```

这样，无论是yii连接多个mysql实例，还是yii连接1个dbproxy，都可以实现了。

网上有一些例子，试图通过component的event机制，通过在component的配置中指定onUpdate,onBeforeSave等自定义event去hook不同的DAO操作来隐式（自动）的变更database或者connection或者tablename的做法，都是基于model object才能实现的，如果直接使用model class的类似updateAll()方法的话，是绕过DAO直接走了PDO的，不会触发这些event，所以并不是完备的解决方案。

这样的方案原理简单，方案对框架无侵入，只是每次DB操作前都要显式的resetPartitionIndex($uid)调用。如果要做到用户无感知，那必须对ActiveRecord类进行继承，进一步覆盖所有class method的实现以便插入选库选表逻辑，代价过高。


-------
补充：关于分库分表的一些实践细节，分表数量建议2^n，例如n=3的情况下分8张表，然后确定一下几个库，库数量是2^m，但要<=表数量，例如这里1个库，2个库，4个库，8个库都是可以的，表顺序坐落在这些库里即可。
为什么数量都是2指数，是因为如果面临扩容需求，数据的迁移将方便一些。假设分了2张表，数据按uid%2打散，要扩容成4张表，那么只需要把表0的部分数据迁移到表2，表1的部分数据迁移到表3，即可完成扩容，也就是uid%2和uid%4造成的迁移量是很小的，这个可以自己算一下。


