<?php
namespace Nino\Io;

use React\Stream\WritableStreamInterface as ReactWritable;
use React\Stream\Util as ReactUtil;

/**
 */
final class ErrorStream extends EventEmitter implements StreamInterface
{
    private $exception;
    private $loop;

    /**
     * 
     */
    function __construct($exception = null, $options = [])
    {
        $this->loop = isset($options['loop']) && $options['loop'] instanceof LoopInterface ? $options['loop'] : Loop::get();
        
        if($this->exception = $exception)
        {
            $this->loop->futureTick(function()
            {
                $this->emit('error', [$this->exception]);
            });
        }
    }
    
    /**
     * @return \Exception
     */
    public function getException()
    {
        return $this->exception;
    }

    /**
     * 
     */
    public function close()
    {

    }

    /**
     *
     * @return bool
     */
    function isReadable()
    {
        return false;
    }

    /**
     *
     * @return bool
     */
    function isPaused()
    {
        return true;
    }

    /**
     *
     * from: React\Stream
     *
     * @return void
     */
    function pause()
    {
        
    }

    /**
     *
     * from: React\Stream
     *
     * @return void
     */
    function resume()
    {

    }

    /**
     * pipe
     *
     * @param \React\Stream\WritableStreamInterface $dest
     * @param array $options
     * @return \React\Stream\WritableStreamInterface
     */
    function pipe(ReactWritable $dest, array $options = [])
    {
        $this->emit('error', [new \RuntimeException('Cannot pipe a non-readable stream')]);
        return ReactUtil::pipe($this, $dest, $options);
    }
    
    /**
     * unpipe
     *
     * @param \React\Stream\WritableStreamInterface $dest
     * @return \React\Stream\ReadableStreamInterface
     */
    function unpipe(ReactWritable $dest = null)
    {
        return $this; // no-op
    }

    /**
     *
     * @return bool
     */
    function isWritable()
    {
        return false;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \React\Stream\WritableStreamInterface::write()
     */
    function write($data)
    {
        return false;
    }

    /**
     * 
     * @return void
     */
    function end($data = null)
    {

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
        return false;
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
        throw new \RuntimeException('Stream is not seekable');
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
        throw new \RuntimeException('Stream is not seekable');
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
        throw new \RuntimeException('Stream is not seekable');
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
        throw new \RuntimeException('Stream is not seekable');
    }
}