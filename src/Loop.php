<?php
namespace Nino\Io;

use React\EventLoop\Factory as ReactLoopFactory;
use Nino\Io\Loop\CurlDecoratorLoop;


/**
 * The loop implementation singleton
 */
class Loop
{

    /**
     * 
     * @var \Nino\Io\LoopInterface
     */
    private static $loop;

    /**
     * 
     * @var bool
     */
    private static $autoRun = true;

    /**
     * returns the loop instance used by nino
     * 
     * @return \Nino\Io\LoopInterface
     */
    public static function get()
    {
        if(!self::$loop)
        {
            self::$loop = self::create();
        }
        
        return self::$loop;
    }

    /**
     * sets the loop implementation used by nino
     *
     * to take effect, it has to be set prior to any operations
     * on the nino framework
     */
    public static function set(LoopInterface $loop)
    {
        if(self::$loop)
        {
            throw new \RuntimeException('Cannot set loop. Loop already instanciated.');
        }
        
        self::$loop = self::create($loop);
    }

    /**
     * runs the loop immediately
     */
    public static function run()
    {
        self::get()->run();
    }

    /**
     * ticks the loop
     */
    public static function tick()
    {
        self::get()->tick();
    }

    /**
     * stops the loop
     */
    public static function stop()
    {
        self::get()->stop();
    }

    /**
     * sets whether the loop will automatically be run
     * when the script finishes or not.
     * If not the loop has to be triggered manually.
     *
     * Default setting is true and the loop will be run automatically.
     */
    public static function setAutoRun($bool)
    {
        self::$autoRun = !!$bool;
    }

    /**
     * creates the loop instance
     */
    private static function create(LoopInterface $loop = null)
    {
        if (!$loop)
        {
            $loop = new CurlDecoratorLoop( ReactLoopFactory::create() );
        }
        
        register_shutdown_function(function () use ($loop)
        {
            if (self::$autoRun)
            {
                // Only run the tasks if an E_ERROR didn't occur.
                $err = error_get_last();
                if (!$err || ($err['type'] ^ E_ERROR))
                {
                    $loop->run();
                }
            }
        });
        
        return $loop;
    }
}
