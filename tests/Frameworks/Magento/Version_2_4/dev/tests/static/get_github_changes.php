<?php

/**
 * Script to get changes between feature branch and the mainline
 *
 * @category   dev
 * @package    build
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

define(
    'USAGE',
    <<<USAGE
        php -f get_github_changes.php --
    --output-file="<output_file>"
    --base-path="<base_path>"
    --repo="<main_repo>"
    --branch="<branch>"
    [--file-extensions="<comma_separated_list_of_formats>"]

USAGE
);

$options = getopt('', ['output-file:', 'base-path:', 'repo:', 'file-extensions:', 'branch:']);

$requiredOptions = ['output-file', 'base-path', 'repo', 'branch'];
if (!validateInput($options, $requiredOptions)) {
    echo USAGE;
    exit(1);
}

$fileExtensions = explode(',', isset($options['file-extensions']) ? $options['file-extensions'] : 'php');

include_once __DIR__ . '/framework/autoload.php';

$mainline = 'mainline_' . (string)rand(0, 9999);
$repo = getRepo($options, $mainline);
$branches = $repo->getBranches('--remotes');
generateBranchesList($options['output-file'], $branches, $options['branch']);
$changes = retrieveChangesAcrossForks($mainline, $repo, $options['branch']);
$changedFiles = getChangedFiles($changes, $fileExtensions);
generateChangedFilesList($options['output-file'], $changedFiles);
saveChangedFileContent($repo);

$additions = retrieveNewFilesAcrossForks($mainline, $repo, $options['branch']);
$addedFiles = getChangedFiles($additions, $fileExtensions);
$additionsFile = pathinfo($options['output-file']);
$additionsFile = $additionsFile['dirname']
    . DIRECTORY_SEPARATOR
    . $additionsFile['filename']
    . '.added.'
    . $additionsFile['extension'];
generateChangedFilesList($additionsFile, $addedFiles);

cleanup($repo, $mainline);

/**
 * Save changed file content.
 *
 * @param GitRepo $repo
 * @return void
 */
function saveChangedFileContent(GitRepo $repo)
{
    $changedFilesContentFileName = BP . Magento\TestFramework\Utility\ChangedFiles::CHANGED_FILES_CONTENT_FILE;
    foreach ($repo->getChangedContentFiles() as $key => $changedContentFile) {
        $filePath = sprintf($changedFilesContentFileName, $key);
        $oldContent = file_exists($filePath) ? file_get_contents($filePath) : '{}';
        $oldData = json_decode($oldContent, true);
        $data = array_merge($oldData, $changedContentFile);
        file_put_contents($filePath, json_encode($data));
    }
}

/**
 * Generates a file containing changed files
 *
 * @param string $outputFile
 * @param array $changedFiles
 * @return void
 */
function generateChangedFilesList($outputFile, $changedFiles)
{
    $changedFilesList = fopen($outputFile, 'w');
    foreach ($changedFiles as $file) {
        fwrite($changedFilesList, $file . PHP_EOL);
    }
    fclose($changedFilesList);
}

/**
 * Generates a file containing origin branches
 *
 * @param string $outputFile
 * @param array $branches
 * @param string $branchName
 * @return void
 */
function generateBranchesList($outputFile, $branches, $branchName)
{
    $branchOutputFile = str_replace('changed_files', 'branches', $outputFile);
    $branchesList = fopen($branchOutputFile, 'w');
    fwrite($branchesList, $branchName . PHP_EOL);
    foreach ($branches as $branch) {
        fwrite($branchesList, substr(strrchr($branch, '/'), 1) . PHP_EOL);
    }
    fclose($branchesList);
}

/**
 * Gets list of changed files
 *
 * @param array $changes
 * @param array $fileExtensions
 * @return array
 */
function getChangedFiles(array $changes, array $fileExtensions)
{
    $files = [];
    foreach ($changes as $fileName) {
        foreach ($fileExtensions as $extensions) {
            $isFileExension = strpos($fileName, '.' . $extensions);
            if ($isFileExension) {
                $files[] = $fileName;
            }
        }
    }

    return $files;
}

/**
 * Retrieves changes across forks
 *
 * @param array $options
 * @param string $mainline
 * @return GitRepo
 * @throws Exception
 */
function getRepo($options, $mainline)
{
    $repo = new GitRepo($options['base-path']);
    $repo->addRemote($mainline, $options['repo']);
    $repo->fetch($mainline);
    return $repo;
}

/**
 * Combine list of changed files based on comparison between forks.
 *
 * @param string $mainline
 * @param GitRepo $repo
 * @param string $branchName
 * @return array
 */
function retrieveChangesAcrossForks($mainline, GitRepo $repo, $branchName)
{
    return $repo->compareChanges($mainline, $branchName, GitRepo::CHANGE_TYPE_ALL);
}

/**
 * Combine list of new files based on comparison between forks.
 *
 * @param string $mainline
 * @param GitRepo $repo
 * @param string $branchName
 * @return array
 */
function retrieveNewFilesAcrossForks($mainline, GitRepo $repo, $branchName)
{
    return $repo->compareChanges($mainline, $branchName, GitRepo::CHANGE_TYPE_ADDED);
}

/**
 * Deletes temporary "base" repo
 *
 * @param GitRepo $repo
 * @param string $mainline
 */
function cleanup($repo, $mainline)
{
    $repo->removeRemote($mainline);
}

/**
 * Validates input options based on required options
 *
 * @param array $options
 * @param array $requiredOptions
 * @return bool
 */
function validateInput(array $options, array $requiredOptions)
{
    foreach ($requiredOptions as $requiredOption) {
        if (!isset($options[$requiredOption]) || empty($options[$requiredOption])) {
            return false;
        }
    }
    return true;
}

//@codingStandardsIgnoreStart
class GitRepo
// @codingStandardsIgnoreEnd
{
    const CHANGE_TYPE_ADDED = 1;
    const CHANGE_TYPE_MODIFIED = 2;
    const CHANGE_TYPE_ALL = 3;

    /**
     * Absolute path to git project
     *
     * @var string
     */
    private $workTree;

    /**
     * @var array
     */
    private $remoteList = [];

    /**
     * Array of changed content files.
     *
     * Example:
     *         'extension' =>
     *                      'path_to_file/filename'  => 'Content that was edited',
     *                      'path_to_file/filename2' => 'Content that was edited',
     *
     * @var array
     */
    private $changedContentFiles = [];

    /**
     * @param string $workTree absolute path to git project
     */
    public function __construct($workTree)
    {
        if (empty($workTree) || !is_dir($workTree)) {
            throw new UnexpectedValueException('Working tree should be a valid path to directory');
        }
        $this->workTree = $workTree;
    }

    /**
     * Adds remote
     *
     * @param string $alias
     * @param string $url
     */
    public function addRemote($alias, $url)
    {
        if (isset($this->remoteList[$alias])) {
            return;
        }
        $this->remoteList[$alias] = $url;

        $this->call(sprintf('remote add %s %s', $alias, $url));
    }

    /**
     * Remove remote
     *
     * @param string $alias
     */
    public function removeRemote($alias)
    {
        if (isset($this->remoteList[$alias])) {
            $this->call(sprintf('remote rm %s', $alias));
            unset($this->remoteList[$alias]);
        }
    }

    /**
     * Fetches remote
     *
     * @param string $remoteAlias
     */
    public function fetch($remoteAlias)
    {
        if (!isset($this->remoteList[$remoteAlias])) {
            throw new LogicException('Alias "' . $remoteAlias . '" is not defined');
        }

        $this->call(sprintf('fetch %s', $remoteAlias));
    }

    /**
     * Returns branches
     *
     * @param string $source
     * @return array|mixed
     */
    public function getBranches($source = '--all')
    {
        $result = $this->call(sprintf('branch ' . $source));

        return is_array($result) ? $result : [];
    }

    /**
     * Returns files changes between branch and HEAD
     *
     * @param string $remoteAlias
     * @param string $remoteBranch
     * @param int $changesType
     * @return array
     */
    public function compareChanges($remoteAlias, $remoteBranch, $changesType = self::CHANGE_TYPE_ALL)
    {
        if (!isset($this->remoteList[$remoteAlias])) {
            throw new LogicException('Alias "' . $remoteAlias . '" is not defined');
        }

        $result = $this->call(sprintf('log %s/%s..HEAD  --name-status --oneline', $remoteAlias, $remoteBranch));

        return is_array($result)
            ? $this->filterChangedFiles(
                $result,
                $remoteAlias,
                $remoteBranch,
                $changesType
            )
            : [];
    }

    /**
     * Makes a diff of file for specified remote/branch and filters only those have real changes
     *
     * @param array $changes
     * @param string $remoteAlias
     * @param string $remoteBranch
     * @param int $changesType
     * @return array
     */
    protected function filterChangedFiles(
        array $changes,
        $remoteAlias,
        $remoteBranch,
        $changesType = self::CHANGE_TYPE_ALL
    ) {
        $countScannedFiles = 0;
        $changedFilesMasks = $this->buildChangedFilesMask($changesType);
        $filteredChanges = [];
        foreach ($changes as $fileName) {
            $countScannedFiles++;
            if (($countScannedFiles % 5000) == 0) {
                echo $countScannedFiles . " files scanned so far\n";
            }

            $changeTypeMask = $this->detectChangeTypeMask($fileName, $changedFilesMasks);
            if (null === $changeTypeMask) {
                continue;
            }

            $fileName = trim(substr($fileName, strlen($changeTypeMask)));
            if (in_array($fileName, $filteredChanges)) {
                continue;
            }

            $fileChanges = $this->getFileChangeDetails($fileName, $remoteAlias, $remoteBranch);
            if (empty($fileChanges)) {
                continue;
            }

            if (!(isset($this->changedContentFiles[$fileName]))) {
                $this->setChangedContentFile($fileChanges, $fileName);
            }
            $filteredChanges[] = $fileName;
        }
        echo $countScannedFiles . " files scanned\n";

        return $filteredChanges;
    }

    /**
     * Build mask of git diff report
     *
     * @param int $changesType
     * @return array
     */
    private function buildChangedFilesMask(int $changesType): array
    {
        $changedFilesMasks = [];
        foreach ([
            self::CHANGE_TYPE_ADDED => "A\t",
            self::CHANGE_TYPE_MODIFIED => "M\t",
        ] as $changeType => $changedFilesMask) {
            if ($changeType & $changesType) {
                $changedFilesMasks[] = $changedFilesMask;
            }
        }
        return $changedFilesMasks;
    }

    /**
     * Find one of the allowed modification mask returned by git diff.
     *
     * Example of change record: "A path/to/added_file"
     *
     * @param string $changeRecord
     * @param array $allowedMasks
     * @return string|null
     */
    private function detectChangeTypeMask(string $changeRecord, array $allowedMasks)
    {
        foreach ($allowedMasks as $mask) {
            if (strpos($changeRecord, $mask) === 0) {
                return $mask;
            }
        }
        return null;
    }

    /**
     * Read detailed information about changes in a file
     *
     * @param string $fileName
     * @param string $remoteAlias
     * @param string $remoteBranch
     * @return array
     */
    private function getFileChangeDetails(string $fileName, string $remoteAlias, string $remoteBranch): array
    {
        if (!is_file($this->workTree . '/' . $fileName)) {
            return [];
        }

        $result = $this->call(
            sprintf(
                'diff HEAD %s/%s -- %s',
                $remoteAlias,
                $remoteBranch,
                $fileName
            )
        );

        return $result;
    }

    /**
     * Set changed content for file.
     *
     * @param array $content
     * @param string $fileName
     * @return void
     */
    private function setChangedContentFile(array $content, $fileName)
    {
        $changedContent = '';
        $extension = Magento\TestFramework\Utility\ChangedFiles::getFileExtension($fileName);

        foreach ($content as $item) {
            if (strpos($item, '---') !== 0 && strpos($item, '-') === 0 && $line = ltrim($item, '-')) {
                $changedContent .= $line . "\n";
            }
        }
        if ($changedContent !== '') {
            $this->changedContentFiles[$extension][$fileName] = $changedContent;
        }
    }

    /**
     * Get changed content files collection.
     *
     * @return array
     */
    public function getChangedContentFiles()
    {
        return $this->changedContentFiles;
    }

    /**
     * Makes call ro git cli
     *
     * @param string $command
     * @return mixed
     */
    private function call($command)
    {
        $gitCmd = sprintf(
            'git --git-dir %s --work-tree %s',
            escapeshellarg("{$this->workTree}/.git"),
            escapeshellarg($this->workTree)
        );
        $tmp = sprintf('%s %s', $gitCmd, $command);
        // exec() have to be here since this is test.
        // phpcs:ignore Magento2.Security.InsecureFunction
        exec($tmp, $output);
        return $output;
    }
}
