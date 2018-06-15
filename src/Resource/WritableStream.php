<?php
namespace Nino\Io\Resource;

use React\EventLoop\LoopInterface;
use Nino\Io\EventEmitter;
use Nino\Io\Loop;
use Nino\Io\Util;
use Nino\Io\WritableStreamInterface;

/**
 * Defining ranges
 * 
 * @see http://php.net/manual/en/function.fseek.php
 * In general, it is allowed to seek past the end-of-file; 
 * if data is then written, reads in any unwritten region between the end-of-file and the sought position 
 * will yield bytes with value 0. However, certain streams may not support this behavior, 
 * especially when they have an underlying fixed size storage. 
 * 
 *
 */
class WritableStream extends EventEmitter implements WritableStreamInterface
{

    protected $stream;

    protected $loop;

    /**
     * specifies the number of bytes to be buffered in-memory until
     * the write method returns false.
     *
     * soft-limit means that additional writes SHOULD be stopped but if they occur,
     * they will also be buffered in memory
     */
    protected $softLimit;
    
    /**
     * specifies the maximum number of bytes written to the underlying stream resource at once
     * A value of -1 means that all bytes currently buffered are written.
     */
    protected $writeChunkSize;

    protected $listening = false;

    protected $writable = true;

    protected $closed = false;

    protected $data = '';

    protected $autoClose = true;
    
    protected $lock = 0;

    protected $position = 0;

    protected $rangeEnd;

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
     * options:
     * - chunkSize
     * - softLimit
     * - loop
     * - range
     * - autoClose
     * - size - to calculate the ranges based on fileSize
     * - lock - locks resource prior to writing with an exclusive lock (LOCK_EX) and releases lock on finnish, close, failure or __destruct (see flock)
     * - unlock - unlocks the resource if "something" happened so the lock was not released
     * 
     * @param resource $stream
     * @param array $options
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function __construct($stream, $options = [])
    {
        if (!is_resource($stream) || get_resource_type($stream) !== "stream")
        {
            throw new \InvalidArgumentException('First parameter must be a valid stream resource');
        }
        
        $meta = stream_get_meta_data($stream);
        
        // $writable = in_array($mode[0], array('w','x','c','a')) || ($mode[0] == 'r' && isset($mode[1]) && ($mode[1] == '+' || $mode[1] == 'w'));
        if (isset($meta['mode']) && $meta['mode'] !== '' && strtr($meta['mode'], 'waxc+', '.....') === $meta['mode'])
        {
            throw new \InvalidArgumentException('Given stream resource is not opened in write mode');
        }
        
        // this class relies on non-blocking I/O in order to not interrupt the event loop
        // e.g. pipes on Windows do not support this: https://bugs.php.net/bug.php?id=47918
        if (stream_set_blocking($stream, 0) !== true)
        {
            throw new \RuntimeException('Unable to set stream resource to non-blocking mode');
        }
        
        $this->stream = $stream;
        $this->loop = isset($options['loop']) && $options['loop'] instanceof LoopInterface ? $options['loop'] : Loop::get();
        
        $this->softLimit = isset($options['softLimit']) ? intval($options['softLimit']) : WritableStreamInterface::SOFT_LIMIT;
        
        $this->writeChunkSize = isset($options['chunkSize']) ? intval($options['chunkSize']) : WritableStreamInterface::WRITE_CHUNK_SIZE;
        
        // autoClose
        if (isset($options['autoClose']))
        {
            $this->autoClose = !!$options['autoClose'];
        }
        
        // unlock potientially locked resource
        if (isset($options['unlock']))
        {
            @flock($stream, LOCK_UN);
        }
        
        // lock
        if (isset($options['lock']))
        {
            $this->lock = $options['lock'] ? 1 : 0;
        }
        
        if (isset($options['range']))
        {
            $size = isset($options['size']) ? $options['size'] : Util::getStreamSize($stream);
            $range = Util::parseRange($options['range'], $size, true); // true = allowAppend
            
            if ($range === null)
            {
                if (is_array($options['range']))
                {
                    $options['range'] = implode('-', $options['range']);
                }
                throw new \InvalidArgumentException('Invalid range (' . $options['range'] . ').');
            }
            else if ($range[0] === null)
            {
                throw new \InvalidArgumentException('Invalid range. Cannot calculate start position as either ' . 'a start position or the stream size is not defined.');
            }
            else if ((!$meta['seekable'] && $range[0] > 0) || (@fseek($stream, $range[0], SEEK_SET) < 0))
            {
                throw new \InvalidArgumentException('Stream is not seekable. Cannot satisfy range option.');
            }
            
            if ($range[1] !== null)
                $range[1]++; // range definition is inclusive concerning the last byte
            
            $this->position = $range[0];
            $this->rangeEnd = $range[1];
        }
    }

    /**
     * Checks whether this stream is in a writable state.
     *
     * This method can be used to check if the stream still accepts writing
     * any data or if it is ended or closed already.
     * Writing any data to a non-writable stream is a NO-OP:
     *
     * ```php
     * assert($stream->isWritable() === false);
     *
     * $stream->write('end'); // NO-OP
     * $stream->end('end'); // NO-OP
     * ```
     *
     * A successfully opened stream always starts in writable mode.
     *
     * Once the stream ends or closes, it switches to non-writable mode.
     * This can happen any time, explicitly through `end()` or `close()` or
     * implicitly due to a remote close or an unrecoverable transmission error.
     * 
     * Once a stream has switched to non-writable mode, it will not transition
     * back to writable mode, except the `autoClose` was set to false.
     * 
     * If `autoClose` was set to false, calling `end()` will switch the stream to
     * non-writable mode. Once all data from the buffer has been 
     * flushed (i.e. written to the underlying resource), the stream will 
     * switch back to writable-mode (and not close).
     * 
     * With `autoClose` set to false, you can listen to the `finish` event, 
     * which is emitted, everytime the buffer was finally flushed and the stream
     * returns back to writable mode.
     * 
     * Keep in mind, that seeking a writable stream is only possible
     * when the stream is writable and the buffer is empty. I.e. either no
     * data was written to the stream yet or the buffer was flushed and
     * the `finish` event was emitted.
     *
     * @return bool
     */
    public function isWritable()
    {
        return $this->writable;
    }

    /**
     * Write some data into the stream.
     *
     * A successful write is confirmed with a boolean `true`, which means
     * that either the data was written (flushed) immediately or is buffered and
     * scheduled for a future write.
     *
     * If a stream cannot handle writing (or flushing) the data, it emits
     * an `error` event and may `close()` the stream if it can not recover from
     * this error.
     *
     * If the internal buffer is full after adding `$data`, then `write()`
     * will return `false`, indicating that the caller should stop sending
     * data until the buffer drains.
     * The stream sends a `drain` event once the buffer is ready to accept
     * more data.
     *
     * Similarly, if the the stream is not writable (already in a closed state)
     * it will not process the given `$data` and return `false`,
     * indicating that the caller should stop sending data.
     *
     * @param mixed|string $data
     * @return bool
     */
    public function write($data)
    {
        if (!$this->writable)
        {
            return false;
        }
        
        $length = strlen($data);
        
        if ($this->rangeEnd !== null && $this->position + $length > $this->rangeEnd)
        {
            $length = $this->rangeEnd - $this->position;
            $this->data .= substr($data, 0, $length);
            
            $this->end();
        }
        else
        {
            $this->data .= $data;
        }
        
        $this->position += $length;
        
        if (!$this->listening && $this->data !== '' && $this->lock()) // lock calls close on error -> writable = false
        {
            $this->listening = true;
            $this->loop->addWriteStream($this->stream, array($this,'handleData'));
        }
        
        return ($this->writable && !isset($this->data[$this->softLimit - 1]));
    }

    /**
     * Successfully ends the stream (after optionally sending some final data).
     *
     * This method can be used to successfully end the stream, i.e. close
     * the stream after sending out all data that is currently buffered.
     *
     * If there's no data currently buffered and nothing to be flushed, then
     * this method may `close()` the stream immediately.
     *
     * If there's still data in the buffer that needs to be flushed first, then
     * this method will try to write out this data and only then `close()`
     * the stream.
     * Once the stream is closed, it emits a `close` event.
     *
     * You can optionally pass some final data that is written to the stream
     * before ending the stream. If a non-`null` value is given as `$data`, then
     * this method will behave just like calling `write($data)` before ending
     * with no data.`
     *
     * After calling this method, the stream switches into a non-writable
     * mode, see also `isWritable()`.
     * This means that no further writes are possible, so any additional
     * `write()` or `end()` calls have no effect.
     * 
     * The only exception is: if the `autoClose` option was set to false,
     * the stream will not close (and not emit a `close` event) when
     * the buffer was flushed, but it will return back into a writable
     * state.
     *
     * @param mixed|string|null $data
     * @return void
     */
    public function end($data = null)
    {
        if ($data !== null)
        {
            $this->write($data);
        }
        
        $this->writable = false;
        
        // close immediately if buffer is already empty
        // otherwise wait for buffer to flush first
        if ($this->data === '')
        {
            if ($this->autoClose)
            {
                $this->emit('finish');
                $this->close();
            }
            else
            {
                $this->writable = true;
                $this->unlock();
                $this->emit('finish');
            }
        }
    }

    /**
     * Closes the stream (forcefully).
     *
     * This method can be used to forcefully close the stream, i.e. close
     * the stream without waiting for any buffered data to be flushed.
     * If there's still data in the buffer, this data will be discarded.
     *
     * Once the stream is closed, it will emit a `close` event.
     * Note that this event will not be emitted more than once, in particular
     * if this method is called multiple times.
     *
     * After calling this method, the stream switches into a non-writable
     * mode, see also `isWritable()`.
     * This means that no further writes are possible, so any additional
     * `write()` or `end()` calls have no effect.
     *
     * Note that this method should not be confused with the `end()` method.
     * Unlike the `end()` method, this method does not take care of any existing
     * buffers and simply discards any buffer contents.
     *
     * @return void
     * @see \React\Stream\ReadableStreamInterface::close()
     */
    public function close()
    {
        if ($this->closed)
        {
            return;
        }
        
        if ($this->listening)
        {
            $this->listening = false;
            $this->loop->removeWriteStream($this->stream);
        }
        
        $this->closed = true;
        $this->writable = false;
        $this->data = '';
        
        $this->emit('close');
        
        $this->removeAllListeners();
        
        if (is_resource($this->stream))
        {
            $this->unlock();
            fclose($this->stream);
            $this->stream = null;
        }
    }

    /**
     */
    public function handleData()
    {
        $error = null;
        set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$error)
        {
            $error = ['message' => $errstr,'number' => $errno,'file' => $errfile,'line' => $errline];
        });
        
        if ($this->writeChunkSize === -1)
        {
            $sent = fwrite($this->stream, $this->data);
        }
        else
        {
            $sent = fwrite($this->stream, $this->data, $this->writeChunkSize);
        }
        
        restore_error_handler();
        
        // Only report errors if *nothing* could be sent.
        // Any hard (permanent) error will fail to send any data at all.
        // Sending excessive amounts of data will only flush *some* data and then
        // report a temporary error (EAGAIN) which we do not raise here in order
        // to keep the stream open for further tries to write.
        // Should this turn out to be a permanent error later, it will eventually
        // send *nothing* and we can detect this.
        if ($sent === 0 || $sent === false)
        {
            if ($error !== null)
            {
                $error = new \ErrorException($error['message'], 0, $error['number'], $error['file'], $error['line']);
            }
            
            $this->emit('error', [new \RuntimeException('Unable to write to stream: ' . ($error !== null ? $error->getMessage() : 'Unknown error'), 0, $error)]);
            
            $this->close();
            
            return;
        }
        
        $exceeded = isset($this->data[$this->softLimit - 1]);
        $this->data = (string) substr($this->data, $sent);
        
        // buffer has been above limit and is now below limit
        if ($exceeded && !isset($this->data[$this->softLimit - 1]))
        {
            $this->emit('drain');
        }
        
        // buffer is now completely empty => stop trying to write
        if ($this->data === '')
        {
            // stop waiting for resource to be writable
            if ($this->listening)
            {
                $this->loop->removeWriteStream($this->stream);
                $this->listening = false;
            }
            
            // buffer is end()ing and now completely empty => close buffer
            if (!$this->writable)
            {
                if ($this->autoClose)
                {
                    $this->emit('finish'); // before close
                    $this->close();
                }
                else
                {
                    $this->writable = true;
                    $this->unlock();
                    $this->emit('finish'); // after writable was reset to true
                }
            }
        }
    }

    /**
     * 
     */
    public function __destruct()
    {
        if ($this->stream)
        {
            $this->close();
        }
    }
    
    /**
     * 
     */
    protected function lock()
    {
        if ($this->stream && $this->lock == 1)
        {
            $error = false;
            $flock = @flock($this->stream, LOCK_EX | LOCK_NB, $wouldblock);
            
            if($wouldblock)
            {
                $error = new \RuntimeException('Resource is locked by another stream.');
            }
            else if(!$flock)
            {
                $error = new \RuntimeException('Resource could not be locked.');
            }
            else 
            {
                $this->lock = 2;
            }
            
            if($error)
            {
                $this->emit('error', [$error]);
                $this->close();
                return false;
            }
        }
        return true;
    }
    
    /**
     *
     */
    protected function unlock()
    {
        if ($this->stream && $this->lock == 2)
        {
            $flock = @flock($this->stream, LOCK_UN);
            $this->lock = $flock ? 1 : 2;
            return $flock;
        }
        return false;
    }
}