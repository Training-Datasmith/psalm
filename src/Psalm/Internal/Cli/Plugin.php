<?php

declare (strict_types=1);
namespace Psalm\Internal\Cli;

use Psalm\Internal\Cli_Utils;
use Psalm\Internal\Plugin_Manager\Command\Disable_Command;
use Psalm\Internal\Plugin_Manager\Command\Enable_Command;
use Psalm\Internal\Plugin_Manager\Command\Show_Command;
use Psalm\Internal\Plugin_Manager\Plugin_List_Factory;
use Symfony\Component\Console\Application;
use function dirname;
use function getcwd;
// phpcs:disable PSR1.Files.SideEffects
require_once __DIR__ . '/../CliUtils.php';
require_once __DIR__ . '/../ErrorHandler.php';
require_once __DIR__ . '/../Composer.php';
/**
 * @internal
 */
final class Plugin
{
    public static function run(): void
    {
        Cli_Utils::check_runtime_requirements();
        $current_dir = (string) getcwd();
        $vendor_dir = Cli_Utils::get_vendor_dir($current_dir);
        Cli_Utils::require_autoloaders($current_dir, false, $vendor_dir);
        $app = new Application('psalm-plugin', PSALM_VERSION);
        $psalm_root = dirname(__DIR__, 4);
        $plugin_list_factory = new Plugin_List_Factory($current_dir, $psalm_root);
        $app->add_commands([new Show_Command($plugin_list_factory), new Enable_Command($plugin_list_factory), new Disable_Command($plugin_list_factory)]);
        $app->set_default_command('show');
        $app->run();
    }
}