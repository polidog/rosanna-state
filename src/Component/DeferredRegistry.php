<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Component;

final class DeferredRegistry
{
    /** @var array<string, array{component: string, cacheControl: ?string}> */
    private array $entries = [];

    /**
     * 同じ名前の登録は例外 (DF-3)。名前は URL になるので、別の内容を黙って上書きしない。
     *
     * @throws \InvalidArgumentException 名前が不正か、登録済みのとき
     */
    public function register(string $name, string $component, ?string $cacheControl = null): self
    {
        Defer::assertValidName($name);
        if (isset($this->entries[$name])) {
            throw new \InvalidArgumentException(sprintf(
                "Deferred name '%s' is already registered (component: %s). Deferred names are part of the public URL surface and must be unique.",
                $name,
                $this->entries[$name]['component'],
            ));
        }
        $this->entries[$name] = ['component' => $component, 'cacheControl' => $cacheControl];

        return $this;
    }

    /** まったく同じ内容の登録は何もしない。内容が違えば register() と同じく例外 */
    public function registerIfNew(string $name, string $component, ?string $cacheControl): self
    {
        $existing = $this->entries[$name] ?? null;
        if ($existing === null || $existing['component'] !== $component || $existing['cacheControl'] !== $cacheControl) {
            $this->register($name, $component, $cacheControl);
        }

        return $this;
    }

    /** @return array{component: string, cacheControl: ?string}|null */
    public function get(string $name): ?array
    {
        return $this->entries[$name] ?? null;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map('strval', array_keys($this->entries));
    }
}
