<?php
namespace Nino\Io;

use Evenement\EventEmitterInterface;

/**
 * stream interface implementing and React Stream Interfaces
 */
class EventEmitter implements EventEmitterInterface
{
    use EventEmitterTrait;
}
