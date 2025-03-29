#/usr/bin/env bash
set -eo pipefail

githash="${CI_COMMIT_SHA?}"
if [[ "x$CI_COMMIT_BRANCH" == "x" ]] ; then
  echo "The environment variable CI_COMMIT_BRANCH was not set or was empty."
  exit 1
fi
if [[ "$CI_COMMIT_BRANCH" =~ "ddtrace-" ]] ; then
  echo "Release branch detected; not adding git sha1 to version number."
else
  version=$(cat VERSION)
  # if we have e.g. a beta suffix, just strip it
  if [[ $version == *-* ]]; then
    version=${version%-*}
  else
    # otherwise increment minor version
    parts=($(echo -n "$version" | tr '.' '\n'))
    parts[1]=$((parts[1]+1))
    parts[2]=0
    version=$(export IFS=.; (echo "${parts[*]}"))
  fi
  version="$version+$githash"
  echo -n "$version" > VERSION
  echo "Set version number to $version."
fi
