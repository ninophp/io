<?php
namespace Nino\Io\Util;

use Nino\Io\ThroughStream;

/**
 * RangeStream
 * 
 * reads defined range bytes from the data passed
 * and then closes the stream
 */
class RangeStream extends ThroughStream
{
    protected $rangeStart;
    protected $rangeEnd;
    protected $bytesRead = 0;
    
    /**
     * 
     * @param array $range
     */
    public function __construct($rangeStart, $rangeEnd = null)
    {
        if(is_array($rangeStart))
        {
            $rangeStart = isset($rangeStart[0]) ? $rangeStart[0] : null;
            $rangeEnd   = isset($rangeStart[1]) ? $rangeStart[1] : null;
        }
        
        switch(true)
        {
            case($rangeStart=== null):
            case($rangeStart < 0):
            case($rangeEnd !== null && $rangeEnd >=0 && $rangeEnd < $rangeStart):
                throw new \InvalidArgumentException('Range "'.$rangeStart.'-'.$rangeEnd.'" passed to RangeStream is not valid.');
        }
        
        $this->rangeStart = $rangeStart;
        
        // range bytes are inclusive
        $this->rangeEnd = !$rangeEnd ? 0 : $rangeEnd+1;
    }
    
    /**
     *
     * @see \Nino\Io\ThroughStream::transform
     * {@inheritdoc}
     */
    protected function transform($data, $end = false)
    {
        if($this->rangeEnd <0)
        {
            return;
        }
        
        $length = strlen($data);
        $this->bytesRead += $length;
        
        if($this->rangeStart >= 0)
        {
            if($this->bytesRead >= $this->rangeStart)
            {
                $index = $length - $this->bytesRead + $this->rangeStart;
                $data = substr($data, $index);
                
                $this->rangeStart = -1;
                $length -= $index;
            }
            else 
            {
                return; # skip
            }
        }
        
        if($this->rangeEnd >0 && $this->bytesRead >= $this->rangeEnd)
        {
            $data = substr($data, 0, $length - $this->bytesRead + $this->rangeEnd);
            $this->rangeEnd = -1;
        }
        
        if(strlen($data) >0)
        {
            $this->push($data);
        }
        
        if($this->rangeEnd <0)
        {
            $this->finish();
        }
    }
}