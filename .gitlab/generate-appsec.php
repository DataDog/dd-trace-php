<?php

include "generate-common.php";

$ecrLoginSnippet = <<<'EOT'
    - |
      if [ "${ARCH}" = "amd64" ]; then
        curl "https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip" -o "awscliv2.zip"
      else
        curl "https://awscli.amazonaws.com/awscli-exe-linux-aarch64.zip" -o "awscliv2.zip"
      fi
      unzip awscliv2.zip > /dev/null
      ./aws/install
      aws --version
    - |
      echo Assuming ddbuild-agent-ci role
      roleoutput="$(aws sts assume-role --role-arn arn:aws:iam::669783387624:role/ddbuild-dd-trace-php-ci \
        --external-id ddbuild-dd-trace-php-ci --role-session-name RoleSession)"

      export AWS_ACCESS_KEY_ID="$(echo "$roleoutput" | jq -r '.Credentials.AccessKeyId')"
      export AWS_SECRET_ACCESS_KEY="$(echo "$roleoutput" | jq -r '.Credentials.SecretAccessKey')"
      export AWS_SESSION_TOKEN="$(echo "$roleoutput" | jq -r '.Credentials.SessionToken')"
      echo "AWS_ACCESS_KEY_ID: $AWS_ACCESS_KEY_ID"

      echo "Logging in to ECR"
      aws ecr get-login-password | docker login --username AWS --password-stdin 669783387624.dkr.ecr.us-east-1.amazonaws.com
EOT;
?>
variables:
  CI_REGISTRY_USER:
    value: ""
    description: "Your docker hub username"
  CI_REGISTRY_TOKEN:
    value: ""
    description: "Your docker hub personal access token, can be created following this doc https://docs.docker.com/docker-hub/access-tokens/#create-an-access-token"
  CI_REGISTRY:
    value: "docker.io"

stages:
  - test
  - docker-build

.appsec_test:
  tags: [ "arch:${ARCH}" ]
  interruptible: true
  rules:
    - if: $CI_COMMIT_BRANCH == "master"
      interruptible: false
    - when: on_success
  before_script:
<?php unset_dd_runner_env_vars() ?>
    - git config --global --add safe.directory "$(pwd)/appsec/third_party/libddwaf"
    - sudo apt install -y clang-tidy-17 libc++-17-dev libc++abi-17-dev
    - mkdir -p appsec/build boost-cache boost-cache
  cache:
    - key: "appsec boost cache"
      paths:
        - boost-cache

.docker_push_job:
  stage: docker-build
  image: 486234852809.dkr.ecr.us-east-1.amazonaws.com/docker:24.0.4-gbi-focal
  before_script:
<?php echo $ecrLoginSnippet, "\n"; ?>
<?php dockerhub_login() ?>
    - apt update && apt install -y openjdk-17-jre

"test appsec extension":
  stage: test
  extends: .appsec_test
  image: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-${PHP_MAJOR_MINOR}_bookworm-6
  variables:
    KUBERNETES_CPU_REQUEST: 3
    KUBERNETES_MEMORY_REQUEST: 4Gi
    KUBERNETES_MEMORY_LIMIT: 4Gi
  parallel:
    matrix:
      - PHP_MAJOR_MINOR: *all_minor_major_targets
        ARCH: *arch_targets
        SWITCH_PHP_VERSION: debug
      - PHP_MAJOR_MINOR: *no_asan_minor_major_targets
        ARCH: *arch_targets
        SWITCH_PHP_VERSION: debug-zts
      - PHP_MAJOR_MINOR: *asan_minor_major_targets
        ARCH: *arch_targets
        SWITCH_PHP_VERSION: debug-zts-asan
  script:
    - switch-php $SWITCH_PHP_VERSION
    - cd appsec/build
    - "cmake .. -DCMAKE_BUILD_TYPE=Debug -DDD_APPSEC_BUILD_HELPER=OFF
      -DCMAKE_CXX_FLAGS='-stdlib=libc++' -DCMAKE_CXX_LINK_FLAGS='-stdlib=libc++'
      -DDD_APPSEC_TESTING=ON -DBOOST_CACHE_PREFIX=$CI_PROJECT_DIR/boost-cache"
    - make -j 4 xtest

"appsec integration tests":
  stage: test
  image: 486234852809.dkr.ecr.us-east-1.amazonaws.com/docker:24.0.4-gbi-focal # TODO: use a proper docker image with java pre-installed?
  tags: [ "docker-in-docker:amd64" ]
  variables:
    KUBERNETES_CPU_REQUEST: 8
    KUBERNETES_MEMORY_REQUEST: 24Gi
    KUBERNETES_MEMORY_LIMIT: 30Gi
    ARCH: amd64
  parallel:
    matrix:
      - targets:
          - test7.0-release
          - test7.0-release-zts
          - test7.1-release
          - test7.1-release-zts
          - test7.2-release
          - test7.2-release-zts
          - test7.3-release
          - test7.3-release-zts
          - test7.4-release
          - test7.4-release-zts
          - test8.0-release
          - test8.0-release-zts
          - test8.1-release
          - test8.1-release-zts
          - test8.2-release
          - test8.2-release-zts
          - test8.3-release
          - test8.3-release-zts
          - test8.4-release
          - test8.4-release-zts
          - test8.5-release
          - test8.5-release-zts
  before_script:
<?php echo $ecrLoginSnippet, "\n"; ?>
<?php dockerhub_login() ?>
  script:
    - apt update && apt install -y openjdk-17-jre
    - find "$CI_PROJECT_DIR"/appsec/tests/integration/build || true
    - |
      cd appsec/tests/integration
      CACHE_PATH=build/php-appsec-volume-caches-${ARCH}.tar.gz
      if [ -f "$CACHE_PATH" ]; then
        echo "Loading cache from $CACHE_PATH"
        TERM=dumb ./gradlew loadCaches --info
      fi

      TERM=dumb ./gradlew $targets --info -Pbuildscan --scan
      TERM=dumb ./gradlew saveCaches --info
  after_script:
    - mkdir -p "${CI_PROJECT_DIR}/artifacts"
    - find appsec/tests/integration/build/test-results -name "*.xml" -exec cp --parents '{}' "${CI_PROJECT_DIR}/artifacts/" \;
    - cp -r appsec/tests/integration/build/test-logs "${CI_PROJECT_DIR}/artifacts/" 2>/dev/null || true
    - .gitlab/silent-upload-junit-to-datadog.sh "test.source.file:appsec"
  artifacts:
    reports:
      junit: "artifacts/**/test-results/**/TEST-*.xml"
    paths:
      - "artifacts/"
    when: "always"
  cache:
    - key: "appsec int test cache"
      paths:
        - appsec/tests/integration/build/*.tar.gz

"appsec code coverage":
  stage: test
  extends: .appsec_test
  image: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-8.3_bookworm-6
  variables:
    KUBERNETES_CPU_REQUEST: 3
    KUBERNETES_MEMORY_REQUEST: 3Gi
    KUBERNETES_MEMORY_LIMIT: 4Gi
    ARCH: amd64
  script:
    - |
      echo "Installing dependencies"
      cd /tmp
      curl -o vault.zip https://releases.hashicorp.com/vault/1.20.0/vault_1.20.0_linux_amd64.zip
      unzip vault.zip
      sudo cp -v vault /usr/local/bin
      cd -
      sudo sed -i 's|http://deb.debian.org/debian|http://archive.debian.org/debian|g; s|http://security.debian.org/debian-security|http://archive.debian.org/debian-security|g' /etc/apt/sources.list
      sudo apt-get update && sudo apt-get install -y jq gcovr llvm-17 clang-17

      echo "Installing codecov"

      CODECOV_TOKEN=$(vault kv get --format=json kv/k8s/gitlab-runner/dd-trace-php/codecov | jq -r .data.data.token)
      CODECOV_VERSION=0.6.1
      CODECOV_ARCH=linux
      curl https://keybase.io/codecovsecurity/pgp_keys.asc | gpg --no-default-keyring --keyring trustedkeys.gpg --import
      curl -Os https://uploader.codecov.io/v${CODECOV_VERSION}/${CODECOV_ARCH}/codecov
      curl -Os https://uploader.codecov.io/v${CODECOV_VERSION}/${CODECOV_ARCH}/codecov.SHA256SUM
      curl -Os https://uploader.codecov.io/v${CODECOV_VERSION}/${CODECOV_ARCH}/codecov.SHA256SUM.sig
      gpgv codecov.SHA256SUM.sig codecov.SHA256SUM
      shasum -a 256 -c codecov.SHA256SUM
      rm codecov.SHA256SUM.sig codecov.SHA256SUM
      sudo mv codecov /usr/local/bin/codecov
      sudo chmod +x /usr/local/bin/codecov
    - cd appsec/build
    - |
      cmake .. -DCMAKE_BUILD_TYPE=Debug -DDD_APPSEC_ENABLE_COVERAGE=ON \
        -DDD_APPSEC_TESTING=ON -DCMAKE_CXX_FLAGS="-stdlib=libc++" \
        -DCMAKE_C_COMPILER=/usr/bin/clang-17 -DCMAKE_CXX_COMPILER=/usr/bin/clang++-17 \
        -DCMAKE_CXX_LINK_FLAGS="-stdlib=libc++" \
        -DBOOST_CACHE_PREFIX="$CI_PROJECT_DIR/boost-cache"
    - |
      export PATH=$PATH:$HOME/.cargo/bin
      LLVM_PROFILE_FILE="/tmp/cov-ext/%p.profraw" \
        VERBOSE=1 make -j 4 xtest
    - VERBOSE=1 make -j 4 ddappsec_helper_test
    - |
      cd ../..
      LLVM_PROFILE_FILE="/tmp/cov-helper/%p.profraw" \
        ./appsec/build/tests/helper/ddappsec_helper_test
    - |
      cd /tmp/cov-ext
      llvm-profdata-17 merge -sparse *.profraw -o default.profdata
      llvm-cov-17 export "$CI_PROJECT_DIR"/appsec/build/ddappsec.so \
        -format=lcov -instr-profile=default.profdata \
        > "$CI_PROJECT_DIR"/appsec/build/coverage-ext.lcov
      echo "Uploading extension coverage to codecov"
      cd "$CI_PROJECT_DIR"
      codecov -t "$CODECOV_TOKEN" -n appsec-extension -v -f appsec/build/coverage-ext.lcov
    - |
      cd /tmp/cov-helper
      llvm-profdata-17 merge -sparse *.profraw -o default.profdata
      llvm-cov-17 export "$CI_PROJECT_DIR"/appsec/build/tests/helper/ddappsec_helper_test \
        -format=lcov -instr-profile=default.profdata \
        > "$CI_PROJECT_DIR/appsec/build/coverage-helper.lcov"
      echo "Uploading helper coverage to codecov"
      cd "$CI_PROJECT_DIR"
      codecov -t "$CODECOV_TOKEN" -n appsec-helper -v -f appsec/build/coverage-helper.lcov


"push appsec images":
  extends: .docker_push_job
  tags: [ "docker-in-docker:${ARCH}" ]
  variables:
    KUBERNETES_CPU_REQUEST: 8
    KUBERNETES_MEMORY_REQUEST: 16Gi
    KUBERNETES_MEMORY_LIMIT: 24Gi
  parallel:
    matrix:
# XXX: docker-in-docker:arm64 is not supported yet
      - ARCH: ["amd64", "arm64"]
  rules:
    - when: manual
      allow_failure: true
  needs: []
  script:
    - cd appsec/tests/integration
    - TERM=dumb ./gradlew pushAll --info -Pbuildscan --scan

"push appsec docker images multiarch":
  extends: .docker_push_job
  variables:
    KUBERNETES_CPU_REQUEST: 2
    KUBERNETES_MEMORY_REQUEST: 4Gi
    KUBERNETES_MEMORY_LIMIT: 6Gi
    ARCH: amd64
  rules:
    - when: on_success
  needs:
    - job: "push appsec images"
  script:
    - cd appsec/tests/integration
    - TERM=dumb ./gradlew pushMultiArch --info -Pbuildscan --scan

"appsec lint":
  stage: test
  extends: .appsec_test
  image: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-8.3_bookworm-6
  variables:
    KUBERNETES_CPU_REQUEST: 3
    KUBERNETES_MEMORY_REQUEST: 9Gi
    KUBERNETES_MEMORY_LIMIT: 10Gi
    ARCH: amd64
  script:
    - sudo apt install -y clang-format-17
    - cd appsec/build
    - |
      cmake .. -DCMAKE_BUILD_TYPE=Debug -DDD_APPSEC_ENABLE_COVERAGE=OFF \
        -DDD_APPSEC_TESTING=OFF -DCMAKE_CXX_FLAGS="-stdlib=libc++" \
        -DCMAKE_CXX_LINK_FLAGS="-stdlib=libc++" \
        -DBOOST_CACHE_PREFIX="$CI_PROJECT_DIR/boost-cache" \
        -DCLANG_TIDY=/usr/bin/run-clang-tidy-17 \
        -DCLANG_FORMAT=/usr/bin/clang-format-17
    - make -j 4 extension ddappsec-helper
    - make format tidy

"test appsec helper asan":
  stage: test
  extends: .appsec_test
  image: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:bookworm-6
  variables:
    KUBERNETES_CPU_REQUEST: 3
    KUBERNETES_MEMORY_REQUEST: 3Gi
    KUBERNETES_MEMORY_LIMIT: 4Gi
  parallel:
    matrix:
      - ARCH: *arch_targets
  script:
    - cd appsec/build
    - |
      cmake .. -DCMAKE_BUILD_TYPE=Debug -DDD_APPSEC_BUILD_EXTENSION=OFF \
        -DDD_APPSEC_ENABLE_COVERAGE=OFF -DDD_APPSEC_TESTING=ON \
        -DCMAKE_CXX_FLAGS="-stdlib=libc++ -fsanitize=address -fsanitize=leak \
        -DASAN_BUILD" -DCMAKE_C_FLAGS="-fsanitize=address -fsanitize=leak \
        -DASAN_BUILD" -DCMAKE_EXE_LINKER_FLAGS="-fsanitize=address -fsanitize=leak" \
        -DCMAKE_MODULE_LINKER_FLAGS="-fsanitize=address -fsanitize=leak" \
        -DBOOST_CACHE_PREFIX="$CI_PROJECT_DIR/boost-cache" \
        -DCLANG_TIDY=/usr/bin/run-clang-tidy-17
    - make -j 4 ddappsec_helper_test
    - cd ../..; ./appsec/build/tests/helper/ddappsec_helper_test

### Disabled: "we don't rely on the fuzzer these days as the protocol has been stable for a long time, so feel free to disable those jobs for now"
#"fuzz appsec helper":
#  stage: test
#  extends: .appsec_test
#  image: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:bookworm-6
#  variables:
#    KUBERNETES_CPU_REQUEST: 3
#    KUBERNETES_MEMORY_REQUEST: 5Gi
#    KUBERNETES_MEMORY_LIMIT: 6Gi
#  parallel:
#    matrix:
#      - ARCH: *arch_targets
#  script:
#    - curl -LO https://github.com/llvm/llvm-project/archive/refs/tags/llvmorg-17.0.6.tar.gz
#    - tar xzf llvmorg-17.0.6.tar.gz
#    - cd llvm-project-llvmorg-17.0.6/compiler-rt
#    - cmake . -DCMAKE_CXX_FLAGS="-stdlib=libc++" -DCMAKE_CXX_LINK_FLAGS="-stdlib=libc++"
#    - make -j 4 fuzzer
#    - fuzzer=$(realpath lib/linux/libclang_rt.fuzzer_no_main-*.a)
#    - cd -
#
#    - cd appsec/build
#    - cmake .. -DCMAKE_BUILD_TYPE=Debug -DDD_APPSEC_BUILD_EXTENSION=OFF -DCMAKE_CXX_FLAGS="-stdlib=libc++" -DCMAKE_CXX_LINK_FLAGS="-stdlib=libc++" -DFUZZER_ARCHIVE_PATH=$fuzzer -DBOOST_CACHE_PREFIX=/boost-cache -DCLANG_TIDY=/usr/bin/run-clang-tidy-17
#    - make -j 4 ddappsec_helper_fuzzer corpus_generator
#    - cd ..
#    - mkdir -p tests/fuzzer/{corpus,results,logs}
#    - rm -f tests/fuzzer/corpus/*
#
#    - '# Run fuzzer in nop mode'
#    - ./build/tests/fuzzer/corpus_generator tests/fuzzer/corpus 500
#    - LLVM_PROFILE_FILE=off.profraw ./build/tests/fuzzer/ddappsec_helper_fuzzer --log_level=off --fuzz-mode=off -max_total_time=60 -rss_limit_mb=4096 -artifact_prefix=tests/fuzzer/results/ tests/fuzzer/corpus/
#    - rm -f tests/fuzzer/corpus/*
#
#    - '# Run fuzzer in raw mode'
#    - ./build/tests/fuzzer/corpus_generator tests/fuzzer/corpus 500
#    - LLVM_PROFILE_FILE=raw.profraw ./build/tests/fuzzer/ddappsec_helper_fuzzer --log_level=off --fuzz-mode=raw -max_total_time=60 -rss_limit_mb=4096 -artifact_prefix=tests/fuzzer/results/ tests/fuzzer/corpus/
#    - rm -f tests/fuzzer/corpus/*
#
#    - '# Run fuzzer in body mode'
#    - ./build/tests/fuzzer/corpus_generator tests/fuzzer/corpus 500
#    - LLVM_PROFILE_FILE=body.profraw ./build/tests/fuzzer/ddappsec_helper_fuzzer --log_level=off --fuzz-mode=body -max_total_time=60 -rss_limit_mb=4096 -artifact_prefix=tests/fuzzer/results/ tests/fuzzer/corpus/
#
#    - '# Generate coverage'
#    - llvm-profdata-17 merge -sparse *.profraw -o default.profdata
#    - llvm-cov-17 show build/tests/fuzzer/ddappsec_helper_fuzzer -instr-profile=default.profdata -ignore-filename-regex="(tests|third_party|build)" -format=html > fuzzer-coverage.html
#    - llvm-cov-17 report -instr-profile default.profdata build/tests/fuzzer/ddappsec_helper_fuzzer -ignore-filename-regex="(tests|third_party|build)" -show-region-summary=false
#  artifacts:
#    paths:
#     - appsec/fuzzer-coverage.html

"check libxml2 version":
  stage: test
  image: registry.ddbuild.io/images/mirror/python:3.12-slim-bullseye
  tags: [ "arch:amd64" ]
  needs: []
  allow_failure: true
  variables:
    GIT_SUBMODULE_STRATEGY: none
  script:
    - |
      python3 - <<'EOF'
      import urllib.request
      import json
      import re
      import sys

      # Read local version
      with open("appsec/third_party/libxml2/VERSION") as f:
          local_version = f.read().strip()
      print(f"Local libxml2 version: {local_version}")

      # Fetch latest version from GNOME GitLab
      url = "https://gitlab.gnome.org/api/v4/projects/GNOME%2Flibxml2/repository/tags?per_page=100&order_by=updated&sort=desc"
      with urllib.request.urlopen(url) as response:
          tags = json.load(response)

      # Extract version numbers and find the latest
      versions = []
      for tag in tags:
          match = re.match(r"v(\d+\.\d+\.\d+)$", tag["name"])
          if match:
              versions.append(match.group(1))

      # Sort by version number
      versions.sort(key=lambda v: tuple(map(int, v.split("."))))
      latest_version = versions[-1] if versions else None

      print(f"Latest libxml2 version: {latest_version}")

      if local_version != latest_version:
          print("ERROR: libxml2 version mismatch!")
          print(f"Local version:  {local_version}")
          print(f"Latest version: {latest_version}")
          print("Please update appsec/third_party/libxml2 to the latest version.")
          sys.exit(1)

      print("libxml2 version is up to date.")
      EOF
