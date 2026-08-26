Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## All products
### Fixed
- Ensure files and directories created by the installer remain readable when
  installation runs under a restrictive umask #4138

## Profiling
### Fixed
- Fix I/O profiling upscaling #4137
- Normalize missing environment values in profiler samples #4133
- Fix macOS profiler builds with test features enabled #4132
