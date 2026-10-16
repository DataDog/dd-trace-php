# Loaded by generator actions running under the declared static execution shell.

preflight_host_php() {
    php_loader=$1
    php_search_path=$2
    php_binary=$3
    allowed_library_root=$4

    if ! loaded_objects=$("$php_loader" --inhibit-cache --list --library-path "$php_search_path" "$php_binary"); then
        echo "execution PHP loader preflight failed" >&2
        return 1
    fi

    resolved=0
    old_ifs=$IFS
    IFS='
'
    for line in $loaded_objects; do
        case "$line" in
            *" => not found"*)
                echo "execution PHP dependency is missing: $line" >&2
                IFS=$old_ifs
                return 1
                ;;
            *" => "*)
                library_path=${line#* => }
                library_path=${library_path%% *}
                case "$library_path" in
                    "$allowed_library_root"/*) ;;
                    *)
                        echo "execution PHP resolved a library outside its declared closure: $library_path" >&2
                        IFS=$old_ifs
                        return 1
                        ;;
                esac
                case "$library_path" in
                    *[Aa][Ss][Aa][Nn]*)
                        echo "stable execution PHP unexpectedly resolved an ASan runtime: $library_path" >&2
                        IFS=$old_ifs
                        return 1
                        ;;
                esac
                resolved=$((resolved + 1))
                ;;
        esac
    done
    IFS=$old_ifs
    if [ "$resolved" -eq 0 ]; then
        echo "execution PHP loader preflight resolved no declared libraries" >&2
        return 1
    fi
}
