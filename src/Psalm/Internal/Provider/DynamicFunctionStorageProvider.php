<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider;

use Closure;
use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Plugin\Arg_Type_Inferer;
use Psalm\Plugin\Dynamic_Function_Storage;
use Psalm\Plugin\Dynamic_Template_Provider;
use Psalm\Plugin\Event_Handler\Dynamic_Function_Storage_Provider_Interface;
use Psalm\Plugin\Event_Handler\Event\Dynamic_Function_Storage_Provider_Event;
use Psalm\Storage\Function_Storage;
use function strtolower;
/**
 * For each function call analysis will be created individual FunctionStorage in plugin hook.
 * If it is created be aware, it shadows the FunctionStorage Psalm may generate during the scanning phase.
 *
 * @internal
 */
final class Dynamic_Function_Storage_Provider
{
    /** @var array<lowercase-string, array<Closure(DynamicFunctionStorageProviderEvent): ?DynamicFunctionStorage>> */
    private static array $handlers = [];
    /** @var array<lowercase-string, ?FunctionStorage> */
    private static array $dynamic_storages = [];
    /**
     * @param class-string<DynamicFunctionStorageProviderInterface> $class
     */
    public function register_class(string $class): void
    {
        $callable = $class::get_function_storage(...);
        foreach ($class::get_function_ids() as $function_id) {
            $this->register_closure($function_id, $callable);
        }
    }
    /**
     * @param Closure(DynamicFunctionStorageProviderEvent): ?DynamicFunctionStorage $c
     */
    public function register_closure(string $fq_function_name, Closure $c): void
    {
        self::$handlers[strtolower($fq_function_name)][] = $c;
    }
    public function has(string $fq_function_name): bool
    {
        return isset(self::$handlers[strtolower($fq_function_name)]);
    }
    public function get_function_storage(Php_Parser\Node\Expr\Func_Call $stmt, Statements_Analyzer $statements_analyzer, string $function_id, Context $context, Code_Location $code_location): ?Function_Storage
    {
        if ($stmt->is_first_class_callable()) {
            return null;
        }
        $dynamic_storage_id = strtolower($statements_analyzer->get_file_path()) . ':' . $stmt->get_line() . ':' . (int) $stmt->get_attribute('startFilePos') . ':dynamic-storage' . ':-:' . strtolower($function_id);
        if (isset(self::$dynamic_storages[$dynamic_storage_id])) {
            return self::$dynamic_storages[$dynamic_storage_id];
        }
        foreach (self::$handlers[strtolower($function_id)] ?? [] as $class_handler) {
            $event = new Dynamic_Function_Storage_Provider_Event(new Arg_Type_Inferer($context, $statements_analyzer), new Dynamic_Template_Provider('fn-' . strtolower($function_id)), $statements_analyzer, $function_id, $stmt, $context, $code_location);
            $result = $class_handler($event);
            return self::$dynamic_storages[$dynamic_storage_id] = $result ? $result->to_function_storage($function_id) : null;
        }
        return null;
    }
}