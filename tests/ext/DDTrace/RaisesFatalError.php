<?php

ini_set("memory_limit", "20M");

error_log('Calling a function that does not exist...');
str_repeat("a", 3e7);
error_log('You should not see this line.');
