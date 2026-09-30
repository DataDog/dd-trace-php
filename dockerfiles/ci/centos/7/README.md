# Build order

```sh
docker buildx bake base --push

# Build everything else. Must be done after building base or else they may end
# up using an old base (or even pulling one if you don't have one locally).
docker buildx bake --push
```
