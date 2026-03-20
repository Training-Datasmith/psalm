<?php

declare (strict_types=1);
namespace Psalm\Code_Location;

use Psalm\Code_Location;
use Psalm\File_Source;
/** @psalm-immutable */
final class Docblock_Type_Location extends Code_Location
{
    public function __construct(File_Source $file_source, int $file_start, int $file_end, int $line_number)
    {
        $this->file_start = $file_start;
        // matches how CodeLocation works
        $this->file_end = $file_end - 1;
        $this->raw_file_start = $file_start;
        $this->raw_file_end = $file_end;
        $this->raw_line_number = $line_number;
        $this->file_path = $file_source->get_file_path();
        $this->file_name = $file_source->get_file_name();
        $this->single_line = false;
        $this->preview_start = $this->file_start;
        $this->docblock_line_number = $line_number;
    }
}