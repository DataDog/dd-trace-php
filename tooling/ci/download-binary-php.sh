VERSION=${1:-'dev'}

echo "Load $VERSION binary "

mkdir -p tooling/ci/binaries
cd tooling/ci/binaries

#This script resides inside of docker image (ci_docker_base)
source /download-binary-tracer.sh

if [ $VERSION = 'dev' ]; then
    get_circleci_artifact "gh/DataDog/dd-trace-php" "build_packages" "package extension" "datadog-php-tracer-.*-nightly.x86_64.tar.gz" "datadog-php-tracer.x86_64.tar.gz"
    echo "get appsec from github action"
    get_github_action_artifact "DataDog/dd-appsec-php" "package.yml" "master" "dd-appsec-php-*-amd64.tar.gz" "dd-appsec-php-amd64.tar.gz"
elif [ $VERSION = 'prod' ]; then
    get_github_release_asset "DataDog/dd-trace-php" "datadog-php-tracer-.*.x86_64.tar.gz" "datadog-php-tracer.x86_64.tar.gz"
    get_github_release_asset "DataDog/dd-appsec-php" "dd-appsec-php-.*-amd64.tar.gz" "dd-appsec-php-amd64.tar.gz"
else
    echo "Don't know how to load version $VERSION for $TARGET"
fi
