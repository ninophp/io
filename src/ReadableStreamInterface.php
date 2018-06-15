<?php
namespace Nino\Io;

use React\Stream\ReadableStreamInterface as ReactRedable;
use React\Stream\WritableStreamInterface as ReactWritable;

/**
 * interface for a WriteStream implementing React Stream Interface
 * a read method is added to read from streams, not in flowing mode
 */
interface ReadableStreamInterface extends ReactRedable
{
    /*
     * default soft-limit
     * defines the amount of bytes a potential internal buffer should hold until 
     * it will stop the underlying resource pushing data
     */
    #CONST SOFT_LIMIT = 262144; # 256kb
    /*
     * Controls the maximum buffer size in bytes to read from the underlying stream
     * and emit at once via a data event.
     * -1 = all available bytes are read from the stream and emitted
     */
    CONST READ_CHUNK_SIZE = 1048576; # 1048576 = 1mb # -1

    /*
     * ReadableStreamInterface
     *
     * @event data // emitted when data was read from stream resource. data is passed to listener and NOT handled any further
     * @event error // emitted when an error occured reading from the underlying stream resource
     * @event end // emitted on calling close
     * @event close // emitted on calling close
     */
    
    /**
     * returns true if the stream is readable otherwise false
     *
     * Checks whether this stream is in a readable state (not closed already).
     *
     * This method can be used to check if the stream still accepts incoming
     * data events or if it is ended or closed already.
     * Once the stream is non-readable, no further `data` or `end` events SHOULD
     * be emitted.
     *
     * A successfully opened stream always MUST start in readable mode.
     *
     * Once the stream ends or closes, it MUST switch to non-readable mode.
     * This can happen any time, explicitly through `close()` or
     * implicitly due to a remote close or an unrecoverable transmission error.
     * Once a stream has switched to non-readable mode, it MUST NOT transition
     * back to readable mode.
     *
     * from: Psr\Stream & React\Stream
     *
     * @return bool
     */
    function isReadable();

    /**
     * Pauses reading incoming data events.
     *
     * Removes the data source file descriptor from the event loop. This
     * allows you to throttle incoming data.
     *
     * Unless otherwise noted, a successfully opened stream SHOULD NOT start
     * in paused state.
     *
     * => Nino streams DO NOT stock to this behavior but to Node js behavior:
     * All Readable streams begin in paused mode but can be switched to flowing mode in one of the following ways:
     * - Adding a 'data' event handler.
     * - Calling the stream.resume() method.
     * - Calling the stream.pipe() method to send the data to a Writable.
     *
     * Once the stream is paused, no futher `data` or `end` events SHOULD
     * be emitted.
     *
     * You can continue processing events by calling `resume()` again.
     *
     * Note that both methods can be called any number of times, in particular
     * calling `pause()` more than once SHOULD NOT have any effect.
     *
     * from: React\Stream
     *
     * @return void
     */
    function pause();

    /**
     * Resumes reading incoming data events.
     *
     * Re-attach the data source after a previous `pause()`.
     *
     * Note that both methods can be called any number of times, in particular
     * calling `resume()` without a prior `pause()` SHOULD NOT have any effect.
     *
     * from: React\Stream
     *
     * @return void
     */
    function resume();

    /**
     * checks whether the stream is in paused or in flowing mode
     *
     * @param
     *            bool
     */
    function isPaused();

    /**
     * Pipes all the data from this readable source into the given writable destination.
     *
     * Automatically sends all incoming data to the destination.
     * Multiple destinations MAY be added.
     * Automatically throttles the source based on what the destination(s) can handle.
     *
     * Once the pipe is set up successfully, the destination stream MUST emit
     * a `pipe` event with this source stream as event argument.
     * 
     * Options:
     * - end: End the writer(s) when the reader ends (call $dest->end() on $source "end" event). Defaults to true.
     * - close: Close the reader ($source) when all writers ended, closed or errored. Defaults to false.
     * 
     * Attention: Be aware that a ReadableStream is automatically paused, when no more "data" listeners are attached.
     * When all pipes are written successfully and have successfully unpiped, in most cases there is no more
     * "data" listener attached to the ReadableStream and it will pause. 
     * But also true for most ReadableStreams is, that they will close automatically when they have emitted all data.
     *
     * returns the destination stream instance ($dest).
     * 
     * @param \React\Stream\WritableStreamInterface $dest
     * @param array $options
     * @return \React\Stream\WritableStreamInterface $dest stream as-is
     */
    function pipe(ReactWritable $dest, array $options = array());
    
    /**
     * Unpipes a destination from this readdable source and stops writing data to the destination(s)
     * 
     * If $dest == null or not specified all destinations are unpiped.
     *
     * Once the pipe is cancelled successfully, the destination stream MUST emit
     * an `unpipe` event with this source stream as event argument.
     * 
     * Destinations are also automatically unpiped when the $source stream was successfully 
     * piped to the defined destination. Or if an error occurs during the pipe operation.
     * When the last pipe destination is unpiped, the source stream will be paused.
     * 
     * returns the source stream instance (this).
     *
     * @param \React\Stream\WritableStreamInterface $dest
     * @return \React\Stream\ReadableStreamInterface $source stream as-is
     */
    function unpipe(ReactWritable $dest = null);

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
    function close();
}
