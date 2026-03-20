<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider;

use Closure;
use Psalm\Plugin\Event_Handler\Event\Function_Existence_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Existence_Provider_Interface;
use Psalm\Statements_Source;
use function is_subclass_of;
use function strtolower;
/**
 * @internal
 */
final class Function_Existence_Provider
{
    /**
     * @var array<
     *   lowercase-string,
     *   array<Closure(FunctionExistenceProviderEvent): ?bool>
     * >
     */
    private static array $handlers = [];
    public function __construct()
    {
        self::$handlers = [];
    }
    /**
     * @param class-string $class
     */
    public function register_class(string $class): void
    {
        if (is_subclass_of($class, Function_Existence_Provider_Interface::class, true)) {
            $callable = $class::does_function_exist(...);
            foreach ($class::get_function_ids() as $function_id) {
                $this->register_closure($function_id, $callable);
            }
        }
    }
    /**
     * @param lowercase-string $function_id
     * @param Closure(FunctionExistenceProviderEvent): ?bool $c
     */
    public function register_closure(string $function_id, Closure $c): void
    {
        self::$handlers[$function_id][] = $c;
    }
    public function has(string $function_id): bool
    {
        return isset(self::$handlers[strtolower($function_id)]);
    }
    public function does_function_exist(Statements_Source $statements_source, string $function_id): ?bool
    {
        foreach (self::$handlers[strtolower($function_id)] ?? [] as $function_handler) {
            $event = new Function_Existence_Provider_Event($statements_source, $function_id);
            $function_exists = $function_handler($event);
            if ($function_exists !== null) {
                return $function_exists;
            }
        }
        return null;
    }
}