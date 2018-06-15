<?php
namespace Nino\Io\Util;

use Nino\Io\ThroughStream;

/**
 * RangeStream
 * 
 * reads defined range bytes from the data passed
 * and then closes the stream
 */
class LengthLimitedStream extends ThroughStream
{
    protected $maxLength;
    protected $bytesRead = 0;
    protected $allowUnderflow;
    
    /**
     * 
     * @param array $range
     */
    public function __construct($maxLength, $allowUnderflow = false)
    {
        $this->maxLength = $maxLength;
        $this->allowUnderflow = $allowUnderflow;
    }
 
    /**
     * 
     * @param string $data
     */
    protected function transform($data, $end = false)
    {
        $length = strlen($data);
        
        if($this->bytesRead + $length > $this->maxLength)
        {
            $length = $this->maxLength - $this->bytesRead;
            $data = substr($data, 0, $length);
        }
        
        if($length > 0)
        {
            $this->bytesRead += $length;
            $this->push($data);
        }
        
        if ($this->bytesRead >= $this->maxLength) 
        {
            $this->finish();
        }
        else if($end && !$this->allowUnderflow)
        {
            throw new \RangeException('LengthLimitedStream: underlying stream ended prior to expected '.$this->maxLength.' bytes.');
        }
    }
}