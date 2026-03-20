<?php

declare (strict_types=1);
namespace Psalm\Internal\Execution_Environment;

use Psalm\Source_Control\Git\Commit_Info;
use Psalm\Source_Control\Git\Git_Info;
use Psalm\Source_Control\Git\Remote_Info;
use RuntimeException;
use function array_keys;
use function array_unique;
use function count;
use function explode;
use function range;
use function str_contains;
use function str_starts_with;
use function trim;
/**
 * Git repository info collector.
 *
 * @author Kitamura Satoshi <with.no.parachute@gmail.com>
 * @internal
 */
final class Git_Info_Collector
{
    /**
     * Git command.
     */
    private readonly System_Command_Executor $executor;
    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->executor = new System_Command_Executor();
    }
    // API
    /**
     * Collect git repository info.
     */
    public function collect(): Git_Info
    {
        $branch = $this->collect_branch();
        $commit = $this->collect_commit();
        $remotes = $this->collect_remotes();
        return new Git_Info($branch, $commit, $remotes);
    }
    /**
     * Collect branch name.
     *
     * @throws RuntimeException
     */
    private function collect_branch(): string
    {
        $branches_result = $this->executor->execute('git branch');
        foreach ($branches_result as $result) {
            if (str_starts_with($result, '* ')) {
                $exploded = explode('* ', $result, 2);
                return $exploded[1];
            }
        }
        throw new RuntimeException();
    }
    /**
     * Collect commit info.
     *
     * @throws RuntimeException
     */
    private function collect_commit(): Commit_Info
    {
        $commit_result = $this->executor->execute('git log -1 --pretty=format:%H%n%aN%n%ae%n%cN%n%ce%n%s%n%at');
        if (count($commit_result) !== 7 || array_keys($commit_result) !== range(0, 6)) {
            throw new RuntimeException();
        }
        $commit = new Commit_Info();
        return $commit->set_id(trim($commit_result[0]))->set_author_name(trim($commit_result[1]))->set_author_email(trim($commit_result[2]))->set_committer_name(trim($commit_result[3]))->set_committer_email(trim($commit_result[4]))->set_message($commit_result[5])->set_date((int) $commit_result[6]);
    }
    /**
     * Collect remotes info.
     *
     * @throws RuntimeException
     * @return list<RemoteInfo>
     */
    private function collect_remotes(): array
    {
        $remotes_result = $this->executor->execute('git remote -v');
        if (count($remotes_result) === 0) {
            throw new RuntimeException();
        }
        // parse command result
        $results = [];
        foreach ($remotes_result as $result) {
            if (str_contains($result, ' ')) {
                [$remote] = explode(' ', $result, 2);
                $results[] = $remote;
            }
        }
        // filter
        $results = array_unique($results);
        // create Remote instances
        $remotes = [];
        foreach ($results as $result) {
            if (str_contains($result, "\t")) {
                [$name, $url] = explode("\t", $result, 2);
                $remote = new Remote_Info();
                $remotes[] = $remote->set_name(trim($name))->set_url(trim($url));
            }
        }
        return $remotes;
    }
}