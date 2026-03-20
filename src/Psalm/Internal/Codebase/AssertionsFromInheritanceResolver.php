<?php

declare (strict_types=1);
namespace Psalm\Internal\Codebase;

use Psalm\Codebase;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Storage\Method_Storage;
use Psalm\Storage\Possibilities;
use function array_filter;
use function array_values;
use function strtolower;
/**
 * @internal
 */
final class Assertions_From_Inheritance_Resolver
{
    public function __construct(private readonly Codebase $codebase)
    {
    }
    /**
     * @return array<int,Possibilities>
     */
    public function resolve(Method_Storage $method_storage, Class_Like_Storage $called_class): array
    {
        $method_name_lc = strtolower($method_storage->cased_name ?? '');
        $assertions = $method_storage->assertions;
        $inherited_classes_and_interfaces = array_values(array_filter([...$called_class->parent_classes, ...$called_class->class_implements], fn(string $class_or_interface): bool => $this->codebase->class_or_interface_or_enum_exists($class_or_interface)));
        foreach ($inherited_classes_and_interfaces as $potential_assertion_providing_class) {
            $potential_assertion_providing_classlike_storage = $this->codebase->classlike_storage_provider->get($potential_assertion_providing_class);
            if (!isset($potential_assertion_providing_classlike_storage->methods[$method_name_lc])) {
                continue;
            }
            $potential_assertion_providing_method_storage = $potential_assertion_providing_classlike_storage->methods[$method_name_lc];
            /**
             * Since the inheritance does not provide its own assertions, we have to detect those
             * from inherited classes
             */
            $assertions += $potential_assertion_providing_method_storage->assertions;
        }
        return $assertions;
    }
}