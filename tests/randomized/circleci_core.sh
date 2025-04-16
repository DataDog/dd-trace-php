#!/bin/bash

# e.g. https://app.circleci.com/pipelines/github/DataDog/dd-trace-php/17113/workflows/196b5740-f3ce-4faf-8731-e9a4b15d114a/jobs/4964970/steps
URL=${1}

if ! [[ $URL =~ workflows/([0-9a-f-]+)/jobs/([0-9]+) ]]; then
  echo "Invalid URL?"
  exit 1
fi

CIRCLE_WORKFLOW_ID=${BASH_REMATCH[1]}
CIRCLE_JOB_ID=${BASH_REMATCH[2]}

TEST_NAME=$(curl -s -X GET "https://circleci.com/api/v2/project/gh/DataDog/dd-trace-php/job/$CIRCLE_JOB_ID" -H "Accept: application/json" | grep -Eo '"name":"randomized[^"]+')
TEST_NAME=${TEST_NAME:8}

echo "Found test run: $TEST_NAME"

COREFILES=($(curl -s -X GET "https://circleci.com/api/v2/project/gh/DataDog/dd-trace-php/$CIRCLE_JOB_ID/artifacts" -H "Accept: application/json" | grep -Eo 'https://[^"]+core'))

if [[ -z $COREFILES ]]; then
  echo "Found no core files..."
  exit 1
fi

num=1
for file in "${COREFILES[@]}"; do
  echo "$((num++)): $(echo "$file" | grep -Eo 'randomized-[^/]+')"
done

while : ; do
  echo -n "Select one core file: "
  read num
  if [[ $num -gt ${#COREFILES[@]} || $num -le 0 ]]; then
    echo "Invalid number $num"
  else
    break
  fi
done

COREFILE=${COREFILES[$((num - 1))]}
corefilename=$(echo "$COREFILE" | grep -Eo 'randomized-[^/]+')
container="datadog/dd-trace-ci:php-randomizedtests-$(if [[ $COREFILE == *buster* ]]; then echo buster; else echo centos7; fi)-${corefilename: -2:1}.${corefilename: -1}-2"

if [[ $TEST_NAME == *asan* ]]; then
  ARTIFACTS_JOB='package extension zts-debug-asan'
else
  ARTIFACTS_JOB='package extension'
fi

PACKAGE_ARCH=$(if [[ $TEST_NAME == *arm* ]]; then echo aarch64; else echo x86_64; fi)

parent_job_id=$(curl -s -X GET "https://circleci.com/api/v2/workflow/$CIRCLE_WORKFLOW_ID/job" -H "Accept: application/json" | grep -Eo '\{[^}]*"'"$ARTIFACTS_JOB"'"[^}]*' | grep -Eo '"job_number":[^,]+' | tail -c +14)
ARTIFACTS_RESULT=$(curl -s -X GET "https://circleci.com/api/v2/project/github/DataDog/dd-trace-php/$parent_job_id/artifacts" -H "Accept: application/json")
artifact_url=$(echo "$ARTIFACTS_RESULT" | grep -Eo '\{[^}]*"dd-library-php-[^"]+'"${PACKAGE_ARCH}"'-linux-gnu.tar.gz"[^}]*' | grep -Eo '"url":"[^"]+' | tail -c +8)
setup_url=$(echo "$ARTIFACTS_RESULT" | grep -Eo '\{[^}]*"datadog-setup.php"[^}]*' | grep -Eo '"url":"[^"]+' | tail -c +8)

set -x
docker run --platform=linux/$(if [[ $TEST_NAME == *arm* ]]; then echo arm64; else echo amd64; fi) --rm -ti "$container" bash -c "curl -Lo /tmp/datadog-setup.php '$setup_url'; curl -Lo /tmp/${artifact_url##*/} '$artifact_url'; curl -Lo /tmp/core '$COREFILE'; php /tmp/datadog-setup.php --php-bin all --file /tmp/${artifact_url##*/} --enable-profiling; $(if [[ $TEST_NAME == *asan* ]]; then echo "switch-php debug-zts-asan; "; fi)exec bash --rcfile <(echo 'gdb \$(file /tmp/core | grep -Po "'from\\s\\x27\\K[^\\x27:]+'") --core=/tmp/core -ix /usr/local/src/php/.gdbinit')"
