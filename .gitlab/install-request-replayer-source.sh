#!/bin/sh

set -u

source_dir=$1
target_dir=$2
ready_file=$3

until [ -f "$source_dir/index.php" ] &&
    [ -f "$source_dir/state-lock.php" ] &&
    cp "$source_dir/state-lock.php" "$target_dir/" &&
    cp "$source_dir/index.php" "$target_dir/index.php.new" &&
    mv "$target_dir/index.php.new" "$target_dir/index.php"
do
    rm -f "$target_dir/index.php.new"
    sleep 1
done

touch "$ready_file"
