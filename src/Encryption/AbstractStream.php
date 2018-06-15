<?php
namespace Nino\Io\Encryption;

use Nino\Io\ThroughStream;

/**
 */
abstract class AbstractStream extends ThroughStream
{

    protected $key;

    protected $iv = null;

    protected $initialIv = null;

    /**
     *
     * @param string $key
     */
    function __construct($key)
    {
        $this->key = md5($key);
    }

    /**
     * returns the initial iv (first 16 bytes of the file)
     * before it was incremented
     */
    public function getInitialIv()
    {
        return $this->initialIv;
    }

    /**
     * returns the current iv that might my incremented due to the decrypt state
     */
    public function getIv()
    {
        return $this->iv;
    }

    /**
     * resets the initial and the current iv to $iv
     */
    public function setIv($iv, $offset = 0)
    {
        if($offset)
        {
            $iv = self::increaseIv($iv, $offset);
        }
        $this->initialIv = $this->iv = $iv;
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
        return $data;
    }

    /**
     * BIG ENDIAN CTR
     */
    protected function crypt($string)
    {
        $numOfBlocks = ceil(strlen($string) / 16);
        
        $ctrStr = $this->iv;
        
        for ($i = 0; $i < $numOfBlocks; ++$i)
        {
            $ctrStr .= $this->iv = self::increaseIv($this->iv);
        }
        
        return $string ^ openssl_encrypt($ctrStr, 'aes-256-ecb', $this->key, OPENSSL_RAW_DATA);
    }

    /**
     */
    public static function splitCtrBlocks($data)
    {
        $n = strlen($data);
        
        if ($n >= 16)
        {
            $n = $n - $n % 16;
            return [substr($data, 0, $n),substr($data, $n)];
        }
        
        return ['',$data];
    }

    /**
     * internal function: ctr iv counter function (big endian)
     */
    public static function increaseIv($iv, $offset = 1)
    {
        for ($i = strlen($iv) - 1; $i >= 0 && $offset > 0; $i--, $offset >>= 8)
        {
            $iv[$i] = chr((ord($iv[$i]) + $offset) & 255);
        }
        
        return $iv;
    }
}