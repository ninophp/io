<?php
namespace Nino\Io\Encryption;

/**
 * DecryptStream
 *
 * is a ThroughStream which can be used in pipe operations to decrypt
 * a stream encrypted with openssl (aes-256-ctr).
 *
 * e.g. (new Stream( encryptedData )).pipe( new DeryptStream('my key') ).pipe( destinationStream );
 *
 * The initializaion vector used for decryption is expected to be the first 16 bytes of the stream.
 */
class DecryptStream extends AbstractStream
{

    /**
     *
     * @see \Nino\Io\ThroughStream::transform
     * {@inheritdoc}
     */
    protected function transform($data, $end = false)
    {
        $length = strlen($data);
        
        if ($length >= 16 || $end)
        {
            if (!$this->getInitialIv())
            {
                if ($end && $length < 16) // not enough data for an iv was provided ($data < 16)
                {
                    if ($data !== '') // we fail silently on an totally empty file
                    {
                        $this->emit('error', [new \RuntimeException('Could not decrypt file. Missing initialization vector.')]);
                    }
                    $this->close();
                    return;
                }
                
                $this->setIv(substr($data, 0, 16));
                $data = substr($data, 16);
            }
            
            $decrypt = $data;
            
            if (!$end)
            {
                list ($decrypt, $data) = self::splitCtrBlocks($data);
            }
            
            // push data further down the pipe
            $this->push( $this->crypt($decrypt) );
        }
        
        return $data;
    }
}