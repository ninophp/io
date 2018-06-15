<?php
namespace Nino\Io\Curl;

use Nino\Io\EventEmitter;
use Nino\Io\LoopInterface;
use Nino\Io\Loop;
use Nino\Io\WritableStreamInterface;
use Nino\Io\Curl\Exception as CurlError;

/**
 * WritableStreamInterface implementation for Curl
 */
class WritableStream extends EventEmitter implements WritableStreamInterface
{

    /*
     * Events
     *
     * @event drain // buffer has exceeded limit and was now written to the stream / buffer has been above limit and is now below limit
     * @event error // error during write operation to stream resource
     * @event finish // emitted when end() was called and the buffer was finnaly written and emptied
     * @event close // emitted on calling close
     * @event pipe // emitted on source stream when source.pipe is called, before any stream operation
     */
    
    protected $softLimit;
    
    protected $writeChunkSize;

    protected $handle;

    protected $writable = true;

    protected $listening = false;

    protected $paused = false;

    protected $closed = false;

    protected $data = '';

    /**
     */
    public function __construct($curlOpt, $options = [], $handle = null)
    {
        if ($curlOpt === true)
        {
            $this->listening = true;
        }
        else if ($curlOpt !== null)
        {
            if (!($curlOpt instanceof Options))
            {
                $curlOpt = new Options($curlOpt);
            }
            
            $curlOpt->set([CURLOPT_UPLOAD => 1, CURLOPT_READFUNCTION => function ($ch, $fd, $length)
            {
                return $this->handleData($length);
            }]);
            
            $this->handle = $curlOpt->createHandle($handle);
        }
        
        $this->softLimit = isset($options['softLimit']) ? intval($options['softLimit']) : WritableStreamInterface::SOFT_LIMIT;
        
        $this->writeChunkSize = isset($options['chunkSize']) ? intval($options['chunkSize']) : WritableStreamInterface::WRITE_CHUNK_SIZE;
        
        $this->loop = isset($options['loop']) && $options['loop'] instanceof LoopInterface ? $options['loop'] : Loop::get();
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
    public function isWritable()
    {
        return !$this->closed;
    }
    
    /**
     * 
     * @return void
     */
    protected function pause()
    {
        if(!$this->paused)
        {
            $this->paused = true;
            $this->loop->pauseCurlHandle($this->handle);
        }
    }
    
    /**
     *
     * @return void
     */
    protected function resume()
    {
        if (!$this->listening)
        {
            $this->loop->addCurlHandle($this->handle, function ($result)
            {
                if($this->closed)
                {
                    return;
                }
                
                // handle was already removed by loop -> dont let close remove it again
                $this->handle = null;
                
                if($result['errno'] == 18)
                {
                    // a range was specified but less than the expected bytes were transfered
                    $this->emit('error', new CurlError(18, $result['info']['http_code'], 'Write range not satisfied: '.$result['error']));
                }
                // if aborted forced by calling close => dont emit error
                else if ($result['errno'] > 0)
                {
                    $this->emit('error', [CurlError::fromResultArray($result)]);
                }
                else
                {
                    $this->emit('finish');
                }
                
                $this->close();
            });
            
            $this->listening = true;
        }
        else if ($this->paused)
        {
            $this->loop->resumeCurlHandle($this->handle);
            $this->paused = false;
        }
    }

    /**
     * writes the string $data to the stream
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
    public function write($data)
    {
        if (!$this->writable)
        {
            return false;
        }
        
        if(strlen($data) >0)
        {
            $this->data .= $data;
            $this->resume();
        }

        return ($this->writable && !isset($this->data[$this->softLimit - 1]));
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
            // echo '<br>stream.finish 2';
            $this->emit('finish');
            $this->close();
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
        if ($this->closed)
        {
            return;
        }
        
        $this->closed = true;
        $this->writable = false;
        $this->data = '';
        
        if($this->handle)
        {
            $this->loop->removeCurlHandle($this->handle);
            $this->handle = null;
        }
        
        $this->emit('close');
        $this->removeAllListeners();
    }

    /**
     * internal method used inside CURLOPT_READFUNCTION
     *
     * correspondant to the React handleWrite method
     *
     * @param
     *            in length
     * @return int length
     */
    public function handleData($length)
    {
        if ($this->closed)
        {
            return ''; // CURL_READFUNC_ABORT;
        }
        
        if($this->writeChunkSize > 0)
        {
            $length = min($this->writeChunkSize, $length);
        }
        
        $exceeded = isset($this->data[$this->softLimit - 1]);
        $send = substr($this->data, 0, $length);
        $this->data = substr($this->data, $length);
        
        // buffer has been above limit and is now below limit
        if ($exceeded && !isset($this->data[$this->softLimit - 1]))
        {
            $this->emit('drain');
        }
        
        // we are in writable mode (not ended) 
        // but currently hold no data to upload -> pause curl
        if ($this->writable && strlen($send) < 1)
        {
            // We need to pause AND return CURL_READFUNC_PAUSE
            // as only returning CURL_READFUNC_PAUSE will stop curl upload for whatever reason
            $this->pause(); 
            return CURL_READFUNC_PAUSE;
        }
        else
        {
            // if buffer is empty -> $send === ''
            // curl will close upload when an empty string is returned
            return $send; 
        }
    }
}
