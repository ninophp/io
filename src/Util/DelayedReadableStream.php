<?php
namespace Nino\Io\Util;

use Nino\Io\EventEmitter;
use Nino\Io\ReadableStreamInterface;
use Nino\Io\SeekableStreamInterface;
use Nino\Io\Util;
use React\Stream\WritableStreamInterface as ReactWritable;

/**
 *
 * DelayedReadableStream
 */
class DelayedReadableStream extends EventEmitter implements ReadableStreamInterface, SeekableStreamInterface
{

    protected $stream;

    protected $exception;

    private $inited = false;
    private $paused = true;
    private $closed = false;
    private $seeked;

    /**
     */
    function __construct(callable $initalize)
    {
        try
        {
            call_user_func($initalize, function ($stream)
            {
                if ($stream instanceof \Exception)
                {
                    $this->setError($stream);
                }
                else
                {
                    $this->initialize($stream);
                }
            }, $this);
        }
        catch (\Exception $error)
        {
            $this->loop->futureTick(function() use ($error)
            {
                $this->setError($error);
            });
        }
    }

    /**
     */
    protected function setError(\Exception $exception)
    {
        $this->exception = $exception;
        $this->emit('error', $exception);
        $this->close();
    }

    /**
     */
    protected function initialize($stream)
    {
        if (!($stream instanceof ReadableStreamInterface))
        {
            throw new \UnexpectedValueException('DelayedReadbaleStream expects a ReadableStreamInterface instance as return value, given ' . (is_object($stream) ? get_class($stream) : gettype($stream)));
        }
        
        $this->inited = true;
        $this->stream = $stream;
        
        if($this->closed)
        {
            $stream->close();
            return;
        }
        else if(!$stream->isReadable())
        {
            $this->closed = true;
            $this->emit('close');
            return;
        }
        
        Util::forwardEvents($stream, $this, ['data','end','close','error','seek']);
        
        if($this->seeked)
        {
            list($offset, $mode) = $this->seeked;
            $this->seeked = null;
            
            if($stream instanceof SeekableStreamInterface)
            {
                $stream->seek($offset, $mode);
            }
            else
            {
                throw new \RuntimeException('Stream is not seekable');
            }
        }
        
        if(!$this->paused)
        {
            $stream->resume();
        }
    }

    /**
     * closes the stream
     *
     * The close(): void method can be used to close the stream (forcefully).
     * This method can be used to forcefully close the stream, i.e. close the stream without waiting
     * for any buffered data to be flushed. If there's still data in the buffer, this data SHOULD be discarded.
     *
     * both from: Psr\Stream & React\Stream
     *
     * @return bool
     */
    public function close()
    {
        if ($this->closed)
        {
            return;
        }
        
        $this->closed = true;
        
        if ($this->inited)
        {
            $this->stream->close();
        }
        else 
        {
            $this->emit('close');
        }
    }

    /**
     * returns true if the stream is readable otherwise false
     *
     * from: Psr\Stream & React\Stream
     *
     * @return bool
     */
    function isReadable()
    {
        return $this->inited 
        ? $this->stream->isReadable() 
        : !$this->closed;
    }

    /**
     *
     * @return bool
     */
    function isPaused()
    {
        return $this->inited 
        ? $this->stream->isPaused() 
        : ($this->closed || $this->paused);
    }

    /**
     *
     * from: React\Stream
     *
     * @return void
     */
    function pause()
    {
        if ($this->closed)
        {
            return;
        }
        
        if ($this->inited)
        {
            $this->stream->pause();
        }
        else
        {
            $this->paused = true;
        }
    }

    /**
     *
     * from: React\Stream
     *
     * @return void
     */
    function resume()
    {
        if ($this->closed)
        {
            return;
        }
        
        if ($this->inited)
        {
            $this->stream->resume();
        }
        else
        {
            $this->paused = false;
        }
    }

    /**
     * pipe
     *
     * @return \React\Stream\WritableStreamInterface
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
     * Closes the stream when the destructed
     *
     * from: Psr\Stream
     */
    function __destruct()
    {
        $this->close();
    }

    /* Pointer and seeking */
    
    /**
     * returns true if the stream is seekable otherwise false
     *
     * from: Psr\Stream
     *
     * @return bool
     */
    function isSeekable()
    {
        return ($this->inited && $this->stream->isSeekable());
    }

    /**
     * seeks inside the stream to position $offset
     *
     * from: Psr\Stream
     *
     * @return void
     */
    function seek($offset, $whence = SEEK_SET)
    {
        if ($this->inited)
        {
            $this->stream->seek($offset, $whence);
        }
        else
        {
            $this->seeked = [$offset, $whence];
        }
    }

    /**
     * seeks inside the stream to the start position 0
     *
     * from: Psr\Stream
     *
     * @return void
     */
    function rewind()
    {
        $this->seek(0);
    }

    /**
     * returns the current position of the file pointer
     *
     * from: Psr\Stream
     *
     * @param
     *            int position
     */
    function tell()
    {
        if ($this->inited)
        {
            return $this->stream->tell();
        }
        return 0;
    }

    /**
     * returns true if the pointer is at the end of the stream data
     *
     * from: Psr\Stream
     *
     * @param
     *            bool
     */
    function eof()
    {
        if ($this->inited)
        {
            return $this->stream->eof();
        }
        return false;
    }
}