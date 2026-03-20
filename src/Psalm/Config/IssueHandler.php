<?php

declare (strict_types=1);
namespace Psalm\Config;

use Psalm\Config;
use Psalm\Exception\Config_Exception;
use Simple_Xml_Element;
use function array_filter;
use function array_map;
use function assert;
use function dirname;
use function in_array;
use function scandir;
use function strtolower;
use function substr;
use const SCANDIR_SORT_NONE;
/** @internal */
final class Issue_Handler
{
    private string $error_level = Config::REPORT_ERROR;
    /**
     * @var list<ErrorLevelFileFilter>
     */
    private array $custom_levels = [];
    public static function load_from_xml_element(Simple_Xml_Element $e, string $base_dir): self
    {
        $handler = new self();
        if (isset($e['errorLevel'])) {
            $handler->error_level = (string) $e['errorLevel'];
            if (!in_array($handler->error_level, Config::$ERROR_LEVELS, true)) {
                throw new Config_Exception('Unexpected error level ' . $handler->error_level);
            }
        }
        if (isset($e->error_level)) {
            foreach ($e->error_level as $error_level) {
                $handler->custom_levels[] = Error_Level_File_Filter::load_from_xml_element($error_level, $base_dir, true);
            }
        }
        return $handler;
    }
    /** @return list<ErrorLevelFileFilter> */
    public function get_filters(): array
    {
        return $this->custom_levels;
    }
    public function set_custom_levels(array $custom_levels, string $base_dir): void
    {
        /** @var array $customLevel */
        foreach ($custom_levels as $custom_level) {
            $this->custom_levels[] = Error_Level_File_Filter::load_from_array($custom_level, $base_dir, true);
        }
    }
    public function set_error_level(string $error_level): void
    {
        if (!in_array($error_level, Config::$ERROR_LEVELS, true)) {
            throw new Config_Exception('Unexpected error level ' . $error_level);
        }
        $this->error_level = $error_level;
    }
    public function get_reporting_level_for_file(string $file_path): string
    {
        foreach ($this->custom_levels as $custom_level) {
            if ($custom_level->allows($file_path)) {
                $custom_level->suppressions++;
                return $custom_level->get_error_level();
            }
        }
        return $this->error_level;
    }
    public function get_reporting_level_for_class(string $fq_classlike_name): ?string
    {
        foreach ($this->custom_levels as $custom_level) {
            if ($custom_level->allows_class($fq_classlike_name)) {
                $custom_level->suppressions++;
                return $custom_level->get_error_level();
            }
        }
        return null;
    }
    public function get_reporting_level_for_method(string $method_id): ?string
    {
        foreach ($this->custom_levels as $custom_level) {
            if ($custom_level->allows_method(strtolower($method_id))) {
                $custom_level->suppressions++;
                return $custom_level->get_error_level();
            }
        }
        return null;
    }
    public function get_reporting_level_for_function(string $function_id): ?string
    {
        foreach ($this->custom_levels as $custom_level) {
            if ($custom_level->allows_method(strtolower($function_id))) {
                return $custom_level->get_error_level();
            }
        }
        return null;
    }
    public function get_reporting_level_for_argument(string $function_id): ?string
    {
        foreach ($this->custom_levels as $custom_level) {
            if ($custom_level->allows_method(strtolower($function_id))) {
                $custom_level->suppressions++;
                return $custom_level->get_error_level();
            }
        }
        return null;
    }
    public function get_reporting_level_for_property(string $property_id): ?string
    {
        foreach ($this->custom_levels as $custom_level) {
            if ($custom_level->allows_property($property_id)) {
                $custom_level->suppressions++;
                return $custom_level->get_error_level();
            }
        }
        return null;
    }
    public function get_reporting_level_for_class_constant(string $constant_id): ?string
    {
        foreach ($this->custom_levels as $custom_level) {
            if ($custom_level->allows_class_constant($constant_id)) {
                $custom_level->suppressions++;
                return $custom_level->get_error_level();
            }
        }
        return null;
    }
    public function get_reporting_level_for_variable(string $var_name): ?string
    {
        foreach ($this->custom_levels as $custom_level) {
            if ($custom_level->allows_variable($var_name)) {
                $custom_level->suppressions++;
                return $custom_level->get_error_level();
            }
        }
        return null;
    }
    /**
     * @return array<int, string>
     */
    public static function get_all_issue_types(): array
    {
        $scan = scandir(dirname(__DIR__) . '/Issue', SCANDIR_SORT_NONE);
        assert($scan !== false);
        return array_filter(array_map(static fn(string $file_name): string => substr($file_name, 0, -4), $scan), static fn(string $issue_name): bool => $issue_name !== '' && $issue_name !== 'MethodIssue' && $issue_name !== 'PropertyIssue' && $issue_name !== 'ClassConstantIssue' && $issue_name !== 'FunctionIssue' && $issue_name !== 'ArgumentIssue' && $issue_name !== 'VariableIssue' && $issue_name !== 'ClassIssue' && $issue_name !== 'CodeIssue' && $issue_name !== 'PsalmInternalError' && $issue_name !== 'ParseError' && $issue_name !== 'PluginIssue' && $issue_name !== 'MixedIssue' && $issue_name !== 'MixedIssueTrait');
    }
}