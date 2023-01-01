<?php

namespace League\Plates\Template;

use Exception;
use League\Plates\Engine;
use League\Plates\Exception\TemplateNotFound;
use LogicException;
use Throwable;

/**
 * Container which holds template data and provides access to template functions.
 */
class Template
{
    const SECTION_MODE_REWRITE = 1;
    const SECTION_MODE_PREPEND = 2;
    const SECTION_MODE_APPEND = 3;

    /**
     * Set section content mode: rewrite/append/prepend
     * @var int
     */
    protected $sectionMode = self::SECTION_MODE_REWRITE;

    /**
     * Instance of the template engine.
     * @var Engine
     */
    protected $engine;

    /**
     * The name of the template.
     * @var Name
     */
    protected $name;

    /**
     * The data assigned to the template.
     * @var array
     */
    protected $data = array();

    /**
     * An array of section content.
     * @var array
     */
    protected $sections = array();

    /**
     * The name of the section currently being rendered.
     * @var string
     */
    protected $sectionName;

    /**
     * Whether the section should be appended or not.
     * @deprecated stayed for backward compatibility, use $sectionMode instead
     * @var boolean
     */
    protected $appendSection;

    /**
     * The name of the template layout.
     * @var array
     */
    protected $layoutName = [];

    /**
     * The data assigned to the template layout.
     * @var array
     */
    protected $layoutData = [];

    /**
     * Create new Template instance.
     * @param Engine $engine
     * @param string $name
     */
    public function __construct(Engine $engine, $name, $data = [])
    {
        $this->engine = $engine;
        $this->name = new Name($engine, $name);

        $this->data($this->engine->getData($name));
        $this->data($data);
    }

    /**
     * Magic method used to call extension functions.
     * @param  string $name
     * @param  array  $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        return $this->engine->getFunction($name)->call($this, $arguments);
    }

    /**
     * Alias for render() method.
     * @throws \Throwable
     * @throws \Exception
     * @return string
     */
    public function __toString()
    {
        return $this->render();
    }

    /**
     * Assign or get template data.
     * @param  array $data
     * @return mixed
     */
    public function data(array $data = null)
    {
        if (!func_num_args()) return $this->data;

        $this->data = is_null($data)? []: array_merge($this->data, $data);
        return $this;
    }

    /**
     * Check if the template exists.
     * @return boolean
     */
    public function exists()
    {
        try {
            ($this->engine->getResolveTemplatePath())($this->name);
            return true;
        } catch (TemplateNotFound $e) {
            return false;
        }
    }

    /**
     * Get the template path.
     * @return string
     */
    public function path()
    {
        try {
            return ($this->engine->getResolveTemplatePath())($this->name);
        } catch (TemplateNotFound $e) {
            return $e->paths()[0];
        }
    }

    /**
     * Render the template and layout.
     * @param  array  $data
     * @throws \Throwable
     * @throws \Exception
     * @return string
     */
    public function render(array $data = array())
    {
        $prev_data = $this->data;
        
        $this->data($data);
        unset($data);
        extract($this->data);

        $path = ($this->engine->getResolveTemplatePath())($this->name);

        try {
            $level = ob_get_level();
            ob_start();

            include $path;

            $content = ob_get_clean();

            if (isset($this->layoutName)) {
                
                $l = count($this->layoutName);
                $currentSections = $this->sections;
                for($i = 0; $i < $l; $i++) {
                    $layoutName = $this->layoutName[$i];
                    $layoutData = $this->layoutData[$i];
                    
                    $layout = $this->engine->make($layoutName);
                    $layout->sections = array_merge($currentSections, array('content' => $content));
                    $content = $layout->render($layoutData);
                    
                    $currentSections = $layout->sections;
                }
            }
            
            $toReturn = $content;
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $e;
        }
        
        $this->data = $prev_data;
        return $toReturn;
    }

    /**
     * Set the template's layout.
     * @param  string $name
     * @param  array  $data
     * @return Template|array
     */
    public function layout($name = null, array $data = array())
    {
        if(empty($name)) return $this->layoutName;
        
        $this->layoutName = [$name];
        $this->layoutData = [$data];
        return $this;
    }
    
    /**
     * Set the template's layout, and return self.
     * @param  array  $layouts Each row must be an array, where [0] is the Layout name and [1] layout data.
     * @param  bool $outwards Defines the order with which the layouts are provided.
     *  Outwards means first is provided the innermost layout, and then going towards the "top" / outermost layout.
     * @return Plates_Template|array
     */
    public function layouts($layouts = [], $outwards = true)
    {
      if(empty($layouts)) return $this->layoutName;
      
      $this->layoutName = [];
      $this->layoutData = [];
      foreach($layouts as $layout) $this->layoutAdd($outwards? 0: 1, $layout[0], $layout[1]?: []);
      return $this;
    }
    
    /**
     * Adds to the template's layouts, and return self.
     * @param  bool $before Should this layout be rendered before any other? Or after.
     *    In otherwords, is it towards the bottom, or the top? Is it innermost, or outermost?
     *    Default behaviour is that new layouts added are stacked outsite/above/after.
     * @param  string $name
     * @param  array  $data
     * @return Plates_Template
     */
    public function layoutAdd($before = 0, $name = null, array $data = array())
    {
      if(empty($name)) return $this;
      
      $func = $before? 'array_unshift': 'array_push';
      call_user_func_array($func, [&$this->layoutName, $name]);
      call_user_func_array($func, [&$this->layoutData, $data]);
      return $this;
    }
    
    /**
     * Adds to the template's layouts, and return self.
     * @param  bool $before Should this layout be rendered before any other? Or after.
     *    In otherwords, is it towards the bottom, or the top? Is it innermost, or outermost?
     *    Default behaviour is that new layouts added are stacked outsite/above/after.
     * @param  array  $layouts Each row must be an array, where [0] is the Layout name and [1] layout data.
     * @param  bool $outwards Defines the order with which the layouts are provided.
     *  Outwards means first is provided the innermost layout, and then going towards the "top" / outermost layout.
     * @return Plates_Template
     */
    public function layoutsAdd($before = 0, $layouts = [], $outwards = true)
    {
      if(empty($layouts)) return $this;
      
      if($before == $outwards) $layouts = array_reverse($layouts);
      
      foreach($layouts as $layout) $this->layoutAdd($before, $layout[0], $layout[1]);
      return $this;
    }
    
    /**
     * Start a new section block.
     * @param  string  $name
     * @return null
     */
    public function start($name)
    {
        if ($name === 'content') {
            throw new LogicException(
                'The section name "content" is reserved.'
            );
        }

        if ($this->sectionName) {
            throw new LogicException('You cannot nest sections within other sections.');
        }

        $this->sectionName = $name;

        ob_start();
    }

    /**
     * Start a new section block in APPEND mode.
     * @param  string $name
     * @return null
     */
    public function push($name)
    {
        $this->appendSection = true; /* for backward compatibility */
        $this->sectionMode = self::SECTION_MODE_APPEND;
        $this->start($name);
    }

    /**
     * Start a new section block in PREPEND mode.
     * @param  string $name
     * @return null
     */
    public function unshift($name)
    {
        $this->appendSection = false; /* for backward compatibility */
        $this->sectionMode = self::SECTION_MODE_PREPEND;
        $this->start($name);
    }

    /**
     * Stop the current section block.
     * @return null
     */
    public function stop()
    {
        if (is_null($this->sectionName)) {
            throw new LogicException(
                'You must start a section before you can stop it.'
            );
        }

        if (!isset($this->sections[$this->sectionName])) {
            $this->sections[$this->sectionName] = '';
        }

        switch ($this->sectionMode) {

            case self::SECTION_MODE_REWRITE:
                $this->sections[$this->sectionName] = ob_get_clean();
                break;

            case self::SECTION_MODE_APPEND:
                $this->sections[$this->sectionName] .= ob_get_clean();
                break;

            case self::SECTION_MODE_PREPEND:
                $this->sections[$this->sectionName] = ob_get_clean().$this->sections[$this->sectionName];
                break;

        }
        $this->sectionName = null;
        $this->sectionMode = self::SECTION_MODE_REWRITE;
        $this->appendSection = false; /* for backward compatibility */
    }

    /**
     * Alias of stop().
     * @return null
     */
    public function end()
    {
        $this->stop();
    }

    /**
     * Returns the content for a section block.
     * @param  string      $name    Section name
     * @param  string      $default Default section content
     * @return string|null
     */
    public function section($name, $default = null)
    {
        if (!isset($this->sections[$name])) {
            return $default;
        }

        return $this->sections[$name];
    }

    /**
     * Fetch a rendered template.
     * @param  string $name
     * @param  array  $data
     * @param [type] $layoutName If porovided sets the layout of the template to use
     * @param closure|array|null $layoutData If not provided, template data is given for layout. If empty, nothing is passed.
     *  If it's a closure, it's called with $data as parameter, and the result is the new $layoutData.
     * @return string
     */
    public function fetch($name, array $data = array(), $layoutName = null, mixed $layoutData = null)
    {
        return $this->engine->render($name, $data, $layoutName, $layoutData);
    }

    /**
     * Output a rendered template.
     * @param  string $name
     * @param  array  $data
     * @param [type] $layoutName If porovided sets the layout of the template to use
     * @param closure|array|null $layoutData If not provided, template data is given for layout. If empty, nothing is passed.
     *  If it's a closure, it's called with $data as parameter, and the result is the new $layoutData.
     * @return null
     */
    public function insert($name, array $data = array(), $layoutName = null, mixed $layoutData = null)
    {
        echo $this->engine->render($name, $data, $layoutName, $layoutData);
    }
    
    /**
     * Create a new template.
     * @param  string   $name
     * @return Template
     */
    public function make($name, $data = []) {
        return $this->engine->make($name, $data);
    }
      
    /**
     * Apply multiple functions to variable.
     * @param  mixed  $var
     * @param  string $functions
     * @return mixed
     */
    public function batch($var, $functions)
    {
        foreach (explode('|', $functions) as $function) {
            if ($this->engine->doesFunctionExist($function)) {
                $var = call_user_func(array($this, $function), $var);
            } elseif (is_callable($function)) {
                $var = call_user_func($function, $var);
            } else {
                throw new LogicException(
                    'The batch function could not find the "' . $function . '" function.'
                );
            }
        }

        return $var;
    }

    /**
     * Escape string.
     * @param  string      $string
     * @param  null|string $functions
     * @return string
     */
    public function escape($string, $functions = null)
    {
        static $flags;

        if (!isset($flags)) {
            $flags = ENT_QUOTES | (defined('ENT_SUBSTITUTE') ? ENT_SUBSTITUTE : 0);
        }

        if ($functions) {
            $string = $this->batch($string, $functions);
        }

        return htmlspecialchars($string ?? '', $flags, 'UTF-8');
    }

    /**
     * Alias to escape function.
     * @param  string      $string
     * @param  null|string $functions
     * @return string
     */
    public function e($string, $functions = null)
    {
        return $this->escape($string, $functions);
    }
}
