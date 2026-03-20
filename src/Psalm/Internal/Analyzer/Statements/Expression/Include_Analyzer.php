<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression;

use AssertionError;
use Php_Parser;
use Psalm\Code_Location;
use Psalm\Config;
use Psalm\Context;
use Psalm\Exception\File_Include_Exception;
use Psalm\Exception\Unprepared_Analysis_Exception;
use Psalm\Internal\Analyzer\File_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Codebase\Taint_Flow_Graph;
use Psalm\Internal\Data_Flow\Taint_Sink;
use Psalm\Internal\Data_Flow\Taint_Source;
use Psalm\Internal\Provider\Node_Data_Provider;
use Psalm\Issue\Missing_File;
use Psalm\Issue\Unresolvable_Include;
use Psalm\Issue_Buffer;
use Psalm\Plugin\Event_Handler\Event\Add_Remove_Taints_Event;
use Psalm\Type\Taint_Kind;
use Symfony\Component\Filesystem\Path;
use function array_diff;
use function constant;
use function defined;
use function dirname;
use function explode;
use function file_exists;
use function get_include_path;
use function get_included_files;
use function implode;
use function in_array;
use function is_string;
use function preg_last_error_msg;
use function preg_match;
use function preg_replace;
use function preg_split;
use function realpath;
use function str_repeat;
use function str_replace;
use function substr;
use const DIRECTORY_SEPARATOR;
use const PATH_SEPARATOR;
use const PHP_EOL;
/**
 * @internal
 */
final class Include_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Include_ $stmt, Context $context, ?Context $global_context = null): bool
    {
        $codebase = $statements_analyzer->get_codebase();
        $config = $codebase->config;
        if (!$config->allow_includes) {
            throw new File_Include_Exception('File includes are not allowed per your Psalm config - check the allowFileIncludes flag.');
        }
        $was_inside_call = $context->inside_call;
        $context->inside_call = true;
        if (Expression_Analyzer::analyze($statements_analyzer, $stmt->expr, $context) === false) {
            $context->inside_call = $was_inside_call;
            return false;
        }
        $context->inside_call = $was_inside_call;
        $stmt_expr_type = $statements_analyzer->node_data->get_type($stmt->expr);
        if ($stmt->expr instanceof Php_Parser\Node\Scalar\String_ || $stmt_expr_type && $stmt_expr_type->is_single_string_literal()) {
            if ($stmt->expr instanceof Php_Parser\Node\Scalar\String_) {
                $path_to_file = $stmt->expr->value;
            } else {
                $path_to_file = $stmt_expr_type->get_single_string_literal()->value;
            }
            $path_to_file = str_replace('/', DIRECTORY_SEPARATOR, $path_to_file);
            // attempts to resolve using get_include_path dirs
            $include_path = self::resolve_include_path($path_to_file, dirname($statements_analyzer->get_file_path()));
            $path_to_file = $include_path ?: $path_to_file;
            if (Path::is_relative($path_to_file)) {
                $path_to_file = $config->base_dir . DIRECTORY_SEPARATOR . $path_to_file;
            }
        } else {
            $path_to_file = self::get_path_to($stmt->expr, $statements_analyzer->node_data, $statements_analyzer, $statements_analyzer->get_file_name(), $config);
        }
        if ($stmt_expr_type && $statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph && $stmt_expr_type->parent_nodes && !in_array('TaintedInput', $statements_analyzer->get_suppressed_issues())) {
            $arg_location = new Code_Location($statements_analyzer->get_source(), $stmt->expr);
            $include_param_sink = Taint_Sink::get_for_method_argument('include', 'include', 0, $arg_location, $arg_location);
            $include_param_sink->taints = [Taint_Kind::INPUT_INCLUDE];
            $statements_analyzer->data_flow_graph->add_sink($include_param_sink);
            $codebase = $statements_analyzer->get_codebase();
            $event = new Add_Remove_Taints_Event($stmt, $context, $statements_analyzer, $codebase);
            $added_taints = $codebase->config->event_dispatcher->dispatch_add_taints($event);
            $removed_taints = $codebase->config->event_dispatcher->dispatch_remove_taints($event);
            $taints = array_diff($added_taints, $removed_taints);
            if ($taints !== []) {
                $taint_source = Taint_Source::from_node($include_param_sink);
                $taint_source->taints = $taints;
                $statements_analyzer->data_flow_graph->add_source($taint_source);
            }
            foreach ($stmt_expr_type->parent_nodes as $parent_node) {
                $statements_analyzer->data_flow_graph->add_path($parent_node, $include_param_sink, 'arg', $added_taints, $removed_taints);
            }
        }
        if ($path_to_file) {
            $path_to_file = self::normalize_file_path($path_to_file);
            // if the file is already included, we can't check much more
            if (in_array(realpath($path_to_file), get_included_files(), true)) {
                return true;
            }
            $current_file_analyzer = $statements_analyzer->get_file_analyzer();
            if ($current_file_analyzer->project_analyzer->file_exists($path_to_file) && !$current_file_analyzer->project_analyzer->is_directory($path_to_file)) {
                if ($config->ignore_include_side_effects) {
                    return true;
                }
                if ($statements_analyzer->has_parent_file_path($path_to_file) || !$codebase->file_storage_provider->has($path_to_file) || $statements_analyzer->has_already_required_file_path($path_to_file) && (!$codebase->file_storage_provider->get($path_to_file)->has_extra_statements || $config->respect_include_once && in_array($stmt->type, [Php_Parser\Node\Expr\Include_::TYPE_INCLUDE_ONCE, Php_Parser\Node\Expr\Include_::TYPE_REQUIRE_ONCE]))) {
                    return true;
                }
                if ($config->must_be_ignored($path_to_file)) {
                    return true;
                }
                $current_file_analyzer->add_required_file_path($path_to_file);
                $file_name = $config->shorten_file_name($path_to_file);
                $nesting = $statements_analyzer->get_require_nesting() + 1;
                $current_file_analyzer->project_analyzer->progress->debug(str_repeat('  ', $nesting) . 'checking ' . $file_name . PHP_EOL);
                $include_file_analyzer = new File_Analyzer($current_file_analyzer->project_analyzer, $path_to_file, $file_name);
                $include_file_analyzer->set_root_file_path($current_file_analyzer->get_root_file_path(), $current_file_analyzer->get_root_file_name());
                $include_file_analyzer->add_parent_file_path($current_file_analyzer->get_file_path());
                $include_file_analyzer->add_required_file_path($current_file_analyzer->get_file_path());
                foreach ($current_file_analyzer->get_required_file_paths() as $required_file_path) {
                    $include_file_analyzer->add_required_file_path($required_file_path);
                }
                foreach ($current_file_analyzer->get_parent_file_paths() as $parent_file_path) {
                    $include_file_analyzer->add_parent_file_path($parent_file_path);
                }
                try {
                    $include_file_analyzer->analyze($context, $global_context);
                } catch (Unprepared_Analysis_Exception) {
                    if ($config->skip_checks_on_unresolvable_includes) {
                        $context->check_classes = false;
                        $context->check_variables = false;
                        $context->check_functions = false;
                    }
                }
                $included_return_type = $include_file_analyzer->get_return_type();
                if ($included_return_type) {
                    $statements_analyzer->node_data->set_type($stmt, $included_return_type);
                }
                $context->has_returned = false;
                foreach ($include_file_analyzer->get_required_file_paths() as $required_file_path) {
                    $current_file_analyzer->add_required_file_path($required_file_path);
                }
                $include_file_analyzer->clear_source_before_destruction();
                return true;
            }
            if (isset($context->phantom_files[$path_to_file])) {
                return true;
            }
            $var_id = Expression_Identifier::get_extended_var_id($stmt->expr, null);
            if ($var_id && isset($context->phantom_files[$var_id])) {
                return true;
            }
            $source = $statements_analyzer->get_source();
            Issue_Buffer::maybe_add(new Missing_File('Cannot find file ' . $path_to_file . ' to include', new Code_Location($source, $stmt)), $source->get_suppressed_issues());
        } else {
            $var_id = Expression_Identifier::get_extended_var_id($stmt->expr, null);
            if (!$var_id || !isset($context->phantom_files[$var_id])) {
                $source = $statements_analyzer->get_source();
                Issue_Buffer::maybe_add(new Unresolvable_Include('Cannot resolve the given expression to a file path', new Code_Location($source, $stmt)), $source->get_suppressed_issues());
            }
        }
        if ($config->skip_checks_on_unresolvable_includes) {
            $context->check_classes = false;
            $context->check_variables = false;
            $context->check_functions = false;
        }
        return true;
    }
    /**
     * @psalm-suppress MixedAssignment
     */
    public static function get_path_to(Php_Parser\Node\Expr $stmt, ?Node_Data_Provider $type_provider, ?Statements_Analyzer $statements_analyzer, string $file_name, Config $config): ?string
    {
        if (Path::is_relative($file_name)) {
            $file_name = $config->base_dir . DIRECTORY_SEPARATOR . $file_name;
        }
        if ($stmt instanceof Php_Parser\Node\Scalar\String_) {
            if (DIRECTORY_SEPARATOR !== '/') {
                return str_replace('/', DIRECTORY_SEPARATOR, $stmt->value);
            }
            return $stmt->value;
        }
        $stmt_type = $type_provider ? $type_provider->get_type($stmt) : null;
        if ($stmt_type && $stmt_type->is_single_string_literal()) {
            if (DIRECTORY_SEPARATOR !== '/') {
                return str_replace('/', DIRECTORY_SEPARATOR, $stmt_type->get_single_string_literal()->value);
            }
            return $stmt_type->get_single_string_literal()->value;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Array_Dim_Fetch) {
            if ($stmt->var instanceof Php_Parser\Node\Expr\Variable && $stmt->var->name === 'GLOBALS' && $stmt->dim instanceof Php_Parser\Node\Scalar\String_) {
                if (isset($GLOBALS[$stmt->dim->value]) && is_string($GLOBALS[$stmt->dim->value])) {
                    return $GLOBALS[$stmt->dim->value];
                }
            }
        } elseif ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Concat) {
            $left_string = self::get_path_to($stmt->left, $type_provider, $statements_analyzer, $file_name, $config);
            $right_string = self::get_path_to($stmt->right, $type_provider, $statements_analyzer, $file_name, $config);
            if ($left_string && $right_string) {
                return $left_string . $right_string;
            }
        } elseif ($stmt instanceof Php_Parser\Node\Expr\Func_Call && $stmt->name instanceof Php_Parser\Node\Name && $stmt->name->get_parts() === ['dirname']) {
            if ($stmt->get_args()) {
                $dir_level = 1;
                if (isset($stmt->get_args()[1])) {
                    if ($stmt->get_args()[1]->value instanceof Php_Parser\Node\Scalar\Int_) {
                        $dir_level = $stmt->get_args()[1]->value->value;
                    } else if ($statements_analyzer) {
                        $t = $statements_analyzer->node_data->get_type($stmt->get_args()[1]->value);
                        if ($t && $t->is_single_int_literal()) {
                            $dir_level = $t->get_single_int_literal()->value;
                        } else {
                            return null;
                        }
                    } else {
                        return null;
                    }
                }
                $evaled_path = self::get_path_to($stmt->get_args()[0]->value, $type_provider, $statements_analyzer, $file_name, $config);
                if (!$evaled_path) {
                    return null;
                }
                if ($dir_level < 1) {
                    return null;
                }
                return dirname($evaled_path, $dir_level);
            }
        } elseif ($stmt instanceof Php_Parser\Node\Expr\Const_Fetch) {
            $const_name = implode('', $stmt->name->get_parts());
            if (defined($const_name)) {
                $constant_value = constant($const_name);
                if (is_string($constant_value)) {
                    return $constant_value;
                }
            }
        } elseif ($stmt instanceof Php_Parser\Node\Scalar\Magic_Const\Dir) {
            return dirname($file_name);
        } elseif ($stmt instanceof Php_Parser\Node\Scalar\Magic_Const\File) {
            return $file_name;
        }
        return null;
    }
    public static function resolve_include_path(string $file_name, string $current_directory): ?string
    {
        if (!$current_directory) {
            return $file_name;
        }
        if (substr($file_name, 0, 2) === '.' . DIRECTORY_SEPARATOR || substr($file_name, 0, 3) === '..' . DIRECTORY_SEPARATOR) {
            $file = $current_directory . DIRECTORY_SEPARATOR . $file_name;
            if (file_exists($file)) {
                return $file;
            }
            return null;
        }
        $paths = PATH_SEPARATOR === ':' ? preg_split('#(?<!phar):#', get_include_path()) : explode(PATH_SEPARATOR, get_include_path());
        if ($paths === false) {
            throw new AssertionError(preg_last_error_msg());
        }
        foreach ($paths as $prefix) {
            $ds = substr($prefix, -1) === DIRECTORY_SEPARATOR ? '' : DIRECTORY_SEPARATOR;
            if ($prefix === '.') {
                $prefix = $current_directory;
            }
            $file = $prefix . $ds . $file_name;
            if (file_exists($file)) {
                return $file;
            }
        }
        return null;
    }
    /**
     * @psalm-pure
     */
    public static function normalize_file_path(string $path_to_file): string
    {
        // replace all \ with / for normalization
        $path_to_file = str_replace('\\', '/', $path_to_file);
        $path_to_file = str_replace('/./', '/', $path_to_file);
        // first remove unnecessary / duplicates
        $path_to_file = (string) preg_replace('/\/[\/]+/', '/', $path_to_file);
        $reduce_pattern = '/\/[^\/]+\/\.\.\//';
        while (preg_match($reduce_pattern, $path_to_file)) {
            $path_to_file = (string) preg_replace($reduce_pattern, '/', $path_to_file, 1);
        }
        if (DIRECTORY_SEPARATOR !== '/') {
            return str_replace('/', DIRECTORY_SEPARATOR, $path_to_file);
        }
        return $path_to_file;
    }
}