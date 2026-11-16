<?php

error_log('Calling a function that does not exist...');
DDTrace\Testing\trigger_error('Fatal error in autoloaded file', E_ERROR);
error_log('You should not see this line.');
