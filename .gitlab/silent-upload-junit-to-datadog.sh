#!/bin/bash
OUTFILE=/tmp/datadog-junit-upload.txt
"$(dirname -- "${BASH_SOURCE[0]}")"/upload-junit-to-datadog.sh "$@" >$OUTFILE 2>&1
if [[ $? -ne 0 ]]; then
  cat $OUTFILE
else
  grep -E '^\* ' $OUTFILE
fi
