FROM ubuntu:18.04

RUN http_proxy=$http_proxy https_proxy=$http_proxy no_proxy=$no_proxy apt-get update
RUN http_proxy=$http_proxy https_proxy=$http_proxy no_proxy=$no_proxy apt-get install -y software-properties-common
RUN http_proxy=$http_proxy https_proxy=$http_proxy no_proxy=$no_proxy add-apt-repository ppa:ondrej/php
RUN http_proxy=$http_proxy https_proxy=$http_proxy no_proxy=$no_proxy apt-get --allow-unauthenticated update
RUN DEBIAN_FRONTEND=noninteractive http_proxy=$http_proxy https_proxy=$http_proxy no_proxy=$no_proxy apt-get install -y --allow-unauthenticated php5.6-dev git-core

# obtain a copy of ZendGuardLoader.so and place it in your repository
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