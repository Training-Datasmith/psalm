<?php

declare (strict_types=1);
namespace Psalm\Node\Scalar\Magic_Const;

use Php_Parser\Node\Scalar\Magic_Const\File;
use Psalm\Node\Virtual_Node;
final class Virtual_File extends File implements Virtual_Node
{
}