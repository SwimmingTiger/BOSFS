<?php
require_once __DIR__.'/bce-php-sdk/BaiduBce.phar';
require_once __DIR__.'/conf.inc.php';

use BaiduBce\BceClientConfigOptions;
use BaiduBce\Util\Time;
use BaiduBce\Util\MimeTypes;
use BaiduBce\Http\HttpHeaders;
use BaiduBce\Services\Bos\BosClient;

/**
* BOSWrapper异常类
*/
class BOSWrapperException extends Exception {
	//与父类一致
}

/**
* BOS文件系统流包装器
* 
* 该类实现从php文件读写函数到BOS云存储的映射。
* 注册该类为流协议后可通过 协议名://文件路径 作为路径用php文件读写函数操作BOS上的文件。
* 该类默认注册的流协议为 bosfs://
* 
* 作者：老虎会游泳 <hu60.cn@gmail.com>
* 授权：公共领域
*/
class BOSWrapper {
	///BOS的Bucket，由conf.inc.php里的文件决定
	const BUCKET = BOS_BUCKET;
	
	///默认权限
	const DEFAULT_MODE = 0644;
	
	///BOS对象，全局共享
	protected static $bos = NULL;
	
	///文件状态缓存，全局共享
	protected static $statCache = array();
	
	///是否发送错误
	protected $isTriggerError = true;
	
	///文件不存在时自动创建
	protected $autoCreate = NULL;
	///文件已存在时打开失败
	protected $existsFailed = NULL;
	///强制清空文件内容
	protected $cleanContent = NULL;
	
	///临时文件路径
	protected $tmpPath = NULL;
	///临时文件句柄
	protected $tmpFp = NULL;
	///文件是否被改变
	protected $changed = NULL;
	
	//目录前缀
	protected $prefix = NULL;
	//目录内容列表
	protected $contentList = NULL;
	//下一页起始记录的名称
	protected $marker = NULL;
	//是否还有下一页
	protected $hasNext = NULL;
	
	/**
	* 初始化流对象
	*/
	public function __construct() {
		if (self::$bos === NULL) {
			$config = 
				array(
					'credentials' => array(
						'ak' => BOS_AK,
						'sk' => BOS_SK,
					),
					'endpoint' => BOS_ENDPOINT,
				);
				
			self::$bos = new BOSClient($config);
		}
	}
	
	/**
	* 销毁流对象
	*/
	public function __destruct() {
		if (NULL !== $this->tmpFp) {
			fclose($this->tmpFp);
		}
		
		if (!empty($this->tmpPath) && is_file($this->tmpPath)) {
			unlink($this->tmpPath);
		}
	}
	
	/**
	* 获取文件状态
	*/
	protected function & getStat($path) {
		if (!isset(self::$statCache[$path])) {
			try {
				$meta = self::$bos->getObjectMetadata(self::BUCKET, $path);
				$this->setStatByMeta($path, $meta);
			}
			catch (Exception $e) {
				//获取失败
				self::$statCache[$path] = false;
			}
		}

		return self::$statCache[$path];
	}
	
	/**
	* 清除文件状态缓存
	*/
	protected function clearStatCache($path) {
		if (isset(self::$statCache[$path])) {
			unset(self::$statCache[$path]);
		}
	}
	
	/**
	* 通过Meta设置文件缓存
	*/
	protected function setStatByMeta($path, $meta) {
		$stat = &self::$statCache[$path];
		$stat['size'] = $meta['contentLength'];
		$stat['mtime'] = $meta['lastModified']->getTimestamp();
		$stat['ctime'] = isset($meta['userMetadata']['ctime']) ? $meta['userMetadata']['ctime'] : $stat['mtime'];
		$stat['mode'] = isset($meta['userMetadata']['mode']) ? octdec($meta['userMetadata']['mode']) : self::DEFAULT_MODE;
	}
	
	/**
	* 通过文件缓存产生带user meta的options数组
	*/
	protected function mkMetaOptions($path) {
		$stat = self::$statCache[$path];
		$options = 
			array(
				'userMetadata' =>
					array(
						'ctime' => (string) ($stat ? $stat['ctime'] : time()),
						'mode' => decoct($stat ? $stat['mode'] : self::DEFAULT_MODE),
					),
			);
			
		return $options;
	}
	
	/**
	* 判断Object是否存在
	*/
	protected function objectExists($path) {
		return false !== $this->getStat($path);
	}
	
	/**
	* 打开流
	*/
	public function stream_open($path, $mode, $options, &$opened_path) {
		#self::log("OPEN '$path' '$mode'");
		
		//根据$options中的STREAM_REPORT_ERRORS标志位决定是否触发错误提示
		$this->isTriggerError = STREAM_REPORT_ERRORS & $options;
		
		if (!$this->parsePath($path)) {
			return false;
		}
		
		$opened_path = $this->scheme . ':/' . $this->path;
		
		//本类仅支持二进制模式，文本模式也当作二进制模式处理
		$mode = str_replace(array('t', 'b'), '', $mode);
		
		switch ($mode) {
			case 'r':
			case 'r+':
				$this->autoCreate = false;
				$this->existsFailed = false;
				$this->cleanContent = false;
				break;
			case 'w':
			case 'w+':
				$this->autoCreate = true;
				$this->existsFailed = false;
				$this->cleanContent = true;
				break;
			case 'a':
			case 'a+':
				$this->autoCreate = true;
				$this->existsFailed = false;
				$this->cleanContent = false;
				break;
			case 'x':
			case 'x+':
				$this->autoCreate = true;
				$this->existsFailed = true;
				$this->cleanContent = true;
				break;
			case 'c':
			case 'c+':
				$this->autoCreate = true;
				$this->existsFailed = false;
				$this->cleanContent = true;
				break;
			default:
				$this->triggerError('Open-mode "'.$mode.'" is undefined!', E_USER_WARNING);
				return false;
				break;
		}
		
		$exists = $this->objectExists($this->path);
		
		if (!$exists && !$this->autoCreate) {
			$this->triggerError('File "'.$this->path.'" is not exists!', E_USER_WARNING);
			return false;
		}
		
		if ($exists && $this->existsFailed) {
			$this->triggerError('File "'.$this->path.'" is exists!', E_USER_WARNING);
			return false;
		}
		
		$this->tmpPath = tempnam(sys_get_temp_dir(), 'BOS');
		
		if (false === $this->tmpPath) {
			$this->triggerError('Get tmpname failed!', E_USER_WARNING);
			return false;
		}
		
		$stat = & $this->getStat($this->path);
		
		if (!$exists && $this->autoCreate) {
			$stat = array();
			$stat['mode'] = self::DEFAULT_MODE;
			$stat['size'] = 0;
			$stat['ctime'] = time();
			$stat['mtime'] = $stat['ctime'];
			$this->changed = true;
		}
		
		if ($this->cleanContent) {
			if ($stat['size'] > 0) {
				$this->changed = true;
			}
			
			$stat['size'] = 0;
		}
		else if ($exists) {
			try {
				self::$bos->getObjectToFile(self::BUCKET, $this->path, $this->tmpPath);
			}
			catch (Exception $e) {
				$this->triggerError('Download file "'.$this->path.'" failed because "'.$e->getMessage().'"!', E_USER_WARNING);
				return false;
			}
		}
		
		//$this->tmpPath已存在，x会失败，故替换为等效的w
		$mode = strtr($mode, 'x', 'w');
		$this->tmpFp = fopen($this->tmpPath, $mode);
		
		return (bool)$this->tmpFp;
	}
	
	/**
	* 关闭流
	*/
	public function stream_close() {
		#self::log("CLOSE '{$this->scheme}:/{$this->path}'");
		
		fclose($this->tmpFp);
		$this->tmpFp = null;
		
		if ($this->changed) {
			$options = $this->mkMetaOptions($this->path);
			
			try {
				self::$bos->putObjectFromFile(self::BUCKET, $this->path, $this->tmpPath, $options);
				$stat = & $this->getStat($this->path);
				$stat['mtime'] = time();
				$stat['size'] = filesize($this->tmpPath);
				$this->changed = false;
			}
			catch (Exception $e) {
				$this->triggerError('Upload file "'.$this->path.'" failed because "'.$e->getMessage().'"!', E_USER_WARNING);
				return false;
			}
		}
		
		unlink($this->tmpPath);
		
		return true;
	}
	
	/**
	* 不支持
	*/
	public function stream_cast($cast_as) {
		#self::log("CAST");
		
		return false;
	}
	
	/**
	* 是否到达文件尾
	*/
	public function stream_eof() {
		#self::log("EOF");
		
		return feof($this->tmpFp);
	}
	
	/**
	* 保存文件更改
	*/
	public function stream_flush() {
		#self::log("FLUSH");
		
		return fflush($this->tmpFp);
	}
	
	/**
	* 锁定或解锁文件
	*/
	public function stream_lock($operation) {
		#self::log("LOCK '$operation'");
		
		return flock($this->tmpFp, $operation);
	}
	
	/**
	* 读文件内容
	*/
	public function stream_read($size) {
		#self::log("READ '$size'");
		
		return fread($this->tmpFp, $size);
	}
	
	/**
	* 写文件内容
	*/
	public function stream_write($data) {
		#self::log("WRITE '".strlen($data)."'");
		
		$this->changed = true;
		
		return fwrite($this->tmpFp, $data);
	}
	
	/**
	* 移动文件指针
	*/
	public function stream_seek($seek, $mode = SEEK_SET) {
		#self::log("SEEK '$seek' '$mode'");
		
		return -1 != fseek($this->tmpFp, $seek, $mode);
	}
	
	/**
	* 设置流选项
	* 
	* 不支持
	*/
	public function stream_set_option($option, $arg1, $arg2) {
		#self::log("SET_OPTION");
		
		return false;
	}
	
	/**
	* 获取当前流的元信息
	*/
	public function stream_stat() {
		#self::log("STAT");
		
		return fstat($this->tmpFp);
	}
	
	/**
	* 取得文件指针位置
	*/
	public function stream_tell() {
		#self::log("TELL '{$this->seek}'");
		
		return ftell($this->tmpFp);
	}
	
	/**
	* 将文件截断到指定长度
	*/
	public function stream_truncate($newsize) {
		#self::log("NEWSIZE '$newsize'");
		
		return ftruncate($this->tmpFp, $newsize);
	}
	
	/**
	* 设置元信息
	* 
	* 不支持
	*/
	public function stream_metadata($path, $option, $value) {
		#self::log("METADATA");
		
		return false;
	}
	
	/**
	* 删除文件
	*/
	public function unlink ($path) {
		#self::log("UNLINK '$path'");
		
		if (!$this->parsePath($path)) {
			return false;
		}
		
		try {
			self::$bos->deleteObject(self::BUCKET, $this->path);
			$this->clearStatCache($this->path);
		}
		catch (Exception $e) {
			$this->triggerError('Unlink "'.$this->path.'" failed because "'.$e->getMessage().'"!', E_USER_WARNING);
		}
	}
	
	/**
	* 重命名路径
	*/
	public function rename($from, $to) {
		#self::log("RENAME '$from' '$to'");
		
		if (!$this->parsePath($from)) {
			return false;
		}
		$from = $this->path;
		
		if (!$this->parsePath($to)) {
			return false;
		}
		$to = $this->path;
		
		if ($this->objectExists($from)) {
			$options = $this->mkMetaOptions($from);
			
			try {
				self::$bos->copyObject(self::BUCKET, $from, self::BUCKET, $to, $options);
				self::$bos->deleteObject(self::BUCKET, $from);
				$this->clearStatCache($from);
				$this->clearStatCache($to);
				return true;
			}
			catch (Exception $e) {
				$this->triggerError('Rename "'.$this->path.'" failed because "'.$e->getMessage().'"!', E_USER_WARNING);
				return false;
			}
		}
		else if ($this->objectExists($from.'/')) {
			$this->triggerError('Rename a directory is not supported now!', E_USER_WARNING);
			return false;
		}
		else {
			$this->triggerError('"'.$from.'" is not exists!', E_USER_WARNING);
			return false;
		}
	}
	
	/**
	* 创建目录
	*/
	public function mkdir($path, $mode, $options) {
		#self::log("MKDIR '$path' '$mode'");
		
		if (!$this->parsePath($path)) {
			return false;
		}
		
		if ($this->objectExists($this->path.'/')) {
			$this->triggerError('Directory "'.$this->path.'" is exists!', E_USER_WARNING);
			return false;
		}
		
		try {
			$options = $this->mkMetaOptions($this->path.'/');
			self::$bos->putObjectFromString(self::BUCKET, $this->path.'/', '', $options);
			$this->clearStatCache($this->path.'/');
			return true;
		}
		catch (Exception $e) {
			$this->triggerError('Create "'.$this->path.'" failed because "'.$e->getMessage().'"!', E_USER_WARNING);
			return false;
		}
	}
	
	/**
	* 删除目录
	*/
	public function rmdir ($path, $options) {
		#self::log("RMDIR '$path'");
		$this->triggerError('Remove a directory is not supported now!', E_USER_WARNING);
		return false;
	}
	
	/**
	* 打开目录
	*/
	public function dir_opendir($path, $options) {
		#self::log("OPENDIR '$path'");
		
		if (!$this->parsePath($path)) {
			return false;
		}
		
		//BOS前缀查找时目录名不以斜杠开头
		$this->prefix = ($this->path == '/') ? '' : substr($this->path, 1).'/';
		$this->marker = '';
		$this->contentList = NULL;
		$this->hasNext = true;
		
		if (!$this->readList()) {
			return false;
		}
		
		return true;
	}
	
	/**
	* 读目录内容列表
	*/
	protected function readList() {
		try {
			$result = self::$bos->listObjects(BOS_BUCKET, array('prefix'=>$this->prefix, 'marker'=>$this->marker));
			$this->setContentList($result->contents);
			$this->hasNext = $result->isTruncated;
			$this->marker = isset($result->nextMarker) ? $result->nextMarker : '';
			
			return true;
		}
		catch (Exception $e) {
			$this->triggerError('Read "'.$this->path.'" \'s content list failed because "'.$e->getMessage().'"!', E_USER_WARNING);
			return false;
		}
	}
	
	/**
	* 设置目录列表
	*/
	protected function setContentList(&$contents) {
		$this->contentList = array();
		$len = strlen($this->prefix);
		
		while (NULL !== ($content = array_shift($contents))) {
			$name = substr($content->key, $len);
			
			if ('/' == substr($name, -1)) {
				$name = substr($name, 0, -1);
			}
			
			if (strlen($name) === 0) {
				$name = '.';
			}

			//若截去前缀后依然含有/，说明是下级目录的内容，故忽略
			if (FALSE === strpos($name, '/')) {
				$this->contentList[] = self::baseName($name);
			}
		}
	}
	
	/**
	* 读取目录中的内容
	*/
	public function dir_readdir() {
		#self::log("READDIR");
		
		if (empty($this->contentList)) {
			if ($this->hasNext) {
				$ok = $this->readList();
				
				if (!$ok) {
					return FALSE;
				}
			}
			else {
				return FALSE;
			}
		}
		
		$file = array_shift($this->contentList);
		
		return $file;
	}
	
	/**
	* 重新读取目录
	*/
	public function dir_rewinddir() {
		#self::log("REWINDDIR");
		
		$this->marker = '';
		
		return $this->readList();
	}
	
	/**
	* 关闭目录
	*/
	public function dir_closedir() {
		#self::log("CLOSEDIR");
		
		$this->prefix = NULL;
		$this->marker = NULL;
		$this->contentList = NULL;
		$this->hasNext = NULL;
	}

	/**
	* 获取路径元信息
	*/
	public function url_stat($path, $flags) {
		#self::log("STAT '$path'");
		
		//STREAM_URL_STAT_QUIET设置则不触发错误
		$this->isTriggerError = !(STREAM_URL_STAT_QUIET & $flags);
		
		if (!$this->parsePath($path)) {
			return false;
		}
		
		/* 通常对文件的操作比对目录的多很多，所以把对文件的判断放在前面。 */
		
		//判断是否为文件
		if ($this->objectExists($this->path)) {
			$meta = $this->getStat($this->path);
			$mode = 0100000;
		}
		//判断是否为目录
		else if ($this->objectExists($this->path.'/')) {
			$meta = $this->getStat($this->path.'/');
			$mode = 040000;
		}
		//不存在
		else {
			$this->triggerError('"'.$this->path.'" is not exists!', E_USER_WARNING);
			return false;
		}
		
		$stat = array();
		$stat['dev'] = $stat[0] = 0;
		$stat['ino'] = $stat[1] = 0;
		$stat['mode'] = $stat[2] = $mode | $meta['mode'];
		$stat['nlink'] = $stat[3] = 0;
		$stat['uid'] = $stat[4] = getmyuid();
		$stat['gid'] = $stat[5] = getmygid();
		$stat['rdev'] = $stat[6] = 0;
		$stat['size'] = $stat[7] = $meta['size'];
		$stat['atime'] = $stat[8] = 0;
		$stat['mtime'] = $stat[9] = $meta['mtime'];
		$stat['ctime'] = $stat[10] = $meta['ctime'];
		$stat['blksize'] = $stat[11] = -1;
		$stat['blocks'] = $stat[12] = -1;
			
		return $stat;
	}
	
	/**
	* 解析原始路径为协议名和BOS路径
	*/
	protected function parsePath($path) {
		if (!preg_match('!^(\w+):/(/.*)$!s', $path, $arr)) {
			throw new BOSWrapperException('路径“' . $path . '”无法解析');
		}
		
		$this->scheme = strtolower($arr[1]);
		$this->path = $this->realpath($arr[2]);
		
		if (strlen($this->path) > 1024) {
			$this->triggerError('File path "'.$this->path.'" too long, over 1024 bytes!', E_USER_WARNING);
			return false;
		}
		
		return true;
	}
	
	/**
	* 简化路径
	* 
	* 与php函数 realpath() 类似
	*/
	protected function realpath($path) {
		$path = str_replace(array('\\', '/./'), '/', $path);
		$path = preg_replace('!//+!s', '/', $path);
		$path = preg_replace('!(?<=/)\.$!s', '', $path);
		
		if ($path == '/') {
			return $path;
		}
		
		$path = preg_replace('!/$!s', '', $path);
		
		$arr = explode('/', $path);

		foreach ($arr as $i=>$v) {
			if ($v == '..') {
				for ($j=$i-1; $j>=0; $j--) {
					if (isset($arr[$j]) && $arr[$j]!='..' && $arr[$j]!='.' && $arr[$j]!='') {
						unset($arr[$j], $arr[$i]);
						break;
					}
				}
			}
		}
			
		if ($arr === array('')) {
			return '/';
		}
		
		$path = implode('/', $arr);

		return $path;
	}
	
	/**
	* 取得目录名
	* 
	* 因dirname有中文BUG，因此重新实现
	*/
	protected static function dirname($path) {
		$path = preg_replace('#/+$#s', '', $path);
		$dir = preg_replace('#/[^/]*$#s', '', $path);
		
		if ($path[0] == '/' && $dir == '') {
			$dir = '/';
		}
		
		#self::log("DIRNAME: '$path' > '$dir'");
		
		return $dir;
	}
	
	/**
	* 取得文件名
	* 
	* 因basename有中文BUG，因此重新实现
	*/
	protected static function basename($path) {
		$path = preg_replace('#/+$#s', '', $path);
		$base = preg_replace('#^.*/#s', '', $path);
		
		#self::log("BASENAME: '$path' > '$base'");
		
		return $base;
	}
	
	/**
	* 触发错误
	*/
	protected function triggerError($msg, $type) {
		if ($this->isTriggerError) {
			trigger_error($msg, $type);
		}
	}
	
	/**
	* 写日志
	*/
	protected static function log($text) {
		static $fp = NULL;
		
		if (is_array($text)) {
			foreach ($text as $i=>$v) {
				/*递归写数组*/
				#self::log("[$i] => '$v'");
			}
			return;
		}
		
		if (!$fp) {
			$fp = fopen(__DIR__.'/log.txt', 'a');
			fwrite($fp, "\n========".date('Y-m-d H:i:s')."========\n");
		}
		
		fwrite($fp, $text."\n");
	}
	
	//用于清空BUCKET（会删除BUCKET中的全部内容！！！）
	/*public function delAll() {
		$result = self::$bos->listObjects(BOS_BUCKET, array('prefix'=>''));
		foreach ($result->contents as $v) {
			var_dump((bool)self::$bos->deleteObject(self::BUCKET, $v->key));
		}
	}*/
}


//注册 BOSWrapper 类为 bosfs:// 流协议
if (!in_array("bosfs", stream_get_wrappers())) {
	stream_wrapper_register("bosfs", "BOSWrapper");
}
