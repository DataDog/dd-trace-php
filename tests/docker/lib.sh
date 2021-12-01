#!/usr/bin/env bash

function image_exists {
  if [[ ${REBUILD_IMAGES:-0} -eq 1 ]]; then
    return 1
  fi
  [[ "$(docker images -q "$1" 2> /dev/null)" != "" ]]
}

function actual_version {
  case $1 in
    7.0)
      echo 7.0.33
      ;;
    7.1)
      echo 7.1.33
      ;;
    7.2)
      echo 7.2.34
      ;;
    7.3)
      echo 7.3.31
      ;;
    7.4)
      echo 7.4.24
      ;;
    8.0)
      echo 8.0.11
      ;;
    8.1)
      echo 8.1.0
      ;;
    *)
      echo "Unknown version: $1" >&2
      exit 1
  esac
}
