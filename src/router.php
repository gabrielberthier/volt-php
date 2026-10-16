<?php

declare(strict_types=1);

namespace Asyncopath;

use Asyncopath\Middlewares\CorsMiddleware;
use Asyncopath\Usecases\DbOrdersRead;
use Asyncopath\Whiteboard\Whiteboard;
use League\Route\Strategy\JsonStrategy;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

return static function (Router $router) {
    $router->addRoute('GET', '/test', function (ServerRequestInterface $request): ResponseInterface {
        $response = new Response;
        $response->getBody()->write('<h1>Hello, World!</h1>');

        return $response;
    });

    $router->get('/db', function (ServerRequestInterface $request): ResponseInterface {
        $response = new Response;
        // $reqBody = $request->getBody()->getContents();
        // $db = new DbOrdersRead();
        // $values = [];
        // foreach ($db->fetchById() as $v) {
        //     $values[] = $v;
        // }

        // $response->getBody()->write(json_encode($values));

        $read = new DbOrdersRead;

        $mapped = $read->fetchById();

        $response->getBody()->write(json_encode($mapped));

        return $response;
    })->setStrategy(new JsonStrategy(new Psr17Factory));

    $router->addRoute('POST', '/echo', function (ServerRequestInterface $request): ResponseInterface {
        $response = new Response;
        $reqBody = $request->getBody()->getContents();
        $response->getBody()->write($reqBody);

        return $response;
    });

    $router->addRoute('GET', '/', function (ServerRequestInterface $request): ResponseInterface {
        $response = new Response(body: '<h1>Hello, World!</h1>');

        return $response;
    });

    $router->addRoute('GET', '/ai', function (ServerRequestInterface $request): ResponseInterface {
        $response = new Response(body: 'ui!!');

        return $response;
    });

    // Shared whiteboard: the page draws with Drawably controls and syncs
    // strokes through the websocket below. Open /whiteboard in two tabs.
    $whiteboard = new Whiteboard;

    $router->get('/whiteboard', function (ServerRequestInterface $request): ResponseInterface {
        return new Response(
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
            file_get_contents(__DIR__.'/Whiteboard/whiteboard.html'),
        );
    });

    $router->websocket('/whiteboard/ws', $whiteboard);

    $router->middleware(new CorsMiddleware(
        responseFactory: new Psr17Factory,
    ));
};
