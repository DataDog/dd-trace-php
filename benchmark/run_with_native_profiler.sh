#!/usr/bin/env bash

set -e

# Help command
if [ "$1" = "--help" ] || [ "$1" = "-h" ]; then
  echo "Usage: $0 [options]"
  echo "Options:"
  echo "  -s, --scenario <scenario>  The scenario to run (e.g., benchTelemetryParsing, LaravelBench). Defaults to all scenarios (.)"
  echo "  -t, --style <style>        The style of benchmark to run (base, opcache). Defaults to base"
  echo "  -n, --n <n>                The number of times to run the benchmark. Defaults to 1"
  echo "  -w, --without-dependencies Do not run the dependencies. Defaults to false"
  exit 0
fi

# Set defaults
SCENARIO="."
STYLE="base"
N=1
WITHOUT_DEPENDENCIES=false

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

# Set a pseudo-unique identifier for this run
TIMESTAMP=$(date +%s)

IDENTIFIER=""
if [ "$SCENARIO" = "." ]; then
  IDENTIFIER="bench-all"
else
  IDENTIFIER="bench-$SCENARIO"
fi
IDENTIFIER="$IDENTIFIER-$STYLE-$TIMESTAMP"
IDENTIFIER=$(echo "$IDENTIFIER" | tr '[:upper:]' '[:lower:]')

echo -e "\e[44mIDENTIFIER: $IDENTIFIER\e[0m"

# If the scenario is "opcache", run the opcache benchmarks
MAKE_COMMAND="call_benchmarks"
if [ "$STYLE" = "opcache" ]; then
  MAKE_COMMAND="call_benchmarks_opcache"
fi

# Run the benchmarks
cd ~/app

if [ "$WITHOUT_DEPENDENCIES" = false ]; then
  make composer_tests_update
  make benchmarks_run_dependencies
fi

for i in $(seq 1 $N); do
  echo -e "\e[44mRunning benchmark $i of $N\e[0m"
  DDPROF_IDENTIFIER="$IDENTIFIER" make $MAKE_COMMAND FILTER="$SCENARIO"
done

# echo with a light blue background
echo -e "\e[92mAll $N benchmarks completed!\e[0m"
echo -e "\e[92mCheck the results in the Datadog app with the following identifier: $IDENTIFIER\e[0m"
echo -e "\e[92mIf you're not receiving any profiles, verify the value of DD_TRACE_AGENT_URL, DD_SITE, and DD_API_KEY.\e[0m"
# Do a clickable link to: https://app.datadoghq.eu/profiling/search?query=service%3A$IDENTIFIER (Note the $IDENTIFIER)
EU_URL="https://app.datadoghq.eu/profiling/search?query=service%3A$IDENTIFIER"
COM_URL="https://app.datadoghq.com/profiling/search?query=service%3A$IDENTIFIER"
echo -e '\e[92mClick here to view the results in the Datadog app:\e[0m'
echo -e '\e[92m[EU] \e]8;;'$EU_URL'\a'$EU_URL'\e]8;;\a\e[0m'
echo -e '\e[92m[COM] \e]8;;'$COM_URL'\a'$COM_URL'\e]8;;\a\e[0m'
