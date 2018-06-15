<?php
namespace Nino\Io\Resource;

use Nino\Io\SeekableStreamInterface;

/**
 * 
 * @author iphone
 *
 */
class SeekableWritableStream extends WritableStream implements SeekableStreamInterface
{
    use SeekableStreamTrait
    {
        isSeekable as traitIsSeekable;
    }
    
    /**
     * returns true if the stream is seekable otherwise false
     *
     * @return bool
     */
    public function isSeekable()
    {
        // if resource is seekable
        if ($this->traitIsSeekable())
        {
            // seeking write streams is only possible if
            // the internal buffer is flushed and empty
            return ($this->writable && $this->data === '');
        }
        return false;
    }
}
