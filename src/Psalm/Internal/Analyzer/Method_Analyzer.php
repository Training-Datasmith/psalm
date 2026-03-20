<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer;

use LogicException;
use Php_Parser;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\Codebase\Internal_Call_Map_Handler;
use Psalm\Internal\Method_Identifier;
use Psalm\Issue\Invalid_Enum_Method;
use Psalm\Issue\Invalid_Static_Invocation;
use Psalm\Issue\Method_Signature_Must_Omit_Return_Type;
use Psalm\Issue\Non_Static_Self_Call;
use Psalm\Issue\Undefined_Magic_Method;
use Psalm\Issue\Undefined_Method;
use Psalm\Issue_Buffer;
use Psalm\Statements_Source;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Storage\Method_Storage;
use Psalm\Storage\Unserialize_Memory_Usage_Suppression_Trait;
use UnexpectedValueException;
use function in_array;
use function strtolower;
/**
 * @internal
 * @extends FunctionLikeAnalyzer<PhpParser\Node\Stmt\ClassMethod>
 */
final class Method_Analyzer extends Function_Like_Analyzer
{
    use Unserialize_Memory_Usage_Suppression_Trait;
    // https://github.com/php/php-src/blob/a83923044c48982c80804ae1b45e761c271966d3/Zend/zend_enum.c#L77-L95
    private const FORBIDDEN_ENUM_METHODS = ['__construct', '__destruct', '__clone', '__get', '__set', '__unset', '__isset', '__tostring', '__debuginfo', '__serialize', '__unserialize', '__sleep', '__wakeup', '__set_state'];
    /** @psalm-external-mutation-free */
    public function __construct(Php_Parser\Node\Stmt\Class_Method $function, Source_Analyzer $source, ?Method_Storage $storage = null)
    {
        $codebase = $source->get_codebase();
        $method_name_lc = strtolower((string) $function->name);
        $source_fqcln = (string) $source->get_fqcln();
        $source_fqcln_lc = strtolower($source_fqcln);
        $method_id = new Method_Identifier($source_fqcln, $method_name_lc);
        if (!$storage) {
            try {
                $storage = $codebase->methods->get_storage($method_id);
            } catch (UnexpectedValueException $e) {
                $class_storage = $codebase->classlike_storage_provider->get($source_fqcln_lc);
                if (!$class_storage->parent_classes) {
                    throw $e;
                }
                $declaring_method_id = $codebase->methods->get_declaring_method_id($method_id);
                if (!$declaring_method_id) {
                    throw $e;
                }
                // happens for fake constructors
                $storage = $codebase->methods->get_storage($declaring_method_id);
            }
        }
        parent::__construct($function, $source, $storage);
    }
    /**
     * Determines whether a given method is static or not
     *
     * @param  array<string>   $suppressed_issues
     */
    public static function check_static(Method_Identifier $method_id, bool $self_call, bool $is_context_dynamic, Codebase $codebase, Code_Location $code_location, array $suppressed_issues, ?bool &$is_dynamic_this_method = false): void
    {
        $codebase_methods = $codebase->methods;
        if ($method_id->fq_class_name === 'Closure' && $method_id->method_name === 'fromcallable') {
            return;
        }
        $original_method_id = $method_id;
        $with_pseudo = true;
        $method_id = $codebase_methods->get_declaring_method_id($method_id, $with_pseudo);
        if (!$method_id) {
            if (Internal_Call_Map_Handler::in_call_map((string) $original_method_id)) {
                return;
            }
            throw new LogicException('Declaring method for ' . $original_method_id . ' should not be null');
        }
        $storage = $codebase_methods->get_storage($method_id, $with_pseudo);
        if (!$storage->is_static) {
            if ($self_call) {
                if (!$is_context_dynamic) {
                    if (Issue_Buffer::accepts(new Non_Static_Self_Call('Method ' . $codebase_methods->get_cased_method_id($method_id) . ' is not static, but is called ' . 'using self::', $code_location), $suppressed_issues)) {
                        return;
                    }
                } else {
                    $is_dynamic_this_method = true;
                }
            } else if (Issue_Buffer::accepts(new Invalid_Static_Invocation('Method ' . $codebase_methods->get_cased_method_id($method_id) . ' is not static, but is called ' . 'statically', $code_location), $suppressed_issues)) {
                return;
            }
        }
    }
    /**
     * @param  string[]     $suppressed_issues
     * @param  lowercase-string|null  $calling_method_id
     */
    public static function check_method_exists(Codebase $codebase, Method_Identifier $method_id, Code_Location $code_location, array $suppressed_issues, ?string $calling_method_id = null, bool $with_pseudo = false): ?bool
    {
        if ($codebase->methods->method_exists($method_id, $calling_method_id, !$calling_method_id || $calling_method_id !== strtolower((string) $method_id) ? $code_location : null, null, $code_location->file_path, true, false, $with_pseudo)) {
            return true;
        }
        if ($with_pseudo) {
            if (Issue_Buffer::accepts(new Undefined_Magic_Method('Magic method ' . $method_id . ' does not exist', $code_location, (string) $method_id), $suppressed_issues)) {
                return false;
            }
        } else if (Issue_Buffer::accepts(new Undefined_Method('Method ' . $method_id . ' does not exist', $code_location, (string) $method_id), $suppressed_issues)) {
            return false;
        }
        return null;
    }
    public static function is_method_visible(Method_Identifier $method_id, Context $context, Statements_Source $source): bool
    {
        $codebase = $source->get_codebase();
        $fq_classlike_name = $method_id->fq_class_name;
        $method_name = $method_id->method_name;
        if ($codebase->methods->visibility_provider->has($fq_classlike_name)) {
            $method_visible = $codebase->methods->visibility_provider->is_method_visible($source, $fq_classlike_name, $method_name, $context);
            if ($method_visible !== null) {
                return $method_visible;
            }
        }
        $declaring_method_id = $codebase->methods->get_declaring_method_id($method_id);
        if (!$declaring_method_id) {
            // this can happen for methods in the callmap that were not reflected
            return true;
        }
        $appearing_method_id = $codebase->methods->get_appearing_method_id($method_id);
        $appearing_method_class = null;
        if ($appearing_method_id) {
            $appearing_method_class = $appearing_method_id->fq_class_name;
            // if the calling class is the same, we know the method exists, so it must be visible
            if ($appearing_method_class === $context->self) {
                return true;
            }
        }
        $declaring_method_class = $declaring_method_id->fq_class_name;
        if ($source->get_source() instanceof Trait_Analyzer && strtolower($declaring_method_class) === strtolower((string) $source->get_fqcln())) {
            return true;
        }
        $storage = $codebase->methods->get_storage($declaring_method_id);
        switch ($storage->visibility) {
            case Class_Like_Analyzer::VISIBILITY_PUBLIC:
                return true;
            case Class_Like_Analyzer::VISIBILITY_PRIVATE:
                return $context->self && $appearing_method_class === $context->self;
            case Class_Like_Analyzer::VISIBILITY_PROTECTED:
                if (!$context->self) {
                    return false;
                }
                if ($appearing_method_class && $codebase->class_extends($appearing_method_class, $context->self)) {
                    return true;
                }
                if ($appearing_method_class && !$codebase->class_extends($context->self, $appearing_method_class)) {
                    return false;
                }
        }
        return true;
    }
    /**
     * Check that __clone, __construct, and __destruct do not have a return type
     * hint in their signature.
     */
    public static function check_method_signature_must_omit_return_type(Method_Storage $method_storage, Code_Location $code_location): void
    {
        if ($method_storage->signature_return_type === null) {
            return;
        }
        if ($method_storage->cased_name === null) {
            return;
        }
        $method_name_lc = strtolower($method_storage->cased_name);
        $methods_of_interest = ['__clone', '__construct', '__destruct'];
        if (in_array($method_name_lc, $methods_of_interest, true)) {
            Issue_Buffer::maybe_add(new Method_Signature_Must_Omit_Return_Type('Method ' . $method_storage->cased_name . ' must not declare a return type', $code_location));
        }
    }
    public function get_method_id(?string $context_self = null): Method_Identifier
    {
        $function_name = (string) $this->function->name;
        return new Method_Identifier($context_self ?: (string) $this->source->get_fqcln(), strtolower($function_name));
    }
    public static function check_forbidden_enum_method(Method_Storage $method_storage, Class_Like_Storage $enum_storage): void
    {
        if ($method_storage->cased_name === null || $method_storage->location === null) {
            return;
        }
        $method_name_lc = strtolower($method_storage->cased_name);
        if (in_array($method_name_lc, self::FORBIDDEN_ENUM_METHODS, true)) {
            Issue_Buffer::maybe_add(new Invalid_Enum_Method('Enums cannot define ' . $method_storage->cased_name, $method_storage->location, $method_storage->defining_fqcln . '::' . $method_storage->cased_name));
        }
        if ($method_name_lc === 'cases') {
            Issue_Buffer::maybe_add(new Invalid_Enum_Method('Enums cannot define ' . $method_storage->cased_name, $method_storage->location, $method_storage->defining_fqcln . '::' . $method_storage->cased_name));
        }
        if ($enum_storage->enum_type && ($method_name_lc === 'from' || $method_name_lc === 'tryfrom')) {
            Issue_Buffer::maybe_add(new Invalid_Enum_Method('Enums cannot define ' . $method_storage->cased_name, $method_storage->location, $method_storage->defining_fqcln . '::' . $method_storage->cased_name));
        }
    }
}