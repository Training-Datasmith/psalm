<?php

declare (strict_types=1);
namespace Psalm;

use Dom_Document;
use Dom_Element;
use Psalm\Exception\Config_Exception;
use Psalm\Internal\Analyzer\Issue_Data;
use Psalm\Internal\Provider\File_Provider;
use RuntimeException;
use function array_filter;
use function array_intersect;
use function array_map;
use function array_merge;
use function array_reduce;
use function array_values;
use function get_loaded_extensions;
use function implode;
use function ksort;
use function min;
use function phpversion;
use function preg_replace_callback;
use function sort;
use function sprintf;
use function str_replace;
use function trim;
use function usort;
use const LIBXML_NOBLANKS;
use const PHP_VERSION;
final class Error_Baseline
{
    /**
     * @param array<string,array<string,array{o:int, s:array<int, string>}>> $existingIssues
     * @psalm-pure
     */
    public static function count_total_issues(array $existing_issues): int
    {
        $total_issues = 0;
        foreach ($existing_issues as $existing_issue) {
            $total_issues += array_reduce(
                $existing_issue,
                /**
                 * @param array{o:int, s:array<int, string>} $existingIssue
                 */
                static fn(int $carry, array $existing_issue): int => $carry + $existing_issue['o'],
                0
            );
        }
        return $total_issues;
    }
    /**
     * @param array<string, list<IssueData>> $issues
     */
    public static function create(File_Provider $file_provider, string $baseline_file, array $issues, bool $include_php_versions): void
    {
        $grouped_issues = self::count_issue_types_by_file($issues);
        self::write_to_file($file_provider, $baseline_file, $grouped_issues, $include_php_versions);
    }
    /**
     * @return array<string,array<string,array{o:int, s: list<string>}>>
     * @throws ConfigException
     */
    public static function read(File_Provider $file_provider, string $baseline_file): array
    {
        if (!$file_provider->file_exists($baseline_file)) {
            throw new Config_Exception("{$baseline_file} does not exist or is not readable");
        }
        $xml_source = $file_provider->get_contents($baseline_file);
        if ($xml_source === '') {
            throw new Config_Exception('Baseline file is empty');
        }
        $baseline_doc = new Dom_Document();
        $baseline_doc->load_xml($xml_source, LIBXML_NOBLANKS);
        $files_element = $baseline_doc->get_elements_by_tag_name('files');
        if ($files_element->length === 0) {
            throw new Config_Exception('Baseline file does not contain <files>');
        }
        $files = [];
        /** @var DOMElement $filesElement */
        $files_element = $files_element[0];
        foreach ($files_element->get_elements_by_tag_name('file') as $file) {
            $file_name = $file->get_attribute('src');
            $file_name = str_replace('\\', '/', $file_name);
            $files[$file_name] = [];
            foreach ($file->child_nodes as $issue) {
                if (!$issue instanceof Dom_Element) {
                    continue;
                }
                $issue_type = $issue->tag_name;
                $files[$file_name][$issue_type] = ['o' => 0, 's' => []];
                $code_samples = $issue->get_elements_by_tag_name('code');
                foreach ($code_samples as $code_sample) {
                    $files[$file_name][$issue_type]['o'] += 1;
                    $files[$file_name][$issue_type]['s'][] = str_replace("\r\n", "\n", trim($code_sample->text_content));
                }
            }
        }
        return $files;
    }
    /**
     * @param array<string, list<IssueData>> $issues
     * @return array<string, array<string, array{o: int, s: list<string>}>>
     * @throws ConfigException
     */
    public static function update(File_Provider $file_provider, string $baseline_file, array $issues, bool $include_php_versions): array
    {
        $existing_issues = self::read($file_provider, $baseline_file);
        $new_issues = self::count_issue_types_by_file($issues);
        foreach ($existing_issues as $file => &$existing_issues_count) {
            if (!isset($new_issues[$file])) {
                unset($existing_issues[$file]);
                continue;
            }
            foreach ($existing_issues_count as $issue_type => $existing_issue_type) {
                if (!isset($new_issues[$file][$issue_type])) {
                    unset($existing_issues_count[$issue_type]);
                    continue;
                }
                $existing_issues_count[$issue_type]['o'] = min($existing_issue_type['o'], $new_issues[$file][$issue_type]['o']);
                $existing_issues_count[$issue_type]['s'] = array_intersect($existing_issue_type['s'], $new_issues[$file][$issue_type]['s']);
            }
        }
        $grouped_issues = array_filter($existing_issues);
        self::write_to_file($file_provider, $baseline_file, $grouped_issues, $include_php_versions);
        return $grouped_issues;
    }
    /**
     * @param array<string, list<IssueData>> $issues
     * @return array<string,array<string,array{o:int, s:array<int, string>}>>
     */
    private static function count_issue_types_by_file(array $issues): array
    {
        if ($issues === []) {
            return [];
        }
        $grouped_issues = array_reduce(
            array_merge(...array_values($issues)),
            /**
             * @param array<string,array<string,array{o:int, s:array<int, string>}>> $carry
             * @return array<string,array<string,array{o:int, s:array<int, string>}>>
             */
            static function (array $carry, Issue_Data $issue): array {
                if ($issue->severity !== Config::REPORT_ERROR) {
                    return $carry;
                }
                $file_name = $issue->file_name;
                $file_name = str_replace('\\', '/', $file_name);
                $issue_type = $issue->type;
                if (!isset($carry[$file_name])) {
                    $carry[$file_name] = [];
                }
                if (!isset($carry[$file_name][$issue_type])) {
                    $carry[$file_name][$issue_type] = ['o' => 0, 's' => []];
                }
                ++$carry[$file_name][$issue_type]['o'];
                $carry[$file_name][$issue_type]['s'][] = $issue->selected_text;
                return $carry;
            },
            []
        );
        // Sort files first
        ksort($grouped_issues);
        foreach ($grouped_issues as &$issues) {
            ksort($issues);
        }
        unset($issues);
        return $grouped_issues;
    }
    /**
     * @param array<string,array<string,array{o:int, s:array<int, string>}>> $groupedIssues
     */
    private static function write_to_file(File_Provider $file_provider, string $baseline_file, array $grouped_issues, bool $include_php_versions): void
    {
        $baseline_doc = new Dom_Document('1.0', 'UTF-8');
        $files_node = $baseline_doc->create_element('files');
        $files_node->set_attribute('psalm-version', PSALM_VERSION);
        if ($include_php_versions) {
            $extensions = [...get_loaded_extensions(), ...get_loaded_extensions(true)];
            usort($extensions, strnatcasecmp(...));
            $files_node->set_attribute('php-version', implode(";\n\t", ['php:' . PHP_VERSION, ...array_map(static fn(string $extension): string => $extension . ':' . phpversion($extension), $extensions)]));
        }
        foreach ($grouped_issues as $file => $issue_types) {
            $file_node = $baseline_doc->create_element('file');
            $file_node->set_attribute('src', $file);
            foreach ($issue_types as $issue_type => $existing_issue_type) {
                $issue_node = $baseline_doc->create_element($issue_type);
                sort($existing_issue_type['s']);
                foreach ($existing_issue_type['s'] as $selection) {
                    $code_node = $baseline_doc->create_element('code');
                    $text_content = trim($selection);
                    $code_node->append_child($baseline_doc->create_cdata_section($text_content));
                    $issue_node->append_child($code_node);
                }
                $file_node->append_child($issue_node);
            }
            $files_node->append_child($file_node);
        }
        $baseline_doc->append_child($files_node);
        $baseline_doc->format_output = true;
        $xml = preg_replace_callback(
            '/<files (psalm-version="[^"]+") php-version="(.+)"(\/?>)\n/',
            /**
             * @param string[] $matches
             */
            static fn(array $matches): string => sprintf("<files\n  %s\n  php-version=\"\n    %s\n  \"\n%s\n", $matches[1], str_replace('&#10;&#9;', "\n    ", $matches[2]), $matches[3]),
            $baseline_doc->save_xml()
        );
        if ($xml === null) {
            throw new RuntimeException('Failed to reformat opening attributes!');
        }
        $file_provider->set_contents($baseline_file, $xml);
    }
}