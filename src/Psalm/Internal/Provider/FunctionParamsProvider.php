<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider;

use Closure;
use Php_Parser\Node\Arg;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Provider\Params_Provider\Array_Filter_Params_Provider;
use Psalm\Internal\Provider\Params_Provider\Array_Multisort_Params_Provider;
use Psalm\Internal\Provider\Params_Provider\Array_U_Array_Params_Provider;
use Psalm\Plugin\Event_Handler\Event\Function_Params_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Params_Provider_Interface;
use Psalm\Statements_Source;
use Psalm\Storage\Function_Like_Parameter;
use function strtolower;
/**
 * @internal
 */
final class Function_Params_Provider
{
    /**
     * @var array<
     *   lowercase-string,
     *   array<Closure(FunctionParamsProviderEvent): ?array<int, FunctionLikeParameter>>
     * >
     */
    private static array $handlers = [];
    public function __construct()
    {
        self::$handlers = [];
        $this->register_class(Array_Filter_Params_Provider::class);
        $this->register_class(Array_Multisort_Params_Provider::class);
        $this->register_class(Array_U_Array_Params_Provider::class);
    }
    /**
     * @param class-string<FunctionParamsProviderInterface> $class
     */
    public function register_class(string $class): void
    {
        $callable = $class::get_function_params(...);
        foreach ($class::get_function_ids() as $function_id) {
            $this->register_closure($function_id, $callable);
        }
    }
    /**
     * @param Closure(FunctionParamsProviderEvent): ?array<int, FunctionLikeParameter> $c
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
     * @param list<Arg> $call_args
     * @return  ?array<int, FunctionLikeParameter>
     */
    public function get_function_params(Statements_Source $statements_source, string $function_id, array $call_args, ?Context $context = null, ?Code_Location $code_location = null): ?array
    {
        foreach (self::$handlers[strtolower($function_id)] ?? [] as $class_handler) {
            $event = new Function_Params_Provider_Event($statements_source, $function_id, $call_args, $context, $code_location);
            $result = $class_handler($event);
            if ($result) {
                return $result;
            }
        }
        return null;
    }
}