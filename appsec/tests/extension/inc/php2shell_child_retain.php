<?php
// Child script for php2shell_mitigation_post_deactivate_order.phpt: hands an
// exception to p2s_stub_retain(), which keeps it until the stub's
// post_deactivate releases it, then prints REQUEST DONE.
p2s_stub_retain(new Exception('kept past shutdown'));
echo "REQUEST DONE\n";
