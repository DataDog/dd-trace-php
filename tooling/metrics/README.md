# Metrics

Calculate metrics using the [sirun](https://github.com/DataDog/sirun) tool.

## Usage

### Memory overhead

```
# Example: 0.63.0-RC
export TARGET_URL=https://529832-119990860-gh.circle-artifacts.com/0/datadog-php-tracer-0.63.0.x86_64.tar.gz
# Example: 0.62.0 release
export REFERENCE_URL=https://github.com/DataDog/dd-trace-php/releases/download/0.62.0/datadog-php-tracer-0.62.0.x86_64.tar.gz
make memory
```
Output
```
"no trace"
1234 (iteration 1)
1234 (iteration 2)
...
1234 (iteration 40)
"reference"
1234 (iteration 1)
1234 (iteration 2)
...
1234 (iteration 40)
"target"
1234 (iteration 1)
1234 (iteration 2)
...
1234 (iteration 40)
```
