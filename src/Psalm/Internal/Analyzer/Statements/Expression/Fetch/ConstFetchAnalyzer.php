<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Fetch;

use Php_Parser;
use Psalm\Aliases;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Namespace_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Simple_Type_Inferer;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Provider\Node_Data_Provider;
use Psalm\Issue\Forbidden_Code;
use Psalm\Issue\Undefined_Constant;
use Psalm\Issue_Buffer;
use Psalm\Type;
use Psalm\Type\Union;
use ReflectionProperty;
use function array_key_exists;
use function array_pop;
use function explode;
use function implode;
use function strtolower;
/**
 * @internal
 */
final class Const_Fetch_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Const_Fetch $stmt, Context $context): void
    {
        $const_name = $stmt->name->to_string();
        switch (strtolower($const_name)) {
            case 'null':
                $statements_analyzer->node_data->set_type($stmt, Type::get_null());
                break;
            case 'false':
                // false is a subtype of bool
                $statements_analyzer->node_data->set_type($stmt, Type::get_false());
                break;
            case 'true':
                $statements_analyzer->node_data->set_type($stmt, Type::get_true());
                break;
            case 'stdin':
                $statements_analyzer->node_data->set_type($stmt, Type::get_resource());
                break;
            default:
                if (isset($statements_analyzer->get_codebase()->config->forbidden_constants[$const_name])) {
                    Issue_Buffer::maybe_add(new Forbidden_Code('You have forbidden the use of ' . $const_name, new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                    return;
                }
                $const_type = self::get_const_type($statements_analyzer, $const_name, $stmt->name instanceof Php_Parser\Node\Name\Fully_Qualified, $context);
                $codebase = $statements_analyzer->get_codebase();
                $aliased_constants = $statements_analyzer->get_aliases()->constants;
                if (isset($aliased_constants[$const_name])) {
                    $fq_const_name = $aliased_constants[$const_name];
                } elseif ($stmt->name instanceof Php_Parser\Node\Name\Fully_Qualified) {
                    $fq_const_name = $const_name;
                } else {
                    $fq_const_name = Type::get_fqcln_from_string($const_name, $statements_analyzer->get_aliases());
                }
                $codebase->analyzer->add_node_reference($statements_analyzer->get_file_path(), $stmt, $const_type ? $fq_const_name : '*' . ($stmt->name instanceof Php_Parser\Node\Name\Fully_Qualified ? '\\' : $statements_analyzer->get_namespace() . '-') . $const_name);
                if ($const_type) {
                    $statements_analyzer->node_data->set_type($stmt, $const_type);
                } elseif ($context->check_consts) {
                    Issue_Buffer::maybe_add(new Undefined_Constant('Const ' . $const_name . ' is not defined' . ', consider enabling the allConstantsGlobal config option if scanning legacy codebases', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                }
        }
    }
    public static function get_global_const_type(Codebase $codebase, string $fq_const_name, string $const_name): ?Union
    {
        if ($const_name === 'STDERR' || $const_name === 'STDOUT' || $const_name === 'STDIN') {
            return Type::get_resource();
        }
        if ($fq_const_name) {
            $stubbed_const_type = $codebase->get_stubbed_constant_type($fq_const_name);
            if ($stubbed_const_type) {
                return $stubbed_const_type;
            }
        }
        $stubbed_const_type = $codebase->get_stubbed_constant_type($const_name);
        if ($stubbed_const_type) {
            return $stubbed_const_type;
        }
        $predefined_constants = $codebase->config->get_predefined_constants();
        if ($fq_const_name && array_key_exists($fq_const_name, $predefined_constants) || array_key_exists($const_name, $predefined_constants)) {
            switch ($const_name) {
                case 'DIRECTORY_SEPARATOR':
                case 'PATH_SEPARATOR':
                case 'PHP_EOL':
                    return Type::get_single_letter();
                case 'PHP_VERSION':
                    return Type::get_non_empty_string();
                case 'PEAR_EXTENSION_DIR':
                case 'PEAR_INSTALL_DIR':
                case 'PHP_BINARY':
                case 'PHP_BINDIR':
                case 'PHP_CONFIG_FILE_PATH':
                case 'PHP_CONFIG_FILE_SCAN_DIR':
                case 'PHP_DATADIR':
                case 'PHP_EXTENSION_DIR':
                case 'PHP_EXTRA_VERSION':
                case 'PHP_LIBDIR':
                case 'PHP_LOCALSTATEDIR':
                case 'PHP_MANDIR':
                case 'PHP_OS':
                case 'PHP_OS_FAMILY':
                case 'PHP_PREFIX':
                case 'PHP_SAPI':
                case 'PHP_SYSCONFDIR':
                    return Type::get_string();
                case 'PHP_MAJOR_VERSION':
                case 'PHP_MINOR_VERSION':
                case 'PHP_RELEASE_VERSION':
                case 'PHP_DEBUG':
                case 'PHP_FLOAT_DIG':
                case 'PHP_INT_MIN':
                case 'PHP_ZTS':
                    return Type::get_int();
                case 'PHP_INT_MAX':
                case 'PHP_INT_SIZE':
                case 'PHP_MAXPATHLEN':
                case 'PHP_VERSION_ID':
                    return Type::get_int_range(1, null);
                case 'PHP_FLOAT_EPSILON':
                case 'PHP_FLOAT_MAX':
                case 'PHP_FLOAT_MIN':
                    return Type::get_float();
            }
            if ($fq_const_name && array_key_exists($fq_const_name, $predefined_constants)) {
                return Class_Like_Analyzer::get_type_from_value($predefined_constants[$fq_const_name]);
            }
            return Class_Like_Analyzer::get_type_from_value($predefined_constants[$const_name]);
        }
        return null;
    }
    public static function get_const_type(Statements_Analyzer $statements_analyzer, string $const_name, bool $is_fully_qualified, ?Context $context): ?Union
    {
        $aliased_constants = $statements_analyzer->get_aliases()->constants;
        if (isset($aliased_constants[$const_name])) {
            $fq_const_name = $aliased_constants[$const_name];
        } elseif ($is_fully_qualified) {
            $fq_const_name = $const_name;
        } else {
            $fq_const_name = Type::get_fqcln_from_string($const_name, $statements_analyzer->get_aliases());
        }
        if ($fq_const_name) {
            $const_name_parts = explode('\\', $fq_const_name);
            $const_name = array_pop($const_name_parts);
            $namespace_name = implode('\\', $const_name_parts);
            $namespace_constants = Namespace_Analyzer::get_constants_for_namespace($namespace_name, ReflectionProperty::IS_PUBLIC);
            if (isset($namespace_constants[$const_name])) {
                return $namespace_constants[$const_name];
            }
        }
        if ($context && $context->has_variable($fq_const_name)) {
            return $context->vars_in_scope[$fq_const_name];
        }
        $file_path = $statements_analyzer->get_root_file_path();
        $codebase = $statements_analyzer->get_codebase();
        $file_storage_provider = $codebase->file_storage_provider;
        $file_storage = $file_storage_provider->get($file_path);
        if (isset($file_storage->declaring_constants[$const_name])) {
            $constant_file_path = $file_storage->declaring_constants[$const_name];
            return $file_storage_provider->get($constant_file_path)->constants[$const_name];
        }
        if (isset($file_storage->declaring_constants[$fq_const_name])) {
            $constant_file_path = $file_storage->declaring_constants[$fq_const_name];
            return $file_storage_provider->get($constant_file_path)->constants[$fq_const_name];
        }
        return self::get_global_const_type($codebase, $fq_const_name, $const_name) ?? self::get_global_const_type($codebase, $const_name, $const_name);
    }
    public static function set_const_type(Statements_Analyzer $statements_analyzer, string $const_name, Union $const_type, Context $context): void
    {
        $context->vars_in_scope[$const_name] = $const_type;
        $context->constants[$const_name] = $const_type;
        $source = $statements_analyzer->get_source();
        if ($source instanceof Namespace_Analyzer) {
            $source->set_const_type($const_name, $const_type);
        }
    }
    public static function get_const_name(Php_Parser\Node\Expr $first_arg_value, Node_Data_Provider $type_provider, Codebase $codebase, Aliases $aliases): ?string
    {
        $const_name = null;
        if ($first_arg_value instanceof Php_Parser\Node\Scalar\String_) {
            $const_name = $first_arg_value->value;
        } elseif ($first_arg_type = $type_provider->get_type($first_arg_value)) {
            if ($first_arg_type->is_single_string_literal()) {
                $const_name = $first_arg_type->get_single_string_literal()->value;
            }
        } else {
            $simple_type = Simple_Type_Inferer::infer($codebase, $type_provider, $first_arg_value, $aliases);
            if ($simple_type && $simple_type->is_single_string_literal()) {
                $const_name = $simple_type->get_single_string_literal()->value;
            }
        }
        return $const_name;
    }
    public static function analyze_const_assignment(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Stmt\Const_ $stmt, Context $context): void
    {
        foreach ($stmt->consts as $const) {
            Expression_Analyzer::analyze($statements_analyzer, $const->value, $context);
            self::set_const_type($statements_analyzer, $const->name->name, $statements_analyzer->node_data->get_type($const->value) ?? Type::get_mixed(), $context);
        }
    }
}