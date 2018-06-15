<?php
namespace Nino\Io;

/**
 * interface for a SeekableStream implementing Psr7 stream-seeking interface methods
 */
interface SeekableStreamInterface
{
    /*
     * SeekableStreamInterface
     *
     * @event seek // emitted when a stream was successfuly seeked
     */
    
    /**
     * returns true if the stream is seekable otherwise false
     *
     * from: Psr\Stream
     *
     * @return bool
     */
    function isSeekable();

    /**
     * seeks inside the stream to position $offset
     *
     * from: Psr\Stream
     *
     * @return bool
     */
    function seek($offset, $whence = SEEK_SET);

    /**
     * seeks inside the stream to the start position 0
     *
     * from: Psr\Stream
     *
     * @return bool
     */
    function rewind();

    /**
     * returns the current position of the file pointer
     *
     * from: Psr\Stream
     *
     * @return int position
     */
    function tell();

    /**
     * returns true if the pointer is at the end of the stream data
     *
     * from: Psr\Stream
     *
     * @return bool
     */
    function eof();
}
