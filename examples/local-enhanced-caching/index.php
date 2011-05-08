<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
define('DS', DIRECTORY_SEPARATOR);
//////////////////////////////////


// load dependencies
require_once(dirname(dirname(dirname(__FILE__))).DS.'JsCssChunker'.DS.'chunker.php');


// Stylesheets Array (cms like)
$stylesheets = array(
  'layout.css' => array('type'=>'text/css'),
  '../_files/yaml/navigation/nav_slidingdoor.css' => array('type'=>'text/css'),
  'http://chunker.hieblmedia.net/_test/external-stylesheet.css' => array('type'=>'text/css'),
);
// Javascript Array (cms like)
$javascripts = array(
  '../_files/js/jquery.js' => array('type'=>'text/javascript'),
  'layout.js' => array('type'=>'text/javascript')
);

$pageURL = 'http';
if (@$_SERVER["HTTPS"] == "on") {
  $pageURL .= "s";
}
$pageURL .= "://";
if (@$_SERVER["SERVER_PORT"] != "80") {
  $pageURL .= @$_SERVER["SERVER_NAME"].":".@$_SERVER["SERVER_PORT"].@$_SERVER["REQUEST_URI"];
} else {
  $pageURL .= @$_SERVER["SERVER_NAME"].@$_SERVER["REQUEST_URI"];
}
$pageURLRel = preg_replace('#'.basename(__FILE__).'$#Uis', '', $pageURL);


$cacheFolderName = 'cache'; // ! without leading and trailing slash
$targetUrl =  preg_replace('#'.basename(__FILE__).'$#Uis', '', $_SERVER["REQUEST_URI"]).($cacheFolderName ? $cacheFolderName.'/' : ''); // relative

/////////// Chunker Part ///////////////
$caching = true;

    // get the chunker
    $chunker = new JsCssChunker(
      $pageURLRel, // !Important: Use only directory Urls (Absolute REQUEST_URI without script filename)
      array(
        'targetUrl' => $targetUrl, // (optional: absolute or relative)
        'logFilesize' => true, // for performance reasons its recommended to enable this only for debugging,
        'javascriptCompress' => true, // default false
        'javascriptCompressorClass' => 'JSMinPlus' // (JSMin, JSMinPlus, JavaScriptPacker) default 'JSMinPlus'
      )
    );

    // check PHP CURL or other streams are available to load contents
    if(!$chunker->check()) {
      die('Can not load contents: cURL Extension required or allow_url_fopen must be enabled in your php.ini');
    }

    // apply stylesheets and javascripts
    foreach($stylesheets as $_href=>$_attribs)
    {
      $type = isset($_attribs['type']) ? $_attribs['type'] : 'text/css';
      $media = isset($_attribs['media']) ? $_attribs['media'] : 'all';
      // add in queue list
      $chunker->addStylesheet($_href, $type, $media);
    }
    foreach($javascripts as $_src=>$_attribs)
    {
      $type = isset($_attribs['type']) ? $_attribs['type'] : 'text/javascript';
      // add in queue list
      $chunker->addJavascript($_src, $type);
    }

    // Enhanced Caching with filetime check (cache will be automaticly refresh when files changed in defined folders)
    // get the right cache path
    $savePath = $chunker->getTargetUrlSavePath();
    $cachePath = $chunker->cleanPath(str_replace($pageURLRel, dirname(__FILE__).DS, $savePath), DS);
    // or define manually
    // $cachePath = dirname(__FILE__).DS.$cacheFolderName;

    // CSS: start
    $cssHash = $chunker->getStylesheetHash();
    $cssCacheFile = $cachePath.DS.$cssHash.'.css';
    $cssFromCache = false;

    $cssHasModifications = $chunker->hasFoldersModifications(
      array(
        dirname(__FILE__).DS.'css',
        dirname(dirname(__FILE__)).DS.'_files'.DS.'css'
      ),
      $cssCacheFile
    );

    if(file_exists($cssCacheFile) && !$cssHasModifications && $caching) {
      $cssFromCache = true;
      $chunker->addLog('Load Stylesheet from Cache - no modifications detected');
    } else {
      if($cssHasModifications) {
        $chunker->addLog('CSS Modifications detected - cache file will be refreshed');
      }

      $cssBuffer = $chunker->chunkStylesheets();
      $cssFromCache = true;

      if($cssBuffer) {
        file_put_contents($cssCacheFile, $cssBuffer);
        $chunker->addLog('Saved Stylesheet Cache - On next reload stylesheets loaded from cache, if caching enabled');
      }
    }

    if($cssFromCache) {
      // replace stylesheets with current cache file
      $stylesheets = array(
        preg_replace('#'.basename(__FILE__).'$#Uis', '', $_SERVER["REQUEST_URI"]).$cacheFolderName.'/'.$cssHash.'.css' => array(
          'type'=>'text/css',
          'media'=>'all'
        )
      );
    }
    //// CSS: end

    // JS: start
    $jsHash = $chunker->getJavascriptHash();
    $jsCacheFile = $cachePath.DS.$jsHash.'.js';
    $jsFromCache = false;

    $jsHasModifications = $chunker->hasFoldersModifications(
      array(
        dirname(__FILE__).DS.'js',
        dirname(dirname(__FILE__)).DS.'_files'.DS.'js'
      ),
      $jsCacheFile
    );

    if(file_exists($jsCacheFile) && !$jsHasModifications && $caching) {
      $jsFromCache = true;
      $chunker->addLog('Load Javascript from Cache - no modifications detected');
    } else {
      if($jsHasModifications) {
        $chunker->addLog('Javascript Modifications detected - cache file will be refreshed');
      }

      $jsBuffer = $chunker->chunkJavascripts();
      $jsFromCache = true;

      if($jsBuffer) {
        file_put_contents($jsCacheFile, $jsBuffer);
        $chunker->addLog('Saved Javascript Cache - On next reload javascripts loaded from cache, if caching enabled');
      }
    }

    if($jsFromCache) {
      // replace javascripts with current cache file
      $javascripts = array(
        preg_replace('#'.basename(__FILE__).'$#Uis', '', $_SERVER["REQUEST_URI"]).$cacheFolderName.'/'.$jsHash.'.js' => array(
          'type'=>'text/javascript',
          'media'=>'all'
        )
      );
    }
    //// JS: end

////////////////////////////////////////


//////////////////
// Example HTML //
//////////////////
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
   "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
  <title>Chunker Example - Local files with enhanced caching</title>

  <?php /* Add stylesshets to head */ ?>
  <?php foreach($stylesheets as $_href=>$_attribs) : ?>
  <link href="<?php echo $_href; ?>" rel="stylesheet" type="<?php echo $_attribs['type']; ?>" />
  <?php endforeach;?>

  <!--[if lte IE 7]>
    <link href="patch.css" rel="stylesheet" type="text/css" />
  <![endif]-->

  <?php /* Add scripts to head */ ?>
  <?php foreach($javascripts as $_src=>$_attribs) : ?>
  <script src="<?php echo $_src; ?>" type="<?php echo $_attribs['type']; ?>"></script>
  <?php endforeach;?>
</head>
<body>
  <ul id="skiplinks">
    <li><a class="skip" href="#nav">Skip to navigation (Press Enter).</a>
    </li>
    <li><a class="skip" href="#col3">Skip to main content (Press Enter).</a>
    </li>
  </ul>
  <div id="chunker-toolbar">
    <div id="toolbar-contents" class="clearfix" style="display:none;">
      <h3>Chunker Log</h3>
      <pre style="background:transparent; color:#fff; padding:5px; text-align:left;"><?php echo htmlentities(print_r($chunker->getLogs(false), true)); ?></pre>
      <?php if($chunker->getOption('logFilesize') && $chunker->sizeLog) : ?>
        <h3>Chunker Filesize Log</h3>
        <pre style="background:transparent; color:#fff; padding:5px; text-align:left;"><?php echo htmlentities(print_r($chunker->sizeLog, true)); ?></pre>
      <?php endif; ?>
    </div>
    <div id="toolbar-toggler" class="clearfix closed">
      <a href="#toolbar-contents"><span>Chunker Results</span></a>
    </div>
  </div>
  <div class="page_margins">
    <div class="page">
      <div id="header" role="banner">
        <div id="topnav" role="contentinfo">
          <span><a href="#">Login</a> | <a href="#">Contact</a> | <a href="#">Imprint</a> </span>
        </div>
        <h1>
          Chunker Example <em>&laquo;Local files with enhanced caching&raquo;</em>
        </h1>
      </div>
      <div id="nav" role="navigation">
        <div class="hlist">
          <ul>
            <li><a href="index.php">Home</a></li>
            <li><a href="#">Link1</a></li>
            <li><a href="#">Link2</a></li>
            <li><a href="#">Link3</a></li>
          </ul>
        </div>
      </div>
      <div id="main">
        <div id="col1" role="complementary">
          <div id="col1_content" class="clearfix">
            <div class="info">
              <h2>About this example</h2>
              <p>
                In the main column you'll find all prestyled <a
                  href="http://www.yaml.de/en/documentation/css-components/design-of-the-content.html">content
                  elements</a> from <em>yaml/screen/content_default.css</em>.
              </p>
              <p>
                <strong>quick jump to ...</strong>
              </p>
              <ul>
                <li><a href="#headings">Heading Levels</a></li>
                <li><a href="#paragraphs">Paragraphs</a></li>
                <li><a href="#blockquotes">Blockquotes</a></li>
                <li><a href="#pre">Preformatted text</a></li>
                <li><a href="#inline">Inline Text Decoration</a></li>
                <li><a href="#lists">Lists</a></li>
                <li><a href="#floatpos">Text &amp; Images</a></li>
                <li><a href="#tables">Tables</a></li>
              </ul>
            </div>
          </div>
        </div>
        <div id="col2" role="complementary">
          <div id="col2_content" class="clearfix">
            <p class="info_bg">This is a paragraph text with an background image. This is a paragraph
              text with an background image. This is a paragraph text with an background image. This
              is a paragraph text with an background image.</p>
            <p class="info_bg_external">This is a paragraph text with an external background image.</p>
            <p class="info_css_external">This is a paragraph text with an CSS Class defined in a external Stylesheet.</p>
          </div>
        </div>
        <div id="col3" role="main">
          <div id="col3_content" class="clearfix">
            <h2>Typographic Settings</h2>
            <a name="headings"></a>
            <h3>Heading Levels</h3>
            <h1>H1 Heading</h1>
            <h2>H2 Heading</h2>
            <h3>H3 Heading</h3>
            <h4>H4 Heading</h4>
            <h5>H5 Heading</h5>
            <h6>H6 Heading</h6>
            <hr />
            <a name="paragraphs"></a>
            <h3>Paragraphs</h3>
            <p>This is a normal paragraph text. This is a normal paragraph text. This is a normal
              paragraph text. This is a normal paragraph text. This is a normal paragraph text. This
              is a normal paragraph text. This is a normal paragraph text. This is a normal paragraph
              text.</p>
            <p class="highlight">This is a paragraph text with class=&quot;highlight&quot;. This is a
              paragraph text with class=&quot;highlight&quot;. This is a paragraph text with
              class=&quot;highlight&quot;. This is a paragraph text with class=&quot;highlight&quot;.
              This is a paragraph text with class=&quot;highlight&quot;.</p>
            <p class="dimmed">This is a paragraph text with class=&quot;dimmed&quot;. This is a
              paragraph text with class=&quot;dimmed&quot;. This is a paragraph text with
              class=&quot;dimmed&quot;. This is a paragraph text with class=&quot;dimmed&quot;. This
              is a paragraph text with class=&quot;dimmed&quot;.</p>
            <p class="info">This is a paragraph text with class=&quot;info&quot;. This is a paragraph
              text with class=&quot;info&quot;. This is a paragraph text with class=&quot;info&quot;.
              This is a paragraph text with class=&quot;info&quot;. This is a paragraph text with
              class=&quot;info&quot;.</p>
            <p class="note">This is a paragraph text with class=&quot;note&quot;. This is a paragraph
              text with class=&quot;note&quot;. This is a paragraph text with class=&quot;note&quot;.
              This is a paragraph text with class=&quot;note&quot;. This is a paragraph text with
              class=&quot;note&quot;.</p>
            <p class="important">This is a paragraph text with class=&quot;important&quot;. This is a
              paragraph text with class=&quot;important&quot;. This is a paragraph text with
              class=&quot;important&quot;. This is a paragraph text with class=&quot;important&quot;.
              This is a paragraph text with class=&quot;important&quot;.</p>
            <p class="warning">This is a paragraph text with class=&quot;warning&quot;. This is a
              paragraph text with class=&quot;warning&quot;. This is a paragraph text with
              class=&quot;warning&quot;. This is a paragraph text with class=&quot;warning&quot;. This
              is a paragraph text with class=&quot;warning&quot;.</p>
            <hr />
            <a name="blockquotes"></a>
            <h3>Blockquotes</h3>
            <blockquote>
              <p>This is a paragraph text within a &lt;blockquote&gt; element. This is a paragraph
                text within a &lt;blockquote&gt; element. This is a paragraph text within a
                &lt;blockquote&gt; element. This is a paragraph text within a &lt;blockquote&gt;
                element.</p>
            </blockquote>
            <a name="pre"></a>
            <h3>Preformatted Text</h3>
            <pre>This is preformatted text, wrapped in a &lt;pre&gt; element. <br />This is preformatted text, wrapped in a &lt;pre&gt; element.</pre>
            <hr />
            <a name="inline"></a>
            <h3>Inline Semantic Text Decoration</h3>
            <ul>
              <li>an <a href="#">anchor</a> tag (<code>&lt;a&gt;</code>) example</li>
              <li>an <i>italics</i> and <em>emphasize</em> tag (<code>&lt;i&gt;</code>,<code>
                  &lt;em&gt;</code>) example</li>
              <li>a <big>big</big> and <small>small</small> tag (<code>&lt;big&gt;</code>,<code>
                  &lt;small&gt;</code>) example</li>
              <li>a <b>bold</b> and <strong>strong</strong> tag (<code>&lt;b&gt;</code>, <code>&lt;strong&gt;</code>)
                example</li>
              <li>an <acronym>acronym</acronym> and <abbr>abbreviation</abbr> tag (<code>&lt;acronym&gt;</code>,
                <code>&lt;abbr&gt;</code>) example</li>
              <li>a <cite>cite</cite> and <q>quote</q> tag (<code>&lt;cite&gt;</code>, <code>&lt;q&gt;</code>
                ) example</li>
              <li>a <code>code</code> und <dfn>definition</dfn> tag (<code>&lt;code&gt;</code>, <code>&lt;dfn&gt;</code>)
                example</li>
              <li>a <tt>teletype</tt> und <kbd>keyboard</kbd> tag (<code>&lt;tt&gt;</code>, <code>&lt;kbd&gt;</code>)
                example</li>
              <li>a <var>variable</var> and <samp>sample</samp> tag (<code>&lt;var&gt;</code>, <code>&lt;samp&gt;</code>)
                example</li>
              <li>an <ins>inserted</ins> and <del>deleted</del> tag (<code>&lt;ins&gt;</code>, <code>&lt;del&gt;</code>)
                example</li>
              <li>a <sub>subscript</sub> and <sup>superscript</sup> tag (<code>&lt;sub&gt;</code>, <code>&lt;sup&gt;</code>)
                example</li>
            </ul>
            <hr />
            <a name="lists" id="lists"></a>
            <h3>Unordered List</h3>
            <ul>
              <li>ut enim ad minim veniam</li>
              <li>occaecat cupidatat non proident
                <ul>
                  <li>facilisis semper</li>
                  <li>quis ac wisi augue</li>
                  <li>risus nec pretium</li>
                  <li>fames scelerisque</li>
                </ul>
              </li>
              <li>nostrud exercitation ullamco</li>
              <li>labore et dolore magna aliqua</li>
              <li>aute irure dolor in reprehenderit in voluptate velit esse cillum dolore</li>
            </ul>
            <h3>Ordered List</h3>
            <ol>
              <li>ut enim ad minim veniam
                <ol>
                  <li>facilisis semper</li>
                  <li>quis ac wisi augue</li>
                  <li>risus nec pretium</li>
                  <li>fames scelerisque</li>
                </ol>
              </li>
              <li>occaecat cupidatat non proident</li>
              <li>nostrud exercitation ullamco</li>
              <li>labore et dolore magna aliqua</li>
              <li>aute irure dolor in reprehenderit in voluptate velit esse cillum dolore</li>
            </ol>
            <h3>Definition List</h3>
            <dl>
              <dt>A definition list &mdash; this is &lt;dt&gt;</dt>
              <dd>A definition list &mdash; this is &lt;dd&gt; element. A definition list &mdash; this
                is &lt;dd&gt; element. A definition list &mdash; this is &lt;dd&gt; element. A
                definition list &mdash; this is &lt;dd&gt; element.</dd>
              <dt>A definition list &mdash; this is &lt;dt&gt;</dt>
              <dd>A definition list &mdash; this is &lt;dd&gt; element. A definition list &mdash; this
                is &lt;dd&gt; element. A definition list &mdash; this is &lt;dd&gt; element. A
                definition list &mdash; this is &lt;dd&gt; element.</dd>
              <dt>A definition list &mdash; this is &lt;dt&gt;</dt>
              <dd>A definition list &mdash; this is &lt;dd&gt; element. A definition list &mdash; this
                is &lt;dd&gt; element. A definition list &mdash; this is &lt;dd&gt; element. A
                definition list &mdash; this is &lt;dd&gt; element.</dd>
            </dl>
            <hr />
            <a name="floatpos"></a>
            <h3>Text &amp; Images</h3>
            <div class="floatbox">
              <img src="images/dummy_150.png" class="float_right" alt="" role="presentation" />
              <p>Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod
                tempor invidunt ut labore et dolore magna aliquyam erat, sed diam voluptua. At vero
                eos et accusam et justo duo dolores et ea rebum. Stet clita kasd gubergren, no sea
                takimata sanctus est Lorem ipsum dolor sit amet. Lorem ipsum dolor sit amet,
                consetetur sadipscing elitr, sed diam nonumy eirmod tempor invidunt ut labore et
                dolore magna aliquyam erat, sed diam voluptua. At vero eos et accusam et justo duo
                dolores et ea rebum.</p>
              <p>Duis autem vel eum iriure dolor in hendrerit in vulputate velit esse molestie
                consequat, vel illum dolore eu feugiat nulla facilisis at vero eros et accumsan et
                iusto odio dignissim qui blandit praesent luptatum zzril delenit augue duis dolore te
                feugait nulla facilisi. Lorem ipsum dolor sit amet, consectetuer adipiscing elit, sed
                diam nonummy nibh euismod tincidunt ut laoreet dolore magna aliquam erat volutpat.</p>
            </div>
            <hr />
            <div class="floatbox">
              <img src="images/dummy_150.png" class="float_left" alt="" role="presentation" />
              <p>Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod
                tempor invidunt ut labore et dolore magna aliquyam erat, sed diam voluptua. At vero
                eos et accusam et justo duo dolores et ea rebum. Stet clita kasd gubergren, no sea
                takimata sanctus est Lorem ipsum dolor sit amet. Lorem ipsum dolor sit amet,
                consetetur sadipscing elitr, sed diam nonumy eirmod tempor invidunt ut labore et
                dolore magna aliquyam erat, sed diam voluptua. At vero eos et accusam et justo duo
                dolores et ea rebum.</p>
              <p>Duis autem vel eum iriure dolor in hendrerit in vulputate velit esse molestie
                consequat, vel illum dolore eu feugiat nulla facilisis at vero eros et accumsan et
                iusto odio dignissim qui blandit praesent luptatum zzril delenit augue duis dolore te
                feugait nulla facilisi. Lorem ipsum dolor sit amet, consectetuer adipiscing elit, sed
                diam nonummy nibh euismod tincidunt ut laoreet dolore magna aliquam erat volutpat.</p>
            </div>
            <hr />
            <p>Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod tempor
              invidunt ut labore et dolore magna aliquyam erat, sed diam voluptua. At vero eos et
              accusam et justo duo dolores et ea rebum. Stet clita kasd gubergren, no sea takimata
              sanctus est Lorem ipsum dolor sit amet.</p>
            <img src="images/dummy_150.png" class="center" alt="" role="presentation" />
            <p>Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod tempor
              invidunt ut labore et dolore magna aliquyam erat, sed diam voluptua. At vero eos et
              accusam et justo duo dolores et ea rebum.</p>
            <hr />
            <h3>Text &amp; Images with Captions</h3>
            <div class="floatbox">
              <p class="icaption_right">
                <img src="images/dummy_300.png" alt="" aria-describedby="fig1" /><strong id="fig1"><b>Fig.
                    1:</b> Sample caption for this beautiful dummy image. </strong>
              </p>
              <p>Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod
                tempor invidunt ut labore et dolore magna aliquyam erat, sed diam voluptua. At vero
                eos et accusam et justo duo dolores et ea rebum. Stet clita kasd gubergren, no sea
                takimata sanctus est Lorem ipsum dolor sit amet.</p>
              <p>Duis autem vel eum iriure dolor in hendrerit in vulputate velit esse molestie
                consequat, vel illum dolore eu feugiat nulla facilisis at vero eros et accumsan et
                iusto odio dignissim qui blandit praesent luptatum zzril delenit augue duis dolore te
                feugait nulla facilisi. Lorem ipsum dolor sit amet, consectetuer adipiscing elit, sed
                diam nonummy nibh euismod tincidunt ut laoreet dolore magna aliquam erat volutpat.</p>
              <p>Ut wisi enim ad minim veniam, quis nostrud exerci tation ullamcorper suscipit
                lobortis nisl ut aliquip ex ea commodo consequat. Duis autem vel eum iriure dolor in
                hendrerit in vulputate velit esse molestie consequat, vel illum dolore eu feugiat
                nulla facilisis at vero eros et accumsan et iusto odio dignissim qui blandit praesent
                luptatum zzril delenit augue duis dolore te feugait nulla facilisi.</p>
            </div>
            <hr />
            <div class="floatbox">
              <p class="icaption_left">
                <img src="images/dummy_300.png" alt="" aria-describedby="fig2" /><strong id="fig2"><b>Fig.
                    2:</b> For captions that are longer than one line, you have<br /> to define a
                  width for the <code>icaption</code> classes in your<br /> <em>content.css</em> or
                  include line-breaks (<code>&lt;br/&gt;</code>) manually.</strong>
              </p>
              <p>Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod
                tempor invidunt ut labore et dolore magna aliquyam erat, sed diam voluptua. At vero
                eos et accusam et justo duo dolores et ea rebum. Stet clita kasd gubergren, no sea
                takimata sanctus est Lorem ipsum dolor sit amet.</p>
              <p>Stet clita kasd gubergren, no sea takimata sanctus est Lorem ipsum dolor sit amet.
                Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod tempor
                invidunt ut labore et dolore magna aliquyam erat, sed diam voluptua. Duis autem vel
                eum iriure dolor in hendrerit in vulputate velit esse molestie consequat, vel illum
                dolore eu feugiat nulla facilisis at vero eros et accumsan et iusto odio dignissim qui
                blandit praesent luptatum zzril delenit augue duis dolore te feugait nulla facilisi.
                Lorem ipsum dolor sit amet, consectetuer adipiscing elit, sed diam nonummy nibh
                euismod tincidunt ut laoreet dolore magna aliquam erat volutpat. At vero eos et
                accusam et justo duo dolores et ea rebum. Stet clita kasd gubergren, no sea takimata
                sanctus est Lorem ipsum dolor sit amet.</p>
              <p>Ut wisi enim ad minim veniam, quis nostrud exerci tation ullamcorper suscipit
                lobortis nisl ut aliquip ex ea commodo consequat. Duis autem vel eum iriure dolor in
                hendrerit in vulputate velit esse molestie consequat, vel illum dolore eu feugiat
                nulla facilisis at vero eros et accumsan et iusto odio dignissim qui blandit praesent
                luptatum zzril delenit augue duis dolore te feugait nulla facilisi.</p>
            </div>
            <hr />
            <a name="tables"></a>
            <h3>Tables</h3>
            <table border="0" cellpadding="0" cellspacing="0">
              <caption>table 1: this is a simple table with caption</caption>
              <thead>
                <tr>
                  <th scope="col" colspan="3">table heading</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <th scope="col">column 1</th>
                  <th scope="col">column 2</th>
                  <th scope="col">column 3</th>
                </tr>
                <tr>
                  <th scope="row">subhead 1</th>
                  <td>dummy content</td>
                  <td>dummy content</td>
                </tr>
                <tr>
                  <th scope="row">subhead 2</th>
                  <td>dummy content</td>
                  <td>dummy content</td>
                </tr>
                <tr>
                  <th scope="row" class="sub">subhead 3</th>
                  <td>dummy content</td>
                  <td>dummy content</td>
                </tr>
              </tbody>
            </table>
            <p>&nbsp;</p>
            <table border="0" cellpadding="0" cellspacing="0" class="full">
              <caption>table 2: this is a table with class=&quot;full&quot;</caption>
              <thead>
                <tr>
                  <th scope="col" colspan="3">table heading</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <th scope="col">column 1</th>
                  <th scope="col">column 2</th>
                  <th scope="col">column 3</th>
                </tr>
                <tr>
                  <th scope="row" class="sub">subhead 1</th>
                  <td>dummy content</td>
                  <td>dummy content</td>
                </tr>
                <tr>
                  <th scope="row" class="sub">subhead 2</th>
                  <td>dummy content</td>
                  <td>dummy content</td>
                </tr>
                <tr>
                  <th scope="row" class="sub">subhead 3</th>
                  <td>dummy content</td>
                  <td>dummy content</td>
                </tr>
              </tbody>
            </table>
            <hr />
          </div>
          <div id="ie_clearing">&nbsp;</div>
        </div>
      </div>
      <div id="footer" role="contentinfo">
        Footer with copyright notice and status information<br /> Layout based on <a
          href="http://www.yaml.de/">YAML</a>
      </div>
    </div>
  </div>
  <script src="../_files/yaml/core/js/yaml-focusfix.js" type="text/javascript"></script>
</body>
</html>




