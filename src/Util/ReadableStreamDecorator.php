<?php
namespace Nino\Io\Util;

use Nino\Io\EventEmitter;
use Nino\Io\ReadableStreamInterface;
use Nino\Io\SeekableStreamInterface;
use Nino\Io\Util;
use React\Stream\WritableStreamInterface as ReactWritable;
use React\Stream\DuplexStreamInterface;

/**
 * Decorator class that turns a DuplexStream into a ReadableStream
 */
class ReadableStreamDecorator extends EventEmitter implements ReadableStreamInterface, SeekableStreamInterface
{

    protected $stream;
    protected $seekable = false;
    /*
     * ReadableStreamInterface
     *
     * @event data // emitted when data was read from stream resource. data is passed to listener and NOT handled any further
     * @event error // emitted when an error occured reading from the underlying stream resource
     * @event end // emitted when all data was read. Then triggers close. If data was fully read end and close are emitted.
     * @event close // emitted on calling close (if closed forcefully only close will be emitted)
     */
    
    /**
     */
    public function __construct(DuplexStreamInterface $stream)
    {
        $this->stream = $stream;
        Util::forwardEvents($stream, $this, ['data','error','end','close']);
        
        if($stream instanceof SeekableStreamInterface)
        {
            $this->seekable = true;
            Util::forwardEvents($stream, $this, ['seek']);
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
        $this->stream->close();
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
        return $this->stream->isReadable();
    }

    /**
     *
     * @return bool
     */
    function isPaused()
    {
        return $this->stream->isPaused();
    }

    /**
     *
     * from: React\Stream
     *
     * @return void
     */
    function pause()
    {
        $this->stream->pause();
    }

    /**
     *
     * from: React\Stream
     *
     * @return void
     */
    function resume()
    {
        $this->stream->resume();
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
        return $this->seekable && $this->stream->isSeekable();
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
        if ($$this->seekable)
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