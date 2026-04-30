# Clang Tools Docker Wrappers

This directory contains transparent wrapper scripts for `clang-format`, `clang-tidy`, and `run-clang-tidy` that execute in Docker while providing seamless filesystem access.

## Overview

The wrapper scripts behave identically to native binaries but run in a minimal Alpine Linux container with LLVM 17.0.6 tools. This ensures consistent tooling across different development environments without requiring local installation.

## Building the Docker Image

```bash
./build_local.sh
```

This builds the `datadog/dd-appsec-php-ci:clang-tools` image (~108MB) with statically-linked binaries.

## Usage

### Adding to PATH

Add this directory to your PATH to use the wrappers system-wide:

```bash
export PATH="/path/to/dd-trace-php/appsec/cmake/clang-tools:$PATH"
```

### clang-format

```bash
# Format code from stdin
echo "int main(){return 0;}" | ./clang-format

# Format a file
./clang-format myfile.cpp

# Format in-place
./clang-format -i myfile.cpp

# Check formatting
./clang-format --dry-run --Werror myfile.cpp
```

### clang-tidy

```bash
# Run clang-tidy on a file
./clang-tidy myfile.cpp -- -Iinclude

# With compilation database
./clang-tidy -p build myfile.cpp
```

### run-clang-tidy

```bash
# Run on all files in compilation database
./run-clang-tidy -p build

# Apply fixes automatically
./run-clang-tidy -p build -fix

# Run on specific files matching regex
./run-clang-tidy -p build '.*\.cpp$'
```

## How It Works

### Filesystem Sharing

- Mounts the **git repository root** at `/workspace` in the container
- Preserves the current working directory relative to the git root
- Automatically translates absolute paths to container paths
- Runs with your user ID to maintain file ownership

### Path Translation

The wrappers intelligently handle paths:
- **Relative paths**: Pass through unchanged
- **Absolute paths within git repo**: Translated to `/workspace/...`
- **Absolute paths outside git repo**: Converted to relative when possible
- **Flags and options**: Pass through unchanged

### stdin/stdout Support

- Detects piped input and enables interactive mode (`-i` flag) when needed
- Preserves stdin for formatting code from pipes
- stdout and stderr pass through transparently

## Example Workflows

### IDE Integration

Configure your IDE to use these wrappers as the clang-format/clang-tidy executables. They'll work transparently with features like "format on save" or inline diagnostics.

### CI/CD

```bash
# Check all C++ files are formatted
find . -name "*.cpp" -o -name "*.h" | xargs ./clang-format --dry-run --Werror

# Run clang-tidy with fixes
./run-clang-tidy -p build -fix -quiet
```

### Git Pre-commit Hook

```bash
#!/bin/bash
# Format staged C++ files
git diff --cached --name-only --diff-filter=ACM | \
    grep -E '\.(cpp|h)$' | \
    xargs ./clang-format -i
```

## Technical Details

**Docker Image**: `datadog/dd-appsec-php-ci:clang-tools`
- Base: Alpine Linux 3.21
- LLVM Version: 17.0.6
- Size: ~108MB (statically-linked binaries)
- Tools: clang-format, clang-tidy, clang-apply-replacements, run-clang-tidy

**User Mapping**: Runs as `-u $(id -u):$(id -g)` to preserve file ownership

**Volume Mount**: `-v $GIT_ROOT:/workspace` for full repository access

## Troubleshooting

### "Docker image not found"

Build the image first:
```bash
./build_local.sh
```

### "Permission denied"

Ensure scripts are executable:
```bash
chmod +x clang-format clang-tidy run-clang-tidy
```

### Paths not resolving correctly

The scripts automatically find the git repository root. If you're outside a git repo, they fall back to `$PWD`.
