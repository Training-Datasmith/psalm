<?php

declare (strict_types=1);
namespace Psalm\Config;

use Override;
use Psalm\Exception\Config_Exception;
use Simple_Xml_Element;
use function str_starts_with;
use function stripos;
/** @internal */
final class Project_File_Filter extends File_Filter
{
    private ?Project_File_Filter $file_filter = null;
    #[Override]
    public static function load_from_xml_element(Simple_Xml_Element $e, string $base_dir, bool $inclusive): static
    {
        $filter = parent::load_from_xml_element($e, $base_dir, $inclusive);
        if (isset($e->ignore_files)) {
            if (!$inclusive) {
                throw new Config_Exception('Cannot nest ignoreFiles inside itself');
            }
            $filter->file_filter = static::load_from_xml_element($e->ignore_files, $base_dir, false);
        }
        return $filter;
    }
    #[Override]
    public function allows(string $file_name, bool $case_sensitive = false): bool
    {
        if (!$this->inclusive) {
            return parent::allows($file_name, $case_sensitive);
        }
        if (!$this->file_filter) {
            return parent::allows($file_name, $case_sensitive);
        }
        if (!$this->file_filter->allows($file_name, $case_sensitive)) {
            return false;
        }
        return parent::allows($file_name, $case_sensitive);
    }
    public function forbids(string $file_name, bool $case_sensitive = false): bool
    {
        if (!$this->inclusive) {
            return false;
        }
        if (!$this->file_filter) {
            return false;
        }
        if (!$this->file_filter->allows($file_name, $case_sensitive)) {
            return true;
        }
        return false;
    }
    public function report_type_stats(string $file_name, bool $case_sensitive = false): bool
    {
        foreach ($this->ignore_type_stats as $exclude_dir => $_) {
            if ($case_sensitive) {
                if (str_starts_with($file_name, $exclude_dir)) {
                    return false;
                }
            } else if (stripos($file_name, $exclude_dir) === 0) {
                return false;
            }
        }
        return true;
    }
    public function use_strict_types(string $file_name, bool $case_sensitive = false): bool
    {
        foreach ($this->declare_strict_types as $exclude_dir => $_) {
            if ($case_sensitive) {
                if (str_starts_with($file_name, $exclude_dir)) {
                    return true;
                }
            } else if (stripos($file_name, $exclude_dir) === 0) {
                return true;
            }
        }
        return false;
    }
}