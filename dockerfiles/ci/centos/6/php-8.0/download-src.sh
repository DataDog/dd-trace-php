set -e

if [ -z "$SRC_DIR" ]; then
    echo "SRC_DIR must be set"
    exit 1
fi
if [ -z "$1" ] || [ -z "$2" ]; then
    echo "Usage: ./download-src.sh <lib_name> <url://to/download.tar.gz>"
    exit 1
fi

name="${1}"
url="${2}"

mkdir -p "${SRC_DIR}/${name}"
curl -fsSL -o "/tmp/${name}.tar.gz" "${url}"
tar xf "/tmp/${name}.tar.gz" -C "${SRC_DIR}/${name}" --strip-components=1
rm -f "/tmp/${name}.tar.gz"
