<?php
namespace Nino\Io\Resource;

use Nino\Io\SeekableStreamInterface;

/**
 */
class SeekableReadableStream extends ReadableStream implements SeekableStreamInterface
{
    use SeekableStreamTrait;
}
