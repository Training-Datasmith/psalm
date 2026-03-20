<?php

declare (strict_types=1);
namespace Psalm\Internal;

use Psalm\Internal\Analyzer\File_Analyzer;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Codebase\Functions;
use Psalm\Internal\Codebase\Reflection;
use Psalm\Internal\File_Manipulation\Class_Docblock_Manipulator;
use Psalm\Internal\File_Manipulation\File_Manipulation_Buffer;
use Psalm\Internal\File_Manipulation\Function_Docblock_Manipulator;
use Psalm\Internal\File_Manipulation\Property_Docblock_Manipulator;
use Psalm\Internal\Provider\Class_Like_Storage_Provider;
use Psalm\Internal\Provider\File_Reference_Provider;
use Psalm\Internal\Provider\File_Storage_Provider;
use Psalm\Internal\Provider\Statements_Provider;
use Psalm\Internal\Scanner\Parsed_Docblock;
use Psalm\Internal\Type\Type_Tokenizer;
use Psalm\Issue_Buffer;
/**
 * @internal
 */
abstract class Runtime_Caches
{
    public static function clear_all(): void
    {
        Issue_Buffer::clear_cache();
        Reflection::clear_cache();
        Functions::clear_cache();
        Type_Tokenizer::clear_cache();
        File_Reference_Provider::clear_cache();
        File_Manipulation_Buffer::clear_cache();
        Class_Docblock_Manipulator::clear_cache();
        Function_Docblock_Manipulator::clear_cache();
        Property_Docblock_Manipulator::clear_cache();
        File_Analyzer::clear_cache();
        Function_Like_Analyzer::clear_cache();
        Class_Like_Storage_Provider::delete_all();
        File_Storage_Provider::delete_all();
        Statements_Provider::clear_parser();
        Parsed_Docblock::reset_newline_between_annotations();
    }
}