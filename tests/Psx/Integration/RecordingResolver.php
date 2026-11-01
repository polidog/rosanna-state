<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Psx\Integration;

use Polidog\Rosanna\Runtime\PsxArgumentResolver;

/**
 * @internal
 */
final class RecordingResolver implements PsxArgumentResolver
{
    /** @var list<array{fqcn: string, metadata: array<mixed>, props: array<mixed>}> */
    public array $calls = [];

    /**
     * @param list<mixed> $arguments
     */
    public function __construct(private readonly array $arguments) {}

    public function resolve(string $fqcn, array $metadata, array $props): array
    {
        $this->calls[] = ['fqcn' => $fqcn, 'metadata' => $metadata, 'props' => $props];

        return $this->arguments;
    }
}
