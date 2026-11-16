<?php

function include_all_files($dir) {
    $files = scandir($dir);
    foreach($files as $file) {
        if ($file == '.' || $file == '..') {
            continue;
        }
        if (is_dir("$dir/$file")) {
            include_all_files("$dir/$file");
        } else if (pathinfo($file, PATHINFO_EXTENSION) == 'php' && $file != 'autoload.php') {
            include_once "$dir/$file";
        }
    }
}

include_all_files(__DIR__);
