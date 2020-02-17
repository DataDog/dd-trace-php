# How to verify packages

## Alpine

```
make -C dockerfiles/build-extension package
make -C dockerfiles/verify_packages prepare_docker_images_alpine
make -C dockerfiles/verify_packages 5.6.alpine
```
