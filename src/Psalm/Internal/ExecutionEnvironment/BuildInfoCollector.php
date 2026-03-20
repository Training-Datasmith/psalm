<?php

declare (strict_types=1);
namespace Psalm\Internal\Execution_Environment;

use Psalm\Source_Control\Git\Commit_Info;
use Psalm\Source_Control\Git\Git_Info;
use function assert;
use function explode;
use function file_get_contents;
use function json_decode;
use function str_contains;
use function str_replace;
use function strtotime;
use const JSON_THROW_ON_ERROR;
/**
 * Environment variables collector for CI environment.
 *
 * @author Kitamura Satoshi <with.no.parachute@gmail.com>
 * @internal
 */
final class Build_Info_Collector
{
    /**
     * Read environment variables.
     */
    private array $read_env = [];
    public function __construct(
        /**
         * Environment variables.
         *
         * Overwritten through collection process.
         */
        protected array $env
    )
    {
    }
    // API
    /**
     * Collect environment variables.
     */
    public function collect(): array
    {
        $this->read_env = [];
        $this->fill_travis_ci()->fill_circle_ci()->fill_app_veyor()->fill_jenkins()->fill_scrutinizer()->fill_github_actions();
        return $this->read_env;
    }
    // internal method
    /**
     * Fill Travis CI environment variables.
     *
     * "TRAVIS", "TRAVIS_JOB_ID" must be set.
     *
     * @return $this
     * @psalm-suppress PossiblyUndefinedStringArrayOffset
     */
    private function fill_travis_ci(): self
    {
        if (isset($this->env['TRAVIS']) && $this->env['TRAVIS'] && isset($this->env['TRAVIS_JOB_ID'])) {
            $this->read_env['CI_JOB_ID'] = $this->env['TRAVIS_JOB_ID'];
            $this->env['CI_NAME'] = 'travis-ci';
            // backup
            $this->read_env['TRAVIS'] = $this->env['TRAVIS'];
            $this->read_env['TRAVIS_JOB_ID'] = $this->env['TRAVIS_JOB_ID'];
            $this->read_env['CI_NAME'] = $this->env['CI_NAME'];
            $this->read_env['TRAVIS_TAG'] = $this->env['TRAVIS_TAG'] ?? '';
            $repo_slug = (string) $this->env['TRAVIS_REPO_SLUG'];
            if ($repo_slug) {
                $slug_parts = explode('/', $repo_slug);
                $this->read_env['CI_REPO_OWNER'] = $slug_parts[0];
                $this->read_env['CI_REPO_NAME'] = $slug_parts[1];
            }
            $pr_slug = (string) ($this->env['TRAVIS_PULL_REQUEST_SLUG'] ?? '');
            if ($pr_slug) {
                $slug_parts = explode('/', $pr_slug);
                $this->read_env['CI_PR_REPO_OWNER'] = $slug_parts[0];
                $this->read_env['CI_PR_REPO_NAME'] = $slug_parts[1];
            }
            $this->read_env['CI_PR_NUMBER'] = $this->env['TRAVIS_PULL_REQUEST'];
            $this->read_env['CI_BRANCH'] = $this->env['TRAVIS_BRANCH'];
        }
        return $this;
    }
    /**
     * Fill CircleCI environment variables.
     *
     * "CIRCLECI", "CIRCLE_BUILD_NUM" must be set.
     *
     * @return $this
     */
    private function fill_circle_ci(): self
    {
        if (isset($this->env['CIRCLECI']) && $this->env['CIRCLECI'] && isset($this->env['CIRCLE_BUILD_NUM'])) {
            $this->env['CI_BUILD_NUMBER'] = $this->env['CIRCLE_BUILD_NUM'];
            $this->env['CI_NAME'] = 'circleci';
            // backup
            $this->read_env['CIRCLECI'] = $this->env['CIRCLECI'];
            $this->read_env['CIRCLE_BUILD_NUM'] = $this->env['CIRCLE_BUILD_NUM'];
            $this->read_env['CI_NAME'] = $this->env['CI_NAME'];
            $this->read_env['CI_PR_REPO_OWNER'] = $this->env['CIRCLE_PR_USERNAME'] ?? null;
            $this->read_env['CI_PR_REPO_NAME'] = $this->env['CIRCLE_PR_REPONAME'] ?? null;
            $this->read_env['CI_REPO_OWNER'] = $this->env['CIRCLE_PROJECT_USERNAME'] ?? null;
            $this->read_env['CI_REPO_NAME'] = $this->env['CIRCLE_PROJECT_REPONAME'] ?? null;
            $this->read_env['CI_PR_NUMBER'] = $this->env['CIRCLE_PR_NUMBER'] ?? null;
            $this->read_env['CI_BRANCH'] = $this->env['CIRCLE_BRANCH'] ?? null;
        }
        return $this;
    }
    /**
     * Fill AppVeyor environment variables.
     *
     * "APPVEYOR", "APPVEYOR_BUILD_NUMBER" must be set.
     *
     * @psalm-suppress PossiblyUndefinedStringArrayOffset
     * @return $this
     */
    private function fill_app_veyor(): self
    {
        if (isset($this->env['APPVEYOR']) && $this->env['APPVEYOR'] && isset($this->env['APPVEYOR_BUILD_NUMBER'])) {
            $this->read_env['CI_BUILD_NUMBER'] = $this->env['APPVEYOR_BUILD_NUMBER'];
            $this->read_env['CI_JOB_ID'] = $this->env['APPVEYOR_JOB_NUMBER'];
            $this->read_env['CI_PR_NUMBER'] = $this->env['APPVEYOR_PULL_REQUEST_NUMBER'] ?? '';
            $this->env['CI_NAME'] = 'AppVeyor';
            // backup
            $this->read_env['APPVEYOR'] = $this->env['APPVEYOR'];
            $this->read_env['APPVEYOR_BUILD_NUMBER'] = $this->env['APPVEYOR_BUILD_NUMBER'];
            $this->read_env['APPVEYOR_JOB_NUMBER'] = $this->env['APPVEYOR_JOB_NUMBER'];
            $this->read_env['APPVEYOR_REPO_BRANCH'] = $this->env['APPVEYOR_REPO_BRANCH'];
            $this->read_env['CI_NAME'] = $this->env['CI_NAME'];
            $repo_slug = (string) ($this->env['APPVEYOR_REPO_NAME'] ?? '');
            if ($repo_slug) {
                $slug_parts = explode('/', $repo_slug);
                $this->read_env['CI_REPO_OWNER'] = $slug_parts[0];
                $this->read_env['CI_REPO_NAME'] = $slug_parts[1];
            }
            $pr_slug = (string) ($this->env['APPVEYOR_PULL_REQUEST_HEAD_REPO_NAME'] ?? '');
            if ($pr_slug) {
                $slug_parts = explode('/', $pr_slug);
                $this->read_env['CI_PR_REPO_OWNER'] = $slug_parts[0];
                $this->read_env['CI_PR_REPO_NAME'] = $slug_parts[1];
            }
            $this->read_env['CI_BRANCH'] = $this->env['APPVEYOR_PULL_REQUEST_HEAD_REPO_BRANCH'] ?? $this->env['APPVEYOR_REPO_BRANCH'];
        }
        return $this;
    }
    /**
     * Fill Jenkins environment variables.
     *
     * "JENKINS_URL", "BUILD_NUMBER" must be set.
     *
     * @return $this
     */
    private function fill_jenkins(): self
    {
        if (isset($this->env['JENKINS_URL']) && isset($this->env['BUILD_NUMBER'])) {
            $this->read_env['CI_BUILD_NUMBER'] = $this->env['BUILD_NUMBER'];
            $this->read_env['CI_BUILD_URL'] = $this->env['JENKINS_URL'];
            $this->env['CI_NAME'] = 'jenkins';
            // backup
            $this->read_env['BUILD_NUMBER'] = $this->env['BUILD_NUMBER'];
            $this->read_env['JENKINS_URL'] = $this->env['JENKINS_URL'];
            $this->read_env['CI_NAME'] = $this->env['CI_NAME'];
        }
        return $this;
    }
    /**
     * Fill Scrutinizer environment variables.
     *
     * "JENKINS_URL", "BUILD_NUMBER" must be set.
     *
     * @psalm-suppress PossiblyUndefinedStringArrayOffset
     * @return $this
     */
    private function fill_scrutinizer(): self
    {
        if (isset($this->env['SCRUTINIZER']) && $this->env['SCRUTINIZER']) {
            $this->read_env['CI_JOB_ID'] = $this->env['SCRUTINIZER_INSPECTION_UUID'];
            $this->read_env['CI_BRANCH'] = $this->env['SCRUTINIZER_BRANCH'];
            $this->read_env['CI_PR_NUMBER'] = $this->env['SCRUTINIZER_PR_NUMBER'] ?? '';
            // backup
            $this->read_env['CI_NAME'] = 'Scrutinizer';
            $repo_slug = (string) ($this->env['SCRUTINIZER_PROJECT'] ?? '');
            if ($repo_slug) {
                $slug_parts = explode('/', $repo_slug);
                if ($this->read_env['CI_PR_NUMBER']) {
                    $this->read_env['CI_PR_REPO_OWNER'] = $slug_parts[1];
                    $this->read_env['CI_PR_REPO_NAME'] = $slug_parts[2];
                } else {
                    $this->read_env['CI_REPO_OWNER'] = $slug_parts[1];
                    $this->read_env['CI_REPO_NAME'] = $slug_parts[2];
                }
            }
        }
        return $this;
    }
    /**
     * Fill GitHub Actions environment variables.
     *
     * @psalm-suppress PossiblyUndefinedStringArrayOffset
     */
    private function fill_github_actions(): Build_Info_Collector
    {
        if (isset($this->env['GITHUB_ACTIONS'])) {
            $this->env['CI_NAME'] = 'github-actions';
            $this->env['CI_JOB_ID'] = $this->env['GITHUB_ACTIONS'];
            $github_ref = (string) $this->env['GITHUB_REF'];
            if (str_contains($github_ref, 'refs/heads/')) {
                $github_ref = str_replace('refs/heads/', '', $github_ref);
            } elseif (str_contains($github_ref, 'refs/tags/')) {
                $github_ref = str_replace('refs/tags/', '', $github_ref);
            }
            $this->env['CI_BRANCH'] = $github_ref;
            $this->read_env['GITHUB_ACTIONS'] = $this->env['GITHUB_ACTIONS'];
            $this->read_env['GITHUB_REF'] = $this->env['GITHUB_REF'];
            $this->read_env['CI_NAME'] = $this->env['CI_NAME'];
            $this->read_env['CI_BRANCH'] = $this->env['CI_BRANCH'];
            $slug_parts = explode('/', (string) $this->env['GITHUB_REPOSITORY']);
            $this->read_env['CI_REPO_OWNER'] = $slug_parts[0];
            $this->read_env['CI_REPO_NAME'] = $slug_parts[1];
            if (isset($this->env['GITHUB_EVENT_PATH'])) {
                $event_json = file_get_contents((string) $this->env['GITHUB_EVENT_PATH']);
                assert($event_json !== false);
                /** @var array */
                $event_data = json_decode($event_json, true, 512, JSON_THROW_ON_ERROR);
                if (isset($event_data['head_commit'])) {
                    /**
                     * @var array{
                     *    id: string,
                     *    author: array{name: string, email: string},
                     *    committer: array{name: string, email: string},
                     *    message: string,
                     *    timestamp: string
                     * }
                     */
                    $head_commit_data = $event_data['head_commit'];
                    $gitinfo = new Git_Info($github_ref, (new Commit_Info())->set_id($head_commit_data['id'])->set_author_name($head_commit_data['author']['name'])->set_author_email($head_commit_data['author']['email'])->set_committer_name($head_commit_data['committer']['name'])->set_committer_email($head_commit_data['committer']['email'])->set_message($head_commit_data['message'])->set_date((int) strtotime($head_commit_data['timestamp'])), []);
                    $this->read_env['git'] = $gitinfo->to_array();
                }
                if ($this->env['GITHUB_EVENT_PATH'] === 'pull_request') {
                    $this->read_env['CI_PR_NUMBER'] = $event_data['number'];
                }
            }
        }
        return $this;
    }
}