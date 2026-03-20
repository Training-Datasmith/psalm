<?php

declare (strict_types=1);
namespace Psalm\Internal\Codebase;

use Psalm\Codebase;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Mixed;
use function array_merge;
/**
 * @internal
 */
final class Class_Constant_By_Wildcard_Resolver
{
    private readonly Storage_By_Pattern_Resolver $resolver;
    public function __construct(private readonly Codebase $codebase)
    {
        $this->resolver = new Storage_By_Pattern_Resolver();
    }
    /**
     * @return non-empty-array<array-key,Atomic>|null
     */
    public function resolve(string $class_name, string $constant_pattern): ?array
    {
        if (!$this->codebase->classlike_storage_provider->has($class_name)) {
            return null;
        }
        $classlike_storage = $this->codebase->classlike_storage_provider->get($class_name);
        $constants = $this->resolver->resolve_constants($classlike_storage, $constant_pattern);
        $types = [];
        foreach ($constants as $class_constant_storage) {
            if (!$class_constant_storage->type) {
                $types[] = [new T_Mixed()];
                continue;
            }
            $types[] = $class_constant_storage->type->get_atomic_types();
        }
        if ($types === []) {
            return null;
        }
        return array_merge([], ...$types);
    }
}