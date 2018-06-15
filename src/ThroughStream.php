<?php
namespace Nino\Io;

use React\Stream\DuplexStreamInterface as ReactDuplex;
use React\Stream\WritableStreamInterface as ReactWritable;

/**
 */
class ThroughStream extends EventEmitter implements ReactDuplex, WritableStreamInterface, ReadableStreamInterface
{

    protected $transformer;

    protected $closed = false;

    protected $paused = true;
    
    protected $finished = false;

    protected $inBuffer = '';
    
    protected $outBuffer = '';

    /**
     * creates a Throughstream instance which just passes data written to the stream further down the "pipe" with
     * emitting a data event
     *
     * @param callable $transformer
     * @param bool $resumed start stream in resumed, i.e. flowing mode (default false)
     */
    function __construct(callable $transformer = null, $resumed = false)
    {
        $this->transformer = $transformer;
        $this->paused = !$resumed;
    }

    /**
     * transform function
     *
     * All data written to the stream via write or end will be buffered.
     *
     * Everytime new data is written to the buffer the transform function
     * is called and the whole buffer is passed.
     *
     * The transform function then can work on the buffer.
     * Transformed data can be passed on by calling the ´push´ method.
     * It will then be emitted further down the pipe by emitting a "data" event.
     *
     * Returning nothing, i.e. null or an empty string will leave the buffer empty.
     * 
     * Throwing an exception inside the transform function will emit the 
     * exception as error event and close the stream.
     *
     * @param string $data
     *            the buffer
     * @param int $end
     *            indicating if "end" and not "write" was called, i.e. it is the last write to this stream
     */
    protected function transform($data, $end = false)
    {
        if ($this->transformer)
        {
            $data = call_user_func($this->transformer, $data, $end);
        }
        
        $this->push($data);
    }
    
    /**
     * method to push data further down the pipe
     * 
     * to be called internally by custom transform methods
     * 
     * @param string $data
     */
    protected function push($data = '')
    {
        if (!$this->closed)
        {
            $this->outBuffer .= $data;
            
            if(!$this->paused)
            {
                if($this->outBuffer !== '')
                {
                    $data = $this->outBuffer;
                    $this->outBuffer = '';
                    $this->emit('data', [$data]);
                }
                
                if($this->finished)
                {
                    $this->emit('end');
                    $this->close();
                }
            }
        }
    }
    
    /**
     * method to finsh pushing data further down the pipe
     *
     * to be called internally by custom transform methods
     *
     * @param string $data
     */
    protected function finish($data = '')
    {
        if (!$this->closed)
        {
            $this->finished = true;
            
            $this->emit('finish');
            
            $this->push($data);
        }
    }

    /**
     * closes the stream
     *
     * The close(): void method can be used to close the stream (forcefully).
     * This method can be used to forcefully close the stream, i.e. close the stream without waiting
     * for any buffered data to be flushed. If there's still data in the buffer, this data SHOULD be discarded.
     *
     * @return bool
     */
    public function close()
    {
        if ($this->closed)
        {
            return;
        }
        
        $this->inBuffer = '';
        $this->outBuffer = '';
        $this->closed = true;
        $this->paused = true;
        
        $this->emit('close');
    }

    /**
     * returns true if the stream is readable otherwise false
     *
     * @return bool
     */
    function isReadable()
    {
        return !$this->closed;
    }

    /**
     *
     * @return bool
     */
    function isPaused()
    {
        return $this->paused;
    }

    /**
     *
     * from: React\Stream
     *
     * @return void
     */
    function pause()
    {
        $this->paused = true;
    }

    /**
     *
     * from: React\Stream
     *
     * @return void
     */
    function resume()
    {
        if ($this->paused && !$this->closed)
        {
            $this->paused = false;
            $this->push(); // push and empty buffer
            $this->emit('drain');
        }
    }

    /**
     * pipe
     *
     * @return WritableStreamInterface
     */
    function pipe(ReactWritable $dest, array $options = [])
    {
        return Util::pipe($this, $dest, $options);
    }
    
    /**
     * unpipe
     *
     * @param \React\Stream\WritableStreamInterface $dest
     * @return \React\Stream\ReadableStreamInterface
     */
    function unpipe(ReactWritable $dest = null)
    {
        return Util::unpipe($this, $dest);
    }

    /**
     * returns true if the stream is writable otherwise false
     *
     * from: Psr\Stream & React\Stream
     *
     * @return bool
     */
    function isWritable()
    {
        return !$this->closed;
    }

    /**
     * writes the string $data to the stream
     *
     * If $data is an IStream, $length data will be read from
     * the source stream and written to this stream
     *
     * React: returns false if buffer is full and true if stream can hold further data
     * Psr: returns int / size of bytes written
     *
     * from: Psr\Stream & React\Stream with slightly different signatures
     *
     * @param string $data
     * @return int length|bool
     */
    function write($data)
    {
        if ($this->closed)
        {
            return false;
        }
        
        $this->inBuffer .= $data;
        
        if($this->inBuffer !== '')
        {
            try 
            {
                $this->inBuffer = $this->transform($this->inBuffer);
            }
            catch(\Exception $error)
            {
                $this->emit('error',[$error]);
                $this->close();
                return false;
            }
        }
        
        return !$this->paused;
    }

    /**
     * The end method can be used to successfully end the stream (after optionally sending some final data).
     *
     * This method can be used to successfully end the stream, i.e. close the stream after sending
     * out all data that is currently buffered.
     *
     * If there's no data currently buffered and nothing to be flushed, then this method MAY close() the stream immediately.
     * If there's still data in the buffer that needs to be flushed first, then this method SHOULD try to write out this data and
     * only then close() the stream. Once the stream is closed, it SHOULD emit a close event.
     *
     * @return void
     */
    function end($data = null)
    {
        if ($this->closed)
        {
            return false;
        }

        if ($data !== null || $data !== '')
        {
            $this->inBuffer .= $data;
        }
        
        try
        {
            $this->transform($this->inBuffer, true);
        }
        catch(\Exception $error)
        {
            $this->emit('error',[$error]);
            $this->close();
            return false;
        }
        
        $this->finish();
    }

    /**
     * Closes the stream when the destructed
     *
     * from: Psr\Stream
     */
    function __destruct()
    {
        $this->close();
    }
}