<?php

/**
 * CURL数据
 *
 * @version v1.0
 * @since  2017-12-13 17:09:55
 * @author tanda <tanda@wondershare.cn>
 */

namespace Tanel\MultiCurl;

use Tanel\MultiCurl\MultiCurlException;

class SingleCurl {
	public static $header;
	/**
	 * 默认的CURL OPTION
	 *
	 * @var array
	 */
	private static $options = [
		CURLOPT_HEADER         => 0,
		CURLOPT_TIMEOUT        => 5000, //超时等待
		CURLOPT_RETURNTRANSFER => 1,
		CURLOPT_HEADERFUNCTION => 'Tanel\MultiCurl\SingleCurl::headerCallback',
	];

	/**
	 * 创建CURL
	 *
	 * @static
	 * @param  string $url
	 * @param  array  $data
	 * @param  array  $options
	 * @return
	 */
	public static function create($url, $options = []) {
		$ch = curl_init($url);
		curl_setopt_array($ch, (array) $options + self::$options);
		return $ch;
	}

	/**
	 * @overload _callStatic
	 *
	 * @static
	 * @param $method
	 * @param $args
	 * @return resource
	 * @throws \Tanel\MultiCurl\MultiCurlException
	 */
	public static function __callStatic($method, $args) {
		$method = strtoupper($method);
		if (in_array($method, ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'HEADER', 'PATCH'])) {
			$options = isset($args[2]) ? (array) $args[2] : [];

			//参数
			$options[CURLOPT_CUSTOMREQUEST] = $method;
			if (!in_array($method, ['GET'])) {
				$options[CURLOPT_POSTFIELDS] = http_build_query(isset($args[1]) ? $args[1] : []);
			}

			//https设置
			if (preg_match('/^https:\/\//', $args[0])) {
				$options[CURLOPT_SSL_VERIFYPEER] = 0; //SSL设置
				print_r($args);
			}

			return self::create($args[0], $options);
		} else {
			throw new MultiCurlException("Call Undefined method");
		}
	}

	/**
	 * @param $ch
	 * @param $header
	 * @return int
	 */
	private static function headerCallback($ch, $header) {
		$_header  = trim($header);
		$colonPos = strpos($_header, ':');
		if ($colonPos > 0) {
			$key   = substr($_header, 0, $colonPos);
			$val   = preg_replace('/^\W+/', '', substr($_header, $colonPos));
			$chstr = strval($ch);

			self::$header[$chstr]['headers'][$key] = $val;
		}
		return strlen($header);
	}

	/**
	 * 发送单个curl
	 *
	 * @static
	 * @return [type] [description]
	 */
	public static function response($ch) {
		if (!is_resource($ch)) {
			throw new MultiCurlException("Error Resource type");
		}

		$chstr = strval($ch);
		$data  = array_fill_keys(['headers', 'body', 'error', 'http_code', 'last_url'], '');

		try {

			//执行给定的cURL会话。
			$response = curl_exec($ch);

			//检查错误
			if (curl_errno($ch) > 0) {
				$data['error'] = curl_error($ch);
			}

			$data['headers']   = self::$header[$chstr]['headers'];
			$data['body']      = $response;
			$data['http_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$data['last_url']  = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
		} catch (MultiCurlException $e) {
			//关闭curl会话
			curl_close($ch);
			$data['error'] = $e->getMessage();
		}

		//释放header
		unset(self::$header[$chstr]);
		return $data;
	}
}