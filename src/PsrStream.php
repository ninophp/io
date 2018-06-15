<?php
namespace Nino\Io;

use Psr\Http\Message\StreamInterface as PsrStreamInterface;

/**
 * An implementation of a Psr7 stream which can be used to decorate Reactphp resp.
 * Nino streams to provide Psr\Http\Message\StreamInterface methods.
 *
 * This stream also implements the nino IStream resp. Reactphp DuplexStreamInterface interfaces.
 *
 * Attention: Even if implemented by this decorator class, not all Psr7 stream methods are supported
 * due to the flow functionality of Reactphp streams.
 *
 * So getContents and __toString return an empty string, whereas read throws an exception.
 * Also detach return null. And getSize and getMetadata will only have the expected functionality
 * if a resource was passed as stream parameter
 */
class PsrStream extends Stream implements StreamInterface, PsrStreamInterface
{

    protected $resource;

    protected $metaData = [];

    protected $size;

    /**
     * creates an Psr7DecoratorStream instance from a resource OR an Reactphp resp.
     * Nino stream implementation
     *
     * Optionally an $options array can be passed defining meta-data of the stream,
     * if information is available e.g. from prior operations
     *
     * @param
     *            resource|ReadableStreamInterface|WritableStreamInterface stream
     * @param
     *            array options
     */
    function __construct($stream, $options = [])
    {
        if (is_resource($stream) && get_resource_type($stream) == 'stream') 
        {
            $this->resource = $stream;
            $this->metaData = stream_get_meta_data($stream);
        } 
        else if (! $stream || is_string($stream)) 
        {
            $this->size = $stream ? strlen($stream) : 0;
        }
        
        if (isset($options['size'])) 
        {
            $this->size = $options['size'];
        }
        
        if (isset($options['metaData'])) 
        {
            $this->metaData = array_merge($this->metaData, $options['metaData']);
        }
        
        parent::__construct($stream, $options);
    }

    /**
     * Reads all data from the stream into a string, from the beginning to end.
     *
     * This method MUST attempt to seek to the beginning of the stream before
     * reading data and read the stream until the end is reached.
     *
     * Warning: This could attempt to load a large amount of data into memory.
     *
     * This method MUST NOT raise an exception in order to conform with PHP's
     * string casting operations.
     *
     * @see http://php.net/manual/en/language.oop5.magic.php#object.tostring
     * @return string
     */
    public function __toString()
    {
        return '';
    }

    /**
     * Separates any underlying resources from the stream.
     *
     * After the stream has been detached, the stream is in an unusable state.
     *
     * @return resource|null Underlying PHP stream, if any
     */
    public function detach()
    {
        return null;
    }

    /**
     * Get the size of the stream if known.
     *
     * @return int|null Returns the size in bytes if known, or null if unknown.
     */
    public function getSize()
    {
        if ($this->size !== null) 
        {
            return $this->size;
        }
        
        if (! isset($this->resource)) 
        {
            return null;
        }
        
        // Clear the stat cache if the stream has a URI
        if (isset($this->metData['uri'])) 
        {
            clearstatcache(true, $this->metData['uri']);
        }
        
        $stats = fstat($this->resource);
        
        if (isset($stats['size'])) 
        {
            $this->size = $stats['size'];
            return $this->size;
        }
        
        return null;
    }

    /**
     * Read data from the stream.
     *
     * @param int $length
     *            Read up to $length bytes from the object and return
     *            them. Fewer than $length bytes may be returned if underlying stream
     *            call returns fewer bytes.
     * @return string Returns the data read from the stream, or an empty string
     *         if no bytes are available.
     * @throws \RuntimeException if an error occurs.
     */
    public function read($length)
    {
        throw new \BadMethodCallException();
    }

    /**
     * Returns the remaining contents in a string
     *
     * @return string
     * @throws \RuntimeException if unable to read or an error occurs while
     *         reading.
     */
    public function getContents()
    {
        return '';
    }

    /**
     * Get stream metadata as an associative array or retrieve a specific key.
     *
     * The keys returned are identical to the keys returned from PHP's
     * stream_get_meta_data() function.
     *
     * @link http://php.net/manual/en/function.stream-get-meta-data.php
     * @param string $key
     *            Specific metadata to retrieve.
     * @return array|mixed|null Returns an associative array if no key is
     *         provided. Returns a specific key value if a key is provided and the
     *         value is found, or null if the key is not found.
     */
    public function getMetadata($key = null)
    {
        if ($key !== null) 
        {
            return isset($this->metaData[$key]) ? $this->metaData[$key] : null;
        }
        
        return $this->metaData;
    }
}