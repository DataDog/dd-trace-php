set -eu

objcopy=$1
strip=$2
source_binary=$3
binary=$4
debug=$5

"$objcopy" --only-keep-debug "$source_binary" "$debug"
"$strip" --strip-unneeded -o "$binary" "$source_binary"
"$objcopy" --add-gnu-debuglink="$debug" "$binary"
