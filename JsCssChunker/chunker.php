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
 * @version    $Id$
 *
 * @package    JsCssChunker
 *
 * @author     Reinhard Hiebl <reinhard@hieblmedia.com>
 * @copyright  Copyright (C) 2011 - 2012, HieblMedia (Reinhard Hiebl)
 * @license    http://www.opensource.org/licenses/gpl-3.0.html GNU General Public License, version 3.0 (GPLv3)
 * @link       http://chunker.hieblmedia.net/
 */

define('JSCSSCHUNKER_COMPRESSOR_DIR', dirname(__FILE__) . DIRECTORY_SEPARATOR . 'compressor');


/**
 * Class to minify, merge and compress stylesheet and javascript files
 *
 * @package  JsCssChunker
 * @since    0.0.1
 */
class JsCssChunker
{
	protected $pageUrl = '';

	protected $rootUrl = '';

	protected $rootTargetUrl = '';

	protected $autoTarget = false;

	protected $options = array(

		// The base href, will be replaced when using parseRawHeader method and base metatag is found. Leave empty for auto-autodetection
		'baseHref' => '',

		// An alternative target Url (relative or absolute), e.g. for a CDN. Leave empty for auto-autodetection
		'targetUrl' => '',

		// If true all empty lines will be removed (only affected when compressors are disabled)
		'removeEmptyLines' => true,

		// If falses @import rules will be ignored
		'stylesheetRecursiv' => true,

		// UTF-8 is highly recommended
		'stylesheetCharset' => 'UTF-8',

		// If false the files are only merged
		'stylesheetCompress' => true,

		/*
		 * Protocol-Relative URL - like url(//domain.tld/path/file.ext)
		 *
		 * If true all absolute links with http(s)://path/xyz will be replaced with //path/xyz, to avoid problems with https in stylesheets
		 * Known issue: IE7 and IE8 load @import files with these feature twice. But it works fine for background urls etc.
		 *
		 * If set 'forceHTTPS' all absolute http:// links become https://.
		 */
		'protocolLess' => false, // (false, true or 'forceHTTPS')

		// If false the files are only merged
		'javascriptCompress' => false,

		// 'recommended: YUICompressor (with java), JSMin (if java not available)'
		'javascriptCompressorClass' => 'JSMin',

		// Full path of java - required for the yui compressor (in most cases only java is enough)
		'javaBin' => 'java',

		// Log file sizes for statistics
		'logFilesize' => false,

		// E.g. array('type' => 'BASIC', 'user' => 'username', 'pass' => 'password)
		'httpAuth' => false,

		// Connection timeout in seconds to load files via url
		'timeout' => 5
	);

	private $_loadMethod = '';

	protected $stylesheetFiles = array();

	protected $javascriptFiles = array();

	private $_log   = array();

	private $_error = array();

	private $_phpSafeMode = false;

	private $_phpOpenBasedir = false;

	private $_stylesheetFileTree = array();

	private $_javascriptFileTree = array();

	public $sizeLog = false;

	protected $stylesheetBuffer = '';

	protected $javascriptBuffer = '';

	/**
	 * Contructor Function for init class and set options
	 *
	 * @param   string  $pageUrl  The Full page URL
	 * @param   array   $options  Options {@link self->options}
	 */
	public function __construct($pageUrl, $options=array())
	{
		$this->pageUrl = $pageUrl;
		$this->rootUrl = parse_url($pageUrl, PHP_URL_SCHEME) . '://' . parse_url($pageUrl, PHP_URL_HOST);

		if (is_array($options) && !empty($options))
		{
			foreach ($options as $k => $v)
			{
				$this->options[$k] = $v;
			}
		}

		$state = self::check();

		if ($state == false)
		{
			throw new Exception('JsCssChunker - Check fail: CURL or file_get_contents with allow_url_fopen or fsockopen is needed');
		}

		// Check PHP settings
		$safeMode = strtolower(ini_get('safe_mode'));
		$this->_phpSafeMode = (($safeMode == '0' || $safeMode == 'off') ? false : true);
		$this->_phpOpenBasedir = (ini_get('open_basedir') == '' ? false : true);

		$this->validateOptions();
	}

	/**
	 * A List of all implemented Javascript Compressors
	 *
	 * @return Array list of compressors (array_keys = compressor option values)
	 */
	static public function getAvailableCompressors()
	{
		return array(
			'YUICompressor' => 'Recommended (if java is available), Compression: Best',
			'JSMin' => 'Recommended (if no java available), Compression: Normal',
			'JSMinPlus' => 'Compression: High, Error-sensitive',
			'JavaScriptPacker' => 'Compression: High, Error-sensitive'
		);
	}

	/**
	 * Method to parse the <head /> from the HTML document
	 *
	 * @param   string   $parseMode  Determine where parse the files (head, body, all, defaults head)
	 * @param   boolean  $autoApply  Automaticly add all founded js and css files to the queue
	 * @param   string   $forceHtml  Optional HTML Content to Parse (leave empty to use the pageUrl Contents)
	 *
	 * @access public
	 * @return array CSS and JS file list
	 */
	public function parseRawHeader($parseMode='head', $autoApply=false, $forceHtml='')
	{
		static $_html;

		if (empty($_html) && !$forceHtml)
		{
			$_html = $this->getFileContents($this->pageUrl);

			// Remove comments from html data
			$_html = preg_replace('#<!--(.*)-->#Uis', '', $_html);

			$html = $_html;

			// Detect the real baseHref from html document
			preg_match('#<base(.*)/>#Uis', $html, $matches);

			if ($matches && isset($matches[1]) && !empty($matches[1]))
			{
				$_attribs = $this->parseAttributes($matches[1]);
				$_attribsLower = array();

				foreach ($_attribs as $k => $v)
				{
					$k = strtolower($k);
					$_attribsLower[$k] = $v;
				}
				$_attribs = $_attribsLower;

				if (isset($_attribs['href']) && !empty($_attribs['href']))
				{
					$baseHref = parse_url($_attribs['href'], PHP_URL_PATH);
					$baseHrefSheme = parse_url($_attribs['href'], PHP_URL_SCHEME);
					$baseHrefHost = parse_url($_attribs['href'], PHP_URL_HOST);

					if ($baseHrefSheme && $baseHrefHost)
					{
						if (substr($baseHref, -1, 1) == '/')
						{
							$baseHref = $baseHref;
						}
						else
						{
							$baseHref = $this->cleanPath(dirname($baseHref)) . '/';
						}

						$this->rootUrl = $baseHrefSheme . '://' . $baseHrefHost;
					}
					else
					{
						$slashBefore = (substr($baseHref, 0, 1) == '/' ? true : false);
						$slashAfter = (substr($baseHref, -1, 1) == '/' ? true : false);

						if (!$slashBefore && !$slashAfter)
						{
							$baseHref = $this->options['baseHref'];
						}
						elseif ($slashBefore && !$slashAfter)
						{
							$baseHref = '/';
						}
						elseif (!$slashBefore && $slashAfter)
						{
							$baseHref = '/' . $baseHref;
						}
					}

					$this->options['baseHref'] = $baseHref;
				}
			}
		}
		elseif ($forceHtml)
		{
			// Remove comments from html data
			$forceHtml = preg_replace('#<!--(.*)-->#Uis', '', $forceHtml);
			$html = $forceHtml;
		}
		else
		{
			$html = $_html;
		}

		$filelist = array(
			'css' => array(),
			'js' => array()
		);

		$_contents = '';

		if (empty($parseMode))
		{
			$parseMode = 'head';
		}
		$parseMode = strtolower($parseMode);

		switch ($parseMode)
		{
			case 'all':
				$_contents = $html;
				break;

			default:
			case 'head':
			case 'body':
				$headData = '';
			$matches = array();

			preg_match('#<' . $parseMode . '.*>(.*)</' . $parseMode . '>#Uis', $html, $matches);
			if (empty($matches[0]))
			{
				return $filelist;
			}

			$_contents = $matches[0];
			break;
		}

		// Get css files
		$matches = array();
		preg_match_all('#<link(.*)>#Uis', $_contents, $matches);

		if ($matches && !empty($matches[0]))
		{
			foreach ($matches[0] as $i => $entry)
			{
				$attributes = trim($matches[1][$i]);

				if (substr($attributes, -1, 1) == '/')
				{
					$attributes = substr($attributes, 0, strlen($attributes) - 1);
					$attributes = trim($attributes);
				}

				$_attribs = $this->parseAttributes($attributes);
				$_attribs['href'] = (isset($_attribs['href']) ? $_attribs['href'] : '');
				$_attribs['rel'] = (isset($_attribs['rel']) ? strtolower($_attribs['rel']) : '');
				$_attribs['type'] = (isset($_attribs['type']) ? strtolower($_attribs['type']) : '');
				$_attribs['media'] = (isset($_attribs['media']) ? strtolower($_attribs['media']) : '');

				if ($_attribs['rel'] == 'stylesheet' && $_attribs['href'] != '')
				{
					$filelist['css'][] = $_attribs;

					if ($autoApply)
					{
						$this->addStylesheet($_attribs['href'], $_attribs['type'], $_attribs['media']);
					}
				}
			}
		}

		// Get js files
		$matches = array();
		preg_match_all('#<script(.*)>.*</script>#Uis', $_contents, $matches);

		if ($matches && !empty($matches[0]))
		{
			foreach ($matches[0] as $i => $entry)
			{
				$attributes = trim($matches[1][$i]);

				if (substr($attributes, -1, 1) == '/')
				{
					$attributes = substr($attributes, 0, strlen($attributes) - 1);
					$attributes = trim($attributes);
				}

				$_attribs = $this->parseAttributes($attributes);
				$_attribs['src'] = (isset($_attribs['src']) ? $_attribs['src'] : '');
				$_attribs['type'] = (isset($_attribs['type']) ? strtolower($_attribs['type']) : '');

				if ($_attribs['src'] != '')
				{
					$filelist['js'][] = $_attribs;

					if ($autoApply)
					{
						$this->addJavascript($_attribs['src'], $_attribs['type']);
					}
				}
			}
		}

		return $filelist;
	}

	/**
	 * Get a specific option in class
	 *
	 * @param   string  $key  Option name
	 * @param   mixed   $def  Default value if $key not set
	 *
	 * @access public
	 * @return mixed The option value
	 */
	public function getOption($key, $def=null)
	{
		return (isset($this->options[$key]) ? $this->options[$key] : $def);
	}

	/**
	 * Set a specific option in class
	 *
	 * @param   string  $key    Option name
	 * @param   mixed   $value  Value to set for option $key
	 *
	 * @access public
	 * @return self
	 */
	public function setOption($key, $value)
	{
		$this->options[$key] = $value;
		$this->validateOptions();
		return $this;
	}

	/**
	 * Method to validate options
	 *
	 * @access protected
	 * @return self
	 */
	protected function validateOptions()
	{
		if ($this->options['baseHref'] == '')
		{
			$pageUrl = $this->pageUrl;

			$pageUrlPath = parse_url($pageUrl, PHP_URL_PATH);

			// Check is filename
			if (substr($pageUrlPath, -1, 1) != '/')
			{
				$file = basename($pageUrlPath);

				if (strrpos($file, '.'))
				{
					$pageUrlPath = preg_replace('#' . $file . '$#Uis', '', $pageUrlPath);
				}
			}

			if (substr($pageUrlPath, -1, 1) != '/')
			{
				$pageUrlPath .= '/';
			}

			$this->options['baseHref'] = $pageUrlPath;
		}

		if (substr($this->options['baseHref'], -1, 1) != '/')
		{
			$this->options['baseHref'] .= '/';
		}

		if ($this->options['targetUrl'] == '')
		{
			$this->options['targetUrl'] = $this->options['baseHref'];
			$this->autoTarget = true;
		}
		else
		{
			$targetUrlScheme = parse_url($this->options['targetUrl'], PHP_URL_SCHEME);
			$targetUrlHost = parse_url($this->options['targetUrl'], PHP_URL_HOST);
			$targetUrlPath = parse_url($this->options['targetUrl'], PHP_URL_PATH);

			if ($targetUrlScheme && $targetUrlHost)
			{
				$this->rootTargetUrl = $targetUrlScheme . '://' . $targetUrlHost;
				$this->options['targetUrl'] = $targetUrlPath;
				$this->autoTarget = false;
			}
			elseif (substr($targetUrlPath, 0, 1) != '/')
			{
				$this->options['targetUrl'] = '/' . $targetUrlPath;
				$this->autoTarget = false;
			}
		}

		if (substr($this->options['targetUrl'], -1, 1) != '/')
		{
			$this->options['targetUrl'] .= '/';
		}

		return $this;
	}

	/**
	 * Method to add a Stylesheet file to parse
	 *
	 * @param   string  $file   Filename (relative or absolute)
	 * @param   string  $type   Type (defaults: text/css)
	 * @param   string  $media  Media (defaults: all)
	 *
	 * @access public
	 * @return self
	 */
	public function addStylesheet($file, $type='text/css', $media='all')
	{
		// Remove url params
		$tmp = explode('?', $file);
		$file = $tmp[0];

		if (!isset($this->stylesheetFiles[$file]))
		{
			$this->stylesheetFiles[$file] = array('media' => $media, 'type' => $type);

			$this->addLog('Stylesheet - Added: ' . $file);
		}

		return $this;
	}

	/**
	 * Method to add a Javascript file to parse
	 *
	 * @param   string  $file  Filename (relative or absolute)
	 * @param   string  $type  Type (defaults: text/javascript)
	 *
	 * @access public
	 * @return self
	 */
	public function addJavascript($file, $type='text/javascript')
	{
		// Remove url params
		$tmp = explode('?', $file);
		$file = $tmp[0];

		if (!isset($this->javascriptFiles[$file]))
		{
			$this->javascriptFiles[$file] = array('type' => $type);

			$this->addLog('Script - Added: ' . $file);
		}

		return $this;
	}

	/**
	 * Method to get a Hash value of processing stylesheets (e.g. for caching)
	 *
	 * @param   boolean  $includeDomain  If true the the PageUrl Domain included in the Cache Hash.
	 *                                   This is Useful for an multi-domain system with one cache folder.
	 *                                   (defaults: false)
	 *
	 * @access public
	 * @return mixed Hash value or false on fail
	 */
	public function getStylesheetHash($includeDomain=false)
	{
		if (!empty($this->stylesheetFiles))
		{
			$prefixArr = array(
				'_type' => 'stylesheet',
				'pageUrl' => $includeDomain ? $this->rootUrl : 'GLOBAL',
				'options' => array(
					$this->getOption('baseHref'),
					$this->getOption('targetUrl'),
					$this->getOption('removeEmptyLines'),
					$this->getOption('stylesheetRecursiv'),
					$this->getOption('stylesheetCharset'),
					$this->getOption('stylesheetCompress')
				)
			);
			$prefix = serialize($prefixArr);

			$hash   = serialize($this->stylesheetFiles);
			$hash   = md5($prefix) . '_' . md5($hash);

			return $hash;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Method to get a Hash value of processing javascripts (e.g. for caching)
	 *
	 * @param   boolean  $includeDomain  If true the the PageUrl Domain included in the Cache Hash.
	 *                        					 This is Useful for an multi-domain system with one cache folder.
	 *                         					 (defaults: false)
	 *
	 * @access public
	 * @return mixed Hash value or false on fail
	 */
	public function getJavascriptHash($includeDomain=false)
	{
		if (!empty($this->javascriptFiles))
		{
			$prefixArr = array(
				'_type' => 'javascript',
				'pageUrl' => $includeDomain ? $this->rootUrl : 'GLOBAL',
				'options' => array(
					$this->getOption('baseHref'),
					$this->getOption('targetUrl'),
					$this->getOption('removeEmptyLines'),
					$this->getOption('javascriptCompress'),
					$this->getOption('javascriptCompressorClass')
				)
			);
			$prefix = serialize($prefixArr);

			$hash = serialize($this->javascriptFiles);
			$hash   = md5($prefix) . '_' . md5($hash);

			return $hash;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Chunk Stylesheets
	 *
	 * @access public
	 * @return string Chunked content
	 */
	public function chunkStylesheets()
	{
		$this->mergeStylesheets();

		$savePath = $this->getTargetUrlSavePath();
		if ($savePath)
		{
			$this->addLog("\n"
				. '!! Important !!: The Stylesheet must be callable from this path:'
				. "\n\t\t\t -- Folder: " . $savePath
				. "\n\t\t\t -- File (e.g.): " . $savePath . $this->getStylesheetHash() . '.css'
				. "\n");
		}

		return $this->stylesheetBuffer;
	}

	/**
	 * Chunk Javascripts
	 *
	 * @access public
	 * @return string Chunked content
	 */
	public function chunkJavascripts()
	{
		$this->mergeJavascripts();

		return $this->javascriptBuffer;
	}

	/**
	 * Merge/Minify/Compress added Javascript files
	 *
	 * @access private
	 * @return string $content return merged content of files
	 */
	private function mergeJavascripts()
	{
		if (empty($this->javascriptFiles))
		{
			return '';
		}

		$contents = array();

		foreach ($this->javascriptFiles as $file => $attribs)
		{
			$filename = $this->getFullUrlFromBase($file);
			$filename = $this->getRealPath($filename);
			$this->_javascriptFileTree[$filename] = array();

			$content  = trim($this->getFileContents($filename));
			$this->logFileSize($content, 'javascript', 'before');

			if ($content != "")
			{
				if ($this->getOption('javascriptCompress'))
				{
					$content = $this->compressJavascript($content);

					if ($_error = $this->getErrors())
					{
						$this->addLog('ERROR - ' . $_error);
					}
					else
					{
						$this->addLog('Javascript - Compressed content');

						// Safe merge without compressor ??
						$content = $content . ';';
					}
				}
				else
				{
					// Safe merge without compressor ??
					$content = $content . ';';
				}

				$contents[$file] = $content;
			}
		}

		if (!empty($contents))
		{
			$content = implode("\n\n", $contents);
			$content = trim($content);
		}

		if (!empty($content) && $this->getOption('removeEmptyLines'))
		{
			$content = $this->removeEmptyLines($content);
			$this->addLog('Javascript - Removed empty lines');
		}

		$this->javascriptBuffer = $content;
		$this->logFileSize($content, 'javascript', 'after');

		$this->addLog('Javascript - Merge complete');

		return $content;
	}

	/**
	 * Merge/Minify/Compress added Stylesheet files recursivly with reading @import rules
	 *
	 * @access private
	 * @return self
	 */
	private function mergeStylesheets()
	{
		if (empty($this->stylesheetFiles))
		{
			return '';
		}

		$contents = array();

		$this->_stylesheetFileTree = array();

		foreach ($this->stylesheetFiles as $file => $attribs)
		{
			$media = $attribs['media'];

			$cont = $this->_loadStylesheets($file, $this->_stylesheetFileTree);
			$cont = $this->_checkCssMedia($cont, $media);

			$contents[$file] = $cont;
		}

		$content = implode("\n\n", $contents);

		// Remove all charset definitions (important!)
		$content = preg_replace('/@charset\s+[\'"](\S*)\b[\'"];/i', '', $content);
		$this->addLog('Stylesheet - remove all @charset rules for browser compatibility');

		if ($this->getOption('stylesheetCompress'))
		{
			$content = $this->compressStylesheet($content);
			$this->addLog('Stylesheet - Compressed content');
		}

		if ($charset = $this->getOption('stylesheetCharset'))
		{
			// Add @charset to stylesheet in FIRST LINE only (important with linebreak for safari)
			$content = "@charset \"" . $charset . "\";\n" . $content;
			$this->addLog('Stylesheet - Set @charset (' . $charset . ') in first line');
		}

		if ($this->getOption('removeEmptyLines'))
		{
			$content = $this->removeEmptyLines($content);
			$this->addLog('Stylesheet - Removed empty lines');
		}

		if ($protocolLess = $this->getOption('protocolLess'))
		{
			if ($protocolLess === 'forceHTTPS')
			{
				$content = preg_replace('/http:\/\//i', 'https://', $content);
				$this->addLog('Stylesheet - protocolLess: Forced absolute http:// links to https://');
			}
			else
			{
				$content = preg_replace('/(http:\/\/|https:\/\/)/i', '//', $content);

				$this->addLog('Stylesheet - protocolLess: Replaced all absolute http(s)://path/xyz links with //path/xyz');
			}
		}

		$this->stylesheetBuffer = $content;
		$this->logFileSize($content, 'stylesheet', 'after');

		$this->addLog('Stylesheet - Merge complete');

		return $this;
	}

	/**
	 * Compress Stylesheet contents
	 * (Note: Some rules are inspired from the YUI-CSS-Compressor)
	 *
	 * @param   string  $content  Stylesheet content
	 *
	 * @access private
	 * @return string compressed Stylesheet content
	 */
	private function compressStylesheet($content)
	{
		$content = $this->stripStylesheetComments($content);

		// Strip whitespaces.
		$content = preg_replace('/\s+/', ' ', $content);

		// Remove the spaces before the things that should not have spaces before them.
		$content = preg_replace('/\s+({|}|;)/', '$1', $content);

		// Remove the spaces after the things that should not have spaces after them.
		$content = preg_replace('/([!{}:;>+\(\[,])\s+/', '$1', $content);

		// Remove unnecessary semicolons
		$content = preg_replace('/;+\}/', '}', $content);

		// Replace 0(px,em,%) with 0.
		$content = preg_replace('/([\s:])(0)(px|em|%|in|cm|mm|pc|pt|ex)/i', '$1$2', $content);

		// Replace 0 0 0 0; with 0.
		$content = preg_replace('/:0 0 0 0(;|\})/', ':0$1', $content);
		$content = preg_replace('/:0 0 0(;|\})/', ':0$1', $content);
		$content = preg_replace('/:0 0(;|\})/', ':0$1', $content);

		// Replace 0.6 to .6, but only when preceded by : or a white-space
		$content = preg_replace('/(:|\s)0+\.(\d+)/', '$1.$2', $content);

		// Shorten colors from rgb(51,102,153) to #336699
		// This makes it more likely that it'll get further compressed in the next step.
		preg_match_all('/rgb\s*\(\s*([0-9,\s]+)\s*\)/i', $content, $matches);
		if (!empty($matches[0]))
		{
			$rgbToHex = array('s' => array(), 'r' => array());

			foreach ($matches[0] as $k => $v)
			{
				$rgb = explode(',', $matches[1][$k]);

				$hex = "#";
				$hex .= str_pad(dechex((int) $rgb[0]), 2, "0", STR_PAD_LEFT);
				$hex .= str_pad(dechex((int) $rgb[1]), 2, "0", STR_PAD_LEFT);
				$hex .= str_pad(dechex((int) $rgb[2]), 2, "0", STR_PAD_LEFT);

				if (!in_array($v, $rgbToHex['s']))
				{
					$rgbToHex['s'][] = $v;
					$rgbToHex['r'][] = $hex;
				}
			}

			if (!empty($rgbToHex))
			{
				$content = str_replace($rgbToHex['s'], $rgbToHex['r'], $content);
			}
		}

		// Shorten colors from #AABBCC to #ABC.
		$content = $this->_compressHexColors($content);

		// Shorter opacity IE filter
		$content = preg_replace('/progid:DXImageTransform\.Microsoft\.Alpha\(Opacity=/i', 'alpha(opacity=', $content);

		// Retain space for special IE6 cases
		$content = preg_replace('/:first-(line|letter)(\{|,)/', ':first-$1 $2', $content);

		// Remove empty rules
		$content = preg_replace('/[^\};\{\/]+\{\}/', '', $content);

		// Replace multiple semi-colons in a row by a single one
		$content = preg_replace('/;;+/', ';', $content);

		// Group same consecutive and remove empty @media rules
		$content = $this->_groupCssMediaRules($content);

		// @ Rules on each line for a small overview of media types and '@charset only in first line'.
		$content = str_replace("@", "\n@", $content);

		return trim($content);
	}

	/**
	 * Strip Stylesheet Comments (consider css hacks)
	 *
	 * @param   string  $content  Contents of Stylesheet/CSS
	 *
	 * @access protected
	 * @return string with striped CSS comments
	 */
	protected function stripStylesheetComments($content='')
	{
		// -- Handle hacks before --

		// Preserve empty comment for value (Box-Model-Hack)
		$content = preg_replace('#/\*\s*\*/\s*:#', '___JSCSSCHUNKER_REPLACETOKEN_HACK_IE_EMPTY_COMMENT___:', $content);
		$content = preg_replace('#:\s*/\*\s*\*/#', ':___JSCSSCHUNKER_REPLACETOKEN_HACK_IE_EMPTY_COMMENT___', $content);

		// Strip comments
		$content = preg_replace("#/\*.+\*/#sU", "", $content);

		// -- Handle hacks after --
		$content = str_replace('___JSCSSCHUNKER_REPLACETOKEN_HACK_IE_EMPTY_COMMENT___', '/**/', $content);

		return $content;
	}

	/**
	 * Shorten colors from #AABBCC to #ABC.
	 *
	 * @param   string  $content  Css Content
	 *
	 * @access private
	 * @return string Replaced Css Content
	 */
	private function _compressHexColors($content)
	{
		return preg_replace_callback('/([^=])#([a-f\\d])\\2([a-f\\d])\\3([a-f\\d])\\4([\\s;\\}])/i', array($this, '_compressHexColors_Callback'), $content);
	}

	/**
	 * Callback Method for preg_replace_callback in _compressHexColors
	 *
	 * @param   array  $m  From preg_replace_callback
	 *
	 * @access private
	 * @return replaced The shorten Hex Code
	 */
	private function _compressHexColors_Callback($m)
	{
		return (string) ($m[1] . '#' . strtolower($m[2] . $m[3] . $m[4]) . $m[5]);
	}

	/**
	 * Group same consecutive and remove empty CSS @media rules
	 *
	 * @param   string  $content  Css Content
	 *
	 * @access private
	 * @return string Replaced Css Content
	 */
	private function _groupCssMediaRules($content)
	{
		$onlyPrintMedia = '';
		$mixedMedia = '';
		$lastFoundMedia = '';

		// Add import rules always first (a bit risk with CSS specificity, but good for browser performance and @import dont work within media rules)
		$atImportContents = '';
		$regex = '/@import\s+(?:url\s*\(\s*[\'"]?|[\'"])([^"^\'^\s]+)(?:[\'"]?\s*\)|[\'"])\s*([\w\s\(\)\d\:,\-]*);/i';
		preg_match_all($regex, $content, $matches);

		if (!empty($matches[0]))
		{
			$atImportContents = implode("\n", $matches[0]) . "\n";
		}

		preg_match_all('/@media\s+(.*)\s?\{(.*)\}\s?\}/Uis', $content, $matches);

		if (!empty($matches[0]))
		{
			foreach ($matches[0] as $k => $v)
			{
				$media = trim($matches[1][$k]);
				$styles = trim($matches[2][$k]);

				// Remove empty media rules
				if (empty($styles))
				{
					continue;
				}

				if ($media == 'print')
				{
					if (empty($onlyPrintMedia))
					{
						$onlyPrintMedia = '@media print{';
					}
					$onlyPrintMedia .= $styles . '}';
				}
				else
				{
					if (empty($mixedMedia) || empty($lastFoundMedia) || $lastFoundMedia != $media)
					{
						if (!empty($mixedMedia))
						{
							$mixedMedia .= '}';
						}
						$mixedMedia .= '@media ' . $media . '{';
						$mixedMedia .= $styles . '}';

						$lastFoundMedia = $media;
					}
					else
					{
						$mixedMedia .= $styles . '}';
					}
				}
			}

			$_tmp = '';
			if (!empty($mixedMedia))
			{
				$_tmp .= $mixedMedia . '}';
			}
			if (!empty($onlyPrintMedia))
			{
				$_tmp .= $onlyPrintMedia . '}';
			}
			$content = $_tmp;
		}

		return $atImportContents . $content;
	}

	/**
	 * Method to check if JavaScript code is already compressed
	 *
	 * @param   string  $jscode  Contents of the Javascript
	 *
	 * @access private
	 * @return boolean
	 */
	private function isJavascriptCompressed($jscode='')
	{
		if ($jscode == '')
		{
			return false;
		}

		if (strpos($jscode, 'eval(function(p,a,c,k,e,d)') !== false)
		{
			return true;
		}

		return false;
	}

	/**
	 * Compress javascript with an compressor class
	 *
	 * @param   string  $content  Contents of the Javascript
	 *
	 * @access private
	 * @return string Compressed Javascript content
	 */
	private function compressJavascript($content)
	{
		if (!empty($content))
		{
			// A simple check of code its already compressed
			if ($this->isJavascriptCompressed($content))
			{
				return trim($content);
			}

			$compressedContent = '';
			$sizeBefore = function_exists('mb_strlen') ? mb_strlen($content) : strlen($content);

			try
			{
				$compressorClass = $this->getOption('javascriptCompressorClass');

				if (!class_exists($compressorClass))
				{
					$compressorFile = JSCSSCHUNKER_COMPRESSOR_DIR . DIRECTORY_SEPARATOR . $compressorClass . '.php';

					if (file_exists($compressorFile))
					{
						require_once $compressorFile;
					}
					else
					{
						$this->addError('Javascript compressor file not found: ' . $compressorFile);
					}
				}

				if (class_exists($compressorClass))
				{
					ob_start();
					ob_implicit_flush(false);

					switch ($compressorClass)
					{
						case 'JSMin':
							$compressedContent = JSMin::minify($content);
							break;
						case 'JSMinPlus':
							$compressedContent = JSMinPlus::minify($content);
							break;
						case 'JavaScriptPacker':
							$packer = new JavaScriptPacker($content);
							$compressedContent = $packer->pack();
							break;
						case 'YUICompressor':
							$compressedContent = YUICompressor::minify($content, array('javabin' => $this->getOption('javaBin', 'java'), 'type' => 'js'));
							break;
						default:
							$this->addError('Compressor not implemented: ' . $compressorClass);
						break;
					}

					$errors = trim(ob_get_contents());
					ob_end_clean();

					if ($errors)
					{
						$this->addError('Javascript Compressor Error: ' . $errors);
					}
				}
				else
				{
					$this->addError('Compressor Class not found or not callable: ' . $compressorClass);
				}
			}
			catch (Exception $e)
			{
				$msg = "/* \n * --- ERROR (Chunker-Javascript-Compressor) --- \n * Message: " . $e->getMessage() . "\n */ \n\n";
				$content = $msg . $content;
			}

			// Only use compressedContent if has contents
			if ($compressedContent)
			{
				$sizeAfter = function_exists('mb_strlen') ? mb_strlen($compressedContent) : strlen($compressedContent);
				$diffSize = $sizeBefore - $sizeAfter;

				// Compress/Minify only if size after lesser then before
				if ($diffSize > 1)
				{
					$content = $compressedContent;
				}
			}
		}

		return trim($content);
	}

	/**
	 * Determine the full url of a file
	 *
	 * @param   string  $url  Relative or Absolute URL
	 *
	 * @access protected
	 * @return string Full aboslute URL
	 */
	protected function getFullUrlFromBase($url='')
	{
		$scheme = parse_url($url, PHP_URL_SCHEME);
		$host = parse_url($url, PHP_URL_HOST);

		if (!$scheme && !$host)
		{
			$url = $this->cleanPath($url);

			if (substr($url, 0, 1) == '/')
			{
				$url = $this->rootUrl . $url;
			}
			else
			{
				$url = $this->rootUrl . $this->options['baseHref'] . $url;
			}
		}

		return $url;
	}

	/**
	 * Remove empty lines in a string
	 *
	 * @param   string  $string  The string of content
	 *
	 * @access public
	 * @return string without empty lines
	 */
	public function removeEmptyLines($string)
	{
		return preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $string);
	}

	/**
	 * Method to load Stylesheets recursivly with @import rule
	 * and replacemnt for included path
	 *
	 * @param   string  $file       Path to file to load
	 * @param   array   &$fileTree  Tree of all files (referenced)
	 *
	 * @access private
	 * @return string Merged content
	 */
	private function _loadStylesheets($file, &$fileTree=array())
	{
		static $loadeFiles = array();

		$filename = $this->getFullUrlFromBase($file);
		$filename = $this->getRealpath($filename);

		// Prevent loops
		if (in_array($filename, $loadeFiles))
		{
			return '';
		}

		$fileTree[$filename] = array();

		$content = $this->getFileContents($filename);
		$this->logFileSize($content, 'stylesheet', 'before');
		$content = trim($content);

		if (empty($content))
		{
			return '';
		}

		$loadeFiles[] = $filename;

		// Is important to remove comments before search @import rules
		$content = $this->stripStylesheetComments($content);

		$base = dirname($filename);

		// $regex = '/@import\s+(?:url\s*\(\s*[\'"]?|[\'"])([^"^\'^\s]+)(?:[\'"]?\s*\)|[\'"])\s*([\w\s\(\)\d\:,\-]*);/i';
		$regex = '/@import\s+(?:url\s*\(\s*[\'"]?|[\'"])((?!http:|https:|ftp:|\/\/)[^"^\'^\s]+)(?:[\'"]?\s*\)|[\'"])\s*([\w\s\(\)\d\:,\-]*);/i';
		preg_match_all($regex, $content, $matches);

		$relpaths = (!empty($matches[0]) ? $matches[1] : array());
		$relpathsMedia = (!empty($matches[0]) ? $matches[2] : array());

		$content = $this->_replaceCSSPaths($content, $base);

		if (!empty($relpaths) && $this->getOption('stylesheetRecursiv'))
		{
			foreach ($relpaths as $key => $relfile)
			{
				$this->_replaceCSSPaths($matches[0][$key], $base);

				$importPath = $base . '/' . $relfile;
				$importPath = $this->getRealpath($importPath);

				// $fileTree[$importPath] = array();

				$relpath = dirname($relfile);
				$icont = $this->_loadStylesheets($importPath, $fileTree[$filename]);
				$icont = trim($icont);

				// Remove all charset definitions
				$icont = preg_replace('/@charset\s+[\'"](\S*)\b[\'"];/i', '', $icont);

				if (!empty($icont))
				{
					/**
					 * If the imported file has defined media
					 * and within contents of file not defined
					 * then do include it.
					 */

					$importMedia = trim($relpathsMedia[$key]);

					// Add media all no media set
					if (strpos($icont, '@') === false)
					{
						// Add media query from @import if available or media all as fallback
						if ($importMedia)
						{
							$icont = '@media ' . $importMedia . ' { ' . $icont . ' }';
						}
						else
						{
							$icont = '@media all { ' . $icont . ' }';
						}
					}
					elseif ($importMedia && strpos($icont, '@') === false)
					{
						// Add media query from @import additional if available
						$icont = str_replace('@media ', '@media ' . $importMedia . ', ', $icont);
					}
				}

				// Replace @import with the loaded contents
				$content = str_replace($matches[0][$key], $icont, $content);
			}
		}

		return $content;
	}

	/**
	 * Check @media type is definied in CSS file and add it if its not found
	 *
	 * @param   string  $content  CSS content
	 * @param   string  $media    Mediatype for css rules, default all
	 *
	 * @access private
	 * @return string $content return content of file with @media rule
	 */
	private function _checkCssMedia($content, $media='all')
	{
		if ($content && strpos($content, '@media') === false)
		{
			$content = '@media ' . $media . ' {' . $content . '}';
		}

		return $content;
	}

	/**
	 * Method to replace url paths in css rules in merged content
	 *
	 * @param   string  $content  CSS content
	 * @param   string  $path     Path of file to replace
	 *
	 * @access protected
	 * @return string $content return content with replaced url([new_path])
	 */
	protected function _replaceCSSPaths($content, $path)
	{
		$path = $this->cleanPath($path);

		if (substr($path, -1, 1) != '/')
		{
			$path .= '/';
		}

		/**
		 * Search for "/([,:].*)url\(([\'"]?)(?![a-z]+:)([^\'")]+)[\'"]?\)?/i"
		 * The : at first as shorthand to exclude the @import rule in stylesheets
		 * Does IGNORE extenal files like http://, ftp://... Only relative urls would by replaced
		 */
		// Set pathscrope for callback method to current stylesheet path
		$this->pathscope = $path;

		// Remove linebreaks to can find multiple url sources (comma seperated)
		$content = str_replace(array("\n", "\r"), '', $content);

		// Strip @import
		$regex = '/@import\s+(?:url\s*\(\s*[\'"]?|[\'"])([^"^\'^\s]+)(?:[\'"]?\s*\)|[\'"])\s*([\w\s\(\)\d\:,\-]*);/i';
		preg_match_all($regex, $content, $importMatches);

		if ($importMatches && !empty($importMatches[0]))
		{
			foreach ($importMatches[0] as $k => $v)
			{
				$content = str_replace($v, '[[_replaceCSSPaths_@import_key_' . $k . ']]', $content);
			}
		}

		// Replace and shortend urls with pathscop
		$regex = '/([,:].*)url\(([\'"]?)(?![a-z]+:)([^\'")]+)[\'"]?\)/Ui'; // only relative urls (and without data:)

		$content = preg_replace_callback($regex, array( &$this, '_replaceCSSPaths_Callback'), $content);
		$content = str_replace('[[CALLBACK_URLREPLACED]]', 'url', $content);

		// Revert @import
		if ($importMatches && !empty($importMatches[0]) && strpos($content, '[[_replaceCSSPaths_@import_key_') !== false)
		{
			foreach ($importMatches[0] as $k => $v)
			{
				$content = str_replace('[[_replaceCSSPaths_@import_key_' . $k . ']]', $importMatches[0][$k], $content);
			}
		}

		// Reset pathscope
		$this->pathscope = '';

		return $content;
	}

	/**
	 * Callback Method for preg_replace_callback in _replaceCSSPaths
	 *
	 * @param   array  $matches  From preg_replace_callback
	 *
	 * @access private
	 * @return replaced path prepend with $this->pathscope
	 */
	private function _replaceCSSPaths_Callback($matches)
	{
		static $targetUrlFull;
		static $targetUrlArr;
		static $baseHrefArr;

		if (preg_match('/^\/\/.*/', $matches[3]))
		{
			// (Special case) Protocol-Relative Url (url without protocol like: //domain.tld/path/file.png)
			return $matches[1] . '[[CALLBACK_URLREPLACED]](' . $matches[3] . ')';
		}
		else
		{
			$url = $this->pathscope . $matches[3];
		}

		$baseUrl = $this->rootUrl . $this->options['baseHref'];
		$targetUrl = $this->getOption('targetUrl');

		if (!$targetUrlFull)
		{
			$targetUrlFull = $this->rootUrl . parse_url($targetUrl, PHP_URL_PATH);
			$targetUrlArr = explode('/', $targetUrl);

			$tmpArr = array();
			foreach ($targetUrlArr as $k => $v)
			{
				if ($v != '')
				{
					$tmpArr[] = $v;
				}
			}
			$targetUrlArr = $tmpArr;

			$baseHrefArr = explode('/', $this->options['baseHref']);

			$tmpArr = array();
			foreach ($baseHrefArr as $k => $v)
			{
				if ($v != '')
				{
					$tmpArr[] = $v;
				}
			}
			$baseHrefArr = $tmpArr;
		}

		if (parse_url($url, PHP_URL_SCHEME) && !preg_match('#^' . $targetUrl . '#', $url))
		{
			// Add directory difference for full replace baseUrl to targetURL
			$diffPath = $targetUrl . str_repeat('../', count($targetUrlArr));
			$url = preg_replace('#' . $this->rootUrl . '#Uis', $this->rootUrl . $diffPath, $url);

			if ($this->options['baseHref'] != $targetUrl)
			{
				$url = preg_replace('#^' . $targetUrlFull . '#', '', $url);
			}
			else
			{
				$url = preg_replace('#^' . $baseUrl . '#', $targetUrl, $url);
			}
		}
		else
		{
			$url = preg_replace('#^' . $baseUrl . '#', $targetUrl, $url);
		}

		// Clean URL-Path
		$url = $this->cleanPath($url);

		if ($this->options['baseHref'] != '/' && $baseHrefArr > 1)
		{
			if (strpos($url, '..' . $this->options['baseHref']))
			{
				$baseHrefCount = count($baseHrefArr);
				$diff = str_repeat('/..', $baseHrefCount) . $this->options['baseHref'];

				if ($diff)
				{
					$url = preg_replace('#' . $diff . '#U', '/', $url, 1);
				}
			}
		}

		return $matches[1] . '[[CALLBACK_URLREPLACED]](' . $this->cleanPath($url) . ')';
	}

	/**
	 * Strip and replace additional / or \ in a path
	 * Removing relative dot notations also like the php realpath function
	 *
	 * @param   string  $path  Path
	 * @param   string  $ds    Directory seperator
	 *
	 * @access public
	 * @return The clean path
	 */
	public function cleanPath($path='', $ds='/')
	{
		$path = trim($path);
		if (empty($path))
		{
			return;
		}

		if (!empty($path))
		{
			$scheme = parse_url($path, PHP_URL_SCHEME);
			$host = parse_url($path, PHP_URL_HOST);
			$path = parse_url($path, PHP_URL_PATH);

			// Remove double slashes and backslahses and convert all slashes and backslashes to DS
			$path = preg_replace('#[/\\\\]+#', $ds, $path);
			$path = $this->getRealPath($path);

			if ($scheme && $host)
			{
				$path = $scheme . '://' . $host . $path;
			}
		}

		return $path;
	}

	/**
	 * Like Relpath function but without check filesystem and does not create an absolute path is relative
	 *
	 * @param   string  $path  Relative path like "path1/./path2/../../file.png" or external like "http://domain.tld/path1/../path2/file.png"
	 *
	 * @access private
	 * @return string $path shortend path
	 */
	private function getRealpath($path='')
	{
		$path = trim($path);
		if (empty($path))
		{
			return;
		}

		$scheme = parse_url($path, PHP_URL_SCHEME);
		$host = parse_url($path, PHP_URL_HOST);
		$path = parse_url($path, PHP_URL_PATH);

		$tmp = array();
		$parts = explode('/', $path);

		foreach ($parts as $i => $dir)
		{
			if ($dir == '' || $dir == '.')
			{
				continue;
			}

			if ($dir == '..' && $i > 0 && end($tmp) != '..' && !empty($tmp))
			{
				array_pop($tmp);
			}
			else
			{
				$tmp[] = $dir;
			}
		}

		$path = ($path{0} == '/' ? '/' : '') . implode('/', $tmp);

		if ($scheme && $host)
		{
			$path = $scheme . '://' . $host . $path;
		}

		return $path;
	}

	/**
	 * Get the absolute URL Path where the stylesheet must be callable
	 *
	 * @param   boolean  $relative  If true only the path will be returned
	 *
	 * @access public
	 * @return string Absolute URL save path
	 */
	public function getTargetUrlSavePath($relative=false)
	{
		$rootUrl = $this->rootTargetUrl ? $this->rootTargetUrl : $this->rootUrl;
		$path = $this->options['targetUrl'];

		return $relative ? str_replace($this->getOption('baseHref'), '/', $path) : $rootUrl . $path;
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
				$connnection = @fsockopen($this->pageUrl, 80, $errno, $error, 4);
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

	/**
	 * Check directores based on a compared file of modifications
	 *
	 * @param   array   $dirs         Absolute directory paths on local filesystem
	 * @param   string  $compareFile  Filename to compare with $dirs
	 * @param   string  $filter       preg_match filter for files (defaults [.css|.js]$)
	 *
	 * @access public
	 * @return mixed (timestamp)Filetime or (bool)false on fail
	 */
	public function hasFoldersModifications($dirs, $compareFile, $filter='[.css|.js]$')
	{
		if (!is_array($dirs))
		{
			$dirs = array($dirs);
		}

		$lastModified = 0;

		foreach ($dirs as $_dir)
		{
			$lm = $this->getLastModifiedFileByFolder($_dir, $filter);

			if ($lm > $lastModified)
			{
				$lastModified = $lm;
			}
		}

		if ($lastModified)
		{
			$compareFile = $this->cleanPath($compareFile);
			$filetime = @filemtime($compareFile);

			if ($filetime && $lastModified > $filetime)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Get LastModified File by local path(dir)
	 *
	 * @param   string  $path    Absolute directory path on local filesystem
	 * @param   string  $filter  preg_match filter for files (defaults [.css|.js]$)
	 *
	 * @access public
	 * @return mixed (timestamp)Filetime or (bool)false on fail
	 */
	public function getLastModifiedFileByFolder($path, $filter='[.css|.js]$')
	{
		// Workaround to fix the path (double-slash, dot-notation, etc.)
		$path = dirname($this->cleanPath($path) . '/.');

		// Check dir exists on local filesystem
		if (!is_dir($path))
		{
			return false;
		}

		$files = self::_filesRecursiv($path, $filter);

		if (empty($files))
		{
			// Check the directory when no files was found in path
			// not supported on all filesystems but much enough as fallback too
			$filetime = @filemtime($path);
		}
		else
		{
			array_multisort(
			array_map('filemtime', $files),
			SORT_NUMERIC,
			SORT_DESC, // Newest first, or `SORT_ASC` for oldest first
			$files
			);

			$file     = array_shift($files);
			$filetime = @filemtime($file);
		}

		return $filetime ? $filetime : false;
	}

	/**
	 * Helper function to search files recursiv by path with an specific filter
	 *
	 * @param   string  $path    Absolute directory path on local filesystem
	 * @param   string  $filter  preg_match filter for files (defaults [.css|.js]$)
	 *
	 * @access private
	 * @return array List of files.
	 */
	private static function _filesRecursiv($path, $filter='[.css|.js]$')
	{
		$arr = array();

		$findfiles = false;

		$handle = opendir($path);

		while (($file = readdir($handle)) !== false)
		{
			if ($file != '.' && $file != '..')
			{
				$fullpath = $path . DIRECTORY_SEPARATOR . $file;

				if (is_dir($fullpath))
				{
					$arr = array_merge($arr, self::_filesRecursiv($fullpath, $filter));
				}
				elseif (preg_match("/$filter/", $file))
				{
					$arr[] = $fullpath;
				}
			}
		}
		closedir($handle);

		return $arr;
	}

	/**
	 * Get the contents of specific file/url
	 *
	 * @param   string  $file  Absolute Path or Url to the file
	 *
	 * @access protected
	 * @return string Contents from File
	 */
	protected function getFileContents($file)
	{
		$content = '';
		$timeout = $this->getOption('timeout');

		@ini_set('default_socket_timeout', $timeout);

		$origLoadMethod = $this->_loadMethod;

		// Force file_get_contents if file exists on local filesystem
		if (!preg_match('#^(http|https)://#Uis', $file) && file_exists($file) && is_readable($file))
		{
			$this->_loadMethod = 'FILEGETCONTENTS';
		}

		$authOptions = $this->getOption('httpAuth');
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
			$httpAuth = @$_SERVER['HTTP_AUTHORIZATION'];
			$httpAuthType = @$_SERVER['AUTH_TYPE'];
			$httpAuthUser = @$_SERVER['PHP_AUTH_USER'];
			$httpAuthPass = @$_SERVER['PHP_AUTH_PW'];
			$isHttpAuth = (!empty($httpAuth) && !empty($httpAuthType) && !empty($httpAuthUser));
		}

		switch ($this->_loadMethod)
		{
			case 'FILEGETCONTENTS':
				$content = @file_get_contents($file);
				break;

			case 'FSOCKOPEN':
				$errno = 0;
				$errstr = '';

				$uri = parse_url($file);
				$fileHost = @$uri['host'];
				$filePath = @$uri['path'];

				$fp = @fsockopen($fileHost, 80, $errno, $errstr, $timeout);

				if ($fp && $fileHost && $filePath)
				{
					@fputs($fp, "GET /" . $filePath . " HTTP/1.1\r\n");
					@fputs($fp, "HOST: " . $fileHost . "\r\n");
					if ($isHttpAuth)
					{
						@fputs($fp, "Authorization: " . trim($httpAuth) . "\r\n");
					}
					@fputs($fp, "User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1) Gecko/20061010 Firefox/2.0\r\n");
					@fputs($fp, "Connection: close\r\n\r\n");
					@stream_set_timeout($fp, $timeout);
					@stream_set_blocking($fp, 1);

					$response = '';
					while (!@feof($fp))
					{
						$response .= @fgets($fp);
					}
					fclose($fp);

					if ($response)
					{
						// Split headers from content
						$response = explode("\r\n\r\n", $response);

						// Remove headers from response
						$headers = array_shift($response);

						// Get contents only as string
						$content = trim(implode("\r\n\r\n", $response));
					}
				}
				else
				{
					$this->addError('fsockopen - Error on load file - ' . $file);
				}
				break;

			case 'CURL':
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
					$http_code = @$info['http_code'];

					if ($http_code == '200')
					{
						$content = $data;
					}
					else
					{
						$this->addError('cURL - Error load file (http-code: ' . $http_code . ') - ' . $file);
					}

					if (curl_errno($ch))
					{
						$this->addError('cURL - Error: ' . curl_error($ch) . ' - ' . $file);
					}

					curl_close($ch);
				}
				break;
		}

		$content = trim($content);

		$this->_loadMethod = $origLoadMethod;

		if (empty($content))
		{
			$this->addLog('Empty content: ' . $file);
		}
		else
		{
			$this->addLog('File contents loaded: ' . $file);
		}

		return $content;
	}

	/**
	 * Wrapper for curl_exec when CURLOPT_FOLLOWLOCATION is not possible
	 * {@link http://www.php.net/manual/de/function.curl-setopt.php#102121}
	 *
	 * @param   ressource  $ch            Curl Ressource
	 * @param   integer    &$maxredirect  Maximum amount of redirects (defaults 5 or libcurl limit)
	 *
	 * @access protected
	 * @return Contents of curl_exec
	 */
	protected function curlExecFollow($ch, &$maxredirect=null)
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
			}
			while ($code && --$mr);

			curl_close($rch);
			if (!$mr)
			{
				if ($maxredirect === null)
				{
					trigger_error('Too many redirects. When following redirects, libcurl hit the maximum amount.', E_USER_WARNING);
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
	 * Method to extract key/value pairs with xml style attributes
	 *
	 * @param   string  $str  String with the xml style attributes
	 *
	 * @access private
	 * @return array Array of extracted Key/Value pairs
	 */
	private function parseAttributes($str)
	{
		$arr = array();

		if (preg_match_all('/([\w:-]+)[\s]?=[\s]?"([^"]*)"/i', $str, $matches))
		{
			$count = count($matches[1]);

			for ($i = 0; $i < $count; $i++)
			{
				$arr[$matches[1][$i]] = $matches[2][$i];
			}
		}

		return $arr;
	}

	/**
	 * Log File sizes, if enabled
	 *
	 * @param   string  $str       Content to determine the size
	 * @param   string  $type      Determine the Type of the Content (grouping like js or css)
	 * @param   string  $timeline  Determine an upper group (like before or after)
	 *
	 * @access protected
	 * @return mixed Size of Chunked contents (multibyte if available or strlen)
	 */
	protected function logFileSize($str, $type, $timeline)
	{
		if (!$this->getOption('logFilesize', false))
		{
			return;
		}

		if ($this->sizeLog == false)
		{
			$this->sizeLog = array(
				'before' => array('stylesheet' => 0, 'javascript' => 0),
				'after' => array('stylesheet' => 0, 'javascript' => 0)
			);
		}

		if (function_exists('mb_strlen'))
		{
			// Multibyte, if possible
			$_multibyte = true;
			$_size = mb_strlen($str);
		}
		else
		{
			$_multibyte = false;
			$_size = strlen($str);
		}

		if ($_size)
		{
			if (!isset($this->sizeLog[$timeline]))
			{
				$this->sizeLog[$timeline] = array();
			}
			if (!isset($this->sizeLog[$timeline][$type]))
			{
				$this->sizeLog[$timeline][$type] = 0;
			}

			$this->sizeLog[$timeline][$type] += $_size;
		}

		$this->addLog('Log Filesize - (' . ($_multibyte ? 'multibyte' : 'strlen') . '): ' . $_size);

		return $_size;
	}

	/**
	 * Add an log message
	 *
	 * @param   string  $msg  Message
	 *
	 * @access public
	 * @return self
	 */
	public function addLog($msg)
	{
		array_push($this->_log, htmlspecialchars($msg));
		return $this;
	}

	/**
	 * Get log messages
	 *
	 * @param   boolean  $mostRecent  Most recent or all messages
	 *
	 * @access public
	 * @return array or string of Log Entrie(s)
	 */
	public function getLogs($mostRecent=true)
	{
		$log = false;

		if ($mostRecent)
		{
			$log = end($this->_log);
		}
		else
		{
			$log = $this->_log;
		}

		return $log;
	}

	/**
	 * Add an error message
	 *
	 * @param   string  $msg  Message
	 *
	 * @access public
	 * @return self
	 */
	public function addError($msg)
	{
		array_push($this->_error, htmlspecialchars($msg));
		return $this;
	}

	/**
	 * Get error messages
	 *
	 * @param   boolean  $mostRecent  Most recent or all messages
	 *
	 * @access public
	 * @return array or string of Error(s)
	 */
	public function getErrors($mostRecent=true)
	{
		$error = false;

		if ($mostRecent)
		{
			$error = end($this->_error);
		}
		else
		{
			$error = $this->_error;
		}

		return $error;
	}

	/**
	 * Get stylesheet file tree (recursiv)
	 *
	 * @access public
	 * @return Array of Files
	 */
	public function getStylesheetFileTree()
	{
		return $this->_stylesheetFileTree;
	}

	/**
	 * Get javascript file tree (recursiv)
	 *
	 * @access public
	 * @return Array of Files
	 */
	public function getJavascriptFileTree()
	{
		return $this->_javascriptFileTree;
	}

	/**
	 * Get Object properties (e.g. to store in database or something else)
	 *
	 * @access public
	 * @return get_object_vars()
	 */
	public function getProperties()
	{
		return get_object_vars($this);
	}

	/*
	public function __destruct() {
		unset($this);
	}
	*/
}
