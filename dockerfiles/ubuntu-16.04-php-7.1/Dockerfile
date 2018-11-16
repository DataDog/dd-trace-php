
FROM ubuntu:16.04

RUN apt-get update && apt-get install -y vim valgrind software-properties-common
RUN LC_ALL=C.UTF-8 add-apt-repository ppa:ondrej/php && apt-get update

CMD ["bash"]

ENTRYPOINT ["/bin/bash", "-c"]
