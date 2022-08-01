#!/usr/bin/env bash

<<EOF
This is only intended to work from the packaged release, not from the source
tree. It expects a directory structure like this:

datadog-profiling
├── bin
│   └── this script
└── lib
    └── php
        ├── 20160303
        │   └── datadog-profiling.so
        ├── 20170718
        │   └── datadog-profiling.so
        ├── 20180731
        │   └── datadog-profiling.so
        └── 20190902
            └── datadog-profiling.so
EOF

basedir="$(dirname $0)"

usage() {
  echo "USAGE: ${0##*/} [php]

OPTIONS:
  php           The name or full path of php. Defaults to \"php\".
"
}

php="${1:-php}"

# not all environments will have `which`, so just try to run php --version
if ! version=$("$php" "--version" 2>/dev/null) ; then
  (echo "ERROR: Unable to detect PHP version through php."
  if [ ! -z "$1" ] ; then
    echo "Provided argument php=\"${1}\"."
    echo ""
  fi
  ) >&2
  exit 1
fi

echo "Detected PHP version:"
echo "$version"

# Should we select "Local Value" or "Master Value"?
extdir=$("$php" -i | awk '/^extension_dir/ { print $3 }')
echo "Detected extension directory: $extdir"

module_api_no=$("$php" -i | awk '/^PHP Extension/ && 4 == NF { print $4 }')
zts=$("$php" -i | awk '/^Thread Safety/ { print ("enabled" == $NF) ? "-zts" : "" ; exit 0}')
debug=$("$php" -i | awk '/^Debug Build/ { print ("yes" == $NF) ? "-debug" : "" ; exit 0}')

build="${module_api_no}${zts}${debug}"
echo "Detected build: $build"

status=0
if [ ! -z "$zts" ] ; then
  echo "Detected a ZTS build of PHP; this is not currently supported." >&2
  status=1
fi

if [ ! -z "$debug" ] ; then
  echo "Detected a debug build of PHP; this is not currently supported." >&2
  status=1
fi

if [ $status -ne 0 ] ; then
  exit $status
fi

target="$basedir/../lib/php/$build/datadog-profiling.so"
if [ ! -f "$target" ] ; then
  echo "ERROR: expected file \"$target\"! Was there a packaging error?" >&2
  exit 1
fi

cp -v "$target" "$extdir"

cat <<EOF

The Datadog profiler has been installed. Add the following line to an INI file:

extension=datadog-profiling.so

EOF
