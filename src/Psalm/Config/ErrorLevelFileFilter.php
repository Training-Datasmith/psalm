<?php

declare (strict_types=1);
namespace Psalm\Config;

use Override;
use Psalm\Config;
use Psalm\Exception\Config_Exception;
use Simple_Xml_Element;
use function in_array;
/** @internal */
final class Error_Level_File_Filter extends File_Filter
{
    private string $error_level = '';
    public int $suppressions = 0;
    #[Override]
    public static function load_from_array(array $config, string $base_dir, bool $inclusive): static
    {
        $filter = parent::load_from_array($config, $base_dir, $inclusive);
        if (isset($config['type'])) {
            $filter->error_level = (string) $config['type'];
            if (!in_array($filter->error_level, Config::$ERROR_LEVELS, true)) {
                throw new Config_Exception('Unexpected error level ' . $filter->error_level);
            }
        } else {
            throw new Config_Exception('<type> element expects a level');
        }
        return $filter;
    }
    #[Override]
    public static function load_from_xml_element(Simple_Xml_Element $e, string $base_dir, bool $inclusive): static
    {
        $filter = parent::load_from_xml_element($e, $base_dir, $inclusive);
        if (isset($e['type'])) {
            $filter->error_level = (string) $e['type'];
            if (!in_array($filter->error_level, Config::$ERROR_LEVELS, true)) {
                throw new Config_Exception('Unexpected error level ' . $filter->error_level);
            }
        } else {
            throw new Config_Exception('<type> element expects a level');
        }
        return $filter;
    }
    public function get_error_level(): string
    {
        return $this->error_level;
    }
}