Call the script from here:

    $ DD_AGENT_HOST=agent php -dddtrace.request_init_hook=`pwd`/../../bridge/dd_wrap_autoloader.php app.php
