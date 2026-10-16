<?php

declare(strict_types=1);

namespace Asyncopath\Whiteboard;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Websocket\Server\WebsocketClientGateway;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;

/**
 * Shared whiteboard over websockets.
 *
 * The board lives in this worker's memory. Each client streams its strokes in
 * small batches of points while drawing; the server stores them and relays
 * them to every other client, so everyone sees the line appear live.
 *
 * Board coordinates are a fixed 1600x1000 space; clients scale to fit.
 *
 * Client -> server messages (JSON text frames):
 *   {"type":"draw","id":"s1","tool":"pen","color":"#1f1d1a","size":4,"points":[[x,y],...]}
 *   {"type":"end","id":"s1"}
 *   {"type":"undo"}
 *   {"type":"clear"}
 *   {"type":"cursor","x":120,"y":340}
 *
 * Server -> client messages:
 *   init, join, leave, draw, end, remove, clear, cursor
 *   Stroke ids are sent back as "<clientId>-<id>".
 */
final class Whiteboard implements WebsocketClientHandler
{
    public const WIDTH = 1600;

    public const HEIGHT = 1000;

    private const TOOLS = ['pen', 'marker', 'eraser'];

    private const MAX_POINTS_PER_MESSAGE = 512;

    /** Oldest strokes are dropped past this many points in total. */
    private const MAX_BOARD_POINTS = 200_000;

    private const PEER_COLORS = ['#2563eb', '#d12724', '#188a42', '#ea7a12', '#7c3aed', '#0e8a8a'];

    private readonly WebsocketClientGateway $gateway;

    /** @var array<string, array{id: string, by: int, tool: string, color: string, size: float, points: list<array{0: float, 1: float}>, done: bool}> */
    private array $strokes = [];

    private int $boardPoints = 0;

    /** @var array<int, array{id: int, name: string, color: string}> */
    private array $peers = [];

    private int $guestNumber = 0;

    public function __construct()
    {
        $this->gateway = new WebsocketClientGateway;
    }

    public function handleClient(WebsocketClient $client, Request $request, Response $response): void
    {
        $clientId = $client->getId();
        $peer = [
            'id' => $clientId,
            'name' => 'Guest '.(++$this->guestNumber),
            'color' => self::PEER_COLORS[$this->guestNumber % \count(self::PEER_COLORS)],
        ];

        $this->gateway->addClient($client);
        $this->peers[$clientId] = $peer;

        $client->sendText($this->encode([
            'type' => 'init',
            'you' => $peer,
            'board' => ['width' => self::WIDTH, 'height' => self::HEIGHT],
            'strokes' => array_values($this->strokes),
            'peers' => array_values($this->peers),
        ]));
        $this->broadcast(['type' => 'join', 'peer' => $peer], except: $clientId);

        try {
            // Each iteration suspends this fiber until the client sends a
            // message; other clients keep being served in the meantime.
            foreach ($client as $message) {
                if ($message->isBinary()) {
                    continue;
                }

                $data = json_decode($message->buffer(), true, 8);
                if (\is_array($data)) {
                    $this->handleMessage($clientId, $data);
                }
            }
        } finally {
            unset($this->peers[$clientId]);
            $this->finishStrokesOf($clientId);
            $this->broadcast(['type' => 'leave', 'id' => $clientId]);
        }
    }

    private function handleMessage(int $clientId, array $data): void
    {
        match ($data['type'] ?? null) {
            'draw' => $this->draw($clientId, $data),
            'end' => $this->end($clientId, $data),
            'undo' => $this->undo($clientId),
            'clear' => $this->clear($clientId),
            'cursor' => $this->cursor($clientId, $data),
            default => null,
        };
    }

    private function draw(int $clientId, array $data): void
    {
        $id = $this->strokeId($clientId, $data['id'] ?? null);
        $points = $this->points($data['points'] ?? null);
        if ($id === null || $points === []) {
            return;
        }

        $stroke = $this->strokes[$id] ?? null;
        if ($stroke === null) {
            $tool = \in_array($data['tool'] ?? null, self::TOOLS, true) ? $data['tool'] : 'pen';
            $color = \is_string($data['color'] ?? null) && preg_match('/^#[0-9a-fA-F]{6}$/', $data['color'])
                ? strtolower($data['color'])
                : '#1f1d1a';
            $size = \is_numeric($data['size'] ?? null) ? max(1.0, min((float) $data['size'], 80.0)) : 4.0;

            $stroke = ['id' => $id, 'by' => $clientId, 'tool' => $tool, 'color' => $color, 'size' => $size, 'points' => [], 'done' => false];
        } elseif ($stroke['by'] !== $clientId || $stroke['done']) {
            return;
        }

        array_push($stroke['points'], ...$points);
        $this->strokes[$id] = $stroke;
        $this->boardPoints += \count($points);

        $this->broadcast([
            'type' => 'draw',
            'id' => $id,
            'by' => $clientId,
            'tool' => $stroke['tool'],
            'color' => $stroke['color'],
            'size' => $stroke['size'],
            'points' => $points,
        ], except: $clientId);

        $this->trimBoard();
    }

    private function end(int $clientId, array $data): void
    {
        $id = $this->strokeId($clientId, $data['id'] ?? null);
        if ($id === null || ! isset($this->strokes[$id])) {
            return;
        }

        $this->strokes[$id]['done'] = true;
        $this->broadcast(['type' => 'end', 'id' => $id], except: $clientId);
    }

    private function undo(int $clientId): void
    {
        foreach (array_reverse($this->strokes, true) as $id => $stroke) {
            if ($stroke['by'] === $clientId) {
                $this->removeStroke($id);

                return;
            }
        }
    }

    private function clear(int $clientId): void
    {
        $this->strokes = [];
        $this->boardPoints = 0;
        $this->broadcast(['type' => 'clear', 'by' => $clientId]);
    }

    private function cursor(int $clientId, array $data): void
    {
        if (! \is_numeric($data['x'] ?? null) || ! \is_numeric($data['y'] ?? null)) {
            return;
        }

        $this->broadcast([
            'type' => 'cursor',
            'id' => $clientId,
            'x' => max(0, min((float) $data['x'], self::WIDTH)),
            'y' => max(0, min((float) $data['y'], self::HEIGHT)),
        ], except: $clientId);
    }

    private function removeStroke(string $id): void
    {
        $this->boardPoints -= \count($this->strokes[$id]['points']);
        unset($this->strokes[$id]);
        $this->broadcast(['type' => 'remove', 'id' => $id]);
    }

    /** A client that disconnects mid-stroke leaves a finished stroke behind. */
    private function finishStrokesOf(int $clientId): void
    {
        foreach ($this->strokes as $id => $stroke) {
            if ($stroke['by'] === $clientId && ! $stroke['done']) {
                $this->strokes[$id]['done'] = true;
                $this->broadcast(['type' => 'end', 'id' => $id]);
            }
        }
    }

    private function trimBoard(): void
    {
        while ($this->boardPoints > self::MAX_BOARD_POINTS && $this->strokes !== []) {
            $this->removeStroke(array_key_first($this->strokes));
        }
    }

    /** Stroke ids are namespaced by client, so clients can't collide or touch each other's strokes. */
    private function strokeId(int $clientId, mixed $id): ?string
    {
        return \is_string($id) && preg_match('/^[A-Za-z0-9_]{1,40}$/', $id) ? $clientId.'-'.$id : null;
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    private function points(mixed $points): array
    {
        if (! \is_array($points)) {
            return [];
        }

        $clean = [];
        foreach (\array_slice($points, 0, self::MAX_POINTS_PER_MESSAGE) as $point) {
            if (\is_array($point) && \is_numeric($point[0] ?? null) && \is_numeric($point[1] ?? null)) {
                $clean[] = [
                    round(max(0, min((float) $point[0], self::WIDTH)), 1),
                    round(max(0, min((float) $point[1], self::HEIGHT)), 1),
                ];
            }
        }

        return $clean;
    }

    /**
     * @param  int|null  $except  client that sent the change and already has it
     */
    private function broadcast(array $message, ?int $except = null): void
    {
        $this->gateway
            ->broadcastText($this->encode($message), $except === null ? [] : [$except])
            ->ignore();
    }

    private function encode(array $message): string
    {
        return json_encode($message, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
