<?php

declare (strict_types=1);
namespace Psalm\Internal;

use InvalidArgumentException;
use Override;
use Psalm\Storage\Immutable_Non_Cloneable_Trait;
use Psalm\Storage\Unserialize_Memory_Usage_Suppression_Trait;
use Stringable;
use function explode;
use function is_string;
use function ltrim;
use function str_contains;
use function strtolower;
/**
 * @psalm-immutable
 * @internal
 */
final class Method_Identifier implements Stringable
{
    use Immutable_Non_Cloneable_Trait;
    use Unserialize_Memory_Usage_Suppression_Trait;
    /**
     * @param lowercase-string $method_name
     */
    public function __construct(public readonly string $fq_class_name, public readonly string $method_name)
    {
    }
    /**
     * Takes any valid reference to a method id and converts
     * it into a MethodIdentifier
     *
     * @psalm-pure
     */
    public static function wrap(string|Method_Identifier $method_id): self
    {
        return is_string($method_id) ? static::from_method_id_reference($method_id) : $method_id;
    }
    /**
     * @psalm-pure
     */
    public static function is_valid_method_id_reference(string $method_id): bool
    {
        return str_contains($method_id, '::');
    }
    /**
     * @psalm-pure
     */
    public static function from_method_id_reference(string $method_id): self
    {
        if (!static::is_valid_method_id_reference($method_id)) {
            throw new InvalidArgumentException('Invalid method id reference provided: ' . $method_id);
        }
        // remove leading backslash if it exists
        $method_id = ltrim($method_id, '\\');
        $method_id_parts = explode('::', $method_id);
        return new self($method_id_parts[0], strtolower($method_id_parts[1]));
    }
    /** @return non-empty-string */
    #[Override]
    public function __toString(): string
    {
        return $this->fq_class_name . '::' . $this->method_name;
    }
}