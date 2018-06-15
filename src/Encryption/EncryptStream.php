<?php
namespace Nino\Io\Encryption;

/**
 * EncryptStream
 *
 * is a ThroughStream which can be used in pipe operations to encrypt
 * a stream with openssl (aes-256-ctr).
 *
 * e.g. (new Stream('my secret data')).pipe( new EncryptStream('my key') ).pipe( destinationStream );
 *
 * The initializaion vector used for encryption is inserted as the first 16 bytes of the stream.
 */
class EncryptStream extends AbstractStream
{
    /**
     * 
     * {@inheritDoc}
     * @see \Nino\Io\Encryption\AbstractStream::transform()
     */
    protected function transform($data, $end = false)
    {
        if (strlen($data) >= 16 || $end)
        {
            $iv = '';
            
            // iv gets only added at the beginning of the file if WE set it
            // if it was set via setIv() we just encrypt as we could be somewhere in the middle of the file
            if (!$this->getInitialIv())
            {
                $this->setIv($iv = openssl_random_pseudo_bytes(16));
            }
            
            $encrypt = $data;
            
            if (!$end)
            {
                list ($encrypt, $data) = self::splitCtrBlocks($data);
            }

            if ($encrypt !== '')
            {
                // push data further down the pipe
                $this->push( $iv . $this->crypt($encrypt) );
            }
        }
        
        // buffer currently lower than 16 bytes return data to buffer
        return $data;
    }
}