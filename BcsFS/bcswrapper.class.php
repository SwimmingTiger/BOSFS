<?php
require_once dirname(__FILE__).'/bcs.class.php';
/**
* BCSWrapper异常类
*/
class BCSWrapperException extends Exception {
	//与父类一致
}

/**
* BCS文件系统流包装器
* 
* 该类实现从php文件读写函数到BCS云存储的映射。
* 注册该类为流协议后可通过 协议名://文件路径 作为路径用php文件读写函数操作BCS上的文件。
* 该类默认注册的流协议为 bcsfs://
* 
* 作者：老虎会游泳 <hu60.cn@gmail.com>
* 授权：公共领域
*/
class BCSWrapper {
	///BCS的Bucket，由conf.inc.php里的文件决定
	const BUCKET = BCS_BUCKET;
	
	///BCS对象，全局共享
	protected static $bcs = NULL;
	
	///目录默认权限，因BCS非UNIX文件系统，因此在此设定虚拟值
	protected $dir_mode = 0644;
	///文件默认权限，因BCS非UNIX文件系统，因此在此设定虚拟值
	protected $file_mode = 0644;
	
	///当前使用的协议名（不包含://）
	protected $scheme = NULL;
	///当前文件的BCS路径（以/开头）
	protected $path = NULL;
	///是否为目录
	protected $isdir = NULL;
	///是否存在
	protected $exists = NULL;
	
	///文件是否可读
	protected $readable = NULL;
	///文件是否可写
	protected $writeable = NULL;
	///是否允许移动文件指针
	protected $seekable = NULL;
	///是否把文件指针移到末尾
	protected $moveSeekEnd = NULL;
	///文件不存在时是否自动创建
	protected $autoCreate = NULL;
	///文件已存在时是否失败
	protected $existsFailed = NULL;
	///强制清空文件内容
	protected $cleanContent = NULL;
	
	///文件内容；为目录时则为目录内文件的数组
	protected $content = NULL;
	///文件时间
	protected $time = NULL;
	///文件或目录指针位置
	protected $seek = NULL;
	///目录offset位置
	protected $offset = NULL;
	///文件长度；为目录时则为当前获取的目录内文件数组长度
	protected $len = NULL;
	///文件的MIME类型
	protected $mime = NULL;
	///文件是否被改变
	protected $changed = NULL;
	
	///是否发送错误
	protected $isTriggerError = NULL;
	
	/**
	* 初始化流对象
	*/
	public function __construct() {
		if (self::$bcs === NULL) {
			self::$bcs = new BaiduBCS();
		}
	}
	
	/**
	* 打开流
	*/
	public function stream_open($path, $mode, $options, &$opened_path) {
		//self::log("OPEN '$path' '$mode'");
		if (STREAM_REPORT_ERRORS & $options) {
			$this->isTriggerError = true;
		} else {
			$this->isTriggerError = false;
		}
		
		if (!$this->parsePath($path)) {
			return false;
		}
		
		$opened_path = $this->scheme . ':/' . $this->path;
		
		//本类仅支持二进制模式
		$mode = str_replace(array('t', 'b'), '', $mode);
		
		switch ($mode) {
			case 'r':
				$this->readable = true;
				$this->writeable = false;
				$this->seekable = true;
				$this->moveSeekEnd = false;
				$this->autoCreate = false;
				$this->existsFailed = false;
				$this->cleanContent = false;
				break;
			case 'r+':
				$this->readable = true;
				$this->writeable = true;
				$this->seekable = true;
				$this->moveSeekEnd = false;
				$this->autoCreate = false;
				$this->existsFailed = false;
				$this->cleanContent = false;
				break;
			case 'w':
				$this->readable = false;
				$this->writeable = true;
				$this->seekable = true;
				$this->moveSeekEnd = false;
				$this->autoCreate = true;
				$this->existsFailed = false;
				$this->cleanContent = true;
				break;
			case 'w+':
				$this->readable = true;
				$this->writeable = true;
				$this->seekable = true;
				$this->moveSeekEnd = false;
				$this->autoCreate = true;
				$this->existsFailed = false;
				$this->cleanContent = true;
				break;
			case 'a':
				$this->readable = false;
				$this->writeable = true;
				$this->seekable = true;
				$this->moveSeekEnd = true;
				$this->autoCreate = true;
				$this->existsFailed = false;
				$this->cleanContent = false;
				break;
			case 'a+':
				$this->readable = true;
				$this->writeable = true;
				$this->seekable = true;
				$this->moveSeekEnd = true;
				$this->autoCreate = true;
				$this->existsFailed = false;
				$this->cleanContent = false;
				break;
			case 'x':
				$this->readable = false;
				$this->writeable = true;
				$this->seekable = true;
				$this->moveSeekEnd = false;
				$this->autoCreate = false;
				$this->existsFailed = true;
				$this->cleanContent = false;
				break;
			case 'x+':
				$this->readable = true;
				$this->writeable = true;
				$this->seekable = true;
				$this->moveSeekEnd = false;
				$this->autoCreate = false;
				$this->existsFailed = true;
				$this->cleanContent = false;
				break;
			case 'c':
				$this->readable = false;
				$this->writeable = true;
				$this->seekable = true;
				$this->moveSeekEnd = false;
				$this->autoCreate = true;
				$this->existsFailed = false;
				$this->cleanContent = false;
				break;
			case 'c+':
				$this->readable = true;
				$this->writeable = true;
				$this->seekable = true;
				$this->moveSeekEnd = false;
				$this->autoCreate = true;
				$this->existsFailed = false;
				$this->cleanContent = false;
				break;
			default:
				$this->triggerError('File "'.$this->path.'" \'s open mode "'.$mode.'" undefined!', E_USER_WARNING);
				return false;
				break;
		}
		
		//判断是否为目录
		if (self::$bcs->is_object_exist(self::BUCKET, $this->path.'/.meta')) {
			$this->isdir = true;
			return false;
		}
		
		$dirname = self::dirname($this->path);
		if ($dirname != '/' && !self::$bcs->is_object_exist(self::BUCKET, $dirname.'/.meta')) {
			//父目录不存在
			return false;
		}
		
		$this->exists = self::$bcs->is_object_exist(self::BUCKET, $this->path);
		
		if ($this->exists && $this->existsFailed) {
			return false;
		}
		
		if (!$this->exists && !$this->autoCreate) {
			return false;
		}
		
		if (!$this->getContent()) {
			$this->triggerError('File "'.$this->path.'" \'s content fetch failed with unkonwn result!', E_USER_WARNING);
			return false;
		}
		
		if ($this->moveSeekEnd) {
			$this->seek = $this->len;
		} else {
			$this->seek = 0;
		}
		
		$this->isdir = false;
		
		if ($this->exists) {
			$this->changed = false;
		} else {
			$this->changed = true;
			$this->stream_flush();
		}
		
		return true;
	}
	
	/**
	* 获取文件内容
	*/
	protected function getContent() {
		if (!$this->exists || $this->cleanContent) {
			$this->content = '';
			$this->len = 0;
			$this->time = time();
			$this->mime = NULL;
			return true;
		} else {
			$result = self::$bcs->get_object(self::BUCKET, $this->path);
			if ($result->isOK()) {
				$this->content = $result->body;
				$this->len = strlen($this->content);
				$this->time = $result->header['_info']['filetime'];
				$this->mime = $result->header['Content-Type'];
				
				return true;
			} else {
				return false;
			}
		}
	}
	
	/**
	* 设置元信息
	* 
	* 不支持
	*/
	public function stream_metadata($path, $option, $value) {
		return false;
	}
	
	/**
	* 不支持
	*/
	public function stream_cast($cast_as) {
		return false;
	}
	
	/**
	* 保存文件更改
	*/
	public function stream_flush() {
		if ($this->changed) {
			$result = self::$bcs->create_object_by_content(self::BUCKET, $this->path, $this->content);
			if ($result->isOK()) {
				$this->changed = false;
				
				$finfo = finfo_open(FILEINFO_MIME);
				$this->mime = finfo_file($finfo, "{$this->scheme}:/{$this->path}");
				finfo_close($finfo);
				self::$bcs->set_object_meta(self::BUCKET, $this->path, array('Content-Type' => $this->mime));
				
				//self::log("SETMIME '{$this->scheme}:/{$this->path}' '{$this->mime}'");
				
				return true;
			} else {
				$this->triggerError('File "'.$this->path.'" save failed with unkonwn result!', E_USER_WARNING);
				return false;
			}
		} else {
			return true;
		}
	}
	
	/**
	* 关闭文件
	*/
	public function stream_close() {
		//self::log("CLOSE '{$this->scheme}:/{$this->path}'");
		
		return $this->stream_flush();
	}
	
	/**
	* 是否到达文件尾
	*/
	public function stream_eof() {
		return $this->seek >= $this->len;
	}
	
	/**
	* 锁定或解锁文件
	* 
	* 虽不支持，但某些程序会使用，因此假装支持
	*/
	public function stream_lock() {
		return true;
	}
	/**
	* 读文件内容
	*/
	public function stream_read($size) {
		//self::log("READ '$size'");
		if (!$this->readable || $size < 1 || $this->stream_eof()) {
			return false;
		}
		
		$content = substr($this->content, $this->seek, $size);
		$this->seek += $size;
		
		if ($this->seek > $this->len) {
			$this->seek = $this->len;
		}
		
		return $content;
	}
	
	/**
	* 写文件内容
	*/
	public function stream_write($data) {
		$size = strlen($data);
		//self::log("WRITE '$size'");
		
		if (!$this->writeable) {
			return false;
		}
	
		if ($this->stream_eof()) {
			$this->content .= $data;
		} elseif ($this->seek == 0) {
			$this->content = $data . substr($this->content, $size);
		} else {
			$this->content = substr($this->content, 0, $seek) . $data . substr($this->content, $seek + $size);
		}
		
		$this->len = strlen($this->content);
		$this->seek += $size;
		$this->changed = true;
		
		if ($this->seek > $this->len) {
			throw new BCSWrapperException('写入内容后文件指针异常，超出文件总长度！');
		}
		
		return $size;
	}
	
	/**
	* 移动文件指针
	*/
	public function stream_seek($seek, $mode = SEEK_SET) {
		//self::log("SEEK '$seek' '$mode'");
	
		if (!$this->seekable) {
			return false;
		}
		
		switch ($mode) {
			case SEEK_SET:
				if ($seek < 0) {
					return false;
				}
				if ($seek > $this->len) {
					$seek = $this->len;
				}
				$this->seek = $seek;
				return true;
				break;
				
			case SEEK_CUR:
				$seek = $this->seek + $seek;
				if ($seek < 0) {
					return false;
				}
				if ($seek > $this->len) {
					$seek = $this->len;
				}
				$this->seek = $seek;
				return true;
				break;
				
			case SEEK_END:
				$seek = $this->len + $seek;
				if ($seek < 0) {
					return false;
				}
				if ($seek > $this->len) {
					$seek = $this->len;
				}
				$this->seek = $seek;
				return true;
				break;
				
			default:
				$this->triggerError('File "'.$this->path.'" \'s seek mode "'.$mode.'" undefined!', E_USER_WARNING);
				return false;
				break;
		}
	}
	
	/**
	* 设置流选项
	* 
	* 不支持
	*/
	public function stream_set_option($option, $arg1, $arg2) {
		return false;
	}
	
	/**
	* 取得文件元信息
	*/
	public function stream_stat() {
		//self::log("STREAMSTAT '{$this->path}'");
		
		$stat['dev'] = $stat[0] = 0;
		$stat['ino'] = $stat[1] = 0;
		if (!$this->isdir) {
			$stat['mode'] = $stat[2] = 0100000 | $this->file_mode;
		} else {
			$stat['mode'] = $stat[2] = 040000 | $this->dir_mode;
		}
		$stat['nlink'] = $stat[3] = 0;
		$stat['uid'] = $stat[4] = getmyuid();
		$stat['gid'] = $stat[5] = getmygid();
		$stat['rdev'] = $stat[6] = 0;
		$stat['size'] = $stat[7] = $this->len;
		$stat['atime'] = $stat[8] = 0;
		$stat['mtime'] = $stat[9] = $this->time;
		$stat['ctime'] = $stat[10] = $this->time;
		$stat['blksize'] = $stat[11] = 0;
		$stat['blocks'] = $stat[12] = 0;
		
		return $stat;
	}
	
	/**
	* 取得文件指针位置
	*/
	public function stream_tell() {
		//self::log("TELL '{$this->seek}'");
		
		return $this->seek;
	}
	
	/**
	* 将文件截断到指定长度
	*/
	public function stream_truncate($newsize) {
		//self::log("NEWSIZE '$newsize'");
		
		if ($newsize >= $this->len) {
			return false;
		} else {
			$this->content = substr($this->content, 0, $newsize);
			$this->len = strlen($this->content);
			return true;
		}
	}
	
	/**
	* 获取路径元信息
	*/
	public function url_stat($path, $flags) {
		//self::log("STAT '$path'");
		if (STREAM_URL_STAT_QUIET & $flags) {
			$this->isTriggerError = false;
		} else {
			$this->isTriggerError = true;
		}
		
		if (!$this->parsePath($path)) {
			return false;
		}
		
		//判断是否为目录
		if (self::$bcs->is_object_exist(self::BUCKET, $this->path.'/.meta')) {
			$this->isdir = true;
			$result = self::$bcs->get_object(self::BUCKET, $this->path.'/.meta');
			
			if (!$result->isOK()) {
				$this->triggerError('Get directory "'.$this->path.'" \'s meta data "'.$mode.'" failed!', E_USER_WARNING);
				return false;
			}
			
			$meta = unserialize($result->body);
			
			if ($meta['time']) {
				$this->time = $meta['time'];
			} else {
				$this->time = $result->header['_info']['filetime'];
			}
			
			$stat['dev'] = $stat[0] = 0;
			$stat['ino'] = $stat[1] = 0;
			$stat['mode'] = $stat[2] = 040000 | $this->dir_mode;
			$stat['nlink'] = $stat[3] = 0;
			$stat['uid'] = $stat[4] = getmyuid();
			$stat['gid'] = $stat[5] = getmygid();
			$stat['rdev'] = $stat[6] = 0;
			$stat['size'] = $stat[7] = 0;
			$stat['atime'] = $stat[8] = 0;
			$stat['mtime'] = $stat[9] = $this->time;
			$stat['ctime'] = $stat[10] = $this->time;
			$stat['blksize'] = $stat[11] = 0;
			$stat['blocks'] = $stat[12] = 0;
			
			return $stat;
		} else {
			$this->isdir = false;
			$result = self::$bcs->get_object_info(self::BUCKET, $this->path);
			
			if (!$result->isOK()) {
				$this->exists = false;
				return false;
			}
			
			$meta = $result->header['_info'];
			$this->time = $meta['filetime'];
			$this->len = $meta['download_content_length'];
			
			$stat['dev'] = $stat[0] = 0;
			$stat['ino'] = $stat[1] = 0;
			$stat['mode'] = $stat[2] = 0100000 | $this->file_mode;
			$stat['nlink'] = $stat[3] = 0;
			$stat['uid'] = $stat[4] = getmyuid();
			$stat['gid'] = $stat[5] = getmygid();
			$stat['rdev'] = $stat[6] = 0;
			$stat['size'] = $stat[7] = $this->len;
			$stat['atime'] = $stat[8] = 0;
			$stat['mtime'] = $stat[9] = $this->time;
			$stat['ctime'] = $stat[10] = $this->time;
			$stat['blksize'] = $stat[11] = 0;
			$stat['blocks'] = $stat[12] = 0;
			
			return $stat;
		}
	}
	
	/**
	* 删除文件
	*/
	public function unlink ($path) {
		//self::log("UNLINK '$path'");
		if (!$this->parsePath($path)) {
			return false;
		}
		
		if (self::basename($this->path) == '.meta') {
			$this->triggerError('Directory meta file "'.$this->path.'" cannot delete by unlink!', E_USER_WARNING);
			return false;
		}
		
		if (self::$bcs->is_object_exist(self::BUCKET, $this->path.'/.meta')) {
			$this->isdir = true;
			return false;
		} else {
			$this->isdir = false;
			$result = self::$bcs->delete_object(self::BUCKET, $this->path);
			return $result->isOK();
		}
	}
	
	/**
	* 删除目录
	*/
	public function rmdir ($path, $options) {
		//self::log("RMDIR '$path'");
		if (!$this->parsePath($path)) {
			return false;
		}
		
		if (self::$bcs->is_object_exist(self::BUCKET, $this->path.'/.meta')) {
			$this->isdir = true;
			$result = self::$bcs->list_object_by_dir(self::BUCKET, $this->path.'/', 2, array('start'=>0, 'limit'=>2));
			if (!$result->isOK()) {
				return false;
			}
			$meta = json_decode($result->body);
			$list = $meta->object_list;
			if (count($list) == 1 && $list[0]->object == $this->path.'/.meta') {
				$result = self::$bcs->delete_object(self::BUCKET, $this->path.'/.meta');
				return $result->isOK();
			} else {
				$this->triggerError('Directory "'.$this->path.'" is not empty!', E_USER_WARNING);
				return false;
			}
		} else {
			$this->isdir = false;
			return false;
		}
	}
	
	/**
	* 重命名路径
	*/
	public function rename($from, $to) {
		//self::log("RENAME '$from' '$to'");
		if (!$this->parsePath($from)) {
			return false;
		}
		$from = $this->path;
		
		if (!$this->parsePath($to)) {
			return false;
		}
		$to = $this->path;
		
		if (self::$bcs->is_object_exist(self::BUCKET, $from.'/.meta')) {
			$this->isdir = true;
			$this->triggerError('Renaming directory "'.$this->path.'" is not supported now!', E_USER_WARNING);
		} else {
			$this->isdir = false;
			$result = self::$bcs->copy_object(array('bucket'=>self::BUCKET, 'object'=>$from), array('bucket'=>self::BUCKET, 'object'=>$to));
			if (!$result->isOK()) {
				return false;
			}
			$result = self::$bcs->delete_object(self::BUCKET, $from);
			return $result->isOK();
		}
	}
	
	/**
	* 创建目录
	*/
	public function mkdir($path, $mode, $options) {
		//self::log("MKDIR '$path' '$mode'");
		if (!$this->parsePath($path)) {
			return false;
		}
		
		if (self::$bcs->is_object_exist(self::BUCKET, $this->path.'/.meta')) {
			$this->isdir = true;
			return false;
		}
		
		if (self::$bcs->is_object_exist(self::BUCKET, $this->path)) {
			$this->isdir = false;
			return false;
		}
		
		$dirname = self::dirname($this->path);
		//父目录不存在
		if ($dirname != '/' && !self::$bcs->is_object_exist(self::BUCKET, $dirname.'/.meta')) {
			if (STREAM_MKDIR_RECURSIVE & $options) {
				//递归创建父目录失败
				if (!mkdir($this->scheme.':/'.$dirname, $mode, true)) {
					$this->isdir = false;
					return false;
				}
			} else {
				$this->isdir = false;
				return false;
			}
		}
		
		$meta = array(
					'time' => time(),
					'mode' => $mode,
				);
		
		$this->content = serialize($meta);
		$result = self::$bcs->create_object_by_content(self::BUCKET, $this->path.'/.meta', $this->content);
			if ($result->isOK()) {
				return true;
			} else {
				$this->triggerError('Directory "'.$this->path.'" \'s meta data save failed with unkonwn result!', E_USER_WARNING);
				return false;
			}
	}
	
	/**
	* 打开目录
	*/
	public function dir_opendir($path, $options) {
		//self::log("OPENDIR '$path'");
		
		if (!$this->parsePath($path)) {
			return false;
		}
		
		//判断是否为目录
		if (!self::$bcs->is_object_exist(self::BUCKET, $this->path.'/.meta')) {
			$this->isdir = false;
			return false;
		}
		
		$this->isdir = true;
		
		$this->getDirList(0);
		
		return true;
	}
	
	/**
	* 取得子目录列表
	*/
	protected function getDirList($offset=NULL, $limit=100) {
		if ($offset === NULL) {
			$offset = $this->offset + $limit;
		}
	
		$this->seek = 0;
		$this->offset = $offset;
		$this->content = array();
		
		if ($this->offset == 0) {
			$this->content[] = '.';
			$dirname = self::dirname($this->path);
			
			if ($this->path != '/') {
				$this->content[] = '..';
			}
		}
		
		$result = self::$bcs->list_object_by_dir(self::BUCKET, $this->path.'/', 2, array('start'=>$this->offset, 'limit'=>$limit));
		
		if (!$result->isOK()) {
			return false;
		}
		
		$meta = json_decode($result->body);
		$list = $meta->object_list;
		
		foreach ($list as $v) {
			$file = self::basename($v->object);
			if ($file != '.meta') {
				$this->content[] = $file;
			}
		}
		
		$this->len = count($this->content);
		
		return true;
	}
	
	/**
	* 读取目录
	*/
	public function dir_readdir() {
		//self::log("READDIR '{$this->path}'");
		
		if ($this->seek >= $this->len) {
			$this->getDirList();
		}
		
		if ($this->seek >= $this->len) {
			return false;
		}
		
		if (!isset($this->content[$this->seek])) {
			return false;
		}
		
		$file = $this->content[$this->seek];
		$this->seek ++;
		
		//self::log("READDIRRESULT '$file'");
		
		return $file;
	}
	
	/**
	* 将目录指针移动到首位
	*/
	public function dir_rewinddir() {
		//self::log("REWINDDIR '{$this->path}'");
		return $this->getDirList(0);
	}
	
	/**
	* 关闭目录
	*/
	public function dir_closedir() {
		if ($this->isdir) {
			$this->content = array();
			return true;
		} else {
			return false;
		}
	}
	
	/**
	* 解析原始路径为协议名和BCS路径
	*/
	protected function parsePath($path) {
		if (!preg_match('!^(\w+):/(/.*)$!s', $path, $arr)) {
			throw new BCSWrapperException('路径“' . $path . '”无法解析');
		}
		
		$this->scheme = strtolower($arr[1]);
		$this->path = $this->realpath($arr[2]);
		
		if (strlen($this->path) > 255) {
			$this->triggerError('File path "'.$this->path.'" too long, over 255 bytes!', E_USER_WARNING);
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
		$path = preg_replace('!//+!', '/', $path);
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
		return preg_replace('#/[^/]*$#', '', $path);
	}
	
	/**
	* 取得文件名
	* 
	* 因basename有中文BUG，因此重新实现
	*/
	protected static function basename($path) {
		return preg_replace('#^.*/#', '', $path);
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
				/*递归写数组*/self::log("[$i] => '$v'");
			}
			return;
		}
		
		if (!$fp) {
			$fp = fopen(__DIR__.'/log.txt', 'a');
			fwrite($fp, "\n========".date('Y-m-d H:i:s')."========\n");
		}
		fwrite($fp, $text."\n");
	}
}

//注册 BCSWrapper 类为 bcsfs:// 流协议
if (!in_array("bcsfs", stream_get_wrappers())) {
	stream_wrapper_register("bcsfs", "BCSWrapper");
}