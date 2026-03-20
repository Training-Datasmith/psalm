<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider;

use Closure;
use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Provider\Return_Type_Provider\Closure_From_Callable_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Date_Time_Modify_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Dom_Node_Append_Child;
use Psalm\Internal\Provider\Return_Type_Provider\Imagick_Pixel_Color_Return_Type_Provider;
use Psalm\Internal\Provider\Return_Type_Provider\Pdo_Statement_Return_Type_Provider;
use Psalm\Plugin\Event_Handler\Event\Method_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Method_Return_Type_Provider_Interface;
use Psalm\Statements_Source;
use Psalm\Type\Union;
use function is_subclass_of;
use function strtolower;
/**
 * @internal
 */
final class Method_Return_Type_Provider
{
    /**
     * @var array<
     *   lowercase-string,
     *   array<Closure(MethodReturnTypeProviderEvent): ?Union>
     * >
     */
    private static array $handlers = [];
    public function __construct()
    {
        self::$handlers = [];
        $this->register_class(Dom_Node_Append_Child::class);
        $this->register_class(Imagick_Pixel_Color_Return_Type_Provider::class);
        $this->register_class(Pdo_Statement_Return_Type_Provider::class);
        $this->register_class(Closure_From_Callable_Return_Type_Provider::class);
        $this->register_class(Date_Time_Modify_Return_Type_Provider::class);
    }
    /**
     * @param class-string $class
     */
    public function register_class(string $class): void
    {
        if (is_subclass_of($class, Method_Return_Type_Provider_Interface::class, true)) {
            $callable = $class::get_method_return_type(...);
            foreach ($class::get_class_like_names() as $fq_classlike_name) {
                $this->register_closure($fq_classlike_name, $callable);
            }
        }
    }
    /**
     * @param Closure(MethodReturnTypeProviderEvent): ?Union $c
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
     * @param non-empty-list<Union>|null $template_type_parameters
     */
    public function get_return_type(Statements_Source $statements_source, string $fq_classlike_name, string $method_name, Php_Parser\Node\Expr\Method_Call|Php_Parser\Node\Expr\Static_Call $stmt, Context $context, Code_Location $code_location, ?array $template_type_parameters = null, ?string $called_fq_classlike_name = null, ?string $called_method_name = null): ?Union
    {
        foreach (self::$handlers[strtolower($fq_classlike_name)] ?? [] as $class_handler) {
            $event = new Method_Return_Type_Provider_Event($statements_source, $fq_classlike_name, strtolower($method_name), $stmt, $context, $code_location, $template_type_parameters, $called_fq_classlike_name, $called_method_name ? strtolower($called_method_name) : null);
            $result = $class_handler($event);
            if ($result) {
                return $result;
            }
        }
        return null;
    }
}