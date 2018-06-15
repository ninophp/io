<?php
namespace Nino\Io\Util;

use Nino\Io\EventEmitter;
use Nino\Io\WritableStreamInterface;
use Nino\Io\SeekableStreamInterface;
use Nino\Io\Util;
use React\Stream\DuplexStreamInterface;

/**
 * Decorator class that turns a DuplexStream into a WritableStream
 */
class WritableStreamDecorator extends EventEmitter implements WritableStreamInterface, SeekableStreamInterface
{
    protected $stream;
    protected $seekable = false;
    
    /*
     * WritableStreamInterface
     *
     * @event pipe // emitted when a readable stream is piped into this stream
     * @event error // emitted when an error occured reading from the underlying stream resource
     * @event drain // emitted when the buffer is again below the limit and the stream can hold more data
     * @event finish // emitted when end() was called AND the buffer was finally written and emptied
     * @event close // emitted when the stream closes
     */
    
    /**
     */
    public function __construct(DuplexStreamInterface $stream)
    {
        $this->stream = $stream;
        Util::forwardEvents($stream, $this, ['error','drain','finish','close']);
        
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
     * returns true if the stream is writable otherwise false
     *
     * from: Psr\Stream & React\Stream
     *
     * @return bool
     */
    function isWritable()
    {
        return $this->stream->isWritable();
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
        return $this->stream->write($data);
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
        return $this->stream->end($data);
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