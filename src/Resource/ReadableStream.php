<?php
namespace Nino\Io\Resource;

use React\EventLoop\LoopInterface;
use React\Stream\WritableStreamInterface;
use React\Stream\WritableStreamInterface as ReactWritable;
use Nino\Io\EventEmitter;
use Nino\Io\Util;
use Nino\Io\Loop;
use Nino\Io\ReadableStreamInterface;

/**
 */
class ReadableStream extends EventEmitter implements ReadableStreamInterface
{
    /**
     *
     * @var resource
     */
    protected $stream;

    protected $loop;

    /**
     * Controls the maximum buffer size in bytes to read at once from the stream.
     *
     * This value SHOULD NOT be changed unless you know what you're doing.
     *
     * This can be a positive number which means that up to X bytes will be read
     * at once from the underlying stream resource. Note that the actual number
     * of bytes read may be lower if the stream resource has less than X bytes
     * currently available.
     *
     * This can be `-1` which means read everything available from the
     * underlying stream resource.
     * This should read until the stream resource is not readable anymore
     * (i.e. underlying buffer drained), note that this does not neccessarily
     * mean it reached EOF.
     *
     * @var int
     */
    protected $readChunkSize; // 65536 # 64kb # 262144 # 256kb
    
    protected $closed = false;
    
    protected $paused = true;

    protected $autoClose = true;
    
    protected $lock = 0;

    protected $position = 0;

    protected $rangeEnd;

    /*
     * ReadableStreamInterface
     *
     * @event data // emitted when data was read from stream resource. data is passed to listener and NOT handled any further
     * @event error // emitted when an error occured reading from the underlying stream resource
     * @event end // emitted when all data was read. Then triggers close. If data was fully read end and close are emitted.
     * @event close // emitted on calling close (if closed forcefully only close will be emitted)
     */
    
    /**
     * options:
     * - range: defines a read range 
     * - size
     * - readChunkSize
     * - loop
     * - autoClose
     * - lock - locks resource prior to reading with a shared lock (LOCK_SH) and releases lock on end, close, failure or __destruct (see flock)
     * 
     * @see Nino\Io\parseRange()
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
        
        // ensure resource is opened for reading (fopen mode must contain "r" or "+")
        $meta = stream_get_meta_data($stream);
        
        // $this->readable = $mode[0] == 'r' || ( in_array($mode[0], array('w','x','c','a')) && isset($mode[1]) && $mode[1] == '+');
        if (isset($meta['mode']) && $meta['mode'] !== '' && strpos($meta['mode'], 'r') === strpos($meta['mode'], '+'))
        {
            throw new \InvalidArgumentException('Given stream resource is not opened in read mode');
        }
        
        // this class relies on non-blocking I/O in order to not interrupt the event loop
        // e.g. pipes on Windows do not support this: https://bugs.php.net/bug.php?id=47918
        if (stream_set_blocking($stream, 0) !== true)
        {
            throw new \RuntimeException('Unable to set stream resource to non-blocking mode');
        }
        
        // Use unbuffered read operations on the underlying stream resource.
        // Reading chunks from the stream may otherwise leave unread bytes in
        // PHP's stream buffers which some event loop implementations do not
        // trigger events on (edge triggered).
        // This does not affect the default event loop implementation (level
        // triggered), so we can ignore platforms not supporting this (HHVM).
        // Pipe streams (such as STDIN) do not seem to require this and legacy
        // PHP versions cause SEGFAULTs on unbuffered pipe streams, so skip this.
        if (function_exists('stream_set_read_buffer') && !self::isLegacyPipe($meta)) 
        {
            stream_set_read_buffer($stream, 0);
        }
        
        $this->stream = $stream;
        $this->loop = isset($options['loop']) && $options['loop'] instanceof LoopInterface ? $options['loop'] : Loop::get();
        
        $this->readChunkSize = isset($options['chunkSize']) ? intval($options['chunkSize']) : ReadableStreamInterface::READ_CHUNK_SIZE;
        
        // autoClose
        if (isset($options['autoClose']))
        {
            $this->autoClose = !!$options['autoClose'];
        }
        
        // lock
        if (isset($options['lock']))
        {
            $this->lock = $options['lock'] ? 1 : 0;
        }
        
        // parse range
        if (isset($options['range']))
        {
            $size  = isset($options['size']) ? $options['size'] : Util::getStreamSize($this->stream);
            $range = Util::parseRange($options['range'], $size);
            
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
                throw new \InvalidArgumentException('Invalid range. Cannot calculate start position as either ' . 'the start position or the stream size is not defined.');
            }
            else if ((!$meta['seekable'] && $range[0] > 0) || (fseek($stream, $range[0], SEEK_SET) < 0))
            {
                throw new \InvalidArgumentException('Stream is not seekable. Cannot satisfy range option.');
            }
            
            if ($range[1] !== null)
                $range[1]++; // range definition is inclusive concerning the last byte
            
            $this->position = $range[0];
            $this->rangeEnd = $range[1];
        }
        
        // React stream auto resumes => we do not! We stick to nodejs behavior:
        // Readable streams effectively operate in one of two modes: flowing and paused.
        // All Readable streams begin in paused mode but can be switched to flowing mode in one of the following ways:
        // - Adding a 'data' event handler.
        // - Calling the stream.resume() method.
        // - Calling the stream.pipe() method to send the data to a Writable.
        // $this->resume();
    }

    /**
     */
    public function isPaused()
    {
        return $this->closed || $this->paused;
    }

    /**
     */
    public function isReadable()
    {
        return !$this->closed;
    }

    /**
     * Pauses reading incoming data events.
     *
     * Removes the data source file descriptor from the event loop. This
     * allows you to throttle incoming data.
     *
     * Once the stream is paused, no futher `data` or `end` events are emitted.
     */
    public function pause()
    {
        if (!$this->closed && !$this->paused)
        {
            // we do not unlock the resource on every pause as it can be several times during piping
            // we unlock on end or close
            $this->loop->removeReadStream($this->stream);
            $this->paused = true;
        }
    }

    /**
     * Resumes reading incoming data events.
     *
     * Re-attach the data source after a previous `pause()`.
     *
     * Note that both methods can be called any number of times, in particular
     * calling `resume()` without a prior `pause()` SHOULD NOT have any effect.
     */
    public function resume()
    {
        if (!$this->closed && $this->paused)
        {
            $this->lock(); // tries to lock file only if it is not already locked
            $this->loop->addReadStream($this->stream, [$this,'handleData']);
            $this->paused = false;
        }
    }

    /**
     * Pipes all the data from this readable source into the given writable destination.
     *
     * Automatically sends all incoming data to the destination.
     * Automatically throttles the source based on what the destination can handle.
     *
     * Once the pipe is set up successfully, the destination stream MUST emit
     * a `pipe` event with this source stream as an event argument.
     *
     * @param WritableStreamInterface $dest
     * @param array $options
     * @return WritableStreamInterface $dest stream as-is
     */
    public function pipe(ReactWritable $dest, array $options = [])
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
     * Closes the stream (forcefully).
     *
     * This method can be used to (forcefully) close the stream.
     *
     * Once the stream is closed, it emits a `close` event.
     * Note that this event SHOULD NOT be emitted more than once, in particular
     * if this method is called multiple times.
     *
     * After calling this method, the stream switches into a non-readable
     * mode, see also `isReadable()`.
     * This means that no further `data` or `end` events are emitted.
     *
     * @return void
     */
    public function close()
    {
        if ($this->closed)
        {
            return;
        }
        
        $this->closed = $this->paused = true;
        
        $this->emit('close');
        $this->loop->removeReadStream($this->stream);
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
        $buffer = $this->readChunkSize;
        
        if ($this->rangeEnd !== null && ($buffer === null || $buffer == -1 || $this->position + $buffer > $this->rangeEnd))
        {
            $buffer = $this->rangeEnd - $this->position;
        }
        
        $error = null;
        set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$error)
        {
            $error = new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        });
        
        $data = stream_get_contents($this->stream, $buffer);
        
        restore_error_handler();
        
        if ($error !== null)
        {
            $this->emit('error', [new \RuntimeException('Unable to read from stream: ' . $error->getMessage(), 0, $error)]);
            $this->close();
            return;
        }
        
        $this->position += strlen($data);
        
        if ($data !== '')
        {
            $this->emit('data', [$data]);
        }

        // if we have no more data OR have reached the end of the defined range
        if ($data === '' || ($this->rangeEnd !== null && $this->position >= $this->rangeEnd))
        {
            // no data read => we reached the end and close the stream
            $this->emit('end');
            
            if ($this->autoClose)
            {
                $this->close();
            }
            else
            {
                $this->unlock();
                $this->pause();
            }
        }
    }

    /**
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
            $flock = @flock($this->stream, LOCK_SH | LOCK_NB, $wouldblock);
            
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
    
    /**
     * Returns whether this is a pipe resource in a legacy environment
     *
     * This works around a legacy PHP bug (#61019) that was fixed in PHP 5.4.28+
     * and PHP 5.5.12+ and newer.
     *
     * @param resource $resource
     * @return bool
     * @link https://github.com/reactphp/child-process/issues/40
     *
     * @codeCoverageIgnore
     */
    private static function isLegacyPipe($streamMeta)
    {
        if (PHP_VERSION_ID < 50428 || (PHP_VERSION_ID >= 50500 && PHP_VERSION_ID < 50512)) 
        {
            return (isset($streamMeta['stream_type']) && $streamMeta['stream_type'] === 'STDIO');
        }
        return false;
    }
}