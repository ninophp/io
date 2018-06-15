<?php
namespace Nino\Io\Resource;

/**
 */
trait SeekableStreamTrait
{

    protected $_seekable;

    /**
     * returns true if the stream is seekable otherwise false
     *
     * from: Psr\Stream
     *
     * @return bool
     */
    public function isSeekable()
    {
        # stream is set to null on close -> !stream is also closed=true
        if (!$this->stream) 
        {
            return false;
        }
        
        if ($this->_seekable === null)
        {
            $meta = stream_get_meta_data($this->stream);
            
            $this->_seekable = !!$meta['seekable'];
        }
        
        return $this->_seekable;
    }

    /**
     * seeks inside the stream to position $offset
     *
     * from: Psr\Stream
     *
     * @return void
     */
    public function seek($offset, $whence = SEEK_SET)
    {
        if (!$this->isSeekable())
        {
            throw new \RuntimeException('Stream is not seekable');
        }
        else if (fseek($this->stream, $offset, $whence) === -1)
        {
            throw new \RuntimeException('Unable to seek to stream position ' . $offset . ' with whence ' . var_export($whence, true));
        }
        
        $this->emit('seek', [$this->tell()]); // emit event
    }

    /**
     * seeks inside the stream to the start position 0
     *
     * from: Psr\Stream
     *
     * @return void
     */
    public function rewind()
    {
        $this->seek(0);
    }

    /**
     * returns the current position of the file pointer
     *
     * from: Psr\Stream
     *
     * @param
     *            int position
     */
    public function tell()
    {
        if (!$this->isSeekable())
        {
            throw new \RuntimeException('Stream is not seekable');
        }
        
        $result = ftell($this->stream);
        
        if ($result === false)
        {
            throw new \RuntimeException('Unable to determine stream position');
        }
        
        return $result;
    }

    /**
     * returns true if the pointer is at the end of the stream data
     *
     * from: Psr\Stream
     *
     * @param
     *            bool
     */
    public function eof()
    {
        if (!$this->isSeekable())
        {
            throw new \RuntimeException('Stream is not seekable');
        }
        
        return feof($this->stream);
    }
}
