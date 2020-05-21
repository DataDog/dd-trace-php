<?php

error_log('Throwing an exception...');
throw new Exception('Oops!');
error_log('You should not see this line.');
