<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Fetch;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Config;
use Psalm\Context;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Assignment_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Codebase\Taint_Flow_Graph;
use Psalm\Internal\Data_Flow\Data_Flow_Node;
use Psalm\Internal\Data_Flow\Taint_Source;
use Psalm\Issue\Impure_Variable;
use Psalm\Issue\Invalid_Scope;
use Psalm\Issue\Possibly_Undefined_Global_Variable;
use Psalm\Issue\Possibly_Undefined_Variable;
use Psalm\Issue\Undefined_Global_Variable;
use Psalm\Issue\Undefined_Variable;
use Psalm\Issue_Buffer;
use Psalm\Plugin\Event_Handler\Event\Add_Remove_Taints_Event;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Bool;
use Psalm\Type\Atomic\T_Float;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_Int_Range;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Non_Empty_Array;
use Psalm\Type\Atomic\T_Non_Empty_String;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Taint_Kind_Group;
use Psalm\Type\Union;
use function array_diff;
use function array_merge;
use function array_unique;
use function in_array;
use function is_string;
use function time;
/**
 * @internal
 */
final class Variable_Fetch_Analyzer
{
    public const SUPER_GLOBALS = ['$GLOBALS', '$_SERVER', '$_GET', '$_POST', '$_FILES', '$_COOKIE', '$_SESSION', '$_REQUEST', '$_ENV', '$http_response_header'];
    /**
     * @param bool $from_global - when used in a global keyword
     * @param bool $assigned_to_reference This is set to true when the expression being analyzed
     *                                    here is being assigned to another variable by reference.
     */
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Variable $stmt, Context $context, bool $passed_by_reference = false, ?Union $by_ref_type = null, bool $array_assignment = false, bool $from_global = false, bool $assigned_to_reference = false): bool
    {
        $project_analyzer = $statements_analyzer->get_file_analyzer()->project_analyzer;
        $codebase = $statements_analyzer->get_codebase();
        if ($stmt->name === 'this') {
            if ($statements_analyzer->is_static()) {
                return !Issue_Buffer::accepts(new Invalid_Scope('Invalid reference to $this in a static context', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
            }
            if (!isset($context->vars_in_scope['$this'])) {
                if (Issue_Buffer::accepts(new Invalid_Scope('Invalid reference to $this in a non-class context', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues())) {
                    return false;
                }
                $context->vars_in_scope['$this'] = Type::get_mixed();
                $context->vars_possibly_in_scope['$this'] = true;
                return true;
            }
            $statements_analyzer->node_data->set_type($stmt, $context->vars_in_scope['$this']);
            if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations && $stmt_type = $statements_analyzer->node_data->get_type($stmt)) {
                $codebase->analyzer->add_node_type($statements_analyzer->get_file_path(), $stmt, $stmt_type->get_id());
            }
            if (!$context->collect_mutations && !$context->collect_initializations) {
                if ($context->pure) {
                    Issue_Buffer::maybe_add(new Impure_Variable('Cannot reference $this in a pure context', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                } elseif ($statements_analyzer->get_source() instanceof Function_Like_Analyzer && $statements_analyzer->get_source()->track_mutations) {
                    $statements_analyzer->get_source()->inferred_impure = true;
                }
            }
            return true;
        }
        if (!$context->check_variables) {
            if (is_string($stmt->name)) {
                $var_name = '$' . $stmt->name;
                if (!$context->has_variable($var_name)) {
                    $context->vars_in_scope[$var_name] = Type::get_mixed();
                    $context->vars_possibly_in_scope[$var_name] = true;
                    $statements_analyzer->node_data->set_type($stmt, Type::get_mixed());
                } else {
                    $stmt_type = $context->vars_in_scope[$var_name];
                    self::add_data_flow_to_variable($statements_analyzer, $stmt, $var_name, $stmt_type, $context);
                    $context->vars_in_scope[$var_name] = $stmt_type;
                    $statements_analyzer->node_data->set_type($stmt, $stmt_type);
                }
            } else {
                $statements_analyzer->node_data->set_type($stmt, Type::get_mixed());
            }
            return true;
        }
        if (is_string($stmt->name) && self::is_super_global('$' . $stmt->name)) {
            $var_name = '$' . $stmt->name;
            if (isset($context->vars_in_scope[$var_name])) {
                $type = $context->vars_in_scope[$var_name];
                self::taint_variable($statements_analyzer, $context, $var_name, $type, $stmt);
                $context->vars_in_scope[$var_name] = $type;
                $statements_analyzer->node_data->set_type($stmt, $type);
                return true;
            }
            $type = self::get_global_type($var_name, $codebase->analysis_php_version_id);
            self::taint_variable($statements_analyzer, $context, $var_name, $type, $stmt);
            $statements_analyzer->node_data->set_type($stmt, $type);
            $context->vars_in_scope[$var_name] = $type;
            $context->vars_possibly_in_scope[$var_name] = true;
            $codebase->analyzer->add_node_reference($statements_analyzer->get_file_path(), $stmt, $var_name);
            return true;
        }
        if (!is_string($stmt->name)) {
            if ($context->pure) {
                Issue_Buffer::maybe_add(new Impure_Variable('Cannot reference an unknown variable in a pure context', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
            } elseif ($statements_analyzer->get_source() instanceof Function_Like_Analyzer && $statements_analyzer->get_source()->track_mutations) {
                $statements_analyzer->get_source()->inferred_impure = true;
            }
            $was_inside_general_use = $context->inside_general_use;
            $context->inside_general_use = true;
            $expr_result = Expression_Analyzer::analyze($statements_analyzer, $stmt->name, $context);
            $context->inside_general_use = $was_inside_general_use;
            return $expr_result;
        }
        if ($passed_by_reference && $by_ref_type) {
            Assignment_Analyzer::assign_by_ref_param($statements_analyzer, $stmt, $by_ref_type, $by_ref_type, $context);
            return true;
        }
        $var_name = '$' . $stmt->name;
        if (!$context->has_variable($var_name)) {
            if (!isset($context->vars_possibly_in_scope[$var_name]) || !$statements_analyzer->get_first_appearance($var_name)) {
                if ($array_assignment || $assigned_to_reference) {
                    if ($array_assignment) {
                        // if we're in an array assignment, let's assign the variable because PHP allows it
                        $stmt_type = Type::get_array();
                    } else {
                        // If a variable is assigned by reference to a variable that
                        // does not exist, they are automatically initialized as `null`
                        $stmt_type = Type::get_null();
                    }
                    $context->vars_in_scope[$var_name] = $stmt_type;
                    $context->vars_possibly_in_scope[$var_name] = true;
                    // it might have been defined first in another if/else branch
                    if (!$statements_analyzer->has_variable($var_name)) {
                        $statements_analyzer->register_variable($var_name, new Code_Location($statements_analyzer, $stmt), $context->branch_point);
                    }
                    self::taint_variable($statements_analyzer, $context, $var_name, $stmt_type, $stmt);
                    $statements_analyzer->node_data->set_type($stmt, $stmt_type);
                    if ($assigned_to_reference) {
                        // Since this variable was created by being assigned to as a reference (ie for
                        // `$a = &$b` this variable is $b), we need to analyze it as an assignment to null.
                        Assignment_Analyzer::analyze($statements_analyzer, $stmt, null, $stmt_type, $context, null);
                        // Stop here, we don't want it to be considered possibly undefined like the array case.
                        return true;
                    }
                } elseif (!$context->inside_isset || $statements_analyzer->get_source() instanceof Function_Like_Analyzer) {
                    if ($context->is_global || $from_global) {
                        $exception = new Undefined_Global_Variable('Cannot find referenced variable ' . $var_name . ' in global scope', new Code_Location($statements_analyzer->get_source(), $stmt), $var_name);
                    } else {
                        $exception = new Undefined_Variable('Cannot find referenced variable ' . $var_name, new Code_Location($statements_analyzer->get_source(), $stmt));
                    }
                    Issue_Buffer::maybe_add($exception, $statements_analyzer->get_suppressed_issues());
                    $type = Type::get_mixed();
                    self::taint_variable($statements_analyzer, $context, $var_name, $type, $stmt);
                    $statements_analyzer->node_data->set_type($stmt, $type);
                    return true;
                }
            }
            $first_appearance = $statements_analyzer->get_first_appearance($var_name);
            if ($first_appearance && !$context->inside_isset && !$context->inside_unset) {
                if ($context->is_global) {
                    if ($codebase->alter_code) {
                        if (!isset($project_analyzer->get_issues_to_fix()['PossiblyUndefinedGlobalVariable'])) {
                            return true;
                        }
                        $branch_point = $statements_analyzer->get_branch_point($var_name);
                        if ($branch_point) {
                            $statements_analyzer->add_variable_initialization($var_name, $branch_point);
                        }
                        return true;
                    }
                    Issue_Buffer::maybe_add(new Possibly_Undefined_Global_Variable('Possibly undefined global variable ' . $var_name . ', first seen on line ' . $first_appearance->get_line_number(), new Code_Location($statements_analyzer->get_source(), $stmt), $var_name), $statements_analyzer->get_suppressed_issues(), (bool) $statements_analyzer->get_branch_point($var_name));
                } else {
                    if ($codebase->alter_code && isset($project_analyzer->get_issues_to_fix()['PossiblyUndefinedVariable'])) {
                        $branch_point = $statements_analyzer->get_branch_point($var_name);
                        if ($branch_point) {
                            $statements_analyzer->add_variable_initialization($var_name, $branch_point);
                        }
                        return true;
                    }
                    Issue_Buffer::maybe_add(new Possibly_Undefined_Variable('Possibly undefined variable ' . $var_name . ', first seen on line ' . $first_appearance->get_line_number(), new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues(), (bool) $statements_analyzer->get_branch_point($var_name));
                }
                if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations) {
                    $codebase->analyzer->add_node_reference($statements_analyzer->get_file_path(), $stmt, $first_appearance->raw_file_start . '-' . $first_appearance->raw_file_end . ':mixed');
                }
                $stmt_type = Type::get_mixed();
                self::add_data_flow_to_variable($statements_analyzer, $stmt, $var_name, $stmt_type, $context);
                $statements_analyzer->node_data->set_type($stmt, $stmt_type);
                $statements_analyzer->register_possibly_undefined_variable($var_name, $stmt);
                return true;
            }
        } else {
            $stmt_type = $context->vars_in_scope[$var_name];
            self::taint_variable($statements_analyzer, $context, $var_name, $stmt_type, $stmt);
            self::add_data_flow_to_variable($statements_analyzer, $stmt, $var_name, $stmt_type, $context);
            $context->vars_in_scope[$var_name] = $stmt_type;
            $statements_analyzer->node_data->set_type($stmt, $stmt_type);
            if ($stmt_type->possibly_undefined_from_try && !$context->inside_isset) {
                if ($context->is_global) {
                    Issue_Buffer::maybe_add(new Possibly_Undefined_Global_Variable('Possibly undefined global variable ' . $var_name . ' defined in try block', new Code_Location($statements_analyzer->get_source(), $stmt), $var_name), $statements_analyzer->get_suppressed_issues());
                } else {
                    Issue_Buffer::maybe_add(new Possibly_Undefined_Variable('Possibly undefined variable ' . $var_name . ' defined in try block', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                }
            }
            if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations) {
                $codebase->analyzer->add_node_type($statements_analyzer->get_file_path(), $stmt, $stmt_type->get_id());
            }
            if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations) {
                $first_appearance = $statements_analyzer->get_first_appearance($var_name);
                if ($first_appearance) {
                    $codebase->analyzer->add_node_reference($statements_analyzer->get_file_path(), $stmt, $first_appearance->raw_file_start . '-' . $first_appearance->raw_file_end . ':' . $stmt_type->get_id());
                }
            }
        }
        return true;
    }
    private static function add_data_flow_to_variable(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Variable $stmt, string $var_name, Union &$stmt_type, Context $context): void
    {
        $codebase = $statements_analyzer->get_codebase();
        if ($statements_analyzer->data_flow_graph && $codebase->find_unused_variables && ($context->inside_return || $context->inside_call || $context->inside_general_use || $context->inside_conditional || $context->inside_throw || $context->inside_isset)) {
            if (!$stmt_type->parent_nodes) {
                $assignment_node = Data_Flow_Node::get_for_assignment($var_name, new Code_Location($statements_analyzer->get_source(), $stmt));
                $stmt_type = $stmt_type->set_parent_nodes([$assignment_node->id => $assignment_node]);
            }
            foreach ($stmt_type->parent_nodes as $parent_node) {
                if ($context->inside_call || $context->inside_return) {
                    $statements_analyzer->data_flow_graph->add_path($parent_node, new Data_Flow_Node('variable-use', 'variable use', null), 'use-inside-call');
                } elseif ($context->inside_conditional) {
                    $statements_analyzer->data_flow_graph->add_path($parent_node, new Data_Flow_Node('variable-use', 'variable use', null), 'use-inside-conditional');
                } elseif ($context->inside_isset) {
                    $statements_analyzer->data_flow_graph->add_path($parent_node, new Data_Flow_Node('variable-use', 'variable use', null), 'use-inside-isset');
                } else {
                    $statements_analyzer->data_flow_graph->add_path($parent_node, new Data_Flow_Node('variable-use', 'variable use', null), 'variable-use');
                }
            }
        }
    }
    private static function taint_variable(Statements_Analyzer $statements_analyzer, Context $context, string $var_name, Union &$type, Php_Parser\Node\Expr\Variable $stmt): void
    {
        if (!$statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph || in_array('TaintedInput', $statements_analyzer->get_suppressed_issues())) {
            return;
        }
        // Add superglobal server taint sources
        if ($var_name === '$_GET' || $var_name === '$_POST' || $var_name === '$_COOKIE' || $var_name === '$_REQUEST') {
            $taints = Taint_Kind_Group::ALL_INPUT;
        } else {
            $taints = [];
        }
        // Trigger event to possibly get more/less taints
        $codebase = $statements_analyzer->get_codebase();
        $event = new Add_Remove_Taints_Event($stmt, $context, $statements_analyzer, $codebase);
        $added_taints = $codebase->config->event_dispatcher->dispatch_add_taints($event);
        $removed_taints = $codebase->config->event_dispatcher->dispatch_remove_taints($event);
        $taints = array_unique(array_merge($taints, $added_taints));
        $taints = array_diff($taints, $removed_taints);
        if ($taints === []) {
            return;
        }
        $taint_location = new Code_Location($statements_analyzer->get_source(), $stmt);
        $taint_source = new Taint_Source($var_name . ':' . $taint_location->file_name . ':' . $taint_location->raw_file_start, $var_name, null, null, $taints);
        $statements_analyzer->data_flow_graph->add_source($taint_source);
        $type = $type->set_parent_nodes([$taint_source->id => $taint_source]);
    }
    /**
     * @psalm-pure
     */
    public static function is_super_global(string $var_id): bool
    {
        return in_array($var_id, self::SUPER_GLOBALS, true);
    }
    /** @var array<value-of<self::SUPER_GLOBALS>|'$_FILES full path'|'$argv'|'$argc', Union> */
    private static array $global_cache = [];
    public static function get_global_type(string $var_id, int $codebase_analysis_php_version_id): Union
    {
        $config = Config::get_instance();
        if (isset($config->globals[$var_id])) {
            return Type::parse_string($config->globals[$var_id]);
        }
        if (!self::$global_cache) {
            foreach (self::SUPER_GLOBALS as $v) {
                self::$global_cache[$v] = self::get_global_type_inner($v);
            }
            self::$global_cache['$_FILES full path'] = self::get_global_type_inner('$_FILES', true);
            self::$global_cache['$argv'] = self::get_global_type_inner('$argv');
            self::$global_cache['$argc'] = self::get_global_type_inner('$argc');
        }
        if ($codebase_analysis_php_version_id >= 80100 && $var_id === '$_FILES') {
            $var_id = '$_FILES full path';
        }
        return self::$global_cache[$var_id] ?? Type::get_mixed();
    }
    /**
     * @param value-of<self::SUPER_GLOBALS>|'$argv'|'$argc' $var_id
     */
    private static function get_global_type_inner(string $var_id, bool $files_full_path = false): Union
    {
        if ($var_id === '$argv') {
            // only in CLI, null otherwise
            return new Union([Type::get_non_empty_list_atomic(Type::get_string()), new T_Null()], ['ignore_nullable_issues' => true]);
            // use TNull explicitly instead of this
            // as it will cause weird errors due to ignore_nullable_issues true
            // e.g. InvalidPropertyAssignmentValue
            // $this->argv 'list<string>' cannot be assigned type 'non-empty-list<string>'
        }
        if ($var_id === '$argc') {
            // only in CLI, null otherwise
            return new Union([new T_Int_Range(1, null), new T_Null()], ['ignore_nullable_issues' => true]);
        }
        if ($var_id === '$http_response_header') {
            // $http_response_header exists only in the local scope after a successful network request
            return new Union([Type::get_non_empty_list_atomic(Type::get_non_falsy_string())], ['possibly_undefined' => true]);
        }
        if ($var_id === '$GLOBALS') {
            return new Union([new T_Non_Empty_Array([Type::get_non_empty_string(), Type::get_mixed()])]);
        }
        if ($var_id === '$_COOKIE') {
            $type = new T_Array([Type::get_non_empty_string(), Type::get_string()]);
            return new Union([$type]);
        }
        if (in_array($var_id, ['$_GET', '$_POST', '$_REQUEST'], true)) {
            $array_key = new Union([new T_Non_Empty_String(), new T_Int()]);
            $array = new T_Non_Empty_Array([$array_key, new Union([new T_String(), new T_Array([$array_key, Type::get_mixed()])])]);
            $type = new T_Array([$array_key, new Union([new T_String(), $array])]);
            return new Union([$type]);
        }
        if ($var_id === '$_SERVER' || $var_id === '$_ENV') {
            $string_helper = new Union([new T_String()], ['possibly_undefined' => true]);
            $non_empty_string_helper = new Union([new T_Non_Empty_String()], ['possibly_undefined' => true]);
            $argv_helper = new Union([Type::get_non_empty_list_atomic(Type::get_string())], ['possibly_undefined' => true]);
            $argc_helper = new Union([new T_Int_Range(1, null)], ['possibly_undefined' => true]);
            $request_time_helper = new Union([new T_Int_Range(time(), null)], ['possibly_undefined' => true]);
            $request_time_float_helper = new Union([new T_Float()], ['possibly_undefined' => true]);
            $bool_string_helper = new Union([new T_Bool(), new T_String()], ['possibly_undefined' => true]);
            $arr = [
                // https://www.php.net/manual/en/reserved.variables.server.php
                'PHP_SELF' => $non_empty_string_helper,
                'GATEWAY_INTERFACE' => $non_empty_string_helper,
                'SERVER_ADDR' => $non_empty_string_helper,
                'SERVER_NAME' => $non_empty_string_helper,
                'SERVER_SOFTWARE' => $non_empty_string_helper,
                'SERVER_PROTOCOL' => $non_empty_string_helper,
                'REQUEST_METHOD' => $non_empty_string_helper,
                'REQUEST_TIME' => $request_time_helper,
                'REQUEST_TIME_FLOAT' => $request_time_float_helper,
                'QUERY_STRING' => $string_helper,
                'DOCUMENT_ROOT' => $non_empty_string_helper,
                'HTTP_ACCEPT' => $non_empty_string_helper,
                'HTTP_ACCEPT_CHARSET' => $non_empty_string_helper,
                'HTTP_ACCEPT_ENCODING' => $non_empty_string_helper,
                'HTTP_ACCEPT_LANGUAGE' => $non_empty_string_helper,
                'HTTP_CONNECTION' => $non_empty_string_helper,
                'HTTP_HOST' => $non_empty_string_helper,
                'HTTP_REFERER' => $non_empty_string_helper,
                'HTTP_USER_AGENT' => $non_empty_string_helper,
                'HTTPS' => $string_helper,
                'REMOTE_ADDR' => $non_empty_string_helper,
                'REMOTE_HOST' => $non_empty_string_helper,
                'REMOTE_PORT' => $string_helper,
                'REMOTE_USER' => $non_empty_string_helper,
                'REDIRECT_REMOTE_USER' => $non_empty_string_helper,
                'SCRIPT_FILENAME' => $non_empty_string_helper,
                'SERVER_ADMIN' => $non_empty_string_helper,
                'SERVER_PORT' => $non_empty_string_helper,
                'SERVER_SIGNATURE' => $non_empty_string_helper,
                'PATH_TRANSLATED' => $non_empty_string_helper,
                'SCRIPT_NAME' => $non_empty_string_helper,
                'REQUEST_URI' => $non_empty_string_helper,
                'PHP_AUTH_DIGEST' => $non_empty_string_helper,
                'PHP_AUTH_USER' => $non_empty_string_helper,
                'PHP_AUTH_PW' => $non_empty_string_helper,
                'AUTH_TYPE' => $non_empty_string_helper,
                'PATH_INFO' => $non_empty_string_helper,
                'ORIG_PATH_INFO' => $non_empty_string_helper,
                // misc from RFC not included above already http://www.faqs.org/rfcs/rfc3875.html
                'CONTENT_LENGTH' => $string_helper,
                'CONTENT_TYPE' => $string_helper,
                // common, misc stuff
                'FCGI_ROLE' => $non_empty_string_helper,
                'HOME' => $non_empty_string_helper,
                'HTTP_CACHE_CONTROL' => $non_empty_string_helper,
                'HTTP_COOKIE' => $non_empty_string_helper,
                'HTTP_PRIORITY' => $non_empty_string_helper,
                'PATH' => $non_empty_string_helper,
                'REDIRECT_STATUS' => $non_empty_string_helper,
                'REQUEST_SCHEME' => $non_empty_string_helper,
                'USER' => $non_empty_string_helper,
                // common, misc headers
                'HTTP_UPGRADE_INSECURE_REQUESTS' => $non_empty_string_helper,
                'HTTP_X_FORWARDED_PROTO' => $non_empty_string_helper,
                'HTTP_CLIENT_IP' => $non_empty_string_helper,
                'HTTP_X_REAL_IP' => $non_empty_string_helper,
                'HTTP_X_FORWARDED_FOR' => $non_empty_string_helper,
                'HTTP_CF_CONNECTING_IP' => $non_empty_string_helper,
                'HTTP_CF_IPCOUNTRY' => $non_empty_string_helper,
                'HTTP_CF_VISITOR' => $non_empty_string_helper,
                'HTTP_CDN_LOOP' => $non_empty_string_helper,
                // common, misc browser headers
                'HTTP_DNT' => $non_empty_string_helper,
                'HTTP_SEC_FETCH_DEST' => $non_empty_string_helper,
                'HTTP_SEC_FETCH_USER' => $non_empty_string_helper,
                'HTTP_SEC_FETCH_MODE' => $non_empty_string_helper,
                'HTTP_SEC_FETCH_SITE' => $non_empty_string_helper,
                'HTTP_SEC_CH_UA_PLATFORM' => $non_empty_string_helper,
                'HTTP_SEC_CH_UA_MOBILE' => $non_empty_string_helper,
                'HTTP_SEC_CH_UA' => $non_empty_string_helper,
                // phpunit
                'APP_DEBUG' => $bool_string_helper,
                'APP_ENV' => $string_helper,
            ];
            if ($var_id === '$_SERVER') {
                $arr['argv'] = $argv_helper;
                $arr['argc'] = $argc_helper;
            }
            $detailed_type = new T_Keyed_Array($arr, null, [Type::get_non_empty_string(), Type::get_string()]);
            return new Union([$detailed_type]);
        }
        if ($var_id === '$_FILES') {
            $str = Type::get_string();
            $values = ['name' => $str, 'type' => $str, 'tmp_name' => $str, 'size' => Type::get_list_key(), 'error' => Type::get_int_range(0, 8)];
            if ($files_full_path) {
                $values['full_path'] = $str;
            }
            $type = new Union([new T_Keyed_Array($values)]);
            $parent = new T_Array([Type::get_non_empty_string(), $type]);
            return new Union([$parent]);
        }
        // $var_id === $_SESSION
        // keys must be string
        return new Union([new T_Array([Type::get_non_empty_string(), Type::get_mixed()])], ['possibly_undefined' => true]);
    }
}