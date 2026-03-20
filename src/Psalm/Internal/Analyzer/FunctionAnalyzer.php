<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer;

use Php_Parser;
use Psalm\Config;
use Psalm\Context;
use Psalm\Storage\Unserialize_Memory_Usage_Suppression_Trait;
use UnexpectedValueException;
use function is_string;
use function strtolower;
/**
 * @internal
 * @extends FunctionLikeAnalyzer<PhpParser\Node\Stmt\Function_>
 */
final class Function_Analyzer extends Function_Like_Analyzer
{
    use Unserialize_Memory_Usage_Suppression_Trait;
    public function __construct(Php_Parser\Node\Stmt\Function_ $function, Source_Analyzer $source)
    {
        $codebase = $source->get_codebase();
        $file_storage_provider = $codebase->file_storage_provider;
        $file_storage = $file_storage_provider->get($source->get_file_path());
        $namespace = $source->get_namespace();
        $function_id = ($namespace ? strtolower($namespace) . '\\' : '') . strtolower($function->name->name);
        if (!isset($file_storage->functions[$function_id])) {
            throw new UnexpectedValueException('Function ' . $function_id . ' should be defined in ' . $source->get_file_path());
        }
        $storage = $file_storage->functions[$function_id];
        parent::__construct($function, $source, $storage);
    }
    /**
     * @return non-empty-lowercase-string
     * @throws UnexpectedValueException if function is closure or arrow function.
     */
    public function get_function_id(): string
    {
        $namespace = $this->source->get_namespace();
        return ($namespace ? strtolower($namespace) . '\\' : '') . strtolower($this->function->name->name);
    }
    public static function analyze_statement(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Stmt\Function_ $stmt, Context $context): void
    {
        foreach ($stmt->stmts as $function_stmt) {
            if ($function_stmt instanceof Php_Parser\Node\Stmt\Global_) {
                foreach ($function_stmt->vars as $var) {
                    if (!$var instanceof Php_Parser\Node\Expr\Variable) {
                        continue;
                    }
                    if (!is_string($var->name)) {
                        continue;
                    }
                    $var_id = '$' . $var->name;
                    // registers variable in global context
                    $context->has_variable($var_id);
                }
            } elseif (!$function_stmt instanceof Php_Parser\Node\Stmt\Nop) {
                break;
            }
        }
        $codebase = $statements_analyzer->get_codebase();
        if (!$codebase->register_stub_files && !$codebase->register_autoload_files) {
            $function_name = strtolower($stmt->name->name);
            if ($ns = $statements_analyzer->get_namespace()) {
                $fq_function_name = strtolower($ns) . '\\' . $function_name;
            } else {
                $fq_function_name = $function_name;
            }
            $function_context = new Context($context->self);
            $function_context->strict_types = $context->strict_types;
            $config = Config::get_instance();
            $function_context->collect_exceptions = $config->check_for_throws_docblock;
            if ($function_analyzer = $statements_analyzer->get_function_analyzer($fq_function_name)) {
                $function_analyzer->analyze($function_context, $statements_analyzer->node_data, $context);
                if ($config->report_issue_in_file('InvalidReturnType', $statements_analyzer->get_file_path())) {
                    $method_id = $function_analyzer->get_id();
                    $function_storage = $codebase->functions->get_storage($statements_analyzer, strtolower($method_id));
                    $return_type = $function_storage->return_type;
                    $return_type_location = $function_storage->return_type_location;
                    $function_analyzer->verify_return_type($stmt->get_stmts(), $statements_analyzer, $return_type, $statements_analyzer->get_fqcln(), $return_type_location, $function_context->has_returned);
                }
            }
        }
    }
}