<?php
namespace Nino\Io;

use React\EventLoop\LoopInterface as ReactLoopInterface;

/**
 * interface for a combined react stream and curl multi loop
 */
interface LoopInterface extends ReactLoopInterface
{

    /**
     * adds a curl handle to be executed asynchronously
     * 
     * via curl_multi_add_handle
     * 
     * The listener function will be called when the curl request 
     * was handled (successfully or unsuccessfully).
     * 
     * The listener gets passed an array as argument containing the following elements:
     * - result: the requested content (retrieved via curl_multi_getcontent)
     * - info: the curl info array (retrieved via curl_getinfo)
     * - errno: the curl error code if any (i.e. the curl response code retrieved via curl_multi_info_read [result])
     * - error: the curl error message if any (retrieved via curl_error)
     * 
     * ATTENTION: 
     * This is different to addWriteStream or addReadStream, where the listener is 
     * used to handle stream data and gets called multiple times, i.e. everytime new data is available.
     * As for curl the loop implementation just assures that mutliple curl request are processed
     * in parallel. As not every curl handle will define a CURLOPT_READFUNCTION or CURLOPT_WRITEFUNCTION,
     * the loop itself cannot judge whether a stream handling function is defined
     * and a "data listener" is available.
     * 
     * @see http://php.net/manual/en/function.curl-init.php
     * @see http://php.net/manual/en/function.curl-multi-add-handle.php
     * @see http://php.net/manual/en/function.curl-multi-getcontent.php
     * @see http://php.net/manual/en/function.curl-getinfo.php
     * @see http://php.net/manual/en/function.curl-multi-info-read.php
     * @see http://php.net/manual/en/function.curl-error.php
     * 
     * @param resource $handle a curl handle created with curl_init
     * @param callable $listener a callback function to be called when request has finished
     * @return void
     */
    public function addCurlHandle($handle, $listener);

    /**
     * removes a curl handle
     * 
     * @param resource $handle a curl handle created with curl_init
     * @return void
     */
    public function removeCurlHandle($handle);

    /**
     * pauses the upload or download attached to a curl handle
     *
     * the write callback (defined with CURLOPT_WRITEFUNCTION) won't be called.
     * the read callback (defined with CURLOPT_READFUNCTION) won't be called
     *
     * @see https://curl.haxx.se/libcurl/c/curl_easy_pause.html
     * @see http://php.net/manual/en/function.curl-pause.php
     * 
     * @param resource $handle a curl handle created with curl_init
     * @return void
     */
    public function pauseCurlHandle($handle);

    /**
     * resumes a paused upload or download attached to a curl handle
     * 
     * @param resource $handle a curl handle created with curl_init
     * @return void
     */
    public function resumeCurlHandle($handle);
}