<?php
/**
 * JsCssChunker
 *
 * All rights reserved. The JsCssChunker is a PHP Class to minify
 * and compress stylesheet and javascript files.
 * - http://chunker.hieblmedia.net/
 *
 * -----------------------------------------------------------------------------
 *
 * @package    JsCssChunker
 *
 * @author     Reinhard Hiebl <reinhard@hieblmedia.com>
 * @copyright  Copyright (C) 2011 - 2014, HieblMedia (Reinhard Hiebl)
 * @license    http://www.opensource.org/licenses/gpl-3.0.html GNU General Public License, version 3.0 (GPLv3)
 * @link       http://chunker.hieblmedia.net/
 */

namespace JsCssChunker;

/**
 * Request class to get the file contents
 *
 * @package  JsCssChunker
 * @since    0.0.4
 */
class Request
{
	private $_phpSafeMode = false;

	private $_phpOpenBasedir = false;

	private $_loadMethod = '';

	/**
	 * @var \JsCssChunker
	 */
	private $_chunker = null;

	static private $_instance = null;

	/**
	 * Contructor Function for init class
	 *
	 * @throws Exception Throws an expecption if the check method fails
	 *
	 * @param   object &$chunker JsCssChunker/Base
	 */
	public function __construct(&$chunker)
	{
		// Check PHP settings
		$safeMode = strtolower(ini_get('safe_mode'));
		$this->_phpSafeMode = (($safeMode == '0' || $safeMode == 'off') ? false : true);
		$this->_phpOpenBasedir = (ini_get('open_basedir') == '' ? false : true);

		$this->_chunker = & $chunker;

		if ($this->check() == false)
		{
			throw new Exception('JsCssChunker(Request) - Check fail: CURL or file_get_contents with allow_url_fopen or fsockopen is needed');
		}

		self::$_instance = $this;

		return self::$_instance;
	}

	/**
	 * Get the Request Instance
	 *
	 * @static
	 * @return \JsCssChunker
	 */
	public static function getInstance()
	{
		return self::$_instance;
	}

	/**
	 * Get the contents of specific file/url
	 *
	 * @param   string  $file    Absolute Path or Url to the file
	 * @param   string  $post    Url Parameters if Url must be submit as POST request
	 * @param   integer $timeout Timeout in seconds, if not set the default will be used.
	 *
	 * @access public
	 * @return string Contents from File
	 */
	public function getFileContents($file, $post = null, $timeout = null)
	{
		if (empty($post))
		{
			$url_to_local_map = $this->_chunker->getOption('url_to_local_map');

			// Try to map URL's to local filesystem
			if (is_array($url_to_local_map) && count($url_to_local_map))
			{
				$_regex = '#^(http:\/\/|https:\/\/|\/\/)#Ui';
				$_file = $this->_chunker->cleanPath(preg_replace($_regex, '', $file), DIRECTORY_SEPARATOR);

				foreach ($url_to_local_map as $_path => $_urls)
				{
					$_path = $this->_chunker->cleanPath($_path . '/', DIRECTORY_SEPARATOR);
					$_urls = (array) $_urls;

					foreach ($_urls as $_url)
					{
						$_url = $this->_chunker->cleanPath(preg_replace($_regex, '', $_url) . '/', DIRECTORY_SEPARATOR);
						$_filePath = preg_replace('#^' . preg_quote($_url, '#') . '#Ui', $_path, $_file, 1, $_count);

						if ($_count && file_exists($_filePath))
						{
							return file_get_contents($_filePath);
						}
					}
				}
			}
		}

		$content = '';
		if ($timeout === null)
		{
			$timeout = $this->_chunker->getOption('timeout');
		}

		/** @noinspection PhpUsageOfSilenceOperatorInspection */
		@ini_set('default_socket_timeout', $timeout);

		$origLoadMethod = $this->_loadMethod;

		// Force file_get_contents if file exists on local filesystem
		if (!preg_match('#^(http|https)://#Uis', $file) && file_exists($file) && is_readable($file))
		{
			$this->_loadMethod = 'FILEGETCONTENTS';
		}

		$authOptions = $this->_chunker->getOption('httpAuth');
		if ($authOptions)
		{
			$httpAuth = true;
			$httpAuthType = isset($authOptions['type']) ? $authOptions['type'] : 'ANY';
			$httpAuthUser = isset($authOptions['user']) ? $authOptions['user'] : '';
			$httpAuthPass = isset($authOptions['pass']) ? $authOptions['pass'] : '';
			$isHttpAuth = (!empty($httpAuth) && !empty($httpAuthType) && !empty($httpAuthUser));
		}
		else
		{
			// If logged in currently with http auth add options into headers
			$httpAuth = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
			$httpAuthType = isset($_SERVER['AUTH_TYPE']) ? $_SERVER['AUTH_TYPE'] : '';
			$httpAuthUser = isset($_SERVER['PHP_AUTH_USER']) ? $_SERVER['PHP_AUTH_USER'] : '';
			$httpAuthPass = isset($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : '';
			$isHttpAuth = (!empty($httpAuth) && !empty($httpAuthType) && !empty($httpAuthUser));
		}

		$postdata = null;
		if (!empty($post))
		{
			if (is_array($post))
			{
				$postdata = http_build_query($post);
			}
			else
			{
				$postdata = $post;
			}
		}

		switch ($this->_loadMethod)
		{
			case 'FILEGETCONTENTS':
				if ($postdata !== null)
				{
					$opts = array(
						'http' => array(
							'method' => 'POST',
							'header' => 'Content-type: application/x-www-form-urlencoded',
							'content' => $postdata
						)
					);

					/** @noinspection PhpUsageOfSilenceOperatorInspection */
					$_context = @stream_context_create($opts);
					/** @noinspection PhpUsageOfSilenceOperatorInspection */
					$content = @file_get_contents($file, false, $_context);
				}
				else
				{
					if (!$httpAuth)
					{
						/** @noinspection PhpUsageOfSilenceOperatorInspection */
						$content = @file_get_contents($file);
					}
					else
					{
						$opts = array(
							'http' => array(
								'method' => 'GET',
								'header' => 'Authorization: ' . $httpAuth,
							)
						);

						/** @noinspection PhpUsageOfSilenceOperatorInspection */
						$_context = @stream_context_create($opts);
						/** @noinspection PhpUsageOfSilenceOperatorInspection */
						$content = @file_get_contents($file, false, $_context);
					}
				}
				break;

			case 'FSOCKOPEN':
				$errno = 0;
				$errstr = '';

				$uri = parse_url($file);
				/** @noinspection PhpUsageOfSilenceOperatorInspection */
				$fileHost = @$uri['host'];
				/** @noinspection PhpUsageOfSilenceOperatorInspection */
				$filePath = @$uri['path'];

				/** @noinspection PhpUsageOfSilenceOperatorInspection */
				$fp = @fsockopen($fileHost, 80, $errno, $errstr, $timeout);

				if ($fp && $fileHost && $filePath)
				{
					$_method = (($postdata === null) ? 'GET' : 'POST');

					/** @noinspection PhpUsageOfSilenceOperatorInspection */
					@fputs($fp, $_method . " /" . $filePath . " HTTP/1.1\r\n");
					/** @noinspection PhpUsageOfSilenceOperatorInspection */
					@fputs($fp, "HOST: " . $fileHost . "\r\n");
					if ($isHttpAuth)
					{
						/** @noinspection PhpUsageOfSilenceOperatorInspection */
						@fputs($fp, "Authorization: " . trim($httpAuth) . "\r\n");
					}
					/** @noinspection PhpUsageOfSilenceOperatorInspection */
					@fputs($fp, "User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1) Gecko/20061010 Firefox/2.0\r\n");
					/** @noinspection PhpUsageOfSilenceOperatorInspection */
					@fputs($fp, "Connection: close\r\n\r\n");
					if ($_method == 'POST')
					{
						/** @noinspection PhpUsageOfSilenceOperatorInspection */
						@fputs($fp, $postdata);
					}
					/** @noinspection PhpUsageOfSilenceOperatorInspection */
					@stream_set_timeout($fp, $timeout);
					/** @noinspection PhpUsageOfSilenceOperatorInspection */
					@stream_set_blocking($fp, 1);

					$response = '';
					/** @noinspection PhpUsageOfSilenceOperatorInspection */
					while (!@feof($fp))
					{
						/** @noinspection PhpUsageOfSilenceOperatorInspection */
						$response .= @fgets($fp);
					}
					fclose($fp);

					if ($response)
					{
						// Split headers from content
						$response = explode("\r\n\r\n", $response);

						// Remove headers from response
						array_shift($response);

						// Get contents only as string
						$content = trim(implode("\r\n\r\n", $response));
					}
				}
				else
				{
					$this->_chunker->addError('fsockopen - Error on load file - ' . $file);
				}
				break;

			case 'CURL':
				/** @noinspection PhpUsageOfSilenceOperatorInspection */
				$ch = @curl_init();
				if ($ch)
				{
					curl_setopt($ch, CURLOPT_HEADER, 0);
					curl_setopt($ch, CURLOPT_FAILONERROR, 1);
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
					curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
					curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
					curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
					curl_setopt($ch, CURLOPT_URL, $file);

					// Do not verify the SSL certificate
					curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
					curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

					if ($postdata !== null)
					{
						curl_setopt($ch, CURLOPT_POST, true);
						curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
					}
					else
					{
						curl_setopt($ch, CURLOPT_POST, false);
					}

					if ($isHttpAuth)
					{
						$_type = strtoupper($httpAuthType);

						switch ($_type)
						{
							case 'NTLM':
								curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_NTLM);
								break;
							case 'GSSNEGOTIATE':
								curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_GSSNEGOTIATE);
								break;
							case 'DIGEST':
								curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
								break;
							default:
							case 'BASIC':
								curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
								break;
						}

						curl_setopt($ch, CURLOPT_USERPWD, $httpAuthUser . ':' . $httpAuthPass);
					}

					if ($this->_phpSafeMode || $this->_phpOpenBasedir)
					{
						// Follow location/redirect does not work if safe_mode enabled or open_basedir is set
						curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
						$data = $this->curlExecFollow($ch);
					}
					else
					{
						curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
						$data = curl_exec($ch);
					}

					$info = curl_getinfo($ch);
					/** @noinspection PhpUsageOfSilenceOperatorInspection */
					$http_code = @$info['http_code'];

					if ($http_code == '200')
					{
						$content = $data;
					}
					else
					{
						$this->_chunker->addError('cURL - Error load file (http-code: ' . $http_code . ') - ' . $file);
					}

					if (curl_errno($ch))
					{
						$this->_chunker->addError('cURL - Error: ' . curl_error($ch) . ' - ' . $file);
					}

					curl_close($ch);
				}
				break;
		}

		$content = trim($content);

		$this->_loadMethod = $origLoadMethod;

		if (empty($content))
		{
			$this->_chunker->addLog('Empty content: ' . $file);
		}
		else
		{
			$this->_chunker->addLog('File contents loaded: ' . $file);
		}

		return $content;
	}

	/**
	 * Wrapper for curl_exec when CURLOPT_FOLLOWLOCATION is not possible
	 * {@link http://www.php.net/manual/de/function.curl-setopt.php#102121}
	 *
	 * @param resource $ch          Curl Ressource
	 * @param null     $maxredirect Maximum amount of redirects (defaults 5 or libcurl limit)
	 *
	 * @return string Contents of curl_exec
	 */
	protected function curlExecFollow($ch, &$maxredirect = null)
	{
		$mr = ($maxredirect === null ? 5 : (int) $maxredirect);

		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
		if ($mr > 0)
		{
			$newurl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
			$rch = curl_copy_handle($ch);

			curl_setopt($rch, CURLOPT_HEADER, true);
			curl_setopt($rch, CURLOPT_NOBODY, true);
			curl_setopt($rch, CURLOPT_FORBID_REUSE, false);
			curl_setopt($rch, CURLOPT_RETURNTRANSFER, true);

			do
			{
				curl_setopt($rch, CURLOPT_URL, $newurl);
				$header = curl_exec($rch);

				if (curl_errno($rch))
				{
					$code = 0;
				}
				else
				{
					$code = curl_getinfo($rch, CURLINFO_HTTP_CODE);

					if ($code == 301 || $code == 302)
					{
						preg_match('/Location:(.*?)\n/', $header, $matches);
						$newurl = trim(array_pop($matches));
					}
					else
					{
						$code = 0;
					}
				}
			} while ($code && --$mr);

			curl_close($rch);
			if (!$mr)
			{
				if ($maxredirect === null)
				{
					trigger_error('JsCssChunker(Request) - Too many redirects. When following redirects, libcurl hit the maximum amount.', E_USER_WARNING);
				}
				else
				{
					$maxredirect = 0;
				}

				return false;
			}

			curl_setopt($ch, CURLOPT_URL, $newurl);
		}

		return curl_exec($ch);
	}

	/**
	 * Check can load files
	 *
	 * @access public
	 * @return boolean Can chunk
	 */
	public function check()
	{
		static $state;

		if ($state == null || empty($this->_loadMethod))
		{
			$state = false;

			/** @noinspection PhpUsageOfSilenceOperatorInspection */
			@ini_set('allow_url_fopen', '1');
			$allow_url_fopen = ini_get('allow_url_fopen');

			if (function_exists('curl_init') && function_exists('curl_exec') && empty($this->_loadMethod))
			{
				$this->_loadMethod = 'CURL';
			}

			if (function_exists('file_get_contents') && $allow_url_fopen && empty($this->_loadMethod))
			{
				$this->_loadMethod = 'FILEGETCONTENTS';
			}

			if (function_exists('fsockopen') && empty($this->_loadMethod))
			{
				/** @noinspection PhpUsageOfSilenceOperatorInspection */
				$connnection = @fsockopen('127.0.0.1', 80, $errno, $error, 4);
				/** @noinspection PhpUsageOfSilenceOperatorInspection */
				if ($connnection && @is_resource($connnection))
				{
					$this->_loadMethod = 'FSOCKOPEN';
				}
			}

			if ($this->_loadMethod)
			{
				$state = true;
			}
		}

		return $state;
	}
}
