<?php

$github_pat = $_ENV["GITHUB_RELEASE_PAT"];
$target_branch = $_ENV["CI_COMMIT_BRANCH"] ?? "master";
$repo_url = "https://github.com/DataDog/dd-trace-php";
$api_base_url = "https://api.github.com/repos/DataDog/dd-trace-php";

if (!$github_pat) {
    fprintf(STDERR, "Error: GITHUB_RELEASE_PAT environment variable not set\n");
    exit(1);
}

if ($argc < 2) {
    fprintf(STDERR, "Usage: %s <packages directory>\n", $argv[0]);
    exit(1);
}

$package_directory = $argv[1];
$repository_root = __DIR__ . "/../..";
$version = trim(file_get_contents("$repository_root/VERSION"));
$changelog = trim(implode(array_slice(file("$repository_root/CHANGELOG.md"), 2)));

if (!str_contains($target_branch, $version)) {
    fprintf(STDERR, "Error: This does not look like a release branch.\n");
    fprintf(STDERR, "Target branch name '%s' does not contain version '%s'\n", $target_branch, $version);
    fprintf(STDERR, "For a manual test, specify the CI_COMMIT_BRANCH environment variable explicitly.\n");
    exit(1);
}

if (!is_dir($package_directory)) {
    fprintf(STDERR, "Error: Package directory '%s' does not exist\n", $package_directory);
    exit(1);
}

$files = glob($package_directory . '/*');
if (empty($files)) {
    fprintf(STDERR, "Error: No files found in package directory '%s'\n", $package_directory);
    exit(1);
}

function makeGithubRequest($url, $method, $data, $github_pat) {
    if (empty($github_pat)) {
        fprintf(STDERR, "Warning: GitHub token is empty!\n");
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => [
                "Authorization: token $github_pat",
                "Accept: application/vnd.github+json",
                "User-Agent: DataDog/dd-trace-php (create_release.php)",
                "Content-Type: application/json",
            ],
            'content' => $data ? is_array($data) ? json_encode($data) : $data : null,
            'ignore_errors' => true,
        ]
    ]);

    $response = file_get_contents($url, false, $context);
    if ($response === false) {
        exit(1);
    }

    // Debug: dump response headers
    if (isset($http_response_header)) {
        fprintf(STDERR, "Debug: Response headers:\n");
        foreach ($http_response_header as $header) {
            fprintf(STDERR, "  %s\n", $header);
        }
    }

    $json = json_decode($response, true);
    if (!$json) {
        if (str_contains($http_response_header[0], 204)) {
            return null;
        }

        fprintf(STDERR, "Error: Failed to parse response from GitHub API\n");
        fprintf(STDERR, "Got: %s\n", $response);
        exit(1);
    }

    return $json;
}

// Fetch releases (sadly there's no way to fetch a draft release directly)
$release = null;
$page = 1;
$per_page = 100; // maximum allowed per page
do {
    $url = "$api_base_url/releases?page=$page&per_page=$per_page";
    $releases_list = makeGitHubRequest($url, 'GET', null, $github_pat);

    foreach ($releases_list as $found_release) {
        if ($found_release['tag_name'] == $version) {
            if (!$found_release['draft']) {
                echo "Release $version already exists and was actually released...\n";
                if (isset($_ENV["GITHUB_RELEASE_OVERWRITE"])) {
                    echo "GITHUB_RELEASE_OVERWRITE was specified. Overwriting release $version...\n";
                } else {
                    fprintf(STDERR, "Release $version already exists and was already released... Specify the GITHUB_RELEASE_OVERWRITE=1 environment variable if that's intended.\n");
                    exit(1);
                }
                fprintf(STDERR, "\n");
            }
            $release = $found_release;
            break 2;
        }
    }

    ++$page;
} while (count($releases_list) == $per_page);

if (isset($release['id'])) {
    echo "Release $version already exists at {$release['html_url']}, updating assets only...\n";

    // Ensure to remove the old assets because github does not like overwriting...
    foreach ($release['assets'] ?? [] as $asset) {
        echo "Deleting existing asset: {$asset['name']}\n";
        $response = makeGithubRequest("$api_base_url/releases/assets/{$asset['id']}", 'DELETE', null, $github_pat);
        if (isset($response['message'])) {
            fprintf(STDERR, "Error: Failed to delete asset\n");
            fprintf(STDERR, "GitHub API error: %s\n", $release['message']);
            exit(1);
        }
    }
} else {
    echo "Creating new release $version...\n";

    $release_data = [
        'tag_name' => $version,
        'target_commitish' => $target_branch,
        'name' => $version,
        'body' => $changelog,
        'draft' => true,
        'prerelease' => (bool) preg_match("([a-z])i", $version),
    ];
    $release = makeGithubRequest("$api_base_url/releases", 'POST', $release_data, $github_pat);

    if (!isset($release['id'])) {
        fprintf(STDERR, "Error: Failed to create release\n");
        if (isset($release['message'])) {
            fprintf(STDERR, "GitHub API error: %s\n", $release['message']);
        }
        exit(1);
    }
    echo "Drafted release at {$release['html_url']}\n";
}

foreach ($files as $file_path) {
    $filename = basename($file_path);
    $file_contents = file_get_contents($file_path);

    echo "Uploading $filename...\n";
    if ($file_contents === false) {
        fprintf(STDERR, "Error: Could not read file '%s'\n", $file_path);
        exit(1);
    }

    $upload_url = str_replace('{?name,label}', '?name=' . urlencode($filename), $release['upload_url']);
    $response = makeGithubRequest($upload_url, 'POST', $file_contents, $github_pat);
    if (isset($response['message'])) {
        fprintf(STDERR, "Error: Failed to upload file\n");
        fprintf(STDERR, "GitHub API error: %s\n", $response['message']);
        exit(1);
    }
    echo "Successfully uploaded: $filename\n";
}

echo "All artifacts uploaded for release $version at {$release['html_url']}\n";
