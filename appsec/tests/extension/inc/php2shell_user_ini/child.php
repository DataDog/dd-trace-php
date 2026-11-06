<?php
// Child script for php2shell_mitigation_hard_limit_post_max_size_user_ini.phpt.
// The .user.ini next to it changes post_max_size after the urlencoded body was
// read under the php.ini value. Prints the limits the PHP2Shell mitigation
// reports and the post_max_size in force while the script runs.
require __DIR__ . '/../php2shell_child_status.php';
echo "POST_MAX_SIZE ", ini_get('post_max_size'), "\n";
