<?php

namespace Asyncopath\Factories;

use Amp\ByteStream;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Asyncopath\Router;
use Monolog\Logger;

class RouterFactory
{
    public static function create(): Router
    {
        $handler = new StreamHandler(ByteStream\getStdout());
        $handler->setFormatter(new ConsoleFormatter);

        $logger = new Logger('main');
        $logger->pushHandler($handler);
        $errorHandler = new DefaultErrorHandler;
        $router = new Router($logger, $errorHandler);

        return $router;
    }
}
