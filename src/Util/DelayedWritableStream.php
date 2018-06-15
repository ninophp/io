<?php
namespace Nino\Io\Util;

use Nino\Io\EventEmitter;
use Nino\Io\WritableStreamInterface;
use Nino\Io\SeekableStreamInterface;
use React\Stream\Util as ReactUtil;

/**
 * 
 *
 */
class DelayedWritableStream extends EventEmitter implements WritableStreamInterface, SeekableStreamInterface
{

    protected $stream;

    protected $exception;

    private $inited = false;
    private $closed = false;
    private $ended = false;
    private $seekable;
    private $seeked;
    private $data = '';

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
    private function initialize($stream)
    {
        if (!$stream instanceof WritableStreamInterface)
        {
            throw new \UnexpectedValueException('DelayedWritableStream expects a WritableStreamInterface instance as return value, given ' . (is_object($stream) ? get_class($stream) : gettype($stream)));
        }
        
        $this->stream = $stream;
        $this->inited = true;
        
        // stream was closed forcefully -> no need to write buffer to underlying stream
        if($this->closed)
        {
            $stream->close();
        }
        else if(!$stream->isWritable())
        {
            $this->closed = true;
            $this->emit('close');
        }
        else 
        {
            ReactUtil::forwardEvents($stream, $this, ['drain','pipe','finish','close','error','seek']);
            
            $this->seekable = ($stream instanceof SeekableStreamInterface);
            
            if($this->seeked)
            {
                list($offset, $mode) = $this->seeked;
                $this->seeked = null;
                
                if($this->seekable)
                {
                    $stream->seek($offset, $mode);
                }
                else
                {
                    throw new \RuntimeException('Stream is not seekable');
                }
            }

            if($this->data !== '')
            {
                $stream->write($this->data);
                $this->emit('drain');
            }
            
            if($this->ended)
            {
                $stream->end();
            }
        }
        
        $this->data = '';
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
     * returns true if the stream is writable otherwise false
     *
     * from: Psr\Stream & React\Stream
     *
     * @return bool
     */
    function isWritable()
    {
        return $this->inited
        ? $this->stream->isWritable()
        : !$this->closed;
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
     * @param
     *            string
     * @return int length|bool
     */
    function write($data)
    {        
        if ($this->inited)
        {
            return $this->stream->write($data);
        }
        else if (!$this->closed)
        {
            $this->data .= $data;
            return false;
        }
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
        if ($this->inited)
        {
            return $this->stream->end($data);
        }
        else if (!$this->closed)
        {
            $this->ended = true;
            
            if($data !== null)
            {
                $this->write($data);
            }
        }
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
        return ($this->seekable && $this->stream->isSeekable());
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
        if ($this->seekable)
        {
            $this->stream->seek($offset, $whence);
        }
        else if(!$this->inited && !$this->closed && $this->data === '')
        {
            $this->seek = [$offset, $whence];
        }
        else 
        {
            throw new \RuntimeException('Stream is not seekable');
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
        if ($this->seekable)
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
        if ($this->seekable)
        {
            return $this->stream->eof();
        }
        return false;
    }
}