VERSION=${1:-'dev'}
PLATFORM=${2:-'x86_64'}

echo "Load $VERSION binary "

mkdir -p tooling/ci/binaries
cd tooling/ci/binaries

#This script resides inside of docker image (ci_docker_base)
source /download-binary-tracer.sh

if [ $VERSION = 'dev' ]; then
    get_circleci_artifact "gh/DataDog/dd-trace-php" "build_packages" "package extension" "dd-library-php-.*-$PLATFORM-linux-gnu.tar.gz" "dd-library-php-$PLATFORM-linux-gnu.tar.gz"
    get_circleci_artifact "gh/DataDog/dd-trace-php" "build_packages" "package extension" "datadog-setup.php" "datadog-setup.php"
elif [ $VERSION = 'prod' ]; then
    get_github_release_asset "DataDog/dd-trace-php" "dd-library-php-.*-$PLATFORM-linux-gnu.tar.gz" "dd-library-php-$$PLATFORM-linux-gnu.tar.gz"
    get_github_release_asset "DataDog/dd-trace-php" "datadog-setup.php" "datadog-setup.php"
else
    echo "Don't know how to load version $VERSION for $TARGET"
fi
