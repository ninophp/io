<?php
namespace Nino\Io;

use React\Stream\DuplexStreamInterface;

/**
 * stream interface implementing and React Stream Interfaces
 */
interface StreamInterface extends DuplexStreamInterface, WritableStreamInterface, ReadableStreamInterface, SeekableStreamInterface
{
}
