<?php
namespace Nino\Io\Encryption;

use Nino\Io\ThroughStream;

/**
 */
class HashStream extends ThroughStream
{

    protected $hash;

    protected $final;

    /*
     * HashStream
     *
     * @event hash // emitted when all data passed and a final hash was calculated,
     */
    
    /**
     * creates a HashStream instance
     *
     * that calculates a hash on the passed data
     *
     * @see http://php.net/manual/en/function.hash-init.php
     *
     *
     * @param string $algo
     * @param int $options
     * @param string $key
     */
    function __construct($algo = 'md5', $options = 0, $key = null)
    {
        $this->hash = \hash_init($algo, $options, $key);
    }

    /**
     * returns the hash of the data handled by this Throughstream
     * 
     * One can retrieve the current hash value during the stream
     * operation or the final hash value when the stream has ended.
     * 
     * Listen for stream end to call getHash to get the final hash
     * or listen to the stream's hash event directly which gets
     * passed the final hash value as only parameter.
     * 
     * @return string
     */
    public function getHash()
    {
        if ($this->final)
        {
            return $this->final;
        }
        else
        {
            $hash = $this->hash;
            $this->hash = \hash_copy($hash);
            return \hash_final($hash, false);
        }
    }

    /**
     */
    private function finalize()
    {
        if (!$this->final)
        {
            $this->final = \hash_final($this->hash, false);
            $this->hash = null;
        }
        return $this->final;
    }

    /**
     * transform function
     *
     * All data written to the stream via write or end will be buffered.
     *
     * Everytime new data is written to the buffer the transform function
     * is called and the whole buffer is passed.
     *
     * The transform function then can work on the buffer.
     * Transformed data can be passed on by calling the ´push´ method.
     * It will then be emitted further down the pipe by emitting a "data" event.
     *
     * Returning nothing, i.e. null or an empty string will leave the buffer empty.
     *
     * Throwing an exception inside the transform function will emit the
     * exception as error event and close the stream.
     *
     * @param string $data
     *            the buffer
     * @param int $end
     *            indicating if "end" and not "write" was called, i.e. it is the last write to this stream
     */
    protected function transform($data, $end = false)
    {
        \hash_update($this->hash, $data);
        
        if ($end)
        {
            $this->emit('hash', [$this->finalize()]);
        }
        
        // push data further down the pipe
        $this->push($data);
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
        $this->finalize();
        parent::close();
    }
}