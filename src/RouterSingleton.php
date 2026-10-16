<?php

namespace Asyncopath;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Asyncopath\Factories\RouterFactory;

class RouterSingleton implements RequestHandler
{
    private static Router $router;

    public function __construct()
    {
        self::$router = RouterFactory::create();

        $routerSettings = require __DIR__.'/router.php';

        $routerSettings(self::$router);
    }

    public function restartRouter()
    {
        self::$router->stop();
        self::$router = RouterFactory::create();

        $routerSettings = require __DIR__.'/router.php';

        $routerSettings(self::$router);
    }

    public function stop(): void
    {
        self::$router->stop();
    }

    public function handleRequest(Request $request): Response
    {
        return self::$router->handleRequest($request);
    }
}
