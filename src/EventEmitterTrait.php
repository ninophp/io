<?php
namespace Nino\Io;

use InvalidArgumentException;

/**
 * stream interface implementing and React Stream Interfaces
 */
trait EventEmitterTrait
{
    protected $listeners = [];
    protected $isOnceListener = [];
    
    /**
     * 
     * {@inheritDoc}
     * @see \Evenement\EventEmitterInterface::on()
     */
    public function on($event, callable $listener)
    {
        if ($event === null)
        {
            throw new InvalidArgumentException('event name must not be null');
        }
        
        if (!isset($this->listeners[$event])) 
        {
            $this->listeners[$event] = [];
            $this->isOnceListener[$event] = [];
        }
        
        $this->listeners[$event][] = $listener;
        $this->isOnceListener[$event][] = false;
        
        // add autoresume functionality on ReadableStreams
        if($event == 'data' && $this instanceof ReadableStreamInterface && $this->isPaused())
        {
            $this->resume();
        }
        
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \Evenement\EventEmitterInterface::once()
     */
    public function once($event, callable $listener)
    {
        $this->on($event, $listener);
        $this->isOnceListener[$event][ key(end($this->isOnceListener)) ] = true;
        
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \Evenement\EventEmitterInterface::removeListener()
     */
    public function removeListener($event, callable $listener)
    {
        if ($event === null) 
        {
            throw new InvalidArgumentException('event name must not be null');
        }
        
        if (isset($this->listeners[$event])) 
        {
            $index = \array_search($listener, $this->listeners[$event], true);
            
            if (false !== $index) 
            {
                unset($this->listeners[$event][$index]);
                unset($this->isOnceListener[$event][$index]);
                
                if (\count($this->listeners[$event]) === 0) 
                {
                    unset($this->listeners[$event]);
                    unset($this->isOnceListener[$event]);
                    
                    // add autopause functionality on ReadableStreams
                    if ($event == 'data' && $this instanceof ReadableStreamInterface && !$this->isPaused())
                    {
                        $this->pause();
                    }
                }
            }
        }
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \Evenement\EventEmitterInterface::removeAllListeners()
     */
    public function removeAllListeners($event = null)
    {
        if ($event !== null) 
        {
            unset($this->listeners[$event]);
            unset($this->isOnceListener[$event]);
        } 
        else 
        {
            $this->listeners = [];
            $this->isOnceListener = [];
        }
        
        // add autopause functionality on ReadableStreams
        if (($event == 'data' || $event === null) && $this instanceof ReadableStreamInterface && !$this->isPaused())
        {
            $this->pause();
        }
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \Evenement\EventEmitterInterface::listeners()
     */
    public function listeners($event = null)
    {
        return $event !== null
        ? isset($this->listeners[$event]) ? $this->listeners[$event] : []
        : $this->listeners;
    }
    
    /**
     * extending functonality of Evenement\EventEmitter.
     * 
     * returning false in a listener function will stop 
     * further propagation of the event.
     * 
     * returning an array will replace the initial arguments passed to emit.
     * e.g. 
     * $emitter->emit('event', [1]);
     * 
     * $emitter->on('event', function($val){
     *      return [2];
     * });
     * 
     * // second listener will echo 2
     * $emitter->on('event', function($val){
     *      echo $val;
     * });
     * 
     * {@inheritDoc}
     * @see \Evenement\EventEmitterInterface::emit()
     */
    public function emit($event, array $arguments = [])
    {
        if ($event === null) 
        {
            throw new InvalidArgumentException('event name must not be null');
        }
        
        if(!isset($this->listeners[$event]))
        {
            return;
        }
        
        $listeners = & $this->listeners[$event];
        $once = & $this->isOnceListener[$event];
        
        foreach ($listeners as $index=>$listener) 
        {
            $return = \call_user_func_array($listener, $arguments);
            
            if($once[$index])
            {
                unset($listeners[$index]);
                unset($once[$index]);
            }
            
            if($return === false)
            {
                break;
            }
            else if(is_array($return))
            {
                $arguments = $return;
            }
        }
        
        if(count($listeners) === 0)
        {
            unset($this->listeners[$event]);
            unset($this->isOnceListener[$event]);
        }
    }
}
