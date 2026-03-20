<?php

declare (strict_types=1);
namespace Psalm\Internal\Codebase;

use function dirname;
use function strtolower;
/**
 * @internal
 */
final class Property_Map
{
    /**
     * @var array<lowercase-string, array<string, string>>|null
     */
    private static ?array $property_map = null;
    /**
     * Gets the method/function call map
     *
     * @return array<lowercase-string, array<string, string>>
     */
    public static function get_property_map(): array
    {
        if (self::$property_map !== null) {
            return self::$property_map;
        }
        /** @var array<lowercase-string, array<string, string>> */
        $property_map = require dirname(__DIR__, 4) . '/dictionaries/PropertyMap.php';
        self::$property_map = $property_map;
        return self::$property_map;
    }
    public static function in_property_map(string $class_name): bool
    {
        return isset(self::get_property_map()[strtolower($class_name)]);
    }
}