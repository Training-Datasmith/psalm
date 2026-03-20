<?php

declare (strict_types=1);
namespace Psalm\Internal\Language_Server\Server;

use InvalidArgumentException;
use Language_Server_Protocol\File_Change_Type;
use Language_Server_Protocol\File_Event;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\Project_Analyzer;
use Psalm\Internal\Composer;
use Psalm\Internal\Language_Server\Language_Server;
use Psalm\Internal\Provider\File_Reference_Provider;
use function array_filter;
use function array_map;
use function in_array;
use function realpath;
/**
 * Provides method handlers for all workspace/* methods
 *
 * @internal
 */
final class Workspace
{
    public function __construct(protected Language_Server $server, protected Codebase $codebase, protected Project_Analyzer $project_analyzer)
    {
    }
    /**
     * The watched files notification is sent from the client to the server when the client
     * detects changes to files and folders watched by the language client (note although
     * the name suggest that only file events are sent it is about file system events
     * which include folders as well). It is recommended that servers register for these
     * file system events using the registration mechanism. In former implementations clients
     * pushed file events without the server actively asking for it.
     *
     * @param FileEvent[] $changes
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function did_change_watched_files(array $changes): void
    {
        $this->server->log_debug('workspace/didChangeWatchedFiles');
        $real_files = array_filter(array_map(function (File_Event $change): ?string {
            try {
                return $this->server->uri_to_path($change->uri);
            } catch (InvalidArgumentException) {
                return null;
            }
        }, $changes));
        $composer_lock_file = realpath(Composer::get_lock_file_path($this->codebase->config->base_dir));
        if (in_array($composer_lock_file, $real_files)) {
            $this->server->log_info('Composer.lock file changed. Reloading codebase');
            File_Reference_Provider::clear_cache();
            $this->server->queue_file_analysis_with_opened_files();
            return;
        }
        foreach ($changes as $change) {
            $file_path = $this->server->uri_to_path($change->uri);
            if ($composer_lock_file === $file_path) {
                continue;
            }
            if ($change->type === File_Change_Type::DELETED) {
                $this->codebase->invalidate_information_for_file($file_path);
                continue;
            }
            if (!$this->codebase->config->is_in_project_dirs($file_path)) {
                continue;
            }
            if ($this->project_analyzer->onchange_line_limit === 0) {
                continue;
            }
            //If the file is currently open then dont analyze it because its tracked in didChange
            if (!$this->codebase->file_provider->is_open($file_path)) {
                $this->server->queue_closed_file_analysis($file_path, $change->uri);
            }
        }
    }
    // @codingStandardsIgnoreStart
    /**
     * A notification sent from the client to the server to signal the change of configuration settings.
     *
     * @psalm-suppress PossiblyUnusedMethod, UnusedParam, MissingParamType
     */
    public function did_change_configuration(): void
    {
        // @codingStandardsIgnoreEnd
        $this->server->log_debug('workspace/didChangeConfiguration');
        $this->server->client->refresh_configuration();
    }
    // @codingStandardsIgnoreStart
    /**
     * The workspace/executeCommand request is sent from the client to the server to
     * trigger command execution on the server.
     *
     * @psalm-suppress PossiblyUnusedMethod, MissingParamType
     */
    public function execute_command(string $command, $arguments): void
    {
        // @codingStandardsIgnoreEnd
        $this->server->log_debug('workspace/executeCommand', ['command' => $command, 'arguments' => $arguments]);
        switch ($command) {
            case 'psalm.analyze.uri':
                /** @var array{uri: string} */
                $arguments = (array) $arguments;
                $file = $this->server->uri_to_path($arguments['uri']);
                $this->codebase->reload_files($this->project_analyzer, [$file], true);
                $this->codebase->analyzer->add_files_to_analyze([$file => $file]);
                $this->codebase->analyzer->analyze_files($this->project_analyzer, 1, false);
                $this->server->emit_versioned_issues([$file => $arguments['uri']]);
                break;
        }
    }
}