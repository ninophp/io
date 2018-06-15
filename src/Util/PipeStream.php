<?php
namespace Nino\Io\Util;

use React\Stream\DuplexStreamInterface as ReactDuplex;
use React\Stream\WritableStreamInterface as ReactWritable;
use Nino\Io\Util;
use Nino\Io\EventEmitter;
use Nino\Io\WritableStreamInterface;
use Nino\Io\ReadableStreamInterface;

/**
 */
class PipeStream extends EventEmitter implements ReactDuplex, WritableStreamInterface, ReadableStreamInterface
{
    /**
     * whether to close source stream on end (of pipe / writing)
     * @var integer
     */
    const CLOSE_ON_END = 1;
    /**
     * whether to pause source stream on end (of pipe / writing)
     * @var integer
     */
    const PAUSE_ON_END = 2;
    /**
     * whether to let source stream in open state on end (of pipe / writing)
     * @var integer
     */
    const NONE_ON_END = 0;

    protected $source;
    protected $pipes = [];
    protected $target;
    
    protected $readable = false;
    protected $writable = false;
    
    protected $closed = false;
    protected $closeOnFinish = false;
    protected $flag;

    /**
     * 
     */
    function __construct(ReadableStreamInterface $source, $flag = self::CLOSE_ON_END)
    {
        $this->source = $source;
        $this->flag = $flag;
        
        // add source events
        if($source instanceof WritableStreamInterface)
        {
            $this->writable = true;
            
            $source->on('drain', [$this, 'handleDrain']);
            $source->on('close', [$this, 'handleClose']);
        }
        
        $source->on('error', [$this, 'handleError']);
    }
    
    /**
     * 
     * @param ReadableStreamInterface $source
     * @param array $options
     * @return \Nino\Io\Util\PipeStream
     */
    public static function from(ReadableStreamInterface $source, $flag = self::CLOSE_ON_END)
    {
        return new self($source, $flag);
    }
    
    /**
     * 
     * @param WritableStreamInterface $stream
     * @return \Nino\Io\Util\PipeStream
     */
    public function add(WritableStreamInterface $stream)
    {
        $last = $this->target;
        
        if($last)
        {
            $last->removeListener('finish', [$this, 'handleFinish']);
            $last->removeListener('close', [$this,'close']);
            
            $last->on('close', [$this,'handleClose']); // soft close
            
            if($last instanceof ReadableStreamInterface)
            {
                $last->removeListener('data', [$this,'handleData']);
                $last->removeListener('end', [$this,'handleEnd']);
            }
            
            $this->pipes[] = $last;
        }
        
        $stream->on('error', [$this,'handleError']);
        
        $stream->on('finish', [$this,'handleFinish']);
        $stream->on('close', [$this,'close']);
        
        if($stream instanceof ReadableStreamInterface)
        {
            $this->readable = true;
            
            $stream->on('data', [$this,'handleData']);
            $stream->on('end', [$this,'handleEnd']);
        }
        
        $last = $last ?: $this->source;
        
        $last->pipe($stream);
        
        $this->target = $stream;
        
        return $this;
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
        
        // needs to be here as some further close calls will forward to here
        $this->closed = true;
        
        foreach($this->pipes as $pipe)
        {
            $pipe->close();
        }
        
        $this->source->removeListener('drain', [$this, 'handleDrain']);
        $this->source->removeListener('close', [$this, 'handleClose']);
        $this->source->removeListener('error', [$this, 'handleError']);
        
        if($this->flag == self::CLOSE_ON_END)
        {
            $this->source->close();
        }
        else if($this->flag == self::PAUSE_ON_END)
        {
            $this->source->pause();
        }
        
        if($this->target)
        {
            $this->target->close();
        }
        
        $this->emit('close');
        $this->removeAllListeners();
    }
    
    /**
     *
     * @param string $data
     */
    public function handleData($data)
    {
        $this->emit('data', [$data]);
    }
    
    /**
     * 
     */
    public function handleEnd()
    {
        $this->emit('end');
    }
    
    /**
     * 
     */
    public function handleDrain()
    {
        $this->emit('drain');
    }
    
    /**
     * 
     */
    public function handleFinish()
    {
        $this->emit('finish');
        
        if($this->closeOnFinish)
        {
            $this->close();
        }
    }
    
    /**
     * 
     */
    public function handleClose()
    {
        $this->closeOnFinish = true;
    }
    
    /**
     * 
     * @param \Exception $error
     */
    public function handleError($error)
    {
        $this->emit('error', [$error]);
        $this->close();
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
        return !$this->closed && $this->readable && $this->target->isReadable();
    }

    /**
     *
     * @return bool
     */
    function isPaused()
    {
        return !$this->isReadable() || $this->target->isPaused();
    }

    /**
     *
     * from: React\Stream
     *
     * @return void
     */
    function pause()
    {
        if($this->isReadable())
        {
            $this->target->pause();
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
        if($this->isReadable())
        {
            $this->target->resume();
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
        return !$this->closed && $this->writable && $this->source->isWritable();
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
        if (!$this->isWritable())
        {
            return false;
        }
        
        return $this->source->write($data);
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
        if (!$this->isWritable())
        {
            return;
        }
        
        return $this->source->end($data);
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