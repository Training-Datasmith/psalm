<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider;

use Closure;
use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Provider\Return_Type_Provider\Array_Chunk_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Array_Column_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Array_Combine_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Array_Fill_Keys_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Array_Fill_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Array_Filter_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Array_Map_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Array_Merge_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Array_Pad_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Array_Pointer_Adjustment_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Array_Pop_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Array_Rand_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Array_Reduce_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Array_Reverse_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Array_Slice_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Array_Splice_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Basename_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Date_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Dirname_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Filter_Input_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Filter_Var_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\First_Arg_String_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Get_Class_Methods_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Get_Object_Vars_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Hexdec_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\In_Array_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Iterator_To_Array_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Mb_Internal_Encoding_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Min_Max_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Mktime_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Parse_Url_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Pow_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Rand_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Round_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Sprintf_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Str_Replace_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Str_Tr_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Trigger_Error_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Version_Compare_Return_Type_Provider;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Statements_Source;
use Psalm\Type\Union;
use function is_subclass_of;
use function strtolower;
/**
 * @internal
 */
final class Function_Return_Type_Provider
{
    /**
     * @var array<
     *   lowercase-string,
     *   array<Closure(FunctionReturnTypeProviderEvent): ?Union>
     * >
     */
    private static array $handlers = [];
    public function __construct()
    {
        self::$handlers = [];
        $this->register_class(Array_Chunk_Return_Type_Provider::class);
        $this->register_class(Array_Column_Return_Type_Provider::class);
        $this->register_class(Array_Combine_Return_Type_Provider::class);
        $this->register_class(Array_Filter_Return_Type_Provider::class);
        $this->register_class(Array_Map_Return_Type_Provider::class);
        $this->register_class(Array_Merge_Return_Type_Provider::class);
        $this->register_class(Array_Pad_Return_Type_Provider::class);
        $this->register_class(Array_Pointer_Adjustment_Return_Type_Provider::class);
        $this->register_class(Array_Pop_Return_Type_Provider::class);
        $this->register_class(Array_Rand_Return_Type_Provider::class);
        $this->register_class(Array_Reduce_Return_Type_Provider::class);
        $this->register_class(Array_Slice_Return_Type_Provider::class);
        $this->register_class(Array_Splice_Return_Type_Provider::class);
        $this->register_class(Array_Reverse_Return_Type_Provider::class);
        $this->register_class(Array_Fill_Return_Type_Provider::class);
        $this->register_class(Array_Fill_Keys_Return_Type_Provider::class);
        $this->register_class(Filter_Input_Return_Type_Provider::class);
        $this->register_class(Filter_Var_Return_Type_Provider::class);
        $this->register_class(Iterator_To_Array_Return_Type_Provider::class);
        $this->register_class(Parse_Url_Return_Type_Provider::class);
        $this->register_class(Str_Replace_Return_Type_Provider::class);
        $this->register_class(Str_Tr_Return_Type_Provider::class);
        $this->register_class(Version_Compare_Return_Type_Provider::class);
        $this->register_class(Mktime_Return_Type_Provider::class);
        $this->register_class(Basename_Return_Type_Provider::class);
        $this->register_class(Dirname_Return_Type_Provider::class);
        $this->register_class(Get_Object_Vars_Return_Type_Provider::class);
        $this->register_class(Get_Class_Methods_Return_Type_Provider::class);
        $this->register_class(First_Arg_String_Return_Type_Provider::class);
        $this->register_class(Hexdec_Return_Type_Provider::class);
        $this->register_class(Min_Max_Return_Type_Provider::class);
        $this->register_class(Trigger_Error_Return_Type_Provider::class);
        $this->register_class(Rand_Return_Type_Provider::class);
        $this->register_class(In_Array_Return_Type_Provider::class);
        $this->register_class(Round_Return_Type_Provider::class);
        $this->register_class(Mb_Internal_Encoding_Return_Type_Provider::class);
        $this->register_class(Date_Return_Type_Provider::class);
        $this->register_class(Pow_Return_Type_Provider::class);
        $this->register_class(Sprintf_Return_Type_Provider::class);
    }
    /**
     * @param class-string $class
     */
    public function register_class(string $class): void
    {
        if (is_subclass_of($class, Function_Return_Type_Provider_Interface::class, true)) {
            $callable = $class::get_function_return_type(...);
            foreach ($class::get_function_ids() as $function_id) {
                $this->register_closure($function_id, $callable);
            }
        }
    }
    /**
     * @param lowercase-string $function_id
     * @param Closure(FunctionReturnTypeProviderEvent): ?Union $c
     */
    public function register_closure(string $function_id, Closure $c): void
    {
        self::$handlers[$function_id][] = $c;
    }
    public function has(string $function_id): bool
    {
        return isset(self::$handlers[strtolower($function_id)]);
    }
    /**
     * @param  non-empty-string $function_id
     */
    public function get_return_type(Statements_Source $statements_source, string $function_id, Php_Parser\Node\Expr\Func_Call $stmt, Context $context, Code_Location $code_location): ?Union
    {
        foreach (self::$handlers[strtolower($function_id)] ?? [] as $function_handler) {
            $event = new Function_Return_Type_Provider_Event($statements_source, $function_id, $stmt, $context, $code_location);
            $return_type = $function_handler($event);
            if ($return_type) {
                return $return_type;
            }
        }
        return null;
    }
}