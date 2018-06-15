<?php
namespace Nino\Io\Loop;

use React\EventLoop\LoopInterface as ReactLoopInterface;
#use React\EventLoop\Timer\TimerInterface;
use React\EventLoop\TimerInterface;
use Nino\Io\LoopInterface;

/**
 * A curl_multi_exec extension to the event-loop.
 */
class CurlDecoratorLoop implements LoopInterface
{

    private $loop;

    private $curlHandles = [];

    private $curlListeners = [];

    private $curlMulti;

    private $active = 1;

    private $listening = false;

    private $selectTimeout = 1;

    /**
     */
    public function __construct(ReactLoopInterface $loop)
    {
        $this->loop = $loop;
    }

    /**
     */
    public function __destruct()
    {
        // echo '<br>curlloop.destruct';
        if ($this->curlMulti)
        {
            @curl_multi_close($this->curlMulti);
        }
    }

    /**
     */
    private function listenNextTick()
    {
        if (!$this->listening)
        {
            $this->listening = true;
            
            $this->loop->futureTick(function ()
            {
                $this->listening = false;
                $this->waitForCurlActivity();
            });
        }
    }

    /**
     * Wait/check for curl activity.
     *
     * if a curl handle was operated successfully, i.e. curl_multi_info_read returned data
     * the registered callback will be called and passed an array:
     * result: curl_multi_getcontent
     * info: curl_getinfo
     * errno: the curl error code if any
     * error: the curl error message if any
     */
    private function waitForCurlActivity()
    {
        $multi = $this->getCurlMulti();
        
        $n = curl_multi_select($multi, $this->selectTimeout);
        
        if ($n === -1) // or should we let the master loop handle this?
        {
            // Perform a usleep if a select returns -1.
            // See: https://bugs.php.net/bug.php?id=61141
            usleep(250);
        }
        
        while (curl_multi_exec($multi, $this->active) === CURLM_CALL_MULTI_PERFORM);
        
        // echo '<br><br>active: '.$this->active;
        
        while ($done = curl_multi_info_read($multi))
        {
            // echo '<br>done: '.$this->active;
            
            $key = (int) $done['handle'];
            
            if (!isset($this->curlHandles[$key]))
            {
                // Probably was cancelled.
                continue;
            }
            
            // save listener
            $listener = $this->curlListeners[$key];
            
            $result['result'] = curl_multi_getcontent($done['handle']);
            $result['errno'] = $done['result'];
            $result['error'] = curl_error($done['handle']);
            $result['info'] = curl_getinfo($done['handle']);
            
            // remove curl handle as it might be reused by listener callback
            // and would then not be added as it already would exist
            $this->removeCurlHandle($done['handle']);
            
            // call listener
            call_user_func($listener, $result, $this);
        }
        
        // echo '<br>active2: '.$this->active .' - ' . count($this->curlHandles);
        // echo '<br>listenNext: '. (($this->active || count($this->curlHandles)) ? 1 : 0);
        
        if ($this->active || count($this->curlHandles))
            $this->listenNextTick();
    }

    /**
     */
    private function getCurlMulti()
    {
        if (!$this->curlMulti)
        {
            $this->curlMulti = curl_multi_init();
        }
        
        return $this->curlMulti;
    }

    /**
     * adds a curl handle to be executed asynchronously
     * 
     * {@inheritDoc}
     * @see \Nino\Io\LoopInterface::addCurlHandle()
     */
    public function addCurlHandle($handle, $listener)
    {
        $key = (int) $handle;
        
        if (!isset($this->curlHandles[$key]))
        {
            $this->curlHandles[$key] = $handle;
            $this->curlListeners[$key] = $listener;
            
             #echo '<br>addCurlHandle ';
             #print_r($handle);
            
            curl_multi_add_handle($this->getCurlMulti(), $handle);
            
            // echo ' - multi: ';
            // print_r($this->getCurlMulti());
            
            $this->listenNextTick();
        }
    }

    /**
     * removes a curl handle
     */
    public function removeCurlHandle($handle)
    {
        $key = (int) $handle;
        
        if (isset($this->curlHandles[$key]))
        {
             #echo '<br>removeCurlHandle ';
             #print_r($handle);
            
            curl_multi_remove_handle($this->getCurlMulti(), $handle);
            // curl_close($handle); // dont close handle as Guzzle reuses it's handles
            
            // echo ' - multi: ';
            // print_r($this->getCurlMulti());
            
            unset($this->curlHandles[$key], $this->curlListeners[$key]);
        }
    }

    /**
     * pauses the upload or download of a curl handle
     *
     * the write callback (defined with CURLOPT_WRITEFUNCTION) won't be called.
     * the read callback (defined with CURLOPT_READFUNCTION) won't be called
     *
     * @see https://curl.haxx.se/libcurl/c/curl_easy_pause.html
     */
    public function pauseCurlHandle($handle)
    {
        $key = (int) $handle;
        
        if (isset($this->curlHandles[$key]))
        {
            curl_pause($handle, CURLPAUSE_ALL);
        }
    }

    /**
     * resumes a paused curl upload or download
     */
    public function resumeCurlHandle($handle)
    {
        $key = (int) $handle;
        
        if (isset($this->curlHandles[$key]))
        {
            curl_pause($handle, CURLPAUSE_CONT);
        }
    }

    /**
     * Register a listener to be notified when a stream is ready to read.
     *
     * @param resource $stream
     *            The PHP stream resource to check.
     * @param callable $listener
     *            Invoked when the stream is ready.
     */
    public function addReadStream($stream, $listener)
    {
        $this->loop->addReadStream($stream, $listener);
    }

    /**
     * Register a listener to be notified when a stream is ready to write.
     *
     * @param resource $stream
     *            The PHP stream resource to check.
     * @param callable $listener
     *            Invoked when the stream is ready.
     */
    public function addWriteStream($stream, $listener)
    {
        $this->loop->addWriteStream($stream, $listener);
    }

    /**
     * Remove the read event listener for the given stream.
     *
     * @param resource $stream
     *            The PHP stream resource.
     */
    public function removeReadStream($stream)
    {
        $this->loop->removeReadStream($stream);
    }

    /**
     * Remove the write event listener for the given stream.
     *
     * @param resource $stream
     *            The PHP stream resource.
     */
    public function removeWriteStream($stream)
    {
        $this->loop->removeWriteStream($stream);
    }
    
    /**
     * Register a listener to be notified when a signal has been caught by this process.
     *
     * This is useful to catch user interrupt signals or shutdown signals from
     * tools like `supervisor` or `systemd`.
     *
     * The listener callback function MUST be able to accept a single parameter,
     * the signal added by this method or you MAY use a function which
     * has no parameters at all.
     *
     * The listener callback function MUST NOT throw an `Exception`.
     * The return value of the listener callback function will be ignored and has
     * no effect, so for performance reasons you're recommended to not return
     * any excessive data structures.
     *
     * ```php
     * $loop->addSignal(SIGINT, function (int $signal) {
     *     echo 'Caught user interrupt signal' . PHP_EOL;
     * });
     * ```
     *
     * See also [example #4](examples).
     *
     * Signaling is only available on Unix-like platform, Windows isn't
     * supported due to operating system limitations.
     * This method may throw a `BadMethodCallException` if signals aren't
     * supported on this platform, for example when required extensions are
     * missing.
     *
     * **Note: A listener can only be added once to the same signal, any
     * attempts to add it more then once will be ignored.**
     *
     * @param int $signal
     * @param callable $listener
     *
     * @throws \BadMethodCallException when signals aren't supported on this
     *     platform, for example when required extensions are missing.
     *
     * @return void
     */
    public function addSignal($signal, $listener)
    {
        return $this->loop->addSignal($signal, $listener);
    }
    
    /**
     * Removes a previously added signal listener.
     *
     * ```php
     * $loop->removeSignal(SIGINT, $listener);
     * ```
     *
     * Any attempts to remove listeners that aren't registered will be ignored.
     *
     * @param int $signal
     * @param callable $listener
     *
     * @return void
     */
    public function removeSignal($signal, $listener)
    {
        return $this->loop->removeSignal($signal, $listener);
    }

    /**
     * Enqueue a callback to be invoked once after the given interval.
     *
     * The execution order of timers scheduled to execute at the same time is
     * not guaranteed.
     *
     * @param int|float $interval
     *            The number of seconds to wait before execution.
     * @param callable $callback
     *            The callback to invoke.
     *            
     * @return TimerInterface
     */
    public function addTimer($interval, $callback)
    {
        return $this->loop->addTimer($interval, $callback);
    }

    /**
     * Enqueue a callback to be invoked repeatedly after the given interval.
     *
     * The execution order of timers scheduled to execute at the same time is
     * not guaranteed.
     *
     * @param int|float $interval
     *            The number of seconds to wait before execution.
     * @param callable $callback
     *            The callback to invoke.
     *            
     * @return TimerInterface
     */
    public function addPeriodicTimer($interval, $callback)
    {
        return $this->loop->addPeriodicTimer($interval, $callback);
    }

    /**
     * Cancel a pending timer.
     *
     * @param TimerInterface $timer
     *            The timer to cancel.
     */
    public function cancelTimer(TimerInterface $timer)
    {
        $this->loop->cancelTimer($timer);
    }

    /**
     * Check if a given timer is active.
     *
     * @param TimerInterface $timer
     *            The timer to check.
     *            
     * @return boolean True if the timer is still enqueued for execution.
     */
    public function isTimerActive(TimerInterface $timer)
    {
        return $this->loop->isTimerActive($timer);
    }

    /**
     * Schedule a callback to be invoked on a future tick of the event loop.
     *
     * Callbacks are guaranteed to be executed in the order they are enqueued.
     *
     * @param callable $listener
     *            The callback to invoke.
     */
    public function futureTick($listener)
    {
        $this->loop->futureTick($listener);
    }

    /**
     * Perform a single iteration of the event loop.
     */
    public function tick()
    {
        $this->loop->tick();
    }

    /**
     * Run the event loop until there are no more tasks to perform.
     */
    public function run()
    {
        $this->loop->run();
    }

    /**
     * Instruct a running event loop to stop.
     */
    public function stop()
    {
        $this->loop->stop();
    }
}
