<?php


class YUICompressor
{
  private $options = array(
    'javabin' => 'java',
    // most common fullpath windows > 'javabin' => 'C:\\Program Files\\Java\\jre6\\bin\\java.exe',
    // most common fullpath unix    > 'javabin' => '/usr/bin/java',
    'jarpath' => null,

    'type' => 'js',

    'line-break' => false,
    'nomunge' => false,
    'preserve-semi' => false,
    'disable-optimizations' => false
  );

  private $cleanupFiles = array();

  private $string = '';
  private $stringCompressed = '';

  /**
   * Contructor Function for init class and set options
   *
   * @access public
   * @param string $content String to compress
   * @param array $options Options {@link self->options}
   * @return void
   */
  public function __construct($content, $options=array())
  {
    $this->string = $content;

    foreach($options as $k => $v)
    {
      if (isset($this->options[$k])) {
        $this->options[$k] = $v;
      }
    }
  }

  /**
   * Method to minify/compress {@link self->string}
   *
   * @access public
   * @static
   * @param string $content String to compress
   * @param array $options Options {@link self->options}
   * @return string Compressed String on success, Un-Compressed String on error
   */
  public static function minify($content, $options=array())
  {
    $klass = __CLASS__;
    $instance = new $klass($content, $options);

    $minifiedContent = $instance->compress();
    $instance->cleanUp();

    return $instance->getStringCompressed();
  }

  /**
   * Cleanup temporary files
   *
   * @access public
   * @return void
   */
  public function cleanUp()
  {
    array_map('unlink', $this->cleanupFiles);
  }

  /**
   * Get the Compressed Content
   *
   * @access protected
   * @return string {@link self->stringCompressed}
   */
  protected function getStringCompressed()
  {
    return (string)$this->stringCompressed;
  }

  /**
   * Get a specific option in class
   *
   * @access protected
   * @param string $key Option name
   * @param mixed $def Default value if $key not set
   * @return mixed The option value
   */
  protected function getOption($k, $def = null)
  {
    return isset($this->options[$k]) ? $this->options[$k] : $def;
  }

  /**
   * Method to compress the given string {@link self->string}
   *
   * @access private
   * @return string Compressed String on success, Un-Compressed String on error
   */
  private function compress()
  {
    $this->stringCompressed = $this->string;

    $isWindows = defined('PHP_WINDOWS_VERSION_MAJOR');

    $cwd = getcwd();
    $env = null;
    $stdin = null;
    $stdout = '';
    $stderr = '';
    $timeout = 60;

    $javabin = $this->getOption('javabin', 'java');
    $jarpath = $this->getOption('jarpath', dirname(__FILE__) . DIRECTORY_SEPARATOR . 'yuicompressor.jar');
    $type = $this->getOption('type');

    $options = array(
      'suppress_errors' => true,
      'binary_pipes' => true,
      'bypass_shell' => false
    );

    $descriptors = array(
      array('pipe', 'r'),
      array('pipe', 'w'),
      array('pipe', 'w')
    );

    $this->cleanupFiles[] = $outputfile = tempnam(sys_get_temp_dir(), 'js_css_chunker_yui_compressor_outputfile');
    $this->cleanupFiles[] = $inputfile = tempnam(sys_get_temp_dir(), 'js_css_chunker_yui_compressor_inputfile');

    file_put_contents($inputfile, $this->string);

    $arguments = array(
      $javabin,
      '-jar', $jarpath,
      '-o', $outputfile,
      $inputfile,
     '--type', (strtolower($type) == 'css' ? 'css' : 'js'),
     '--charset', 'UTF-8'
    );

    if (false !== ($linebreak = $this->getOption('line-break', false)))
    {
      // Some source control tools don't like files containing lines longer than,
      // say 8000 characters. The linebreak option is used in that case to split
      // long lines after a specific column. It can also be used to make the code
      // more readable, easier to debug (especially with the MS Script Debugger)
      // Specify 0 to get a line break after each semi-colon in JavaScript, and
      // after each rule in CSS.
      $arguments += array('--line-break', (int)$linebreak);
    }

    if (false !== $this->getOption('nomunge', false))
    {
      // Minify only. Do not obfuscate local symbols.
      $arguments += array('--nomunge');
    }

    if (false !== $this->getOption('preserve-semi', false))
    {
      // Preserve unnecessary semicolons (such as right before a '}') This option
      // is useful when compressed code has to be run through JSLint (which is the
      // case of YUI for example)
      $arguments += array('--preserve-semi');
    }

    if (false !== $this->getOption('disable-optimizations', false))
    {
      // Disable all the built-in micro optimizations.
      $arguments += array('--disable-optimizations');
    }

    if ($isWindows)
    {
      $options['bypass_shell'] = true;

      $args = $arguments;
      $cmd = array_shift($args);

      $script = '"' . $cmd . '"';
      if ($args) {
        $script .= ' ' . implode(' ', array_map('escapeshellarg', $args));
      }

      $script = 'cmd /V:ON /E:ON /C "' . $script . '"';
    }
    else
    {
      $script = implode(' ', array_map('escapeshellarg', $arguments));
    }

    $process = proc_open($script, $descriptors, $pipes, $cwd, $env, $options);

    if (is_resource($process))
    {
      $status = null;

      foreach ($pipes as $pipe) {
        stream_set_blocking($pipe, false);
      }

      if (null === $stdin)
      {
        fclose($pipes[0]);
        $writePipes = null;
      }
      else
      {
        $writePipes = array($pipes[0]);
        $stdinLen = strlen($stdin);
        $stdinOffset = 0;
      }
      unset($pipes[0]);

      while ($pipes || $writePipes)
      {
        $r = $pipes;
        $w = $writePipes;
        $e = null;

        $n = @stream_select($r, $w, $e, $timeout);

        if (false === $n) {
          break;
        }
        elseif ($n === 0)
        {
          proc_terminate($process);
          throw new \RuntimeException('YUICompressor: The process timed out.');
        }

        if ($w)
        {
          $written = fwrite($writePipes[0], (binary) substr($stdin, $stdinOffset), 8192);

          if (false !== $written) {
            $stdinOffset += $written;
          }

          if ($stdinOffset >= $stdinLen)
          {
            fclose($writePipes[0]);
            $writePipes = null;
          }
        }

        foreach ($r as $pipe)
        {
          $type = array_search($pipe, $pipes);
          $data = fread($pipe, 8192);

          if (strlen($data) > 0)
          {
            if ($type == 1) {
              $stdout .= $data;
            } else {
              $stderr .= $data;
            }
          }

          if (false === $data || feof($pipe))
          {
            fclose($pipe);
            unset($pipes[$type]);
          }
        }
      }

      $status = proc_get_status($process);
      $time = 0;

      while (1 == $status['running'] && $time < 1000000)
      {
        $time += 1000;
        usleep(1000);
        $status = proc_get_status($process);
      }

      $exitcode = proc_close($process);

      if ($status['signaled']) {
        throw new \RuntimeException(sprintf('YUICompressor: The process stopped because of a "%s" signal.', $status['stopsig']));
      }

      $exitcode = $status['running'] ? $exitcode : $status['exitcode'];

      if (!empty($stderr)) {
        throw new \RuntimeException('YUICompressor: ' . $stderr);
      }

      $outputContents = file_get_contents($outputfile);

      if (!empty($outputContents)) {
        $this->stringCompressed = $outputContents;
      }
    } else {
      throw new \RuntimeException('YUICompressor: Unable to start the process');
    }

    return $this->stringCompressed;
  }
}
