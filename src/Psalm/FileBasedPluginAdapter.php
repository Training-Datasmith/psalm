<?php

declare (strict_types=1);
namespace Psalm;

use Override;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Scanner\File_Scanner;
use Psalm\Plugin\Plugin_Entry_Point_Interface;
use Psalm\Plugin\Registration_Interface;
use Simple_Xml_Element;
use UnexpectedValueException;
use function assert;
use function class_exists;
use function count;
use function reset;
use function str_replace;
use const DIRECTORY_SEPARATOR;
/** @internal */
final class File_Based_Plugin_Adapter implements Plugin_Entry_Point_Interface
{
    private readonly string $path;
    public function __construct(string $path, private readonly Config $config, private readonly Codebase $codebase)
    {
        if (!$path) {
            throw new UnexpectedValueException('$path cannot be empty');
        }
        $this->path = $path;
    }
    #[Override]
    public function __invoke(Registration_Interface $registration, ?Simple_Xml_Element $config = null): void
    {
        $fq_class_name = $this->get_plugin_class_for_path($this->path);
        /** @psalm-suppress UnresolvableInclude */
        require_once $this->path;
        assert(class_exists($fq_class_name));
        $registration->register_hooks_from_class($fq_class_name);
    }
    private function get_plugin_class_for_path(string $path): string
    {
        $codebase = $this->codebase;
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $file_storage = $codebase->create_file_storage_for_path($path);
        $file_to_scan = new File_Scanner($path, $this->config->shorten_file_name($path), true);
        $file_to_scan->scan($codebase, $file_storage);
        $declared_classes = Class_Like_Analyzer::get_classes_for_file($codebase, $path);
        assert(count($declared_classes) > 0, 'FileBasedPlugin contains a class');
        return reset($declared_classes);
    }
}