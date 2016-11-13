## 百度开放云对象存储文件系统

通过php流包装器像操作本地文件系统一样操作百度开放云(BCE)的BOS对象存储。

### 简介

该项目实现了一个php的流包装器，提供从php文件读写函数到BOS云存储的映射。

加载BOSWrapper.class.php并注册BOSWrapper类为流协议后，即可用php文件读写函数通过 协议名://文件路径 操作BOS上的文件。

支持绝大多数php文件和目录操作函数，如读写（fopen、file_* 等）、目录遍历（opendir）、重命名（rename）、拷贝（copy）、删除（unlink、rmdir）、创建目录（mkdir）等。拷贝不仅支持从BOS到BOS，还支持在本地文件系统和BOS之间拷贝（这是php文件系统提供的特性）。

BOSWrapper.class.php中默认注册的流协议为 bosfs://　，您可直接使用。但在使用之前，您需配置 conf.inc.php 中的BOS相关信息（公钥、私钥、Bucket）。

### 用途

移植应用到BAE的难度之一就是BAE的本地文件操作是临时的，因而我们不得不重写文件上传的代码才能把文件存储在其他地方（如BOS），这很复杂。

但如果我们能做一个兼容层，使BOS在php上表现的就和本地文件系统一样，那么我们只要改动文件的上传目录就能完成移植了！

大部分设计良好的php程序都能通过调整配置文件轻松改变文件上传目录，因此移植一个程序到BOS只需以下几步：

1. 将本项目文件复制到已有的php程序中的适当位置。
2. 在BOS中创建Bucket，配置 conf.inc.php。
3. 在原程序的主配置文件中：
```php
require_once '本项目路径/BOSWrapper.class.php';
```
4. 调整原程序的配置，将文件上传目录改为：
```php
bosfs://任意目录
```
5. 调整原程序的配置，将文件浏览URL改为（Bucket读写权限须设置为公共读）：
```php
http://你的Bucket.地区.bcebos.com/第四部指定的目录
```
6. 移植轻松完成。

### Mediawiki插件

如果您正在使用Mediawiki，您可以直接使用本项目的Mediawiki插件。使用方法如下：

1. 将整个BOSFS目录复制到Mediawiki的extensions目录下。
2. 在 LocalSettings.php 中加入如下语句：
      require_once "$IP/extensions/BOSFS/BOSFS.php";      
3. 去BOS创建Bucket，然后修改文件上传设置：
```php
$wgEnableUploads = true;
$wgUploadDirectory = 'bosfs://upload';
$wgUploadPath = 'http://你的Bucket.地区.bcebos.com/upload';
```
4. 正确配置插件目录中的 conf.inc.php 后即可使用文件上传。

### 例子

以下是使用本项目读写BOS文件的例子：
```php
<?php
//提示：使用前请先配置conf.inc.php
//加载流包装器
require_once './BOSWrapper.class.php';
//创建目录
mkdir('bosfs://test', 0777);
//写文件
file_put_contents('bosfs://test/a.txt', '老虎是个道童');
//拷贝
copy('bosfs://test/a.txt', 'bosfs://test/b.txt');
//修改
$fp = fopen('bosfs://test/b.txt', 'r+');
fseek($fp, -6, SEEK_END); //假设用UTF-8编码
fwrite($fp, '程序员');
fclose($fp);
//输出
readfile('bosfs://test/b.txt');
//删除
unlink('bosfs://test/b.txt');
unlink('bosfs://test/a.txt');
rmdir('bosfs://test');
?>
```

### 已知的问题

1. 不支持跨Bucket操作，目前Bucket在conf.inc.php中写定，一次只能操作一个Bucket。
2. 程序把BOSFS当作lock文件存放目录时会发生锁文件无法删除的问题。建议程序使用本地目录作为lock文件存放目录，或者干脆关掉lock功能。
3. 支持UTF-8编码的中文文件名，但是GBK支持未知（要看BOS是否支持），
因此建议文件名只使用UTF-8编码。
4. 无法删除目录。

### 技术细节

BOS本身并无目录中，以/结尾的文件会被视为目录。

### 作者

老虎会游泳 <hu60.cn@gmail.com>

### 授权

#### 放弃著作权声明

自本声明发布起，本人自愿放弃该项目的一切著作权，将其置入公有领域。注意：该项目中仅本人所有的部分置入公有领域，其他部分（如BCE SDK）的著作权保护状态维持不变。

置入公有领域的文件列表：
　　
* BOSFS.php //BOSFS的Mediawiki插件

* BOSWrapper.class.php //BcsFS的流包装器

* README.md //本说明文件

### 版本库

该项目发布在Github上，您可通过以下网址获取更新或提交贡献：http://github.com/SwimmingTiger/BOSFS

### 时间

BcsFS的README：老虎会游泳写于 2016年2月26日 15:06:23（丙申年正月十九未时）
BOSFS的README：更新于 2016年6月20日 13:54:14（丙申年五月十六未时）
