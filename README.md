【作者】
　　老虎会游泳 <hu60.cn@gmail.com>

【授权】
　　　　　　　　　　放弃著作权声明
　　自本声明发布起，本人自愿放弃该项目的一切著作权，将其
置入公有领域。注意：该项目中仅本人所有的部分置入公有领域，
其他部分（如BCE SDK）的著作权保护状态维持不变。
　　置入公有领域的文件列表：
BOSFS.php //BOSFS的Mediawiki插件
BOSWrapper.class.php //BcsFS的流包装器
README.txt //本说明文件

【版本库】
　　该项目发布在Github上，您可通过以下网址获取更新或提交
贡献：http://github.com/SwimmingTiger/BOSFS

【简介】
　　该项目实现了一个php的流包装器，提供从php文件读写函数
到BOS云存储的映射。
　　加载BOSWrapper.class.php并注册BOSWrapper类为流协议后，
即可用php文件读写函数通过 协议名://文件路径 操作BOS上的文
件。
　　支持绝大多数php文件和目录操作函数，如读写（fopen、file_*
等）、目录遍历（opendir）、重命名（rename）、拷贝（copy）、
删除（unlink、rmdir）、创建目录（mkdir）等。拷贝不仅支持从
BOS到BOS，还支持在本地文件系统和BOS之间拷贝（这是php文件系统
提供的特性）。
　　BOSWrapper.class.php中默认注册的流协议为 bosfs://　，
您可直接使用。但在使用之前，您需配置 conf.inc.php 中的BOS
相关信息（公钥、私钥、Bucket）。

【用途】
　　移植应用到BAE的难度之一就是BAE的本地文件操作是临时的，
因而我们不得不重写文件上传的代码才能把文件存储在其他地方
（如BOS），这很复杂。
　　但如果我们能做一个兼容层，使BOS在php上表现的就和本地
文件系统一样，那么我们只要改动文件的上传目录就能完成移植
了！
　　大部分设计良好的php程序都能通过调整配置文件轻松改变
文件上传目录，因此移植一个程序到BOS只需以下几步：
　　1. 将本项目文件复制到已有的php程序中的适当位置。
　　2. 在BOS中创建Bucket，配置 conf.inc.php。
　　3. 在原程序的主配置文件中：
require_once '本项目路径/BOSWrapper.class.php';
　　4. 调整原程序的配置，将文件上传目录改为：
bosfs://任意目录
　　5. 调整原程序的配置，将文件浏览URL改为（Bucket读写权限
须设置为公共读）：
http://你的Bucket.地区.bcebos.com/第四部指定的目录
　　6. 移植轻松完成。

【Mediawiki插件】
　　如果您正在使用Mediawiki，您可以直接使用本项目的Mediawiki
插件。使用方法如下：
　　1. 将整个BOSFS目录复制到Mediawiki的extensions目录下。
　　2. 在 LocalSettings.php 中加入如下语句：
require_once "$IP/extensions/BOSFS/BOSFS.php";
　　3. 去BOS创建Bucket，然后修改文件上传设置：
$wgEnableUploads = true;
$wgUploadDirectory = 'bosfs://upload';
$wgUploadPath = 'http://你的Bucket.地区.bcebos.com/upload';
　　4. 正确配置插件目录中的 conf.inc.php 后即可使用文件上传。

【例子】
以下是使用本项目读写BOS文件的例子：

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

【已知的问题】
　　1. 不支持跨Bucket操作，目前Bucket在conf.inc.php中写定，一次
只能操作一个Bucket。
　　2. 锁定文件的操作是空操作，因此flock()不能锁定BOS上的文件，虽
然调用flock会返回真（这可以蒙骗某些需要加锁才能使用的程序，比如
Mediawiki的文件上传）。
　　3. 无缓存，每次获取文件状态都会重新从BOS读取文件信息，因此操作
大量文件可能会很慢。
　　4. 支持UTF-8编码的中文文件名，但是GBK支持未知（要看BOS是否支持），
因此建议文件名只使用UTF-8编码。
　　5. 不支持文件权限更改，文件和目录权限在流包装器里写死。
　　6. 目录删除后，在开发者服务管理的BOS文件管理中仍能看见目录。

【技术细节】
　　BOS不支持目录状态查询，因此为了完整实现目录创建、删除，本项目
在创建目录时自动在目录下创建一个.meta文件，如果该文件存在，则表示
目录存在，否则目录不存在。
　　因此请不要在开发者服务管理中删除目录下的.meta文件，否则该项目
将无法正常读取目录内容。

【时间】
　　老虎会游泳写于 2016年2月26日 15:06:23（丙申年正月十九未时）
