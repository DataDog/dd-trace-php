The base image should be built and published first, before other jobs run.

The images are versioned, unlike the buster image. This so that when you are
working on the next version of the image, you don't have to worry about
breaking master; you only have to worry about other people also working on
the next version. Version numbers are maintained in .env, and the
docker-compose.yml file will use it, but other files (like the one in the
root of the repository) may also need updated

