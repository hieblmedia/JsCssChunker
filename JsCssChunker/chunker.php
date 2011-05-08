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
 * @version       $Id$
 *
 * @author        Reinhard Hiebl
 * @copyright     Copyright (C) 2011, Reinhard Hiebl, HieblMedia
 * @license       GNU General Public License, version 3.0 (GPLv3),
 *                http://www.opensource.org/licenses/gpl-3.0.html
 * @link          http://chunker.hieblmedia.net/
 * @package       JsCssChunker
 *
 */

define('JSCSSCHUNKER_COMPRESSOR_DIR', dirname(__FILE__).DIRECTORY_SEPARATOR.'compressor');

/*
 * Class to minify, merge and compress stylesheet and javascript files
 *
 * @link http://chunker.hieblmedia.com
 * @package JsCssChunker
 */
class JsCssChunker
{
  protected $baseUrl = '';
  protected $autoTarget = false;

  protected $options = array(
    'timeout' => 5,
    'targetUrl' => '',
    'removeEmptyLines' => true,
    'stylesheetRecursiv' => true,
    'stylesheetCharset' => 'UTF-8',
    'stylesheetCompress' => true,
    'javascriptCompress' => false,
    'javascriptCompressorClass' => 'JSMinPlus',
    'logFilesize' => false,
    'httpAuth' => false
  );

  protected $loadMethod = '';

  protected $_stylesheetFiles = array();
  protected $_javascriptFiles = array();

  protected $_log   = array();
  protected $_error = array();

  protected $_stylesheetBuffer = '';
  protected $_javascriptBuffer = '';

  protected $_phpSafeMode = false;
  protected $_phpOpenBasedir = false;

  protected $stylesheetFileTree = array();
  protected $javascriptFileTree = array();

  public $sizeLog = false;

  protected $_html = '';

  /**
   * Contructor Function for init class and set options
   *
   * @access public
   * @param $baseUrl The parent url of files from relative,
   *                 Make sure to use only directory URLs
   *                 (absolute REQUEST_URI without Script filename)
   * @param $options Options {@link self->options}
   * @return mixed The option value
   */
  public function __construct($baseUrl, $options=array())
  {
    $this->baseUrl = $baseUrl;

    if(is_array($options) && !empty($options))
    {
      foreach($options as $k=>$v) {
        $this->options[$k] = $v;
      }
    }

    $state = self::check();

    if($state==false) {
      throw new Exception('JsCssChunker - Check fail: CURL or file_get_contents with allow_url_fopen or fsockopen is needed');
    }

    // check PHP settings
    $safeMode = strtolower(ini_get('safe_mode'));
    $this->_phpSafeMode = (($safeMode=='0' || $safeMode=='off') ? false : true);
    $this->_phpOpenBasedir = (ini_get('open_basedir') == '' ? false : true);

    $this->validateOptions();
  }

  /**
   * Method to parse the <head /> from the HTML document
   *
   * @access public
   * @static
   * @param $html HTML Content
   * @param $parseMode Determine where parse the files (head, body, all, defaults head)
   * @param $autoApply Automaticly add all founded js and css files to the queue
   * @return array CSS and JS file list
   */
  public function parseRawHeader($html='', $parseMode='head', $autoApply=false)
  {
    $html = $html ? $html : $this->_html;

    $filelist = array(
      'css' => array(),
      'js' => array()
    );

    $_contents = '';

    if(empty($parseMode)) {
      $parseMode = 'head';
    }
    $parseMode = strtolower($parseMode);

    switch($parseMode)
    {
      case 'all':
        $_contents = $html;
      break;

      default:
      case 'head':
      case 'body':
        $headData = '';
        $matches = array();

        preg_match('#<'.$parseMode.'.*>(.*)</'.$parseMode.'>#Uis', $html, $matches);
        if(empty($matches[0])) {
          return $filelist;
        }

        $_contents = $matches[0];
        break;
    }


    // get css files
    $matches = array();
    preg_match_all('#<link(.*)>#Uis', $_contents, $matches);

    if($matches && !empty($matches[0]))
    {
      foreach($matches[0] as $i=>$entry)
      {
        $attributes = trim($matches[1][$i]);

        if(substr($attributes, -1, 1) == '/')
        {
          $attributes = substr($attributes, 0, strlen($attributes)-1);
          $attributes = trim($attributes);
        }

        $_attribs = $this->parseAttributes($attributes);
        $_attribs['href'] = (isset($_attribs['href']) ? $_attribs['href'] : '');
        $_attribs['rel'] = (isset($_attribs['rel']) ? strtolower($_attribs['rel']) : '');
        $_attribs['type'] = (isset($_attribs['type']) ? strtolower($_attribs['type']) : '');
        $_attribs['media'] = (isset($_attribs['media']) ? strtolower($_attribs['media']) : '');

        if($_attribs['rel']=='stylesheet' && $_attribs['href'] != '')
        {
          $filelist['css'][] = $_attribs;

          if($autoApply) {
            $this->addStylesheet($_attribs['href'], $_attribs['type'], $_attribs['media']);
          }
        }
      }
    }

    // get js files
    $matches = array();
    preg_match_all('#<script(.*)>.*</script>#Uis', $_contents, $matches);

    if($matches && !empty($matches[0]))
    {
      foreach($matches[0] as $i=>$entry)
      {
        $attributes = trim($matches[1][$i]);

        if(substr($attributes, -1, 1) == '/')
        {
          $attributes = substr($attributes, 0, strlen($attributes)-1);
          $attributes = trim($attributes);
        }

        $_attribs = $this->parseAttributes($attributes);
        $_attribs['src'] = (isset($_attribs['src']) ? $_attribs['src'] : '');
        $_attribs['type'] = (isset($_attribs['type']) ? strtolower($_attribs['type']) : '');

        if($_attribs['src'] != '')
        {
          $filelist['js'][] = $_attribs;

          if($autoApply) {
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
   * @access public
   * @param $key Option name
   * @param $def Default value if $key not set
   * @return mixed The option value
   */
  public function getOption($key, $def=null) {
    return (isset($this->options[$key]) ? $this->options[$key] : $def);
  }

  /**
   * Set a specific option in class
   *
   * @access public
   * @param $key Option name
   * @param $value Value to set for option $key
   * @return void
   */
  public function setOption($key, $value)
  {
    $this->options[$key] = $value;
    $this->validateOptions();
  }

  /**
   * Method to validate options
   *
   * @access protected
   * @return void
   */
  protected function validateOptions()
  {
    if($this->options['targetUrl'] == '') {
      $this->options['targetUrl'] = $this->baseUrl;
      $this->autoTarget = true;
    } else {
      $this->autoTarget = false;
    }

    if(substr($this->options['targetUrl'], -1, 1) != '/') {
      $this->options['targetUrl'] .= '/';
    }
  }

  /**
   * Method to add a Stylesheet file to parse
   *
   * @access public
   * @param $file Filename (relative or absolute)
   * @param $type Type (defaults: text/css)
   * @param $media Media (defaults: all)
   * @return void
   */
  public function addStylesheet($file, $type='text/css', $media='all')
  {
    // remove url params
    $tmp = explode('?', $file);
    $file = $tmp[0];

    if(!isset($this->_stylesheetFiles[$file])) {
      $this->_stylesheetFiles[$file] = array(
        'media'=>$media,
        'type'=>$type
      );

      $this->addLog('Stylesheet - Added: '.$file);
    }
  }

  /**
   * Method to add a Javascript file to parse
   *
   * @access public
   * @param $file Filename (relative or absolute)
   * @param $type Type (defaults: text/javascript)
   * @return void
   */
  public function addJavascript($file, $type='text/javascript')
  {
    // remove url params
    $tmp = explode('?', $file);
    $file = $tmp[0];

    if(!isset($this->_javascriptFiles[$file])) {
      $this->_javascriptFiles[$file] = array(
        'type'=>$type
      );

      $this->addLog('Script - Added: '.$file);
    }
  }

  /**
   * Method to get a Hash value of processing stylesheets (e.g. for caching)
   *
   * @access public
   * @param $useBase Set this like a host name, if your site has multiple domains (more effective caching)
   *                 (defults: baseUrl)
   * @return Hash value
   */
  public function getStylesheetHash($useBase='')
  {
    if(!empty($this->_stylesheetFiles))
    {
      $prefixArr = array(
        '_type' => 'stylesheet',
        'baseUrl' => $useBase ? $useBase : $this->baseUrl,
        'options' => array(
          $this->getOption('targetUrl'),
          $this->getOption('removeEmptyLines'),
          $this->getOption('stylesheetRecursiv'),
          $this->getOption('stylesheetCharset'),
          $this->getOption('stylesheetCompress')
        )
      );
      $prefix = serialize($prefixArr);

      $hash   = serialize($this->_stylesheetFiles);
      $hash   = md5($prefix).'_'.md5($hash);

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
   * @access public
   * @param $useBase Set this like a host name, if your site has multiple domains (more effective caching)
   *                 (defults: baseUrl)
   * @return Hash value
   */
  public function getJavascriptHash($useBase='')
  {
    if(!empty($this->_javascriptFiles))
    {
      $prefixArr = array(
        '_type' => 'javascript',
        'baseUrl' => $useBase ? $useBase : $this->baseUrl,
        'options' => array(
          $this->getOption('targetUrl'),
          $this->getOption('removeEmptyLines'),
          $this->getOption('javascriptCompress'),
          $this->getOption('javascriptCompressorClass')
        )
      );
      $prefix = serialize($prefixArr);

      $hash = serialize($this->_javascriptFiles);
      $hash   = md5($prefix).'_'.md5($hash);

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
    if($savePath) {
      $this->addLog(
        "\n".
        '!! Important !!: The Stylesheet must be callable from this path:'.
        "\n\t\t\t -- Folder:      ".
        $savePath.
        "\n\t\t\t -- File (e.g.): ".$savePath.$this->getStylesheetHash().'.css'.
        "\n"
      );

    }

    return $this->_stylesheetBuffer;
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

    return $this->_javascriptBuffer;
  }

  /**
   * Merge/Minify/Compress added Javascript files
   *
   * @access private
   * @return string $content return merged content of files
   */
  private function mergeJavascripts()
  {
    if(empty($this->_javascriptFiles)) { return ''; }

    $contents = array();

    foreach($this->_javascriptFiles as $file=>$attribs)
    {
      $filename = $this->getFullFilePath($file);
      $filename = $this->getRealPath($filename);
      $this->javascriptFileTree[$filename] = array();

      $content  = trim($this->getFileContents($filename));
      $this->logFileSize($content, 'javascript', 'before');

      if ($content != "") {
        if($this->getOption('javascriptCompress'))
        {
          $content = $this->compressJavascript($content);

          if($_error = $this->getErrors()) {
            $this->addLog('ERROR - '.$_error);
          } else {
            $this->addLog('Javascript - Compressed content');

            $content = $content.';'; // safe merge without compressor ??
          }
        }
        else
        {
          $content = $content.';'; // safe merge without compressor ??
        }

        $contents[$file] = $content;
      }
    }

    if(!empty($contents))
    {
      $content = implode("\n\n", $contents);
      $content = trim($content);
    }

    if(!empty($content) && $this->getOption('removeEmptyLines'))
    {
      $content = $this->removeEmptyLines($content);
      $this->addLog('Javascript - Removed empty lines');
    }

    $this->_javascriptBuffer = $content;
    $this->logFileSize($content, 'javascript', 'after');

    $this->addLog('Javascript - Merge complete');

    return $content;
  }

  /**
   * Merge/Minify/Compress added Stylesheet files recursivly with reading @import rules
   * @TODO: add @import tree for logging
   *
   * @access private
   * @return string $content return merged content of files
   */
  private function mergeStylesheets()
  {
    if(empty($this->_stylesheetFiles)) { return ''; }

    $contents = array();

    $this->stylesheetFileTree = array();

    foreach($this->_stylesheetFiles as $file=>$attribs)
    {
      $media = $attribs['media'];

      $cont = $this->_loadStylesheets($file, $this->stylesheetFileTree);
      $cont = $this->_checkCssMedia($cont, $media);

      $contents[$file] = $cont;
    }

    $content = implode("\n\n", $contents);

    // remove all charset definitions (important!)
    $content = preg_replace('/@charset\s+[\'"](\S*)\b[\'"];/i', '', $content);
    $this->addLog('Stylesheet - remove all @charset rules for browser compatibility');

    if($charset = $this->getOption('stylesheetCharset'))
    {
      // add @charset to stylesheet in FIRST LINE only (important with linebreak for safari)
      $content = "@charset \"".$charset."\";\n".$content;
      $this->addLog('Stylesheet - Set @charset ('.$charset.') in first line');
    }

    if($this->getOption('stylesheetCompress'))
    {
      $content = $this->compressStylesheet($content);
      $this->addLog('Stylesheet - Compressed content');
    }

    if($this->getOption('removeEmptyLines'))
    {
      $content = $this->removeEmptyLines($content);
      $this->addLog('Stylesheet - Removed empty lines');
    }

    $this->_stylesheetBuffer = $content;
    $this->logFileSize($content, 'stylesheet', 'after');

    $this->addLog('Stylesheet - Merge complete');
  }

  /**
   * Compress Stylesheet contents
   *
   * @access private
   * @param string $content Stylesheet content
   * @return string compressed Stylesheet content
   */
  private function compressStylesheet($content)
  {
    $content = $this->stripStylesheetComments($content);

    $replace = array(
      "#\s\s+#"      => " "   // Strip double-whitespaces,linebreaks,tabs
    );
    $search = array_keys($replace);

    $search = array_keys($replace);
    $content = preg_replace($search, $replace, $content);
    $content = str_replace(array("\n", "\r"), " ", $content);

    $replace = array(
      ": " => ":", // Srip spaces
      " :" => ":", // Srip spaces
      "; " => ";", // Srip spaces
      " ;" => ";", // Srip spaces
      "{ " => "{", // Srip spaces
      " {" => "{", // Srip spaces
      "} " => "}", // Srip spaces
      " }" => "}", // Srip spaces
      ", " => ",", // Srip spaces
      " ," => ",", // Srip spaces
      ";}" => "}", // Strip last semicolons.
      "@"  => "\n@", // @ Rules on each line for a small overview of media types.
      "}"  => "} " // One withespace after closing
    );

    $search = array_keys($replace);
    $content = str_replace($search, $replace, $content);

    return trim($content);
  }

  /**
   * Strip Stylesheet Comments (consider css hacks)
   *
   * @access protected
   * @param string $content Contents of Stylesheet/CSS
   * @return String with striped CSS comments
   */
  protected function stripStylesheetComments($content='')
  {
    // handle hacks before
    $content = str_replace('/**/', '[[HACK__IE_EMPTY_COMMENT]]', $content);

    // strip comments
    $content = preg_replace("#/\*.+\*/#sU", "", $content);

    // handle hacks after
    $content = str_replace('[[HACK__IE_EMPTY_COMMENT]]', '/**/', $content);

    return $content;
  }

  /**
   * Compress javascript with an compressor class
   *
   * @access private
   * @param string $content Contents of the Javascript
   * @return Compressed Javascript content
   */
  private function compressJavascript($content)
  {
    if(!empty($content))
    {
      try
      {
        $compressorClass = $this->getOption('javascriptCompressorClass');

        if(!class_exists($compressorClass))
        {
          $compressorFile = JSCSSCHUNKER_COMPRESSOR_DIR.DIRECTORY_SEPARATOR.$compressorClass.'.php';

          if(file_exists($compressorFile)) {
            require_once($compressorFile);
          } else {
            $this->addError('Javascript compressor file not found: '.$compressorFile);
          }
        }

        if(class_exists($compressorClass))
        {
          ob_start();
          ob_implicit_flush(false);

          switch($compressorClass)
          {
            case 'JSMin':
              $content = JSMin::minify($content);
              break;
            case 'JSMinPlus':
              $content = JSMinPlus::minify($content);
              break;

            case 'JavaScriptPacker':
              $packer = new JavaScriptPacker($content);
              $content = $packer->pack();
              break;

            default:
              $this->addError('Compressor not implemented: '.$compressorClass);
              $content = '';
              break;
          }

          $errors = trim(ob_get_contents());
          ob_end_clean();

          if($errors) {
            $this->addError('Javascript Compressor Error: '.$errors);
          }
        }
        else
        {
          $this->addError('Compressor Class not found or not callable: '.$compressorClass);
        }
      }
      catch (Exception $e)
      {
        $msg = "/* \n * --- ERROR (Code not minified) --- \n * Message: ". $e->getMessage()."\n */ \n\n";
        $content = $msg.$content;
      }
    }

    return trim($content);
  }

  /**
   * Determine the fullpath of a file
   *
   * @access public
   * @param string $file Path to file to load
   * @param bool Set use target option, default false
   * @return Replaced file path
   */
  public function getFullFilePath($file, $useTarget=false)
  {
    if(substr($file, 0, 4) != 'http')
    {
      $baseUrl = ($useTarget ? $this->getOption('targetUrl') : $this->baseUrl);
      $basePath = parse_url($this->baseUrl, PHP_URL_PATH);

      if(substr($file, 0, strlen($basePath)) == $basePath) {
        $file = substr($file, strlen($basePath));
      }

      $file = $baseUrl.$file;
    }

    return $file;
  }

  /**
   * Remove empty lines in a string
   *
   * @access public
   * @param string $string The string of content
   * @return string without empty lines
   */
  public function removeEmptyLines($string){
    return preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $string);
  }

  /**
   * Method to load Stylesheets recursivly with @import rule
   * and replacemnt for included path
   *
   * @access private
   * @param string $file Path to file to load
   * @return Merged content
   */
  private function _loadStylesheets($file, &$fileTree=array())
  {
    $filename = $this->getFullFilePath($file);
    $filename = $this->getRealpath($filename);
    $fileTree[$filename] = array();

    $content = $this->getFileContents($filename);
    $this->logFileSize($content, 'stylesheet', 'before');
    $content = trim($content);

    if (empty($content)) { return ''; }

    // Is important to remove comments before search @import rules
    $content = $this->stripStylesheetComments($content);

    $base = dirname($filename);

    preg_match_all('/@import\s*(?:url\()?[\'"]?(?![a-z]+:)([^\'"\()]+)[\'"]?\)?(.*);/iS', $content, $matches);
    $relpaths = @$matches[1];
    $relpathsMedia = @$matches[2];

    $content = $this->_replaceCSSPaths($content, $base);

    if (!empty($relpaths) && $this->getOption('stylesheetRecursiv'))
    {
      foreach($relpaths as $key=>$relfile)
      {
        $importPath = $base.'/'.$relfile;
        $importPath = $this->getRealpath($importPath);
        //$fileTree[$importPath] = array();

        $relpath = dirname($relfile);
        $icont = $this->_loadStylesheets($importPath, $fileTree[$filename]);
        $icont = trim($icont);

        // remove all charset definitions
        $icont = preg_replace('/@charset\s+[\'"](\S*)\b[\'"];/i', '', $icont);

        if(!empty($icont))
        {
          /**
           * If the imported file has defined media
           * and within contents of file not defined
           * then do include it.
           */

          $importMedia = trim($relpathsMedia[$key]);

          // add media all no media set
          if(strpos($icont, '@media')===false)
          {
            // add media query from @import if available or media all as fallback
            if($importMedia) {
              $icont = '@media '.$importMedia.' { '.$icont.' }';
            } else {
              $icont = '@media all { '.$icont.' }';
            }
          }
          elseif($importMedia)
          {
             // add media query from @import additional if available
             $icont = str_replace('@media ', '@media '.$importMedia.', ', $icont);
          }
        }

        // replace @import with the loaded contents
        $content = str_replace($matches[0][$key], $icont, $content);
      }
    }

    return $content;
  }

  /**
   * Check @media type is definied in CSS file and add it if its not found
   *
   * @access private
   * @param string $content CSS content
   * @param string $media mediatype for css rules, default all
   * @return string $content return content of file with @media rule
   */
  private function _checkCssMedia($content, $media='all')
  {
    if ($content && strpos($content, '@media')===false) {
      $content = '@media '.$media.' {'.$content.'}';
    }
    return $content;
  }

  /**
   * Method to replace url paths in css rules in merged content
   *
   * @access public
   * @param string $content CSS content
   * @param string $path of file to replace
   * @return string $content return content with replaced url([new_path])
   */
  public function _replaceCSSPaths($content, $path)
  {
    $path = $this->cleanPath($path);

    if(substr($path, -1, 1) != '/') {
      $path .= '/';
    }

    /**
     **Search for "/(:.*)url\(([\'"]?)(?![a-z]+:)([^\'")]+)[\'"]?\)?/i"
     * The : at first as shorthand to exclude the @import rule in stylesheets
     * Does IGNORE extenal files like http://, ftp://... Only relative urls would by replaced
     */
    // set pathscrope for callback method to current stylesheet path
    $this->pathscope = $path;

    // replace and shortend urls with pathscope
    $regex = '/(:.*)url\(([\'"]?)(?![a-z]+:)([^\'")]+)[\'"]?\)/iU'; // only relative urls

    $content = preg_replace_callback($regex, array( &$this, '_replaceCSSPaths_Callback'), $content);
    $content = str_replace('[[CALLBACK_URLREPLACED]]', 'url', $content);

    // reset pathscope
    $this->pathscope = '';

    return $content;
  }

  /**
   * Callback Method for preg_replace_callback in _replaceCSSPaths
   *
   * @access private
   * @param array $matches from preg_replace_callback
   * @return replaced path prepend with $this->pathscope
   */
  private function _replaceCSSPaths_Callback($matches)
  {
    $url = $this->pathscope.$matches[3];
    $targetUrl = $this->getOption('targetUrl');

    $baseUrlCompare = $this->baseUrl;
    if(substr($baseUrlCompare, -1, 1) != '/') {
      $baseUrlCompare .= '/';
    }

    $holdAbsoluteUrl = false;
    if(preg_match('#^(http|https)://#Uis', $url))
    {
      $u1 = parse_url($this->baseUrl);
      $u2 = parse_url($url);

      if(!empty($u1['host']) && !empty($u2['host']) && $u1['host'] != $u2['host']) {
        $holdAbsoluteUrl = true;
      }
    }

    if(!$holdAbsoluteUrl)
    {
      if($baseUrlCompare == $targetUrl || $this->autoTarget)
      {
        $url = preg_replace('#^'.$targetUrl.'#', '/', $url);
      }
      else
      {
        $targetTmp = parse_url($targetUrl, PHP_URL_PATH);
        $targetTmp = explode('/', preg_replace('#/$#', '', $targetTmp));

        $scheme = parse_url($this->pathscope, PHP_URL_SCHEME);
        $host = parse_url($this->pathscope, PHP_URL_HOST);
        $scopeTmp = parse_url($this->pathscope, PHP_URL_PATH);
        $scopeTmp = explode('/', preg_replace('#/$#', '', $scopeTmp));

        $baseTmp = parse_url($this->baseUrl, PHP_URL_PATH);
        $baseTmp = explode('/', preg_replace('#/$#', '', $baseTmp));

        $lastIndex = 0;
        foreach($targetTmp as $k=>$v)
        {
          if(isset($baseTmp[$k]) && $v==$baseTmp[$k]) {
            $lastIndex = $k;
          }
        }
        $parentCount = (count($targetTmp)-1) - $lastIndex;

        // base to target convert with additional relative placeholders (if internal)
        if($parentCount > 0 && $lastIndex > 0 && $lastIndex >= (count($baseTmp)-1))
        {
          // scope is within base directory
          //$url = $this->pathscope.str_repeat('../', $parentCount).$matches[3];
          $url = $this->pathscope.$matches[3];
          $url = preg_replace('#^'.$this->baseUrl.'#', $targetUrl.str_repeat('../', $parentCount), $url);
        }
        else
        {
          // scope is outside base directory
          $diff = array_slice($targetTmp, $lastIndex+1);

          if(count($diff))
          {
            $a = array_slice($scopeTmp, 0, $lastIndex+1);
            $b = array_slice($scopeTmp, $lastIndex+1);

            if($lastIndex > 0) {
              $diffPath = '/'.implode('/', $diff).str_repeat('/..', count($diff));
            } else {
              $diffPath = str_repeat('/..', count($diff));
            }

            $url = $scheme.'://'.$host.implode('/', $a).$diffPath.($b ? '/'.implode('/', $b) : '').'/'.$matches[3];

            if($pos = strpos($url, $targetUrl)) {
              // in most cases relative target
              $url = substr($url, $pos);
            } elseif($lastIndex > 0) {
              // in most cases an absolute target (CDN Host)
              $diffPath = $targetUrl.str_repeat('../', count($diff));
              $url = $diffPath.($b ? implode('/', $b) : '').'/'.$matches[3];
            }
          }
        }

        if(!parse_url($targetUrl, PHP_URL_HOST)) {
          $url = preg_replace('#^'.$this->baseUrl.'#', '', $url);
        } else {
          $url = preg_replace('#^'.$this->baseUrl.'#', $targetUrl, $url);
        }
      }
    }

    // shrink URL
    $url = $this->cleanPath($url);
    // $url = $this->getRealpath($url);

    // Full SSL (https) support, if URL absolute
    // Note: If the target file external the host needs an valid SSL certificate
    if(preg_match('#^https://#Uis', $this->baseUrl)) {
      $url = preg_replace('#http://#Uis', 'https://', $url);
    }

    return $matches[1].'[[CALLBACK_URLREPLACED]]('.$this->cleanPath($url).')';
  }

  /**
   * Strip and replace additional / or \ in a path
   *
   * @access public
   * @param string $ds Directory seperator
   * @return The clean path
   */
  public function cleanPath($path, $ds='/')
  {
    $path = trim($path);

    if (!empty($path))
    {
      // handle absolute urls
      $scheme = '';
      $tmp = explode('://', $path);
      if(count($tmp)==2) {
        $scheme = $tmp[0];
        $path = $tmp[1];
      }

      // Remove double slashes and backslahses and convert all slashes and backslashes to DS
      $path = preg_replace('#[/\\\\]+#', $ds, $path);
      $path = $this->getRealPath($path);

      if($scheme) {
        $path = $scheme.'://'.$path;
      }
    }

    return $path;
  }

  /**
   * Like Relpath function but without check filesystem and does not create an absolute path is relative
   *
   * @access private
   * @param string $path relative path like "path1/./path2/../../file.png" or external like "http://domain.tld/path1/../path2/file.png"
   * @return string $path shortend path
   */
  private function getRealpath($path='')
  {
    if(!$path) { return; }

    $origPath = $path;

    $scheme = '';
    $tmp = explode('://', $path);
    if(count($tmp)==2) {
      $scheme = $tmp[0];
      $path = $tmp[1];
    }

    $tmp=array();
    $parts = explode('/', $path);

    foreach($parts as $i=>$dir)
    {
      if ($dir=='' || $dir=='.') { continue; }

      //if ($dir=='..' && $i>0 && end($tmp)!='..') {
      if ($dir=='..' && $i>0 && end($tmp)!='..' && !empty($tmp)) {
        array_pop($tmp);
      } else {
        $tmp[]= $dir;
      }
    }

    $path = ($path{0}=='/' ? '/' : '').implode('/', $tmp);

    if($scheme) {
      $path = $scheme.'://'.$path;
    }

    return $path;
  }

  /**
   * Get the absolute URL Path where the stylesheet must be callable
   *
   * @access public
   * @return string Absolute URL save path
   */
  public function getTargetUrlSavePath()
  {
    $path = '';

    $baseUrl = $this->baseUrl;
    $targetUrl = $this->getOption('targetUrl');

    if($this->autoTarget)
    {
      $path = $baseUrl;
    } else {
      if(preg_match('#^(http|https)://#Uis', $targetUrl))
      {
        $path = $targetUrl;
      }
      else
      {
        $basePath = parse_url($baseUrl, PHP_URL_PATH);
        $targetUrl = str_replace($basePath, '', $targetUrl);
        $path = $baseUrl.'/'.$targetUrl;
      }
    }

    $path = $this->getRealpath($path);

    if(substr($path, -1, 1) != '/') {
      $path .= '/';
    }

    return $path;
  }

  /**
   * Check can load files
   *
   * @access public
   * @return bool Can chunk
   */
  public function check()
  {
    static $state;

    if($state==null || empty($this->loadMethod))
    {
      $state = false;

      @ini_set('allow_url_fopen', '1');
      $allow_url_fopen = ini_get('allow_url_fopen');

      if (function_exists('curl_init') && function_exists('curl_exec') && empty($this->loadMethod)) {
        $this->loadMethod = 'CURL';
      }

      if (function_exists('file_get_contents') && $allow_url_fopen && empty($this->loadMethod)) {
        $this->loadMethod = 'FILEGETCONTENTS';
      }

      if (function_exists('fsockopen') && empty($this->loadMethod))
      {
        $connnection = @fsockopen($this->baseUrl, 80, $errno, $error, 4);
        if ($connnection && @is_resource($connnection)) {
          $this->loadMethod = 'FSOCKOPEN';
        }
      }

      if($this->loadMethod) {
        $state = true;
      }
    }

    return $state;
  }

  /**
   * Check directores based on a compared file of modifications
   *
   * @access public
   * @param array $dirs Absolute directory paths on local filesystem
   * @param string $comparefile Filename to compare with $dirs
   * @param string preg_match filter for files (defaults [.css|.js]$)
   * @return mixed (timestamp)Filetime or (bool)false on fail
   */
  public function hasFoldersModifications($dirs, $compareFile, $filter='[.css|.js]$')
  {
    if(!is_array($dirs)) {
      $dirs = array($dirs);
    }

    $lastModified = 0;

    foreach($dirs as $_dir)
    {
      $lm = $this->getLastModifiedFileByFolder($_dir, $filter);

      if($lm>$lastModified) {
        $lastModified = $lm;
      }
    }

    if($lastModified)
    {
      $compareFile = $this->cleanPath($compareFile);
      $filetime = @filemtime($compareFile);

      if($filetime && $lastModified > $filetime) {
        return true;
      }
    }

    return false;
  }

  /**
   * Get LastModified File by local path(dir)
   *
   * @access public
   * @param string $path Absolute directory path on local filesystem
   * @param string preg_match filter for files (defaults [.css|.js]$)
   * @return mixed (timestamp)Filetime or (bool)false on fail
   */
  public function getLastModifiedFileByFolder($path, $filter='[.css|.js]$')
  {
    // workaround to fix the path (double-slash, dot-notation, etc.)
    $path = dirname($this->cleanPath($path).'/.');

    // check dir exists on local filesystem
    if(!is_dir($path)) {
      return false;
    }

    $files = self::_filesRecursiv($path, $filter);

    array_multisort(
        array_map( 'filemtime', $files ),
        SORT_NUMERIC,
        SORT_DESC, // newest first, or `SORT_ASC` for oldest first
        $files
    );

    $file     = array_shift($files);
    $filetime = @filemtime($file);

    return $filetime ? $filetime : false;
  }

  /**
   * Helper function to search files recursiv by path with an specific filter
   *
   * @access private
   * @param string $path Absolute directory path on local filesystem
   * @param string preg_match filter for files (defaults [.css|.js]$)
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
        $fullpath = $path.DIRECTORY_SEPARATOR.$file;

        if (is_dir($fullpath)) {
          $arr = array_merge($arr, self::_filesRecursiv($fullpath, $filter));
        } elseif(preg_match("/$filter/", $file)) {
          $arr[] = $fullpath;
        }
      }
    }
    closedir($handle);

    return $arr;
  }

  /**
   * Get the contents of a given URL or File
   *
   * @access public
   * @param $url URL or File (local or external)
   * @return string Loaded contents
   */
  public function loadHtml($url='')
  {
    $other = $url ? true : false;
    $contents = '';

    if(!$this->_html || $other) {
      $url = trim($url ? $url : $this->baseUrl);

      if($url != '') {
        $contents = $this->getFileContents($url);

        if(!$other) {
          $this->_html = $contents;
        }
      }
    } else {
      $contents = $this->_html;
    }

    return $contents;
  }

  /**
   * Get the contents of specific file/url
   *
   * @access protected
   * @param $file Absolute Path or Url to the file
   * @return string Contents from File
   */
  protected function getFileContents($file)
  {
    $content = '';
    $timeout = $this->getOption('timeout');

    @ini_set('default_socket_timeout', $timeout);

    $origLoadMethod = $this->loadMethod;

    // force file_get_contents if file exists on local filesystem
    if(!preg_match('#^(http|https)://#Uis', $file) && file_exists($file) && is_readable($file)) {
      $this->loadMethod = 'FILEGETCONTENTS';
    }

    $authOptions = $this->getOption('httpAuth');
    if($authOptions)
    {
      $httpAuth = true;
      $httpAuthType = isset($authOptions['type']) ? $authOptions['type'] : 'ANY';
      $httpAuthUser = isset($authOptions['user']) ? $authOptions['user'] : '';
      $httpAuthPass = isset($authOptions['pass']) ? $authOptions['pass'] : '';
      $isHttpAuth = (!empty($httpAuth) && !empty($httpAuthType) && !empty($httpAuthUser));
    }
    else
    {
      // if logged in currently with http auth add options into headers
      $httpAuth = @$_SERVER['HTTP_AUTHORIZATION'];
      $httpAuthType = @$_SERVER['AUTH_TYPE'];
      $httpAuthUser = @$_SERVER['PHP_AUTH_USER'];
      $httpAuthPass = @$_SERVER['PHP_AUTH_PW'];
      $isHttpAuth = (!empty($httpAuth) && !empty($httpAuthType) && !empty($httpAuthUser));
    }

    switch ($this->loadMethod)
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
          @fputs($fp, "GET /".$filePath." HTTP/1.1\r\n");
          @fputs($fp, "HOST: ".$fileHost."\r\n");
          if($isHttpAuth) {
            @fputs($fp, "Authorization: ".trim($httpAuth)."\r\n");
          }
          @fputs($fp, "User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1) Gecko/20061010 Firefox/2.0\r\n");
          @fputs($fp, "Connection: close\r\n\r\n");
          @stream_set_timeout($fp, $timeout);
          @stream_set_blocking($fp, 1);

          $response = '';
          while (!@feof($fp)) {
            $response .= @fgets($fp);
          }
          fclose($fp);

          if($response)
          {
            // split headers from content
            $response = explode("\r\n\r\n", $response);
            // remove headers from response
            $headers = array_shift($response);
            // get contents only as string
            $content = trim(implode("\r\n\r\n", $response));
          }
        } else {
          $this->addError('fsockopen - Error on load file - '.$file);
        }
        break;

      case 'CURL':
        $ch = @curl_init();
        if ($ch) {
          curl_setopt($ch, CURLOPT_HEADER, 0);
          curl_setopt($ch, CURLOPT_FAILONERROR, 1);
          curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
          curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
          curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
          curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
          curl_setopt($ch, CURLOPT_URL, $file);

          // do not verify the SSL certificate
          curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
          curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

          if($isHttpAuth) {
            $_type = strtoupper($httpAuthType);

            switch($_type) {
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

            curl_setopt($ch, CURLOPT_USERPWD, $httpAuthUser.':'.$httpAuthPass);
          }


          if($this->_phpSafeMode || $this->_phpOpenBasedir)
          {
            // follow location/redirect does not work if safe_mode enabled or open_basedir is set
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

          if ($http_code=='200') {
            $content = $data;
          } else {
            $this->addError('cURL - Error load file (http-code: '.$http_code.') - '.$file);
          }

          if(curl_errno($ch)) {
            $this->addError('cURL - Error: '.curl_error($ch));
          }

          curl_close($ch);
        }
        break;
    }

    $content = trim($content);

    $this->loadMethod = $origLoadMethod;

    if(empty($content)) {
      $this->addLog('Empty content: '. $file);
    } else {
      $this->addLog('File contents loaded: '. $file);
    }

    return $content;
  }

  /**
   * Wrapper for curl_exec when CURLOPT_FOLLOWLOCATION is not possible
   * {@link http://www.php.net/manual/de/function.curl-setopt.php#102121}
   *
   * @access protected
   * @param ressource $ch Curl Ressource
   * @param integer $maxredirect Maximum amount of redirects (defaults 5 or libcurl limit)
   * @return Contents of curl_exec
   */
  protected function curlExecFollow($ch, &$maxredirect=null)
  {
    $mr = ($maxredirect === null ? 5 : (int)$maxredirect);

    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    if ($mr > 0)
    {
      $newurl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
      $rch = curl_copy_handle($ch);

      curl_setopt($rch, CURLOPT_HEADER, true);
      curl_setopt($rch, CURLOPT_NOBODY, true);
      curl_setopt($rch, CURLOPT_FORBID_REUSE, false);
      curl_setopt($rch, CURLOPT_RETURNTRANSFER, true);

      do {
        curl_setopt($rch, CURLOPT_URL, $newurl);
        $header = curl_exec($rch);
        if (curl_errno($rch))
        {
          $code = 0;
        }
        else
        {
          $code = curl_getinfo($rch, CURLINFO_HTTP_CODE);
          if ($code == 301 || $code == 302) {
            preg_match('/Location:(.*?)\n/', $header, $matches);
            $newurl = trim(array_pop($matches));
          } else {
            $code = 0;
          }
        }
      } while ($code && --$mr);

      curl_close($rch);
      if (!$mr)
      {
        if ($maxredirect === null) {
          trigger_error('Too many redirects. When following redirects, libcurl hit the maximum amount.', E_USER_WARNING);
        } else {
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
   * @access private
   * @param	string $str String with the xml style attributes
   * @return array Array of extracted Key/Value pairs
   */
  private function parseAttributes($str)
  {
    $arr = array();

    if (preg_match_all('/([\w:-]+)[\s]?=[\s]?"([^"]*)"/i', $str, $matches))
    {
      $count = count($matches[1]);

      for ($i = 0; $i < $count; $i++) {
        $arr[$matches[1][$i]] = $matches[2][$i];
      }
    }

    return $arr;
  }

  /**
   * Log File sizes, if enabled
   *
   * @access protected
   * @param string $str Content to determine the size
   * @param string $type Determine the Type of the Content (grouping like js or css)
   * @param string $timeline Determine an upper group (like before or after)
   * @return mixed Size of Chunked contents (multibyte if available or strlen)
   */
  protected function logFileSize($str, $type, $timeline)
  {
    if(!$this->getOption('logFilesize', false)) {
      return;
    }

    if($this->sizeLog == false) {
      $this->sizeLog = array(
        'before'=> array('stylesheet'=>0, 'javascript'=>0),
        'after'=> array('stylesheet'=>0, 'javascript'=>0)
      );
    }

    if(function_exists('mb_strlen'))
    {
      $_multibyte = true;
      $_size = mb_strlen($str); // multibyte, if possible
    }
    else
    {
      $_multibyte = false;
      $_size = strlen($str);
    }

    if($_size)
    {
      if(!isset($this->sizeLog[$timeline])) {
        $this->sizeLog[$timeline] = array();
      }
      if(!isset($this->sizeLog[$timeline][$type])) {
        $this->sizeLog[$timeline][$type] = 0;
      }

      $this->sizeLog[$timeline][$type] += $_size;
    }

    $this->addLog('Log Filesize - ('.($_multibyte ? 'multibyte' : 'strlen').'): '.$_size);

    return $_size;
  }

  /**
   * Add an log message
   *
   * @access public
   * @param string $msg Message
   * @return void
   */
  public function addLog($msg) {
    array_push($this->_log, htmlspecialchars($msg));
  }

  /**
   * Get log messages
   *
   * @param bool $mostRecent Most recent or all messages
   * @access public
   * @return array or string of Log Entrie(s)
   */
  public function getLogs($mostRecent=true)
  {
    $log = false;

    if ($mostRecent) {
      $log = end($this->_log);
    } else {
      $log = $this->_log;
    }

    return $log;
  }

  /**
   * Add an error message
   *
   * @access public
   * @param string $msg Message
   * @return void
   */
  public function addError($msg) {
    array_push($this->_error, htmlspecialchars($msg));
  }

  /**
   * Get error messages
   *
   * @param bool $mostRecent Most recent or all messages
   * @access public
   * @return array or string of Error(s)
   */
  public function getErrors($mostRecent=true)
  {
    $error = false;

    if ($mostRecent) {
      $error = end($this->_error);
    } else {
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
    return $this->stylesheetFileTree;
  }

  /**
   * Get javascript file tree (recursiv)
   *
   * @access public
   * @return Array of Files
   */
  public function getJavascriptFileTree()
  {
    return $this->javascriptFileTree;
  }

  /**
   * Get Object properties (e.g. to store in database or something else)
   *
   * @access public
   * @return get_object_vars()
   */
  public function getProperties() {
    return get_object_vars($this);
  }

  //public function __destruct() {
  //  unset($this);
  //}
}
