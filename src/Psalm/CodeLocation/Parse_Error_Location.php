<?php

declare (strict_types=1);
namespace Psalm\Code_Location;

use Php_Parser;
use Psalm\Code_Location;
use function substr;
use function substr_count;
/** @psalm-immutable */
final class Parse_Error_Location extends Code_Location
{
    public function __construct(Php_Parser\Error $error, string $file_contents, string $file_path, string $file_name)
    {
        /** @psalm-suppress PossiblyUndefinedStringArrayOffset */
        $this->file_start = (int) $error->get_attributes()['startFilePos'];
        /** @psalm-suppress PossiblyUndefinedStringArrayOffset */
        $this->file_end = (int) $error->get_attributes()['endFilePos'];
        $this->raw_file_start = $this->file_start;
        $this->raw_file_end = $this->file_end;
        $this->file_path = $file_path;
        $this->file_name = $file_name;
        $this->single_line = false;
        $this->preview_start = $this->file_start;
        $this->raw_line_number = substr_count(substr($file_contents, 0, $this->file_start), "\n") + 1;
    }
}