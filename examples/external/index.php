<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
define('DS', DIRECTORY_SEPARATOR);
//////////////////////////////////


// load dependencies
require_once(dirname(dirname(dirname(__FILE__))).DS.'JsCssChunker'.DS.'chunker.php');


// URL
$pageUrl = 'http://chunker.hieblmedia.net/_test/examples/test/';
$targetUrl = 'http://chunker.hieblmedia.de/_test/examples/test/cache/';


// get the chunker
$chunker = new JsCssChunker(
  $pageUrl,
  array(/* Options: for example here is using setOption */)
);

// check PHP CURL or other streams are available to load contents
if(!$chunker->check()) {
  die('Can not load contents: cURL Extension required or allow_url_fopen must be enabled in your php.ini');
}

// set Options
if(!empty($targetUrl)) {
  $chunker->setOption('targetUrl', $targetUrl); // absolute or relative (/path1/path2/../../ also allowed for root correction)
}

$chunker->setOption('logFilesize', true);
// $chunker->setOption('javascriptCompress', true); // default false (false does merge files without minify)
// $chunker->setOption('javascriptCompressorClass', 'JSMin'); // (JSMin, JSMinPlus, JavaScriptPacker) default 'JSMin'
// $chunker->setOption('httpAuth', array('user'=>'username', 'pass'=>'password')); // If httpAuth requried on local system, dot not set, it will be automaticly detected.


// load html from pageUrl, parse html head and auto apply all js and css files
$chunker->parseRawHeader('head', true);

// Simple Caching
$cachePath = dirname(__FILE__).DS.'cache';
$caching = true;

/* Example to get the right cache path to save on local filesystem */
   // $savePath = $chunker->getTargetUrlSavePath(); // absolute url
   // // $savePath = $chunker->getTargetUrlSavePath(); // relative path from root of pageUrl
   // $cachePath = $chunker->cleanPath(str_replace($pageUrl, dirname(__FILE__), $savePath), DS);


// CSS
$cssBuffer = '';
$cssHash = $chunker->getStylesheetHash();
$cssFileFromCache = false;

$cssBuffer = $chunker->chunkStylesheets();

if($cssHash) {
  $cssCacheFile = $cachePath.DS.$cssHash.'.css';

  if(file_exists($cssCacheFile) && $caching)
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
  $jsCacheFile = $cachePath.DS.$jsHash.'.js';

  if(file_exists($jsCacheFile) && $caching)
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

?>
  <h3>Chunker Errors</h3>
  <pre style="overflow:auto; background:#000; color:#fff; padding:5px; text-align:left; margin:10px 0;"><?php echo htmlentities(print_r($chunker->getErrors(false), true)); ?></pre>

  <h3>Chunker Log</h3>
  <pre style="overflow:auto; background:#000; color:#fff; padding:5px; text-align:left; margin:10px 0;"><?php echo htmlentities(print_r($chunker->getLogs(false), true)); ?></pre>
<?php

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

?>








