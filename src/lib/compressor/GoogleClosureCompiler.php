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

namespace JsCssChunker\Compressor;

/**
 * GoogleClosureCompiler (non java version)
 *
 * @package  JsCssChunker
 * @since    0.0.4
 */
class GoogleClosureCompiler
{
	/**
	 * Method to minify/compress {@link self->_string}
	 *
	 * @param   string   $content    String to compress
	 * @param   array    $urls       Array of urls to compress (The urls must be public)
	 * @param   boolean  $withStats  Determine to return an object with content including statistics.
	 *
	 * @access public
	 * @static
	 * @return string Compressed String on success, Un-Compressed String on error
	 */
	public static function minify($content, $urls = array(), $withStats = false)
	{
		$minifiedContent = $content;
		$statistics = array('sizeBefore' => 0, 'sizeAfter' => 0);

		$content = trim($content);

		if ($content || count($urls))
		{
			$request = \JsCssChunker\Request::getInstance();

			$post = array(
				'output_format' => 'json',
				'compilation_level' => 'SIMPLE_OPTIMIZATIONS', // OR ADVANCED_OPTIMIZATIONS
				'output_info' => 'errors'
			);
			$post = http_build_query($post) . '&output_info=compiled_code';
			if ($withStats)
			{
				$post .= '&output_info=statistics';
			}

			if ($content)
			{
				$post .= '&js_code=' . rawurlencode($content);
			}
			if (count($urls))
			{
				foreach ($urls as $_url)
				{
					$post .= '&code_url=' . rawurlencode($_url);
				}
			}

			$jsonResult = $request->getFileContents('http://closure-compiler.appspot.com/compile', $post, 30);
			$result = json_decode($jsonResult);

			$errors = array();
			if (isset($result->errors) && is_array($result->errors) && count($result->errors))
			{
				$result->errors = (array) $result->errors;
				foreach ($result->errors as $_error)
				{
					$_type = $_error->type;
					$errors[$_type] = $_error->error;
				}
			}

			if (isset($result->serverErrors) && is_array($result->serverErrors) && count($result->serverErrors))
			{
				$result->serverErrors = (array) $result->serverErrors;
				foreach ($result->serverErrors as $_error)
				{
					$_type = 'ERRNO_' . $_error->code;
					$errors[$_type] = $_error->error;
				}
			}
			if (count($errors) > 0)
			{
				echo implode("\n", $errors);
			}
			else
			{
				if (isset($result->compiledCode))
				{
					$minifiedContent = $result->compiledCode;
				}
			}

			if ($withStats && isset($result->statistics))
			{
				$stats = $result->statistics;
				$statistics = array(
					'sizeBefore' => isset($stats->originalSize) ? $stats->originalSize : 0,
					'sizeAfter' => isset($stats->compressedSize) ? $stats->compressedSize : 0,
				);
			}

		}
		unset($content); /* Free memory */

		if ($withStats)
		{
			return array_merge(array('content' => $minifiedContent), $statistics);
		}

		return $minifiedContent;
	}
}
