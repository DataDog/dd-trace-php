--TEST--
Check if ddog_php_experiment is loaded
--EXTENSIONS--
ddog_php_experiment
--FILE--
<?php
echo 'The extension "ddog_php_experiment" is available';
?>
--EXPECT--
The extension "ddog_php_experiment" is available
