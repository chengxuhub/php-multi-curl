<?php

/**
 * 多进程并发滚动执行CURL
 *
 * @version v1.0
 * @since  2017-12-13 17:09:55
 * @author tanda <tanda@wondershare.cn>
 */

namespace Tanel\MultiCurl;

use Tanel\MultiCurl\MultiCurlException;
use Tanel\MultiCurl\SingleCurl;

//use Tanel\MultiCurl\MultiCurlInvalidParameterException;

class MultiCurl {
	const MAX_WINDOWN_SIZE   = 10; //最大并发数
	const EXEC_TIMEOUT_MS    = 5000; //执行超时毫秒数
	const CONNECT_TIMEOUT_MS = 2000; //连接超时毫秒数

	/**
	 * 实例
	 * @var MultiCurl
	 */
	static $instance;
	/**
	 * 实例化次数
	 * @var integer
	 */
	static $singleton = 0;

	private $mh; //curl_multi_init 句柄
	private $running;
	private $pools     = [];
	private $chs       = []; //所以curl handler
	private $headers   = [];
	private $responses = [];

	/**
	 * 默认CURL CURLOPT参数
	 * @var array
	 */
	private $std_options = [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true, //追踪重定向
		CURLOPT_MAXREDIRS      => 5, //最大重定向深度
	];

	/**
	 * 单例实例
	 * @return MultiCurl
	 */
	public static function getInstance() {
		if (self::$instance == null) {
			self::$singleton = 1;
			self::$instance  = new MultiCurl();
		}
		return self::$instance;
	}

	/**
	 * 实例化
	 */
	public function __construct() {
		if (self::$singleton > 0) {
			//throw new MultiCurlException('This class cannot be instantiated.  You must instantiate it using: $obj = MultiCurl::getInstance();');
		}
		$this->mh = curl_multi_init();
		/***
	 * 'code'   => CURLINFO_HTTP_CODE,
	 * 'time'   => CURLINFO_TOTAL_TIME,
	 * 'length' => CURLINFO_CONTENT_LENGTH_DOWNLOAD,
	 * 'type'   => CURLINFO_CONTENT_TYPE,
	 * 'url'    => CURLINFO_EFFECTIVE_URL,
	 */
	}

	/**
	 * ch的唯一字符串
	 * @param  [type] $ch [description]
	 * @return [type]     [description]
	 */
	private function getKey($ch) {
		return (string) $ch;
	}

	/**
	 * @overload _callStatic
	 *
	 * @static
	 * @param $method
	 * @param $args
	 * @return MultiCurl
	 * @throws \Tanel\MultiCurl\MultiCurlException
	 */
	public function __call($method, $args) {
		$method = strtoupper($method);
		if (in_array($method, ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'HEADER', 'PATCH'])) {
			$options = isset($args[2]) ? (array) $args[2] : [];

			//参数
			$options[CURLOPT_CUSTOMREQUEST] = $method;
			if (!in_array($method, ['GET'])) {
				$options[CURLOPT_POSTFIELDS] = http_build_query(isset($args[1]) ? $args[1] : []);
			}

			$this->add($args[0], $options);
			return $this;
		} else {
			throw new MultiCurlException("Call Undefined method");
		}
	}

	/**
	 * @param $ch
	 * @param $header
	 * @return int
	 */
	private function headerCallback($ch, $header) {
		$_header  = trim($header);
		$colonPos = strpos($_header, ':');
		if ($colonPos > 0) {
			$key = substr($_header, 0, $colonPos);
			$val = preg_replace('/^\W+/', '', substr($_header, $colonPos));

			$this->responses[$this->getKey($ch)]['headers'][$key] = $val;
		}
		return strlen($header);
	}

	/**
	 * 向CURL_Mutli中增加
	 *
	 * @param string $url     [description]
	 * @param array $options  [description]
	 */
	private function createCurl($url, $options = []) {
		isset($options[CURLOPT_CUSTOMREQUEST]) or ($options[CURLOPT_CUSTOMREQUEST] = 'GET');

		//https设置
		if (preg_match('/^https:\/\//', $url)) {
			$options[CURLOPT_SSL_VERIFYPEER] = 0; //SSL设置
		}

		$ch = SingleCurl::create($url, $options);

		if (!is_resource($ch)) {
			throw new MultiCurlInvalidParameterException('Parameter must be a valid curl handle');
		}
		return $ch;
	}

	/**
	 * 添加url请求
	 * @param string $url      请求连接
	 * @param array  $options  请求参数
	 * @return $this
	 */
	public function add($url, $options = []) {

		if (!isset($options[CURLOPT_CUSTOMREQUEST])) {
			$options[CURLOPT_CUSTOMREQUEST] = 'GET';
		}
		$options[CURLOPT_URL]            = $url;
		$options[CURLOPT_HEADERFUNCTION] = [$this, 'headerCallback'];

		//添加到队列池
		$this->pools[] = ['url' => $url, 'options' => $this->std_options + $options];
		return $this;
	}

	/**
	 * 利用滚动策略并发执行curl
	 *
	 * @param  array $urls      urls信息
	 * @param  array  $callback 单个curl执行成功后回调函数
	 * @return mixed
	 */
	public function rollingExec($callback = []) {
		$length = sizeof($this->pools);
		$length = ($length < self::MAX_WINDOWN_SIZE) ? $length : self::MAX_WINDOWN_SIZE;

		//初始化curl资源队列
		$this->chs       = [];
		$this->responses = [];

		for ($i = 0; $i < $length; $i++) {
			$this->chs[] = $curl = $this->createCurl($this->pools[$i]['url'], $this->pools[$i]['options']);
			curl_multi_add_handle($this->mh, $curl);
		}

		do {
			while (($execrun = curl_multi_exec($this->mh, $running)) == CURLM_CALL_MULTI_PERFORM);
			if ($execrun != CURLM_OK) {
				break;
			}

			while ($done = curl_multi_info_read($this->mh)) {
				$key  = $this->getKey($done['handle']);
				$info = curl_getinfo($done['handle']);
				//响应数据
				$this->responses[$key] = [
					'http_code' => $info['http_code'],
					'time'      => $info['total_time'],
					'length'    => $info['download_content_length'],
					'type'      => $info['content_type'],
					'errror'    => [],
					'content'   => curl_multi_getcontent($done['handle']), ////获取信息
				];

				if ($info['http_code'] == 200) {
					$this->responses[$key]['error'] = ['code' => curl_errno($done['handle']), 'msg' => curl_error($done['handle'])];
				}
				//新建一个curl资源并加入并发队列
				if ($i < sizeof($this->pools)) {
					$this->chs[] = $curl = $this->createCurl($this->pools[$i]['url'], $this->pools[$i]['options']);
					curl_multi_add_handle($this->mh, $curl);
					$i++;
				}

				empty($callback) or call_user_func_array($callback, $this->responses[$key]);
				curl_multi_remove_handle($this->mh, $done['handle']);
				curl_close($done['handle']);
			}
		} while ($running);

		curl_multi_close($this->mh);
		return $this->responses;
	}

	/**
	 * 析构函数,释放资源
	 */
	public function __destruct() {
		if (empty($this->chs)) {
			foreach ($this->chs as $ch) {
				!is_resource($ch) or curl_close($ch);
			}
		}
		!is_resource($this->mh) or curl_multi_close($this->mh);
	}
}