<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider;

use Closure;
use Psalm\Code_Location;
use Psalm\Plugin\Event_Handler\Event\Method_Existence_Provider_Event;
use Psalm\Plugin\Event_Handler\Method_Existence_Provider_Interface;
use Psalm\Statements_Source;
use function strtolower;
/**
 * @internal
 */
final class Method_Existence_Provider
{
    /**
     * @var array<
     *   lowercase-string,
     *   array<Closure(MethodExistenceProviderEvent): ?bool>
     * >
     */
    private static array $handlers = [];
    public function __construct()
    {
        self::$handlers = [];
    }
    /**
     * @param class-string<MethodExistenceProviderInterface> $class
     */
    public function register_class(string $class): void
    {
        $callable = $class::does_method_exist(...);
        foreach ($class::get_class_like_names() as $fq_classlike_name) {
            $this->register_closure($fq_classlike_name, $callable);
        }
    }
    /**
     * @param Closure(MethodExistenceProviderEvent): ?bool $c
     */
    public function register_closure(string $fq_classlike_name, Closure $c): void
    {
        self::$handlers[strtolower($fq_classlike_name)][] = $c;
    }
    public function has(string $fq_classlike_name): bool
    {
        return isset(self::$handlers[strtolower($fq_classlike_name)]);
    }
    public function does_method_exist(string $fq_classlike_name, string $method_name_lowercase, ?Statements_Source $source = null, ?Code_Location $code_location = null): ?bool
    {
        foreach (self::$handlers[strtolower($fq_classlike_name)] ?? [] as $method_handler) {
            $event = new Method_Existence_Provider_Event($fq_classlike_name, $method_name_lowercase, $source, $code_location);
            $method_exists = $method_handler($event);
            if ($method_exists !== null) {
                return $method_exists;
            }
        }
        return null;
    }
}