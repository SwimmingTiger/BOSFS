<?php
/**
* BOSFS的Mediawiki插件
* 
* 使用方法：
*     在 LocalSettings.php 中加入如下语句：
*         require_once "$IP/extensions/BOSFS/BOSFS.php";
*     修改文件上传设置：
*         $wgUploadDirectory = 'bosfs://upload';
*         $wgUploadPath = 'http://你的Bucket.地区.bcebos.com/upload';
*     然后正确配置当前目录中的 conf.inc.php 后即可使用。
*/

//提醒用户不能直接访问文件
if ( !defined( 'MEDIAWIKI' ) ) {
	echo <<<EOT
请在LocalSettings.php里添加以下语句来安装本插件:
require_once "\$IP/extensions/BOSFS/BOSFS.php";
EOT;
	exit( 1 );
}

$wgExtensionCredits[ 'other' ][] = array(
	'path' => __FILE__,
	'name' => 'BOS文件系统',
	'author' => '老虎会游泳',
	'url' => 'http://github.com/SwimmingTiger/BOSFS',
	'descriptionmsg' => '可通过“上传文件”页面上传文件至BOS云存储。',
	'version' => '0.2.1',
);

require dirname(__FILE__).'/BOSWrapper.class.php';
