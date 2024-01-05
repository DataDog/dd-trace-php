#!/usr/bin/env bash

set -e

# Help command
if [ "$1" = "--help" ] || [ "$1" = "-h" ]; then
  echo "Usage: $0 [options]"
  echo "Options:"
  echo "  -s, --scenario <scenario>  The scenario to run (e.g., benchTelemetryParsing, LaravelBench). Defaults to all scenarios (.)"
  echo "  -t, --style <style>        The style of benchmark to run (base, opcache). Defaults to base"
  echo "  -n, --n <n>                The number of times to run the benchmark. Defaults to 1"
  echo "  -w, --without-dependencies If set, the dependencies will not be installed."
  echo "  --split <true|false>       Whether to split the results into multiple profiles. Defaults to true. Only applies when all scenarios are run at once."

  echo "Example: ./run_with_native_profiler.sh --scenario benchTelemetryParsing --style base -n 5 -w"
  echo "Example: ./run_with_native_profiler.sh  --style opcache -n 5 -w --split false"
  exit 0
fi

# Set defaults
SCENARIO="."
STYLE="base"
N=1
WITHOUT_DEPENDENCIES=false
SPLIT=true

# Retrieve the arguments
while [[ $# -gt 0 ]]; do
  key="$1"

  case $key in
    -s|--scenario)
      SCENARIO="$2"
      shift # past argument
      shift # past value
      ;;
    -t|--style)
      STYLE="$2"
      shift # past argument
      shift # past value
      ;;
    -n|--n)
      N="$2"
      shift # past argument
      shift # past value
      ;;
    -w|--without-dependencies)
      WITHOUT_DEPENDENCIES=true
      shift # past argument
      ;;
    --split)
      SPLIT="$2"
      shift # past argument
      shift # past value
      ;;
    *)    # unknown option
      echo "Unknown option: $key"
      exit 1
      ;;
  esac
done

if [ "$SCENARIO" = "." ]; then
  echo -e "\e[43mWARNING: Running all scenarios at once is not recommended.\e[0m"
  echo -e "\e[43mIt is recommended to run the scenarios individually.\e[0m"
fi


# Check that ddprof is installed, install if not
if ! command -v ddprof; then
  echo -e "\e[43mWARNING: ddprof not installed, installing..."

  export ARCH=$(dpkg --print-architecture) # ARCH should hold amd64 or arm64
  # ddprof requires xz-utils to uncompress the archive
  sudo apt-get update && \
  sudo DEBIAN_FRONTEND=noninteractive apt-get install -y xz-utils curl jq && \
  tag_name=$(curl -s https://api.github.com/repos/DataDog/ddprof/releases/latest | jq -r '.tag_name[1:]') && \
  url_release="https://github.com/DataDog/ddprof/releases/download/v${tag_name}/ddprof-${tag_name}-${ARCH}-linux.tar.xz" && \
  curl -L -o ddprof-${ARCH}-linux.tar.xz ${url_release} && \
  tar xvf ddprof-${ARCH}-linux.tar.xz && \
  sudo mv ddprof/bin/ddprof /usr/local/bin && \
  rm -Rf ddprof-amd64-linux.tar.xz ./ddprof && \
  ddprof --version

  echo -e "\e[42mSUCCESS: ddprof installed!\e[0m"
else
  echo -e "\e[42mSUCCESS: ddprof already installed!\e[0m"
fi

# Set kernel.perf_event_paranoid to 2, fail if not possible
if ! sudo sysctl -w kernel.perf_event_paranoid=2; then
  echo -e "\e[41mERROR: Failed to set kernel.perf_event_paranoid to 2. This is required by ddprof.\e[0m"
  echo -e "\e[41mPlease check if the container is running with --privileged.\e[0m"
  exit 1
fi

if [ -z "$DD_TRACE_AGENT_URL" ]; then
  echo -e "\e[43mWARNING: DD_TRACE_AGENT_URL is not set!\e[0m"
  echo -e "\e[43mExporting DD_TRACE_AGENT_URL to unix:///var/run/datadog/apm.socket\e[0m"
  export DD_TRACE_AGENT_URL=unix:///var/run/datadog/apm.socket
else
  echo "DD_TRACE_AGENT_URL is set to $DD_TRACE_AGENT_URL"
fi

echo -e "\e[44mSCENARIO: $SCENARIO\e[0m"
echo -e "\e[44mSTYLE: $STYLE\e[0m"
echo -e "\e[44mN: $N\e[0m"
echo -e "\e[44mWITHOUT_DEPENDENCIES: $WITHOUT_DEPENDENCIES\e[0m"

TIMESTAMP=$(date +%s)

# Run the benchmarks
cd ~/app

if [ "$WITHOUT_DEPENDENCIES" = false ]; then
  make composer_tests_update
  make benchmarks_run_dependencies
fi

function call_benchmarks {
  SUBJECT="$1"
  STYLE="$2"
  TIMESTAMP="$3"
  N="$4"

  # If the scenario is "opcache", run the opcache benchmarks
  MAKE_COMMAND="call_benchmarks"
  if [ "$STYLE" = "opcache" ]; then
    MAKE_COMMAND="call_benchmarks_opcache"
  fi

  IDENTIFIER=$(echo "$SUBJECT-$STYLE-$TIMESTAMP" | tr '[:upper:]' '[:lower:]')

  echo -e "\e[44mRunning benchmark for $SUBJECT\e[0m"
  for i in $(seq 1 $N); do
    echo -e "\e[44m[$SUBJECT] Running benchmark $i of $N\e[0m"
    DDPROF_IDENTIFIER="$IDENTIFIER" make $MAKE_COMMAND FILTER="$SUBJECT"
  done

  echo -e "\e[92m[$SUBJECT] All $N benchmarks completed!\e[0m"
  echo -e "\e[92m[$SUBJECT] Check the results in the Datadog app with the following identifier: $IDENTIFIER\e[0m"
  echo -e "\e[92m[$SUBJECT] If you're not receiving any profiles, verify the value of DD_TRACE_AGENT_URL, DD_SITE, and DD_API_KEY.\e[0m"

  EU_URL="https://app.datadoghq.eu/profiling/search?query=service%3A$IDENTIFIER"
  COM_URL="https://app.datadoghq.com/profiling/search?query=service%3A$IDENTIFIER"
  echo -e '\e[92m[$SUBJECT] Click here to view the results in the Datadog app:\e[0m'
  echo -e '\e[92m[$SUBJECT] [EU] \e]8;;'$EU_URL'\a'$EU_URL'\e]8;;\a\e[0m'
  echo -e '\e[92m[$SUBJECT] [COM] \e]8;;'$COM_URL'\a'$COM_URL'\e]8;;\a\e[0m'
}

# If all scenarios are run at once, split the results into multiple profiles
if [ "$SCENARIO" = "." ] && [ "$SPLIT" = true ]; then
  echo -e "\e[44mSplitting results into multiple profiles\e[0m"
  SUBJECTS=$(find tests/Benchmarks/ -type f -name "*Bench.php" -exec basename {} .php \;)
  for SUBJECT in $SUBJECTS; do
    call_benchmarks "$SUBJECT" "$STYLE" "$TIMESTAMP" "$N"
  done
else
  call_benchmarks "$SCENARIO" "$STYLE" "$TIMESTAMP" "$N"
fi
