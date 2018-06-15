<?php
namespace Nino\Io\Util;

use React\Stream\WritableStreamInterface as ReactWritable;
use Nino\Io\WritableStreamInterface;
use Nino\Io\ReadableStreamInterface;
use Nino\Io\SeekableStreamInterface;
use Nino\Io\StreamInterface;
use Nino\Io\EventEmitter;
use Nino\Io\Util;
use Nino\Io\ErrorStream;

/**
 * LazyStream is lazily initiallizing a stream
 * 
 * expects a callback function 
 *
 */
final class LazyStream extends EventEmitter implements StreamInterface
{
    private $stream;
    private $callback;
    private $closed = false;

    /**
     *
     */
    public function __construct(callable $initialize)
    {
        $this->callback = $initialize;
    }
    
    /**
     * 
     */
    private function getStream()
    {
        if(!$this->stream)
        {
            try
            {
                $stream = \call_user_func($this->callback);
                
                if (!($stream instanceof WritableStreamInterface) && !($stream instanceof ReadableStreamInterface))
                {
                    $error = new \UnexpectedValueException('LazyStream expects a stream instance as return value of callback, given ' . (is_object($stream) ? get_class($stream) : gettype($stream)));
                }
            }
            catch(\Exception $error){}
            
            if($error)
            {
                $this->stream = new ErrorStream($error);
            }
            # error stream is initially closed. 
            # If lazy stream was closed we close underlying stream on init.
            else if($this->closed) 
            {
                $stream->close(); # will not forward event yet! Important as it was already emitted
            }
            
            # will emit error event of ErrorStream as forwardEvents registers error event on ErrrorStream
            Util::forwardEvents($stream, $this, ['drain','pipe','finish','data','end','close','error','seek']);
        }
        
        return $this->stream;
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
        if($this->closed)
        {
            return;
        }
        
        $this->closed = true;
        
        if($this->stream)
        {
            $this->getStream()->close();
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
    public function isReadable()
    {
        $stream = $this->getStream();
        return ($stream instanceof ReadableStreamInterface && $stream->isReadable());
    }

    /**
     *
     * @return bool
     */
    public function isPaused()
    {
        if(!$this->stream)
        {
            return $this->getStream()->isPaused();
        }
        else
        {
            return true;
        }
    }

    /**
     *
     * from: React\Stream
     *
     * @return void
     */
    public function pause()
    {
        if($this->stream && $this->isReadable())
        {
            $this->getStream()->pause();
        }
    }

    /**
     *
     * from: React\Stream
     *
     * @return void
     */
    public function resume()
    {
        if($this->isReadable())
        {
            $this->getStream()->resume();
        }
    }

    /**
     * pipe
     *
     * @param \React\Stream\WritableStreamInterface $dest
     * @param array $options
     * @return \React\Stream\WritableStreamInterface
     */
    public function pipe(ReactWritable $dest, array $options = [])
    {
        return $this->getStream()->pipe($dest, $options);
    }
    
    
    /**
     * unpipe
     *
     * @param \React\Stream\WritableStreamInterface $dest
     * @return \React\Stream\ReadableStreamInterface
     */
    function unpipe(ReactWritable $dest = null)
    {
        $this->getStream()->unpipe($dest);
        return $this;
    }

    /**
     * returns true if the stream is writable otherwise false
     *
     * from: Psr\Stream & React\Stream
     *
     * @return bool
     */
    public function isWritable()
    {
        $stream = $this->getStream();
        return ($stream instanceof WritableStreamInterface && $stream->isWritable());
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
     * @param string|StreamInterface $data
     * @return int length|bool
     */
    public function write($data)
    {
        if($this->isWritable())
        {
            return $this->getStream()->write($data);
        }
        return false;
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
    public function end($data = null)
    {
        if($this->isWritable())
        {
            $this->getStream()->end($data);
        }
    }

    /**
     * Closes the stream when the destructed
     *
     * from: Psr\Stream
     */
    public function __destruct()
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
    public function isSeekable()
    {
        $stream = $this->getStream();
        return ($stream instanceof SeekableStreamInterface && $stream->isSeekable());
    }

    /**
     * seeks inside the stream to position $offset
     *
     * from: Psr\Stream
     *
     * @return void
     */
    public function seek($offset, $whence = SEEK_SET)
    {
        if($this->isSeekable())
        {
            $this->getStream()->seek();
        }
    }

    /**
     * seeks inside the stream to the start position 0
     *
     * from: Psr\Stream
     *
     * @return void
     */
    public function rewind()
    {
        if($this->isSeekable())
        {
            $this->getStream()->rewind();
        }
    }

    /**
     * returns the current position of the file pointer
     *
     * from: Psr\Stream
     *
     * @param
     *            int position
     */
    public function tell()
    {
        if($this->isSeekable())
        {
            $this->getStream()->tell();
        }
    }

    /**
     * returns true if the pointer is at the end of the stream data
     *
     * from: Psr\Stream
     *
     * @param
     *            bool
     */
    public function eof()
    {
        if($this->isSeekable())
        {
            $this->getStream()->eof();
        }
    }
}