<?php
declare(strict_types=1);

namespace MonkeysLegion\Files\Image;

/**
 * MonkeysLegion Framework — Files Package
 *
 * Registry for named image conversions. Register once, apply many times.
 *
 * ```php
 * $registry = new ConversionRegistry();
 * $registry->register(Conversion::thumbnail('thumb', 200, 200));
 * $registry->register(Conversion::toFormat('webp', ImageFormat::Webp));
 *
 * foreach ($registry->all() as $conversion) {
 *     // apply conversion...
 * }
 * ```
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class ConversionRegistry
{
    /** @var array<string, Conversion> */
    private array $conversions = [];

    /** Number of registered conversions. */
    public int $count {
        get => count($this->conversions);
    }

    /** Registered conversion names. */
    public array $names {
        get => array_keys($this->conversions);
    }

    /**
     * Register a named conversion.
     */
    public function register(Conversion $conversion): self
    {
        $this->conversions[$conversion->name] = $conversion;

        return $this;
    }

    /**
     * Get a conversion by name.
     */
    public function get(string $name): ?Conversion
    {
        return $this->conversions[$name] ?? null;
    }

    /**
     * Check if a conversion is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->conversions[$name]);
    }

    /**
     * Remove a conversion.
     */
    public function remove(string $name): self
    {
        unset($this->conversions[$name]);

        return $this;
    }

    /**
     * Get all registered conversions.
     *
     * @return array<string, Conversion>
     */
    public function all(): array
    {
        return $this->conversions;
    }
}
