<?php
namespace Nino\Io;

use React\Stream\ReadableStreamInterface as Readable;
use React\Stream\WritableStreamInterface as Writable;


/**
 * Utility functions class
 *
 * contents based on React\Stream\Util
 */
final class Util
{
    /**
     * if flag = false => obj will be detached from store
     * if flag = true => data attached to obj in store will be returned
     * if flag = null or unset => it is checked if store contains obj 
     * 
     * @param Object $obj
     * @param bool $flag
     */
    private static function store($obj, $flag = null)
    {
        static $store = null;
        
        if(!$store)
        {
            $store = new \SplObjectStorage();
        }
        
        if($flag === null)
        {
            return $store->contains($obj);
        }
        else if($flag)
        {
            if(!$store->contains($obj))
            {
                // need to cast to object (stdClass) to assure that it is passed by reference
                $store[$obj] = (object) [
                    'count'      => 0,
                    'awaitDrain' => 0,
                    'pipes'      => null
                ];
            }
            
            return $store[$obj];
        }
        else if($store->contains($obj))
        {
            $store->detach($obj);
        }
    }
    
    /**
     * Pipes all the data from the given $source into the $dest
     *
     * @param ReadableStreamInterface $source
     * @param WritableStreamInterface $dest
     * @param array $options
     * @return WritableStreamInterface $dest stream as-is
     * @see ReadableStreamInterface::pipe() for more details
     */
    public static function pipe(Readable $source, Writable $dest, array $options = [])
    {
        // source not readable => NO-OP
        if (!$source->isReadable())
        {
            return $dest;
        }
        
        // destination not writable => just pause() source
        if (!$dest->isWritable())
        {
            $source->pause();
            return $dest;
        }
        
        // get store for $source
        $store = self::store($source, true);
        $close = null;
        
        // add destination to pipes
        switch($store->count)
        {
            case(0):
                $store->pipes = $dest;
                // only needed once per source
                $source->once('close', $close = function () use ($source)
                {
                    // remove all destinations from source
                    self::unpipe($source);
                });
                break;
            case(1):
                $store->pipes = [$store->pipes, $dest];
                break;
            default:
                $store->pipes[] = $dest;
        }
        
        $store->count++;
        
        // marker: if this destination stream is paused and needs to drain
        $needDrain = false;
        
        // forward end event from source as $dest->end()
        // NEEDS TO COME FIRST, as registering a data event could immediately lead to 
        // a data and end event being emitted (if buffer was filled)
        $addEnd = isset($options['end']) ? $options['end'] : true;
        
        if ($addEnd)
        {
            $source->on('end', $end = function () use ($dest)
            {
                $dest->end();
            });
        }
        
        // forward destination drain as $source->resume()
        $dest->on('drain', $drain = function () use ($source, $store, &$needDrain)
        {
            $store->awaitDrain--;
            $needDrain = false;
            
            if($store->awaitDrain == 0)
            {
                $source->resume();
            }
        });
        
        // forward all source data events as $dest->write()
        $sdata = function ($data) use ($source, $dest, $store, &$needDrain)
        {
            if ($dest->write($data) === false)
            {
                $source->pause();
                $store->awaitDrain++;
                $needDrain = true;
            }
        };
        
        $unpiped = false;
        // add unpipe events
        $unpipe = function($emit = true, $emptied = false) use ($source, $dest, $store, &$unpipe, &$unpiped, &$needDrain, $drain, $sdata, $end, $close, $addEnd)
        {
            if($unpiped)
            {
                return;
            }
            
            $unpiped = true;

            $dest->removeListener('unpipe', $unpipe);
            $dest->removeListener('drain', $drain);
            
            $source->removeListener('data', $sdata);
            
            if($addEnd)
            {
                $source->removeListener('end', $end);
            }
            
            // $emptied == true => unpipe method handled removing pipes
            if(!$emptied)
            {
                if($needDrain)
                {
                    $store->awaitDrain--;
                }
                
                if($store->count == 1)
                {
                    self::store($source, false); // remove store for source
                    $emptied = true;
                }
                else if(($index = array_search($dest, $store->pipes, true)) !== false)
                {
                    array_splice($store->pipes, $index, 1);
                    $store->count--;
                    
                    if($store->count == 1)
                    {
                        $store->pipes = $store->pipes[0];
                    }
                    
                    // unpiped the last stream we were waiting to drain => resume
                    if($store->awaitDrain == 0)
                    {
                        $source->resume();
                    }
                }
            }
            
            if($emptied && $close)
            {
                $source->removeListener('close', $close);
            }
            
            if($emit)
            {
                $dest->emit('unpipe', [$source]);
            }
        };
        
        $dest->on('unpipe', function($targetSource, $emptied=false) use($unpipe, $source)
        {
            if($targetSource === $source)
            {
                $unpipe(false, $emptied);
            }
        });
        
        $dest->once('close', $unpipe);
        $dest->once('finish', $unpipe);
        $dest->once('error', $unpipe);
        
        // forward all source data events as $dest->write()
        $source->on('data', $sdata);
        
        $dest->emit('pipe', [$source]);
        
        return $dest;
    }
    
    /**
     *
     */
    public static function unpipe(Readable $source, Writable $dest = null)
    {
        // if no pipes are attached to source return immediately
        if(!self::store($source))
        {
            return $source;
        }
        
        $store = self::store($source, true);
        
        if($dest)
        {
            if(($store->count == 1 && $store->pipes == $dest) || in_array($dest, $store->pipes, true))
            {
                $dest->emit('unpipe', [$source]);
            }
        }
        // remove all destination pipes
        else
        {
            if($store->count == 1)
            {
                // true = emptied => dont let callback handle removing of pipes
                $store->pipes->emit('unpipe', [$source, true]); 
            }
            else 
            {
                foreach($store->pipes as $pipe)
                {
                    $pipe->emit('unpipe', [$source, true]);
                }
            }
            
            self::store($source, false); // remove store for source
        }
        
        return $source;
    }
    
    /**
     *
     */
    public static function addFinishEvent(Writable $stream)
    {
        // Nino stream => supports finish event already
        if($stream instanceof \Nino\Io\WritableStreamInterface)
        {
            return;
        }
        
        // check if already added
        foreach($stream->listeners('close') as $callback)
        {
            if($callback instanceof FinishCallback)
            {
                return;
            }
        }
        
        $callback = new FinishCallback($stream);
        
        $stream->on('close', $callback);
    }
    
    /**
     * 
     */
    public static function forwardEvents($source, $target, array $events)
    {
        foreach ($events as $event) 
        {
            $source->on($event, function () use ($event, $target) 
            {
                $target->emit($event, func_get_args());
            });
        }
    }

    /**
     * returns the size of the stream data or null the size could not be retrieved
     *
     * @return int|null
     */
    static function getStreamSize($stream)
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream')
        {
            return null;
        }
        
        $meta = stream_get_meta_data($stream);
        
        // Clear the stat cache if the stream has a URI
        if ($meta['uri'])
        {
            clearstatcache(true, $meta['uri']);
        }
        
        $stats = fstat($stream);
        
        return isset($stats['size']) ? $stats['size'] : null;
    }

    /**
     * parse a range and returns an array
     *
     * The first-byte-pos value in a byte-range-spec gives the byte-offset
     * of the first byte in a range. The last-byte-pos value gives the
     * byte-offset of the last byte in the range; that is, the byte
     * positions specified are inclusive. Byte offsets start at zero.
     *
     * Examples of byte-ranges-specifier values (file size is 1000):
     * - The first 500 bytes (byte offsets 0-499, inclusive):
     * bytes=0-499
     * - The second 500 bytes (byte offsets 500-999, inclusive):
     * bytes=500-999
     *
     * A client can limit the number of bytes requested without knowing the
     * size of the selected representation. If the last-byte-pos value is
     * absent, or if the value is greater than or equal to the current
     * length of the representation data, the byte range is interpreted as
     * the remainder of the representation (i.e., the server replaces the
     * value of last-byte-pos with a value that is one less than the current
     * length of the selected representation).
     *
     * A client can request the last N bytes of the selected representation
     * using a suffix-byte-range-spec.
     *
     * - The final 500 bytes (byte offsets 9500-9999, inclusive. file size is 10000):
     * bytes=-500
     * bytes=9500-
     *
     * - The first byte only:
     * bytes=0-0
     *
     * NO MULTI PART RANGES ARE SUPPORTED
     * i.e. bytes=0-50, 100-150
     *
     * The method receives either a byte range string, an array, or an integer.
     *
     * STRING:
     * The string format is "bytes=5-100".
     * The "bytes=" can also be omitted: "5-100"
     * Of course suffix byte ranges are also supported: "-100" or "100-"
     *
     * INTEGER:
     * either a positive or negative integer defining a suffix byte range: 100 or -100
     *
     * ARRAY:
     * The array defines the first and last bytes of the range: [5,100]
     * suffix byte ranges are also supported:
     * [100] is equivalent to 100-
     * [-100] is equivalent to -100
     * [null, 100] is equivalent to -100
     *
     * If the range is invalid, the method returns null
     *
     * If the range is valid, the method will return an array: [startByte, endByte]
     * e.g. [100,200] is a range of: 100-200
     *
     * In case of suffix-byte-ranges the missing value is filled with null:
     * e.g. a range of 100- is transfered to [100, null]
     * e.g. a range of -100 is transfered to [null, 100]
     * e.g. a range of -0 is transfered to [null, 0] (i.e. append)
     *
     * So a call to php implode('-', range) would return a valid range string: 100-100
     *
     * If $fileSize is specified, the method calculates the range based on the passed $fileSize
     * e.g. parseRange([100], 1000) will return [100, 999]
     * e.g. parseRange([-100], 1000) will return [900, 999]
     *
     * If $allowAppend and $fileSize is specified, the method checks and calculates the range ...
     * e.g. parseRange([100], 1000, true) will return [100, null]
     * e.g. parseRange([-100], 1000, true) will return [900, null]
     * e.g. parseRange([null,0], 1000, true) will return [1000, null] (i.e. append)
     *
     * But for startBytes exceeding the fileSize null is returned
     * e.g. parseRange([1200], 1000, true) will return null
     *
     *
     * @param array|string|int $range
     * @param int $filesize
     * @return array|null
     */
    static function parseRange($range, $filesize = null, $allowAppend = false)
    {
        if (is_string($range))
        {
            if (strpos($range, ',') !== false)
            {
                return null; // multipart range
            }
            
            preg_match('/^(?:bytes=)?(\d*)-(\d*)$/', trim($range), $m);
            
            $parsed = [ //
($m[1] = trim($m[1])) == '' ? null : intval($m[1]), //
($m[2] = trim($m[2])) == '' ? null : intval($m[2]) //
];
        }
        else if (is_array($range))
        {
            $parsed = [ //
(isset($range[0]) ? intval($range[0]) : null), //
(isset($range[1]) ? intval($range[1]) : null) //
];
        }
        else if (is_int($range))
        {
            $parsed = [$range];
        }
        else
        {
            return null;
        }
        
        // checks
        switch (true)
        {
            case ($parsed[0] === null && $parsed[1] === null): // [null,null]
            case ($parsed[0] < 0 && $parsed[1] !== null): // [-10,50]
            case ($parsed[1] < 0): // [null,-50]
            case ($parsed[0] !== null && $parsed[1] !== null && $parsed[0] > $parsed[1]):
                return null;
            
            case ($parsed[0] < 0):
                $parsed = [null,-$parsed[0]];
        }
        
        // calculate real range based on filesize
        if ($filesize)
        {
            // if $allowAppend endIndex can be fileSize, if read range endIndex can only be fileSize -1
            $gap = $allowAppend ? 0 : 1;
            
            // range: 120-x and file size is 100
            if ($parsed[0] !== null && $parsed[0] > $filesize - $gap)
            {
                return null;
            }
            
            // range: -10
            if ($parsed[0] === null)
            {
                $parsed = [max($filesize - $parsed[1], 0),null];
            }
            
            // range: 10-
            if ($parsed[1] === null && !$allowAppend)
            {
                $parsed[1] = $filesize - 1;
            }
        }
        
        return $parsed;
    }
}

/**
 * @internal
 */
class FinishCallback
{
    public $stream;
    
    public function __construct(Writable $stream)
    {
        $this->stream = $stream;
    }
    
    public function __invoke()
    {
        $this->stream->emit('finish');
    }
}
