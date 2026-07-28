Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## Tracer
### Fixed
- Prevent application error handlers from replacing successful OpenFeature
  evaluation values with code defaults #4071
