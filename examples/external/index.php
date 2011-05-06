<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
define('DS', DIRECTORY_SEPARATOR);
//////////////////////////////////


// load dependencies
require_once(dirname(dirname(dirname(__FILE__))).DS.'JsCssChunker'.DS.'chunker.php');


// URL
$url = 'http://www.hieblmedia.de';
// $targetUrl = 'http://cdn.chunker.hieblmedia.net/_test/examples/';


// get the chunker
$chunker = new JsCssChunker(
  $url,
  array(/* Options: for example here is using setOption */)
);

// check PHP CURL or other streams are available to load contents
if(!$chunker->check()) {
  die('Can not load contents: cURL Extension required or allow_url_fopen must be enabled in your php.ini');
}

// set Options
//$chunker->setOption('targetUrl', $targetUrl); // absolute or relative (/path1/path2/../../ also allowed for root correction)
$chunker->setOption('logFilesize', true);
// $chunker->setOption('javascriptCompress', true); // default false
// $chunker->setOption('javascriptCompressorClass', 'JSMinPlus'); // (JSMin, JSMinPlus, JavaScriptPacker) default 'JSMinPlus'
// $chunker->setOption('httpAuth', array('user'=>'username', 'pass'=>'password')); // If httpAuth requried on local system, dot not set, it will be automaticly detected.


// load html
$chunker->loadHtml();
// parse html head and auto apply all js and css files
$chunker->parseRawHeader('', 'head', true);





// Simple Caching
$cachPath = dirname(__FILE__).DS.'cache';

// CSS
$cssBuffer = '';
$cssHash = $chunker->getStylesheetHash();
$cssFileFromCache = false;

if($cssHash) {
  $cssCacheFile = $cachPath.DS.$cssHash.'.css';

  if(file_exists($cssCacheFile))
  {
    $cssBuffer = file_get_contents($cssCacheFile);
    $cssFileFromCache = true;
  }
  else
  {
    // chunk Stylesheets
    $cssBuffer = $chunker->chunkStylesheets();

    if($cssBuffer) {
      file_put_contents($cssCacheFile, $cssBuffer);
    }
  }
}

// JS
$jsBuffer = '';
$jsHash = $chunker->getJavascriptHash();
$jsFileFromCache = false;

if($jsHash) {
  $jsCacheFile = $cachPath.DS.$jsHash.'.js';

  if(file_exists($jsCacheFile))
  {
    $jsBuffer = file_get_contents($jsCacheFile);
    $jsFileFromCache = true;
  }
  else
  {
    // chunk Javascripts
    $jsBuffer = $chunker->chunkJavascripts();

    if($jsBuffer) {
      file_put_contents($jsCacheFile, $jsBuffer);
    }
  }
}

if($cssBuffer) {
  // To something
  ?>
  <h2>Chunked Stylesheet - <?php echo $cssFileFromCache ? 'Loaded from Cache: '.$cssCacheFile : 'Loaded... next reload from cache'; ?></h2>
  <pre style="overflow:auto; background:#000; color:#fff; padding:5px; text-align:left; margin:10px 0;"><?php echo htmlentities(print_r($cssBuffer, true)); ?></pre>
  <?php
} else {
  echo '<h2>No Stylesheet found or applied</h2>';
}
if($jsBuffer) {
  // To something
  ?>
  <h2>Chunked Javascript - <?php echo $jsFileFromCache ? 'Loaded from Cache: '.$jsCacheFile : 'Loaded... next reload from cache'; ?></h2>
  <pre style="overflow:auto; background:#000; color:#fff; padding:5px; text-align:left; margin:10px 0;"><?php echo htmlentities(print_r($jsBuffer, true)); ?></pre>
  <?php
} else {
  echo '<h2>No Javascript found or applied</h2>';
}










