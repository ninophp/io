<?php
namespace Nino\Io\Curl;

/**
 */
class Exception extends \Exception
{
    protected $httpCode;
    
    /**
     * 
     * @param int $code
     * @param int $httpCode
     * @param string $message
     * @param \Exception $previous
     */
    public function __construct($code, $httpCode=0, $message='', $previous=null)
    {
        if(!$message && isset(self::$codes[$code]))
        {
            $message = self::$codes[$code];
        }
        
        $this->httpCode = $httpCode;
        
        parent::__construct($message, $code, $previous);
    }
    
    /**
     * 
     * @return int
     */
    public function getHttpCode()
    {
        return $this->httpCode;
    }
    
    /**
     *
     * @return Exception
     */
    public static function fromResultArray(array $result)
    {
        $code = intval($result['errno']);
        
        $httpCode = isset($result['info']['http_code'])
        ? $result['info']['http_code']
        : 0;
        
        return new self($code, $httpCode);
    }
    
    /**
     * 
     * @var array
     */
    protected static $codes = [
        1 => 'CURL_UNSUPPORTED_PROTOCOL: The URL you passed to libcurl used a protocol that this libcurl does not support.', // ##
        2 => 'CURL_FAILED_INIT: Very early initialization code failed.',
        3 => 'CURL_URL_MALFORMAT: The URL was not properly formatted.', // ##
        4 => 'CURL_NOT_BUILT_IN: A requested feature, protocol or option was not found built-in in this libcurl due to a build-time decision.',
        5 => 'CURL_COULDNT_RESOLVE_PROXY: The given proxy host could not be resolved.', // ##
        6 => 'CURL_COULDNT_RESOLVE_HOST: The given remote host was not resolved.', // ##
        7 => 'CURL_COULDNT_CONNECT: Failed to connect to host or proxy.', // ##
        8 => 'CURL_WEIRD_SERVER_REPLY: The server sent data libcurl couldn\'t parse.',
        9 => 'CURL_REMOTE_ACCESS_DENIED: We were denied access to the resource given in the URL.', // ##
        10 => 'CURL_FTP_ACCEPT_FAILED: While waiting for the server to connect back when an active FTP session is used, an error code was sent over the control connection or similar.',
        11 => 'CURL_FTP_WEIRD_PASS_REPLY: After having sent the FTP password to the server, an unexpected code was returned.',
        12 => 'CURL_FTP_ACCEPT_TIMEOUT: During an active FTP session while waiting for the server to connect, the CURLOPT_ACCEPTTIMEOUT_MS (or the internal default) timeout expired.',
        13 => 'CURL_FTP_WEIRD_PASV_REPLY: libcurl failed to get a sensible result back from the server as a response to either a PASV or a EPSV command.',
        14 => 'CURL_FTP_WEIRD_227_FORMAT: FTP servers return a 227-line as a response to a PASV command. If libcurl fails to parse that line, this return code is passed back.',
        15 => 'CURL_FTP_CANT_GET_HOST: An internal failure to lookup the host used for the new connection.',
        16 => 'CURL_HTTP2: A problem was detected in the HTTP2 framing layer. This is somewhat generic and can be one out of several problems, see the error buffer for details.',
        17 => 'CURL_FTP_COULDNT_SET_TYPE: Received an error when trying to set the transfer mode to binary or ASCII.',
        18 => 'CURL_PARTIAL_FILE: A file transfer was shorter or larger than expected. This happens when the client first reports an expected transfer size, and then delivers data that doesn\'t match the previously given size.', // ##
        19 => 'CURL_FTP_COULDNT_RETR_FILE: This was either a weird reply to a RETR command or a zero byte transfer complete.',
        
        21 => 'CURL_QUOTE_ERROR: When sending custom QUOTE commands to the remote server, one of the commands returned an error code that was 400 or higher.',
        22 => 'CURL_HTTP_RETURNED_ERROR: This is returned if CURLOPT_FAILONERROR is set TRUE and the HTTP server returns an error code that is >= 400',
        23 => 'CURL_WRITE_ERROR: An error occurred when writing received data to a local file, or an error was returned to libcurl from a write callback.', // #
        
        25 => 'CURL_UPLOAD_FAILED: Failed starting the upload. For FTP, the server typically denied the STOR command.', // ##
        26 => 'CURL_READ_ERROR: There was a problem reading a local file or an error returned by the read callback.',
        27 => 'CURL_OUT_OF_MEMORY: A memory allocation request failed. This is serious badness and things are severely screwed up if this ever occurs.',
        28 => 'CURL_OPERATION_TIMEDOUT: Operation timeout.', // ##
        
        30 => 'CURL_FTP_PORT_FAILED: The FTP PORT command returned error.',
        31 => 'CURL_FTP_COULDNT_USE_REST: The FTP REST command returned error. This should never happen if the server is sane.',
        
        33 => 'CURL_RANGE_ERROR: The server does not support or accept range requests.', // ##
        34 => 'CURL_HTTP_POST_ERROR: This is an odd error that mainly occurs due to internal confusion.',
        35 => 'CURL_SSL_CONNECT_ERROR: A problem occurred somewhere in the SSL/TLS handshake.',
        36 => 'CURL_BAD_DOWNLOAD_RESUME: The download could not be resumed because the specified offset was out of the file boundary.', // ##
        37 => 'CURL_FILE_COULDNT_READ_FILE: A file given with FILE:// couldn\'t be opened. Most likely because the file path doesn\'t identify an existing file.',
        38 => 'CURL_LDAP_CANNOT_BIND: LDAP bind operation failed.',
        39 => 'CURL_LDAP_SEARCH_FAILED: LDAP search failed.',
        
        41 => 'CURL_FUNCTION_NOT_FOUND: Function not found. A required zlib function was not found.',
        42 => 'CURL_ABORTED_BY_CALLBACK: Aborted by callback. A callback returned "abort" to libcurl.', // ##
        43 => 'CURL_BAD_FUNCTION_ARGUMENT: Internal error. A function was called with a bad parameter.',
        
        45 => 'CURL_INTERFACE_FAILED: Interface error. A specified outgoing interface could not be used.',
        
        47 => 'CURL_TOO_MANY_REDIRECTS: Too many redirects. When following redirects, libcurl hit the maximum amount.', // ##
        48 => 'CURL_UNKNOWN_OPTION: An option passed to libcurl is not recognized/known.',
        49 => 'CURL_TELNET_OPTION_SYNTAX: A telnet option string was Illegally formatted',
        
        51 => 'CURL_PEER_FAILED_VERIFICATION: The remote server\'s SSL certificate or SSH md5 fingerprint was deemed not OK.', // ##
        52 => 'CURL_GOT_NOTHING: Nothing was returned from the server, and under the circumstances, getting nothing is considered an error.',
        53 => 'CURL_SSL_ENGINE_NOTFOUND: The specified crypto engine wasn\'t found.',
        54 => 'CURL_SSL_ENGINE_SETFAILED: Failed setting the selected SSL crypto engine as default.',
        55 => 'CURL_SEND_ERROR: Failed sending network data.',
        56 => 'CURL_RECV_ERROR: Failure with receiving network data.',
        
        58 => 'CURL_SSL_CERTPROBLEM: problem with the local client certificate.',
        59 => 'CURL_SSL_CIPHER: Couldn\'t use specified cipher.',
        60 => 'CURL_SSL_CACERT: Peer certificate cannot be authenticated with known CA certificates.',
        61 => 'CURL_BAD_CONTENT_ENCODING: Unrecognized transfer encoding.',
        62 => 'CURL_LDAP_INVALID_URL: Invalid LDAP URL.',
        63 => 'CURL_FILESIZE_EXCEEDED: Maximum file size exceeded.', // ##
        64 => 'CURL_USE_SSL_FAILED: Requested FTP SSL level failed.',
        65 => 'CURL_SEND_FAIL_REWIND: When doing a send operation curl had to rewind the data to retransmit, but the rewinding operation failed.',
        66 => 'CURL_SSL_ENGINE_INITFAILED: Initiating the SSL Engine failed.',
        67 => 'CURL_LOGIN_DENIED: The remote server denied curl to login.', // ##
        68 => 'CURL_TFTP_NOTFOUND: File not found on TFTP server.',
        69 => 'CURL_TFTP_PERM: Permission problem on TFTP server.',
        70 => 'CURL_REMOTE_DISK_FULL: Out of disk space on the server.', // ##
        71 => 'CURL_TFTP_ILLEGAL: Illegal TFTP operation.',
        72 => 'CURL_TFTP_UNKNOWNID: Unknown TFTP transfer ID.',
        73 => 'CURL_REMOTE_FILE_EXISTS: File already exists and will not be overwritten.', // ##
        74 => 'CURL_TFTP_NOSUCHUSER: This error should never be returned by a properly functioning TFTP server.',
        75 => 'CURL_CONV_FAILED: Character conversion failed.',
        76 => 'CURL_CONV_REQD: Caller must register conversion callbacks.',
        77 => 'CURL_SSL_CACERT_BADFILE: Problem with reading the SSL CA cert.',
        78 => 'CURL_REMOTE_FILE_NOT_FOUND: The resource referenced in the URL does not exist.', // ##
        79 => 'CURL_SSH: An unspecified error occurred during the SSH session.',
        80 => 'CURL_SSL_SHUTDOWN_FAILED: Failed to shut down the SSL connection.',
        81 => 'CURL_AGAIN: Socket is not ready for send/recv wait till it\'s ready and try again.',
        82 => 'CURL_SSL_CRL_BADFILE: Failed to load CRL file.',
        83 => 'CURL_SSL_ISSUER_ERROR: Issuer check failed.',
        84 => 'CURL_FTP_PRET_FAILED: The FTP server does not understand the PRET command at all or does not support the given argument.',
        85 => 'CURL_RTSP_CSEQ_ERROR: Mismatch of RTSP CSeq numbers.',
        86 => 'CURL_RTSP_SESSION_ERROR: Mismatch of RTSP Session Identifiers.',
        87 => 'CURL_FTP_BAD_FILE_LIST: Unable to parse FTP file list (during FTP wildcard downloading).',
        88 => 'CURL_CHUNK_FAILED: Chunk callback reported error.',
        
        90 => 'CURL_SSL_PINNEDPUBKEYNOTMATCH: Failed to match the pinned key specified with CURLOPT_PINNEDPUBLICKEY.',
        91 => 'CURL_SSL_INVALIDCERTSTATUS: Status returned failure when asked with CURLOPT_SSL_VERIFYSTATUS.',
        92 => 'CURL_HTTP2_STREAM: Stream error in the HTTP/2 framing layer.'
    ];
}
