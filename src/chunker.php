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
 * @copyright  Copyright (C) 2011 - 2012, HieblMedia (Reinhard Hiebl)
 * @license    http://www.opensource.org/licenses/gpl-3.0.html GNU General Public License, version 3.0 (GPLv3)
 * @link       http://chunker.hieblmedia.net/
 */

// Check required PHP version
version_compare(PHP_VERSION, '5.3.0', '>') or die ('JsCssChunker requires PHP >= 5.3.0');

// Include dependencies
require_once dirname(__FILE__) . '/lib/Base.php';

/**
 * Class to minify, merge and compress stylesheet and javascript files
 *
 * @package  JsCssChunker
 * @since    0.1.0
 */
class JsCssChunker extends JsCssChunker\Base
{
	/*
	 * Register JsCssChunker classname in global namespace
	 */
}
