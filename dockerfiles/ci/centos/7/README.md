# Build order

```sh
# Takes a long time, needs a lot of RAM
docker-compose build base

# Build everything else. Must be done after building base or else they may end
# up using an old base (or even pulling one if you don't have one locally).
docker-compose build 
```
