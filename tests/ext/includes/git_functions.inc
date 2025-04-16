<?php

function mkdirGitDir($gitDir) {
    if (!file_exists($gitDir)) {
        mkdir($gitDir);
    }
}

function generateHEAD($gitDir) {
    $headFile = $gitDir . '/HEAD';
    if (!file_exists($headFile)) {
        file_put_contents($headFile, 'ref: refs/heads/master');
    }
}

function mkdirHeadsFolder($gitDir) {
    $headsDir = $gitDir . '/refs/heads';
    if (!file_exists($headsDir)) {
        mkdir($headsDir, 0777, true);
    }

    $masterFile = $headsDir . '/master';
    if (!file_exists($masterFile)) {
        file_put_contents($masterFile, '123456');
    }
}

function generateConfigFile($gitDir) {
    $configFile = $gitDir . '/config';
    if (!file_exists($configFile)) {
        file_put_contents($configFile, <<<CONFIG
[core]
    repositoryformatversion = 0
    filemode = true
    bare = false
    logallrefupdates = true
    ignorecase = true
    precomposeunicode = true
[remote "origin"]
    url = https://u:t@github.com/user/repo_new
    fetch = +refs/heads/*:refs/remotes/origin/*
[branch "main"]
    remote = origin
    merge = refs/heads/main
CONFIG
        );
    }
}

function generateFullFakeGitFolder($dir) {
    $gitDir = $dir . '/.git';
    mkdirGitDir($gitDir);
    generateHEAD($gitDir);
    mkdirHeadsFolder($gitDir);
    generateConfigFile($gitDir);
}

function generateInvalidFakeGitFolder($dir) {
    $gitDir = $dir . '/.git';
    mkdirGitDir($gitDir);
    //generateHEAD($gitDir);
    mkdirHeadsFolder($gitDir);
    generateConfigFile($gitDir);
}
