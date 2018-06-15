<?php
namespace Nino\Io\Curl;

use React\Stream\WritableStreamInterface as ReactWritable;
use Nino\Io\EventEmitter;
use Nino\Io\LoopInterface;
use Nino\Io\Loop;
use Nino\Io\Util;
use Nino\Io\ReadableStreamInterface;
use Nino\Io\WritableStreamInterface;
use Nino\Io\Curl\Exception as CurlError;

/**
 * IReadableStream implementation for Curl
 */
class ReadableStream extends EventEmitter implements ReadableStreamInterface
{

    const CURL_IDLE = 1;

    const CURL_LISTENING = 2;

    const CURL_PAUSED = 3;

    const CURL_COMPLETED = 4;
    
    const CURL_ABORTED = 5;

    /**
     * specifies the number of bytes to be buffered in-memory until
     * the curl is advised to pause fetching further data.
     * 
     * Beware if setting this limit to low, the curl 
     * request could time out before all data has been emitted
     */
    protected $softLimit; #16384 2097152

    // 2097152 = 2mb // 327680 = 65536 * 5 = 320 kb
    
    /**
     * Controls the maximum buffer size in bytes to emit at once via a data event.
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
    protected $readChunkSize; # 65536

    protected $handle;

    protected $loop;

    protected $closed = false;

    protected $paused = true;

    protected $curlStatus = self::CURL_IDLE;

    protected $data = '';

    /**
     */
    public function __construct($curlOpt, $options = [], $handle=null)
    {
        // used by Guzzle
        if (is_resource($curlOpt))
        {
            $this->curlStatus = self::CURL_LISTENING;
            $this->paused = false;
            $this->handle = $curlOpt;
            
        } // may be instanciated by superior class using "handleData" directly
        else if ($curlOpt !== null)
        {
            if (!($curlOpt instanceof Options))
            {
                $curlOpt = new Options($curlOpt);
            }
            
            $curlOpt->set([CURLOPT_RETURNTRANSFER => 1,CURLOPT_WRITEFUNCTION => function ($ch, $data)
            {
                return $this->handleData($data);
            }]);
            
            $this->handle = $curlOpt->createHandle($handle);
        }
        
        $this->softLimit = isset($options['softLimit']) ? intval($options['softLimit']) : WritableStreamInterface::SOFT_LIMIT;
        
        $this->readChunkSize = isset($options['chunkSize']) ? intval($options['chunkSize']) : ReadableStreamInterface::READ_CHUNK_SIZE;
        
        $this->loop = isset($options['loop']) && $options['loop'] instanceof LoopInterface ? $options['loop'] : Loop::get();
    }

    /**
     */
    private function listenNextTick()
    {
        $this->loop->futureTick(function ()
        {
            if(!$this->closed)
            {
                $this->handleData();
            }
        });
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
        return !$this->closed;
    }

    /**
     * returns true if the stream is paused
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
        if (!$this->paused && !$this->closed)
        {
            $this->paused = true;
            
            if($this->curlStatus == self::CURL_LISTENING)
            {
                $this->loop->pauseCurlHandle($this->handle);
                $this->curlStatus = self::CURL_PAUSED;
            }
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
        if ($this->paused && !$this->closed)
        {
            $this->paused = false;
            
            switch($this->curlStatus)
            {
                case(self::CURL_COMPLETED):
                    $this->listenNextTick();
                    break;
                    
                case(self::CURL_PAUSED):
                    $this->curlStatus = self::CURL_LISTENING;
                    $this->loop->resumeCurlHandle($this->handle);
                    break;
                  
                case(self::CURL_IDLE):
                    $this->curlStatus = self::CURL_LISTENING;
                    $this->loop->addCurlHandle($this->handle, function ($result)
                    {
                        if($this->curlStatus == self::CURL_ABORTED)
                        {
                            return;
                        }
                        
                        $this->curlStatus = self::CURL_COMPLETED;
                        $this->handle = null; # already removed by loop
                        
                        if ($result['errno'] > 0)
                        {
                            $this->emit('error', [CurlError::fromResultArray($result)]);
                            $this->close();
                        }
                        else
                        {
                            $this->handleData(); // end
                        }
                    });
            }
        }
    }

    /**
     * emits data from the internal buffer via data event 
     * 
     * returns true if there is still data in the buffer and false if not
     *
     * @return bool
     */
    protected function emitData()
    {
        if ($this->data === '')
        {
            return false;
        }
        
        if ($this->readChunkSize === null || $this->readChunkSize < 0)
        {
            $send = $this->data;
            $this->data = '';
        }
        else
        {
            $send = substr($this->data, 0, $this->readChunkSize);
            $this->data = substr($this->data, $this->readChunkSize);
        }
        
        if(strlen($send) >0)
        {
            $this->emit('data', [$send]);
        }
        
        return strlen($this->data) >0;
    }

    /**
     * pipe
     *
     * @return \React\Stream\WritableStreamInterface
     */
    public function pipe(ReactWritable $dest, array $options = array())
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
     * Closes the stream when the destructed
     *
     * from: Psr\Stream
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     */
    public function handleData($data = '')
    {
        // if stream was forcefully closed
        if ($this->closed)
        {
            $this->curlStatus = self::CURL_ABORTED;
            return 0; // abort
        }
        
        if ($data !== '')
        {
            $this->data .= $data;
        }
        
        $listenNextTick = false;
        $return = 0;
        
        // dont emit data events when stream is paused
        if (!$this->paused)
        {
            $bufferLeft = $this->emitData();
            
            // if internal buffer is empty and curl has completed
            if(!$bufferLeft && $this->curlStatus == self::CURL_COMPLETED)
            {
                // we reached the end and close the stream
                $this->emit('end');
                $this->close();
                return;
            }
            
            // if curl is completed or paused
            // AND we still have internal buffer -> loop and drain
            if($bufferLeft && $this->curlStatus != self::CURL_LISTENING)
            {
                $listenNextTick = true;
            }
        }
        
        // handle curl stream: we are not completed yet
        if ($this->curlStatus != self::CURL_COMPLETED)
        {
            $exceed = isset($this->data[$this->softLimit - 1]);
            $return = strlen($data);
            
            // if internal buffer is reached and curl is listening => pause curl download
            if($exceed && $this->curlStatus == self::CURL_LISTENING)
            {
                $this->curlStatus = self::CURL_PAUSED;
                $this->loop->pauseCurlHandle($this->handle);
                
                // now curl is paused, but the stream might be not
                // => keep emitting data and checking internal buffer
                $listenNextTick = !$this->paused;
                $return = CURL_WRITEFUNC_PAUSE;
            }
            
            // if buffer is drained and curl is paused => resume curl download
            if(!$exceed && $this->curlStatus == self::CURL_PAUSED)
            {
                $this->curlStatus = self::CURL_LISTENING;
                $this->loop->resumeCurlHandle($this->handle);
                $listenNextTick = false;
            }
        }
        
        if ($listenNextTick)
        {
            $this->listenNextTick();
        }
        
        return $return;
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
     * @return void
     */
    public function close()
    {
        if ($this->closed)
        {
            return;
        }
        
        // dont remove curl handle here -> loop will hang
        // let handeData return 0 to abort curl operation
        
        $this->closed = $this->paused = true;
        $this->data = '';
        $this->handle = null;
        
        $this->emit('close');
        $this->removeAllListeners();
    }
}
