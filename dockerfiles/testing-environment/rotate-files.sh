#!/usr/bin/env bash

set -e

while true
do
  cp public/header_copy.php public/header.php
  cp public/content_copy.php public/content.php
  cp public/footer_copy.php public/footer.php
  sleep 0.2
done
