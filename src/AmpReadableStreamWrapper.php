<?php

namespace Asyncopath;

use Amp\ByteStream\ReadableStream;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

class AmpReadableStreamWrapper implements StreamInterface
{
    private ?ReadableStream $stream;
    private string $buffer = '';
    private bool $eof = false;
    private int $position = 0;

    public function __construct(ReadableStream $stream)
    {
        $this->stream = $stream;
    }

    public function __toString(): string
    {
        try {
            return $this->getContents();
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            $this->stream->close();
            $this->stream = null;
        }
    }

    public function detach()
    {
        $stream = $this->stream;
        $this->stream = null;
        return $stream;
    }

    public function getSize(): ?int
    {
        // The total size is generally unknown for incoming streaming bodies
        // unless you explicitly read the Content-Length header elsewhere.
        return null;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->eof && $this->buffer === '';
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('Network streams are not seekable.');
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('This stream is read-only.');
    }

    public function isReadable(): bool
    {
        return $this->stream !== null;
    }

    public function read(int $length): string
    {
        if (!$this->isReadable()) {
            throw new RuntimeException('Stream is detached or closed.');
        }

        if ($length < 0) {
            throw new \InvalidArgumentException('Length must be greater than or equal to zero.');
        }

        if ($length === 0) {
            return '';
        }

        if ($this->buffer === '' && !$this->eof) {
            $chunk = $this->stream->read();

            if ($chunk === null) {
                $this->eof = true;
                return '';
            }

            $this->buffer = $chunk;
        }

        // Extract up to $length bytes from whatever we currently have in the buffer
        $result = substr($this->buffer, 0, $length);

        // Remove the read bytes from the buffer
        $this->buffer = substr($this->buffer, \strlen($result));

        $this->position += \strlen($result);

        return $result;
    }

    public function getContents(): string
    {
        if (!$this->isReadable()) {
            throw new RuntimeException('Stream is detached or closed.');
        }

        $contents = $this->buffer;
        $this->buffer = '';

        while (($chunk = $this->stream->read()) !== null) {
            $contents .= $chunk;
        }

        $this->eof = true;
        $this->position += \strlen($contents);

        return $contents;
    }

    public function getMetadata(?string $key = null)
    {
        $metadata = ['eof' => $this->eof()];
        return $key === null ? $metadata : ($metadata[$key] ?? null);
    }
}