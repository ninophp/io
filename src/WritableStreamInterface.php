<?php
namespace Nino\Io;

use React\Stream\WritableStreamInterface as ReactWritable;

/**
 * interface for a WriteStream implementing React Stream Interface
 */
interface WritableStreamInterface extends ReactWritable
{
    /*
     * default soft-limit
     * defines the amount of bytes the buffer should hold until a
     * consecutive call of write will return false and inform the ReadStream to pause
     */
    CONST SOFT_LIMIT = 262144; # 256kb
    /*
     * default chunk size
     * defines how much bytes from the internal buffer
     * should be written underlying stream
     * -1 = all buffered data will be written no matter how many bytes
     */
    CONST WRITE_CHUNK_SIZE = 1048576; # 1048576 = 1mb # -1

    /*
     * WritableStreamInterface
     *
     * @event drain // buffer has exceeded limit and was now written to the stream / buffer has been above limit and is now below limit
     * @event error // error during write operation to stream resource
     * @event close // emitted on calling close
     * @event pipe // emitted on source stream when source.pipe is called, before any stream operation
     * @event finish // emitted when end() was called AND the buffer was finally written and emptied
     */
    
    /**
     * returns true if the stream is writable otherwise false
     *
     * Checks whether this stream is in a writable state (not closed already).
     *
     * This method can be used to check if the stream still accepts writing
     * any data or if it is ended or closed already.
     * Writing any data to a non-writable stream is a NO-OP.
     *
     * A successfully opened stream always MUST start in writable mode.
     *
     * Once the stream ends or closes, it MUST switch to non-writable mode.
     * This can happen any time, explicitly through `end()` or `close()` or
     * implicitly due to a remote close or an unrecoverable transmission error.
     * Once a stream has switched to non-writable mode, it MUST NOT transition
     * back to writable mode.
     *
     * If this stream is a `DuplexStreamInterface`, you should also notice
     * how the readable side of the stream also implements an `isReadable()`
     * method. Unless this is a half-open duplex stream, they SHOULD usually
     * have the same return value.
     *
     * from: Psr\Stream & React\Stream
     *
     * @return bool
     */
    function isWritable();

    /**
     * writes the string $data to the stream
     *
     * A successful write MUST be confirmed with a boolean `true`, which means
     * that either the data was written (flushed) immediately or is buffered and
     * scheduled for a future write. Note that this interface gives you no
     * control over explicitly flushing the buffered data, as finding the
     * appropriate time for this is beyond the scope of this interface and left
     * up to the implementation of this interface.
     *
     * Many common streams (such as a TCP/IP connection or file-based stream)
     * may choose to buffer all given data and schedule a future flush by using
     * an underlying EventLoop to check when the resource is actually writable.
     *
     * If a stream cannot handle writing (or flushing) the data, it SHOULD emit
     * an `error` event and MAY `close()` the stream if it can not recover from
     * this error.
     *
     * If the internal buffer is full after adding `$data`, then `write()`
     * SHOULD return `false`, indicating that the caller should stop sending
     * data until the buffer drains.
     * The stream SHOULD send a `drain` event once the buffer is ready to accept
     * more data.
     *
     * Similarly, if the the stream is not writable (already in a closed state)
     * it MUST NOT process the given `$data` and SHOULD return `false`,
     * indicating that the caller should stop sending data.
     *
     * The given `$data` argument MAY be of mixed type, but it's usually
     * recommended it SHOULD be a `string` value or MAY use a type that allows
     * representation as a `string` for maximum compatibility.
     *
     * React: returns false if buffer is full and true if stream can hold further data
     * Psr: returns int / size of bytes written
     *
     * from: Psr\Stream & React\Stream with slightly different signatures
     *
     * @param string $data
     * @return bool
     */
    function write($data);

    /**
     * Successfully ends the stream (after optionally sending some final data).
     *
     * This method can be used to successfully end the stream, i.e. close
     * the stream after sending out all data that is currently buffered.
     *
     * If there's no data currently buffered and nothing to be flushed, then
     * this method MAY `close()` the stream immediately.
     *
     * If there's still data in the buffer that needs to be flushed first, then
     * this method SHOULD try to write out this data and only then `close()`
     * the stream.
     * Once the stream is closed, it SHOULD emit a `close` event.
     *
     * @param string $data
     * @return void
     */
    function end($data = null);

    /**
     * Closes the stream (forcefully).
     *
     * This method can be used to forcefully close the stream, i.e. close
     * the stream without waiting for any buffered data to be flushed.
     * If there's still data in the buffer, this data SHOULD be discarded.
     *
     * Once the stream is closed, it SHOULD emit a `close` event.
     * Note that this event SHOULD NOT be emitted more than once, in particular
     * if this method is called multiple times.
     *
     * After calling this method, the stream MUST switch into a non-writable
     * mode, see also `isWritable()`.
     * This means that no further writes are possible, so any additional
     * `write()` or `end()` calls have no effect.
     *
     * Note that this method should not be confused with the `end()` method.
     * Unlike the `end()` method, this method does not take care of any existing
     * buffers and simply discards any buffer contents.
     * Likewise, this method may also be called after calling `end()` on a
     * stream in order to stop waiting for the stream to flush its final data.
     *
     * both from: Psr\Stream & React\Stream
     *
     * @return bool
     */
    function close();
}
