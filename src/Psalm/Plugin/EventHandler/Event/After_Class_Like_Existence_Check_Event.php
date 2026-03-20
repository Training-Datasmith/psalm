<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler\Event;

use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\File_Manipulation;
use Psalm\Statements_Source;
final class After_Class_Like_Existence_Check_Event
{
    /**
     * @param FileManipulation[] $file_replacements
     * @internal
     */
    public function __construct(private readonly string $fq_class_name, private readonly Code_Location $code_location, private readonly Statements_Source $statements_source, private readonly Codebase $codebase, private array $file_replacements = [])
    {
    }
    public function get_fq_class_name(): string
    {
        return $this->fq_class_name;
    }
    public function get_code_location(): Code_Location
    {
        return $this->code_location;
    }
    public function get_statements_source(): Statements_Source
    {
        return $this->statements_source;
    }
    public function get_codebase(): Codebase
    {
        return $this->codebase;
    }
    /**
     * @return FileManipulation[]
     */
    public function get_file_replacements(): array
    {
        return $this->file_replacements;
    }
    /**
     * @param FileManipulation[] $file_replacements
     */
    public function set_file_replacements(array $file_replacements): void
    {
        $this->file_replacements = $file_replacements;
    }
}