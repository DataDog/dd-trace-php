<?php declare(strict_types=1);

namespace FakeApp\Http;

final class Headers
    implements \Countable, \IteratorAggregate
{

    private function __construct(private array $headers)
    {
    }

    public static function new(): Headers
    {
        return new Headers([]);
    }

    public function append(string $name, string $value): Headers {
        $this->headers[] = [$name, $value];
	return $this;
    }

    public function count(): int
    {
        return \count($this->headers);
    }

    public function getIterator(): \Iterator
    {
        foreach ($this->headers as [$name, $value]) {
            yield $name => $value;
        }
    }
}
