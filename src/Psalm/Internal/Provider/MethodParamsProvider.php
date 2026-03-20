<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider;

use Closure;
use Php_Parser\Node\Arg;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Provider\Return_Type_Provider\Pdo_Statement_Set_Fetch_Mode;
use Psalm\Plugin\Event_Handler\Event\Method_Params_Provider_Event;
use Psalm\Plugin\Event_Handler\Method_Params_Provider_Interface;
use Psalm\Statements_Source;
use Psalm\Storage\Function_Like_Parameter;
use function array_values;
use function is_subclass_of;
use function strtolower;
/**
 * @internal
 */
final class Method_Params_Provider
{
    /**
     * @var array<
     *   lowercase-string,
     *   array<Closure(MethodParamsProviderEvent): ?array<int, FunctionLikeParameter>>
     * >
     */
    private static array $handlers = [];
    public function __construct()
    {
        self::$handlers = [];
        $this->register_class(Pdo_Statement_Set_Fetch_Mode::class);
    }
    /**
     * @param class-string $class
     */
    public function register_class(string $class): void
    {
        if (is_subclass_of($class, Method_Params_Provider_Interface::class, true)) {
            $callable = $class::get_method_params(...);
            foreach ($class::get_class_like_names() as $fq_classlike_name) {
                $this->register_closure($fq_classlike_name, $callable);
            }
        }
    }
    /**
     * @param Closure(MethodParamsProviderEvent): ?array<int, FunctionLikeParameter> $c
     */
    public function register_closure(string $fq_classlike_name, Closure $c): void
    {
        self::$handlers[strtolower($fq_classlike_name)][] = $c;
    }
    public function has(string $fq_classlike_name): bool
    {
        return isset(self::$handlers[strtolower($fq_classlike_name)]);
    }
    /**
     * @param ?list<Arg>  $call_args
     * @return  ?list<FunctionLikeParameter>
     */
    public function get_method_params(string $fq_classlike_name, string $method_name_lowercase, ?array $call_args = null, ?Statements_Source $statements_source = null, ?Context $context = null, ?Code_Location $code_location = null): ?array
    {
        foreach (self::$handlers[strtolower($fq_classlike_name)] ?? [] as $class_handler) {
            $event = new Method_Params_Provider_Event($fq_classlike_name, $method_name_lowercase, $call_args, $statements_source, $context, $code_location);
            $result = $class_handler($event);
            if ($result !== null) {
                return array_values($result);
            }
        }
        return null;
    }
}