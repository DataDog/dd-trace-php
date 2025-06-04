<?php

include "generate-common.php";

?>
stages:
  - test


"test appsec extension":
  stage: test
  tags: [ "arch:${ARCH}" ]
  image: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-${PHP_MAJOR_MINOR}_buster
  variables:
    KUBERNETES_CPU_REQUEST: 3
    KUBERNETES_MEMORY_REQUEST: 3Gi
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
    - apt install clang-tidy
    # TODO: caching?
    - switch-php $SWITCH_PHP_VERSION
    - |
      mkdir -p appsec/build ; cd appsec/build
      cmake .. -DCMAKE_BUILD_TYPE=Debug -DDD_APPSEC_TESTING=ON -DHUNTER_ROOT=~/datadog/hunter-cache
      find ~/datadog/hunter-cache -name "*.a"  -printf "%f\n" | sort -u | sha256sum | awk '{print "Dependencies-ID: "$1}' >> ../hunter-cache.id
    - cmake .. -DCMAKE_BUILD_TYPE=Debug -DDD_APPSEC_BUILD_HELPER=OFF -DDD_APPSEC_TESTING=ON -DHUNTER_ROOT=~/datadog/hunter-cache
    - make -j 4 xtest

"appsec integration tests":
  stage: test
  image: 486234852809.dkr.ecr.us-east-1.amazonaws.com/docker:24.0.4-gbi-focal # TODO: use a proper docker image with make, php and git pre-installed?
  tags: [ "docker-in-docker:amd64" ]
  variables:
    KUBERNETES_CPU_REQUEST: 8
    KUBERNETES_MEMORY_REQUEST: 24Gi
    KUBERNETES_MEMORY_LIMIT: 30Gi
  parallel:
    matrix:
      - targets:
          - test7.0-release test7.0-release-zts test7.1-release test7.1-release-zts test7.2-release test7.2-release-zts
          - test7.3-release test7.3-release-zts test7.4-release test7.4-release-zts test8.0-release test8.0-release-zts
          - test8.1-release test8.1-release-zts test8.2-release test8.2-release-zts test8.3-release test8.3-release-zts test8.4-release test8.4-release-zts
  script:
    - apt install java
    - cd appsec/tests/integration && TERM=dumb ./gradlew loadCaches $targets --info -Pbuildscan --scan

"appsec code coverage":
  stage: test
  tags: [ "arch:${ARCH}" ]
  image: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-8.3_buster
  variables:
    KUBERNETES_CPU_REQUEST: 3
    KUBERNETES_MEMORY_REQUEST: 3Gi
    KUBERNETES_MEMORY_LIMIT: 4Gi
  parallel:
    matrix:
      - ARCH: *arch_targets
  script:
    - sudo apt install gcovr
    # TODO: caching?
    - mkdir -p appsec/build; cd appsec/build
    - cmake .. -DCMAKE_BUILD_TYPE=Debug -DDD_APPSEC_ENABLE_COVERAGE=ON -DDD_APPSEC_TESTING=ON -DHUNTER_ROOT=/home/circleci/datadog/hunter-cache
    - PATH=$PATH:$HOME/.cargo/bin make -j $(nproc) xtest ddappsec_helper_test
    - ./appsec/build/tests/helper/ddappsec_helper_test
    - cd appsec
    - mkdir coverage
    - gcovr -f '.*src/extension/.*' -x -o coverage.xml
    - gcovr --gcov-ignore-parse-errors --html-details coverage/coverage.html -f ".*src/.*" -d
    - tar -cvzf appsec-extension-coverage.tar.gz coverage/
    # TODO: umm, how to do codecov uploading on gitlab?
  artifacts:
    paths:
      - appsec/appsec-coverage.tar.gz

"appsec lint":
  stage: test
  tags: [ "arch:${ARCH}" ]
  image: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:buster
  variables:
    KUBERNETES_CPU_REQUEST: 3
    KUBERNETES_MEMORY_REQUEST: 3Gi
    KUBERNETES_MEMORY_LIMIT: 4Gi
  parallel:
    matrix:
      - ARCH: *arch_targets
  script:
   - sudo apt install clang-tidy-17 clang-format-17
   - mkdir -p appsec/build ; cd appsec/build
   - cmake .. -DCMAKE_BUILD_TYPE=Debug -DDD_APPSEC_ENABLE_COVERAGE=OFF -DDD_APPSEC_TESTING=OFF -DHUNTER_ROOT=/home/circleci/datadog/hunter-cache -DCLANG_TIDY=/usr/bin/run-clang-tidy-17 -DCLANG_FORMAT=/usr/bin/clang-format-17
   - make -j $(nproc) extension ddappsec-helper
   - make format tidy

"test appsec helper asan":
  stage: test
  tags: [ "arch:${ARCH}" ]
  image: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:buster
  variables:
    KUBERNETES_CPU_REQUEST: 3
    KUBERNETES_MEMORY_REQUEST: 3Gi
    KUBERNETES_MEMORY_LIMIT: 4Gi
  parallel:
    matrix:
      - ARCH: *arch_targets
  script:
   - mkdir -p appsec/build ; cd appsec/build
   - cmake .. -DCMAKE_BUILD_TYPE=Debug -DDD_APPSEC_BUILD_EXTENSION=OFF -DDD_APPSEC_ENABLE_COVERAGE=OFF -DDD_APPSEC_TESTING=ON -DCMAKE_CXX_FLAGS="-fsanitize=address -fsanitize=leak -DASAN_BUILD" -DCMAKE_C_FLAGS="-fsanitize=address -fsanitize=leak -DASAN_BUILD" -DCMAKE_EXE_LINKER_FLAGS="-fsanitize=address -fsanitize=leak" -DCMAKE_MODULE_LINKER_FLAGS="-fsanitize=address -fsanitize=leak" -DHUNTER_ROOT=/home/circleci/datadog/hunter-cache
   - make -j $(nproc) ddappsec_helper_test
   - ./appsec/build/tests/helper/ddappsec_helper_test

"fuzz appsec helper":
  stage: test
  tags: [ "arch:${ARCH}" ]
  image: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:buster
  variables:
    KUBERNETES_CPU_REQUEST: 3
    KUBERNETES_MEMORY_REQUEST: 3Gi
    KUBERNETES_MEMORY_LIMIT: 4Gi
    CC: /usr/bin/clang-17
    CXX: /usr/bin/clang++-17
  parallel:
    matrix:
      - ARCH: *arch_targets
  script:
   - mkdir -p appsec/build ; cd appsec/build
   - cmake .. -DCMAKE_BUILD_TYPE=Debug -DDD_APPSEC_BUILD_EXTENSION=OFF -DHUNTER_ROOT=/home/circleci/datadog/hunter-cache
   - make -C -j $(nproc) ddappsec_helper_fuzzer corpus_generator
   - cd ..
   - mkdir -p tests/fuzzer/{corpus,results,logs}
   - rm -f tests/fuzzer/corpus/*

   - '# Run fuzzer in nop mode'
   - ./build/tests/fuzzer/corpus_generator tests/fuzzer/corpus 500
   - LLVM_PROFILE_FILE=off.profraw ./build/tests/fuzzer/ddappsec_helper_fuzzer --log_level=off --fuzz-mode=off -max_total_time=60 -rss_limit_mb=4096 -artifact_prefix=tests/fuzzer/results/ tests/fuzzer/corpus/
   - rm -f tests/fuzzer/corpus/*

   - '# Run fuzzer in raw mode'
   - ./build/tests/fuzzer/corpus_generator tests/fuzzer/corpus 500
   - LLVM_PROFILE_FILE=raw.profraw ./build/tests/fuzzer/ddappsec_helper_fuzzer --log_level=off --fuzz-mode=raw -max_total_time=60 -rss_limit_mb=4096 -artifact_prefix=tests/fuzzer/results/ tests/fuzzer/corpus/
   - rm -f tests/fuzzer/corpus/*

   - '# Run fuzzer in body mode'
   - ./build/tests/fuzzer/corpus_generator tests/fuzzer/corpus 500
   - LLVM_PROFILE_FILE=body.profraw ./build/tests/fuzzer/ddappsec_helper_fuzzer --log_level=off --fuzz-mode=body -max_total_time=60 -rss_limit_mb=4096 -artifact_prefix=tests/fuzzer/results/ tests/fuzzer/corpus/

   - '# Generate coverage'
   - llvm-profdata-17 merge -sparse *.profraw -o default.profdata
   - llvm-cov-17 show build/tests/fuzzer/ddappsec_helper_fuzzer -instr-profile=default.profdata -ignore-filename-regex="(tests|third_party|build)" -format=html > fuzzer-coverage.html
   - llvm-cov-17 report -instr-profile default.profdata build/tests/fuzzer/ddappsec_helper_fuzzer -ignore-filename-regex="(tests|third_party|build)" -show-region-summary=false
  artifacts:
    paths:
     - appsec/fuzzer-coverage.html
