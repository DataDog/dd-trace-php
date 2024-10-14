#!/usr/bin/env bash

set -e

export NO_INTERACTION=1

export DD_TRACE_ENABLED=true
#export DD_TRACE_DEBUG=true
export DD_TRACE_GENERATE_ROOT_SPAN=true
export DD_TRACE_AGENT_PORT=18126
export PHPRC=

CMAKE_BINARY_DIR="$1"
MOCK_HELPER_BINARY="$2"
TRACER_EXT_FILE="$3"
TEST_PHP_EXECUTABLE="$4"
TEST_PHP_CGI_EXECUTABLE=$(echo "$4" | sed 's@/php\(.\{0,3\}\)$@/php-cgi\1@')
TEST_PHPDBG_EXECUTABLE=$(echo "$4" | sed 's@/php\(.\{0,3\}\)$@/phpdbg\1@')
TEST_TIMEOUT=120
export TEST_PHP_EXECUTABLE TEST_PHP_CGI_EXECUTABLE TEST_PHPDBG_EXECUTABLE \
  MOCK_HELPER_BINARY TRACER_EXT_FILE TEST_TIMEOUT
shift 3

function link_extensions {
  local extensions=(opcache posix pcntl sockets json xml)
  local -r link_ext_dir="${CMAKE_BINARY_DIR}/extensions"
  local -r extension_dir=$("$TEST_PHP_EXECUTABLE" -d display_errors=0 -r "echo ini_get('extension_dir');")
  mkdir -p "$link_ext_dir"
  for ext in "${extensions[@]}"; do
    local ext_orig_fp=$(find "$extension_dir" -maxdepth 1 -name "$ext".'*' || echo '')
    local link_fp="$link_ext_dir/$ext.so"
    if [[ -z $ext_orig_fp ]]; then
      rm -vf "$link_fp"
    fi
    if [[ -L $link_fp && $(readlink $link_fp) != $ext_orig_fp ]]; then
      rm -vf "$link_fp"
    fi
    if [[ ! -L $link_fp && -n $ext_orig_fp ]]; then
      ln -s -v "$ext_orig_fp" "$link_fp"
    fi
  done
  if [[ $TRACER_EXT_FILE != skip ]]; then
    local -r ddtrace="$link_ext_dir"/ddtrace.so
    if [[ -L $ddtrace && $(readlink "$ddtrace") != $TRACER_EXT_FILE ]]; then
      rm -v "$ddtrace"
    fi
    if [[ ! -L $ddtrace ]]; then
      ln -s -v "$TRACER_EXT_FILE" $ddtrace
    fi
  fi
}
EXTRA_FLAGS=()
function set_extra_flags {
  local -r link_ext_dir="${CMAKE_BINARY_DIR}/extensions"
  local -r always_loaded_extensions=(json xml)
  for ext in "${always_loaded_extensions[@]}"; do
    if [[ -L "$link_ext_dir/$ext.so" ]]; then
      EXTRA_FLAGS+=('-d' "extension=$ext.so")
    fi
  done
}
link_extensions
set_extra_flags

if [[ -n $TESTS ]]; then
  set -- "${@:1:$#-1}" "${EXTRA_FLAGS[@]}" $TESTS
else
  set -- "${@:1:$#-1}" "${EXTRA_FLAGS[@]}" "${!#}"
fi

set -x
exec "$@"

