<?php

namespace Asyncopath;

use Asyncopath\Factories\RouterFactory;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;



class RouterSingleton implements RequestHandler
{
    private static Router $router;

    public function __construct()
    {
        self::$router = RouterFactory::create();

        $routerSettings = require __DIR__ . '/router.php';

        $routerSettings(self::$router);
    }

    public function restartRouter()
    {
        self::$router = RouterFactory::create();

        $routerSettings = require __DIR__ . '/router.php';

        $routerSettings(self::$router);
    }

    public function handleRequest(Request $request): Response
    {
        return self::$router->handleRequest($request);
    }


}