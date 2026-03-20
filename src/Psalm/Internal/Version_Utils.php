<?php

declare (strict_types=1);
namespace Psalm\Internal;

use Composer\Installed_Versions;
use OutOfBoundsException;
use Phar;
use function class_exists;
use function dirname;
use function file_put_contents;
use function var_export;
/**
 * @internal
 * @psalm-type _VersionData=array{"vimeo/psalm": string, "nikic/php-parser": string}
 */
final class Version_Utils
{
    private const PSALM_PACKAGE = 'vimeo/psalm';
    private const PHP_PARSER_PACKAGE = 'nikic/php-parser';
    /** @var null|_VersionData */
    private static ?array $versions = null;
    /** @psalm-suppress UnusedConstructor it's here to prevent instantiations */
    private function __construct()
    {
    }
    public static function get_psalm_version(): string
    {
        return self::get_versions()[self::PSALM_PACKAGE];
    }
    public static function get_php_parser_version(): string
    {
        return self::get_versions()[self::PHP_PARSER_PACKAGE];
    }
    /** @psalm-suppress UnusedMethod called from bin/ci/build-phar.sh */
    public static function dump(): void
    {
        $versions = self::load_composer_versions();
        $exported = '<?php return ' . var_export($versions, true) . ';';
        file_put_contents(dirname(__DIR__, 3) . '/build/phar-versions.php', $exported);
    }
    /** @return _VersionData */
    private static function get_versions(): array
    {
        if (self::$versions !== null) {
            return self::$versions;
        }
        if ($versions = self::load_phar_versions()) {
            return self::$versions = $versions;
        }
        if ($versions = self::load_composer_versions()) {
            return self::$versions = $versions;
        }
        return self::$versions = [self::PSALM_PACKAGE => 'unknown', self::PHP_PARSER_PACKAGE => 'unknown'];
    }
    /** @return _VersionData|null */
    private static function load_phar_versions(): ?array
    {
        if (!class_exists(Phar::class)) {
            return null;
        }
        $phar_filename = Phar::running(true);
        if (!$phar_filename) {
            return null;
        }
        /**
         * @psalm-suppress UnresolvableInclude
         */
        return require $phar_filename . '/phar-versions.php';
    }
    /** @return _VersionData|null */
    private static function load_composer_versions(): ?array
    {
        try {
            return [self::PSALM_PACKAGE => self::get_version(self::PSALM_PACKAGE), self::PHP_PARSER_PACKAGE => self::get_version(self::PHP_PARSER_PACKAGE)];
        } catch (OutOfBoundsException) {
        }
        return null;
    }
    private static function get_version(string $package_name): string
    {
        return Installed_Versions::get_pretty_version($package_name) . '@' . Installed_Versions::get_reference($package_name);
    }
}