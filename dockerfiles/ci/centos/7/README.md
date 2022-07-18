# Build order

```
docker-compose build base

# You may want to break these apart so your system isn't overloaded, but if
# you have the power, these only depend on base.
# Note that profiling takes a long time to build (it has to build libclang).
docker-compose build profiling php-7.{0..4} php-8.{0..1}

# These just copy layers that have already been built.
docker-compose build php-7.{1..4}-profiling php-8.{0..1}-profiling
