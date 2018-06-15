<?php
namespace Nino\Io;

use React\Stream\WritableStreamInterface as ReactWritable;
use React\Stream\ReadableStreamInterface as ReactReadable;
use React\Promise\Promise;
use Nino\Io\Resource\ReadableStream;
use Nino\Io\Resource\SeekableReadableStream;
use Nino\Io\Resource\WritableStream;
use Nino\Io\Resource\SeekableWritableStream;

/**
 */
class Stream extends EventEmitter implements StreamInterface
{

    /**
     * Only used with temp streams, i.e.
     * instanciating this class with a string or null value.
     * the max stream data size upon which php://temp should switch from memory to file buffering
     * php://temp will use a temporary file once the amount of data stored hits a predefined limit (the default is 2 MB).
     */
    const MEMORY_BUFFER = 2097152;

    // 2mb
    protected $loop;

    protected $stream;

    protected $readable;

    protected $writable;

    protected $seekable;

    protected $exception;

    protected $autoClose = true;

    protected $closed = false;

    protected $paused = true;

    /**
     * creates an StreamInterface instance
     *
     * parameter $stream can be
     * - a resource (see php fopen)
     * - stream implementation either implementing ReadableStreamInterface or WritableStreamInterface or both
     * - a string. Will create a temporary (php://temp) stream containing the string
     * - null or empty. Will create an empty temporary (php://temp) stream
     * - a callback function which will be immediately called. The return value should be
     * an array containing [$stream, $options, $writableStream]
     *
     * Optionally an $options array can be passed defining meta-data of the stream,
     * if information is available e.g. from prior operations
     *
     * The stream constructor fails silently. I.e. any exceptions thrown during __construction will be catched, cached and
     * emitted as error events.
     *
     * @param
     *            resource|string|ReadableStreamInterface|WritableStreamInterface
     * @param
     *            array options
     * @param WritableStreamInterface $writable
     */
    public function __construct($stream = '', $options = [], ReactWritable $writableStream = null)
    {
        try
        {
            if (is_callable($stream))
            {
                call_user_func($stream, function ($a = null, $b = [], $c = null) use (&$stream, &$options, &$writableStream)
                {
                    $stream = $a;
                    $options = $b;
                    $writableStream = $c;
                });
            }
            else if (!$stream || is_string($stream))
            {
                $buffer = isset($options['memoryBuffer']) ? $options['memoryBuffer'] : self::MEMORY_BUFFER;
                
                $tmp = fopen('php://temp/maxmemory:' . $buffer, 'r+b');
                
                if (is_string($stream) && strlen($stream) > 0)
                {
                    fwrite($tmp, $stream);
                    rewind($tmp);
                }
                
                $options['autoClose'] = false; // dont automatically close temporary streams
                $stream = $tmp;
            }
            
            $this->loop = isset($options['loop']) && $options['loop'] instanceof LoopInterface ? $options['loop'] : Loop::get();
            
            if (is_resource($stream) && get_resource_type($stream) == 'stream')
            {
                $meta = stream_get_meta_data($stream);
                
                $readable = $writable = false;
                
                if (isset($meta['mode']))
                {
                    $mode = $meta['mode'];
                    
                    if ($mode[0] == 'r')
                    {
                        $readable = true;
                        $writable = (isset($mode[1]) && ($mode[1] == '+' || $mode[1] == 'w'));
                    }
                    else if (in_array($mode[0], array('w','x','c','a')))
                    {
                        $writable = true;
                        $readable = (isset($mode[1]) && $mode[1] == '+');
                    }
                }
                
                if ($writable)
                {
                    $writableStream = $meta['seekable'] ? new SeekableWritableStream($stream, $options) : new WritableStream($stream, $options);
                }
                
                if ($readable)
                {
                    $stream = $meta['seekable'] && !$writable  
                    // writable is also/already seekable
                    ? new SeekableReadableStream($stream, $options) 
                    : new ReadableStream($stream, $options);
                }
            }
            
            if ($stream instanceof ReactReadable)
            {
                $this->readable = $stream;
                
                Util::forwardEvents($stream, $this, ['data','end','error']);
                
                $stream->on('close', array($this,'streamClose')); // ATTENTION: streamClose
                    
                // essential, as we have no data listener to this stream, but have registered a data listener on readableStream
                    // even if we use this stream only for writing, e.g. in w+ mode
                    // we only want to register the readableStream to the loop, if a data listener is attached to THIS stream
                $stream->pause();
            }
            
            if ((($seekable = $writableStream) && $writableStream instanceof SeekableStreamInterface) || (($seekable = $stream) && $stream instanceof SeekableStreamInterface))
            {
                // make writableStream the preferred seekable stream
                $this->seekable = $seekable;
                Util::forwardEvents($seekable, $this, ['seek']);
            }
            
            $duplex = false;
            
            if ($writableStream instanceof ReactWritable)
            {
                $duplex = ($writableStream === $stream);
                $stream = $writableStream;
            }
            
            if ($stream instanceof ReactWritable)
            {
                $this->writable = $stream;
                
                #TODO: pipe ? event forwarding we got our own
                Util::forwardEvents($stream, $this, ['drain']); // ['drain','pipe']
                
                // if stream supports finish event (and thus autoClose = false)
                if ($stream instanceof WritableStreamInterface)
                {
                    Util::forwardEvents($stream, $this, ['finish']);
                } // if stream does not support finish event => emulate it
                else
                {
                    $stream->on('close', function ()
                    {
                        $this->emit('finish');
                    });
                }
                
                if (!$duplex)
                {
                    Util::forwardEvents($stream, $this, ['error']);
                    
                    $stream->on('close', array($this,'streamClose')); // ATTENTION: streamClose
                }
            }
            
            // autoClose
            if (isset($options['autoClose']))
            {
                $this->autoClose = !!$options['autoClose'];
            }
            
            if (!$this->readable && !$this->writable)
            {
                throw new \InvalidArgumentException('Stream must either be a resource, a string or an implementation of ' . 'React\Stream\WritableStreamInterface or React\Stream\RedableStreamInterface.');
            }
        }
        catch (\Exception $error)
        {
            $this->exception = $error;
            $this->readable = $this->writable = $this->seekable = null;
            
            $this->loop->futureTick(function()
            {
                $this->emit('error', [$this->exception]);
                $this->close();
            });
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
        
        // echo '<br>STREAM.close '.get_class($this);
        
        $this->closed = true;
        $this->paused = true;
        
        if ($this->isReadable())
        {
            // echo ' - close readable';
            $this->readable->close();
            
            if ($this->readable === $this->writable)
            {
                $this->writable = null;
            }
        }
        if ($this->isWritable())
        {
            // echo ' - close writable';
            $this->writable->close();
        }
        
        $this->readable = $this->writable = $this->seekable = null;
        
        $this->emit('close');
    }

    /**
     */
    public function streamClose()
    {
        if ($this->closed)
        {
            return;
        }
        
        // !$this->isReadable = readable stream closed due to "end" of data
        // !$this->isWritable = writable stream closed due to calling method end
        // => !$this->isReadable && $this->isWritable => readable closed but writable is still available
        if (!$this->isReadable() && $this->isWritable())
        {
            // echo '<br>STREAM.streamClose redable close event';
            // let writable finish writing its buffer
            $this->writable->end();
            return;
        }
        
        // echo '<br>STREAM.streamClose writable close event';
        $this->close();
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
        return ($this->readable && $this->readable->isReadable());
    }

    /**
     *
     * @return bool
     */
    public function isPaused()
    {
        return $this->paused;
    }

    /**
     *
     * from: React\Stream
     *
     * @return void
     */
    public function pause()
    {
        if ($this->isReadable())
        {
            $this->readable->pause();
            $this->paused = true;
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
        if ($this->isReadable())
        {
            $this->readable->resume();
            $this->paused = false;
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
        if ($this->isReadable())
        {
            return Util::pipe($this, $dest, $options);
        }
        else
        {
            throw new \RuntimeException('Cannot pipe a non-readable stream');
        }
    }
    
    /**
     * unpipe
     *
     * @param \React\Stream\WritableStreamInterface $dest
     * @return \React\Stream\ReadableStreamInterface
     */
    function unpipe(ReactWritable $dest = null)
    {
        if ($this->isReadable())
        {
            return Util::unpipe($this, $dest);
        }
        else
        {
            throw new \RuntimeException('Cannot unpipe from a non-readable stream');
        }
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
        return ($this->writable && $this->writable->isWritable());
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
        if ($this->isWritable())
        {
            return $this->writable->write($data);
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
        if ($this->isWritable())
        {
            if ($this->readable)
            {
                $this->readable->pause(); // TODO
            }
            
            return $this->writable->end($data);
        }
    }

    /**
     * Closes the stream when the destructed
     *
     * from: Psr\Stream
     */
    public function __destruct()
    {
        // echo '<br>destruct ' .get_class($this);
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
        return ($this->seekable && $this->seekable->isSeekable());
    }

    /**
     * seeks inside the stream to position $offset
     *
     * from: Psr\Stream
     *
     * @return bool
     */
    public function seek($offset, $whence = SEEK_SET)
    {
        if (!$this->isSeekable())
        {
            throw new \RuntimeException('Stream is not seekable');
        }
        
        $this->seekable->seek($offset, $whence);
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
    public function tell()
    {
        if (!$this->isSeekable())
        {
            throw new \RuntimeException('Stream is not seekable');
        }
        
        return $this->seekable->tell();
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
        if (!$this->isSeekable())
        {
            throw new \RuntimeException('Stream is not seekable');
        }
        
        return $this->seekable->eof();
    }

    /**
     * 
     * @param ReactReadable $stream
     * @return \React\Promise\Promise
     */
    public static function readContents(ReactReadable $stream)
    {
        if ($stream instanceof SeekableStreamInterface)
        {
            $stream->rewind();
        }
        
        return new Promise(function ($resolve, $reject) use ($stream)
        {
            $data = '';
            
            $stream->on('data', function ($read) use (&$data)
            {
                $data .= $read;
            });
            $stream->on('end', function () use (&$data, $resolve)
            {
                $resolve($data);
            });
            $stream->on('error', function ($error) use ($reject)
            {
                $reject($error);
            });
        });
    }
}