<?php

error_log('Calling a function that does not exist...');
this_function_does_not_exist();
error_log('You should not see this line.');
