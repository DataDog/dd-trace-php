<?php

\DDTrace\ATO\V2\track_user_login_failure(
    $_GET['login'],
    true,
    [
      "metakey1" => "metavalue",
      "metakey2" => "metavalue02",
  ]);

echo "User Login Failure";
