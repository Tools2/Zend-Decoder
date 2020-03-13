FROM ubuntu:18.04

RUN http_proxy=$http_proxy https_proxy=$http_proxy no_proxy=$no_proxy apt-get update
RUN http_proxy=$http_proxy https_proxy=$http_proxy no_proxy=$no_proxy apt-get install -y software-properties-common
RUN http_proxy=$http_proxy https_proxy=$http_proxy no_proxy=$no_proxy add-apt-repository ppa:ondrej/php
RUN http_proxy=$http_proxy https_proxy=$http_proxy no_proxy=$no_proxy apt-get --allow-unauthenticated update
RUN DEBIAN_FRONTEND=noninteractive http_proxy=$http_proxy https_proxy=$http_proxy no_proxy=$no_proxy apt-get install -y --allow-unauthenticated php5.6-dev git-core

# Obtain a copy of ZendGuardLoader.so and place it in your repository, before building this Dockerfile
COPY . .
RUN git clone https://github.com/lighttpd/xcache
WORKDIR /xcache
RUN patch -p1 < /xcache.patch
RUN phpize
RUN ./configure --enable-xcache-disassembler
RUN make
RUN make install
RUN printf "\nextension=xcache.so" >> /etc/php/5.6/cli/php.ini
RUN printf "\nzend_extension=/ZendGuardLoader.so" >> /etc/php/5.6/cli/php.ini
WORKDIR /

# Build the container
# docker build -t zenddecoder .

# To decompile your code base, mount it in the docker volume and run a bash one-liner:
# docker run -v /path/to/your/code/:/codebase -it zenddecoder /bin/bash
# for f in $(find /codebase -name '*.php'); do php index.php $f > "dec_"$f; done
