<?php

\datadog\appsec\v2\track_user_login_success(
    $_GET['login'],
    $_GET['id'],
    [
      "metakey1" => "metavalue",
      "metakey2" => "metavalue02",
  ]);

echo "User Login Success";
