<?php
namespace Nino\Io\Curl;

/**
 * interface for a file stream, independent of the file system
 */
class Options implements \ArrayAccess
{

    protected static $defaults = [];

    private $options;

    /**
     */
    function __construct($options = [])
    {
        $this->reset();
        $this->set($options);
    }

    /**
     *
     * @param string $data
     * @return string
     */
    function createHandle($handle = null)
    {
        if ($handle) 
        {
            // Remove all callback functions as they can hold onto references
            // and are not cleaned up by curl_reset. Using curl_setopt_array
            // does not work for some reason, so removing each one
            // individually.
            curl_setopt($handle, CURLOPT_HEADERFUNCTION, null);
            curl_setopt($handle, CURLOPT_READFUNCTION, null);
            curl_setopt($handle, CURLOPT_WRITEFUNCTION, null);
            curl_setopt($handle, CURLOPT_PROGRESSFUNCTION, null);
            curl_reset($handle);
        } 
        else 
        {
            $handle = curl_init();
        }
        
        curl_setopt_array($handle, $this->options);
        return $handle;
    }

    /**
     */
    function reset()
    {
        $this->options = static::$defaults;
    }

    /**
     */
    function getAll()
    {
        return $this->options;
    }

    /**
     */
    function set($options = [])
    {
        foreach ($options as $name => $value) 
        {
            $this->offsetSet($name, $value);
        }
    }

    /**
     */
    function offsetExists($offset)
    {
        return isset($this->options[self::resolve($offset)]);
    }

    /**
     */
    function offsetGet($offset)
    {
        $offset = self::resolve($offset);
        
        return $offset !== null && isset($this->options[$offset]) ? $this->options[$offset] : null;
    }

    /**
     */
    function offsetSet($offset, $value)
    {
        if (! is_null($offset = self::resolve($offset))) 
        {
            if ($offset == CURLOPT_WRITEFUNCTION || $offset == CURLOPT_FILE) 
            {
                /*
                 * It appears that setting CURLOPT_FILE / CURLOPT_WRITEFUNCTION before setting CURLOPT_RETURNTRANSFER doesn't work,
                 * presumably because CURLOPT_FILE depends on CURLOPT_RETURNTRANSFER being set.
                 */
                $this->options[CURLOPT_RETURNTRANSFER] = 1;
                
                if ($offset == CURLOPT_WRITEFUNCTION) 
                {
                    $this->options[CURLOPT_WRITEFUNCTION] = $value;
                } 
                else 
                {
                    $this->options[CURLOPT_FILE] = $value;
                }
            } 
            else if ($offset == CURLOPT_PROGRESSFUNCTION) 
            {
                $this->options[CURLOPT_NOPROGRESS] = false;
                $progress = $value;
                $value = function () use ($progress) 
                {
                    $args = func_get_args();
                    // PHP 5.5 pushed the handle onto the start of the args
                    if (is_resource($args[0])) 
                    {
                        array_shift($args);
                    }
                    call_user_func_array($progress, $args);
                };
            }
            
            $this->options[$offset] = $value;
        }
    }

    /**
     */
    function offsetUnset($offset)
    {
        if (! is_null($offset = self::resolve($offset))) 
        {
            unset($this->options[$offset]);
        }
    }

    /**
     */
    private static function resolve($type)
    {
        if (is_string($type) && ($type = 'CURLOPT_' . strtoupper($type)) && defined($type)) 
        {
            $type = constant($type);
        }
        return $type;
    }
}
