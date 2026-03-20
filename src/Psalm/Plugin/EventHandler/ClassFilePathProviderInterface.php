<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler;

interface Class_File_Path_Provider_Interface
{
    /**
     * @param class-string $class
     */
    public static function get_class_file_path(string $class): ?string;
}