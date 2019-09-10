# ------------------------
FROM alpine:3.10.2 as base
# ------------------------

RUN apk add --no-cache bash unzip curl

SHELL ["/bin/bash", "-e", "-c"]

ENV BUILD_DIR=/tmp/buildroot
ENV INSTALL_DIR=/opt

# ------------------
FROM base as sources
# ------------------

# download php
RUN mkdir -p ${BUILD_DIR}/php\
 && curl -sL https://github.com/php/php-src/archive/php-7.2.21.tar.gz\
  | tar xz -C ${BUILD_DIR}/php --strip-components=1

WORKDIR ${BUILD_DIR}/php

# patch freetype
RUN apk add --no-cache patch
ADD freetype.patch .
RUN patch -p1 < freetype.patch

# ---------------------
FROM sources as builder
# ---------------------

RUN apk add --no-cache pkgconfig make autoconf bison gcc g++

ARG SERVERLESS_PHP_MODULES
ADD php-configure.sh ./
ADD php-modules.sh ./
RUN ./php-configure.sh "${SERVERLESS_PHP_MODULES[@]}"

RUN make -j `nproc` && make install && make clean

# pear modules (xdebug, redis etc)

# ---------------------
FROM builder as bundler
# ---------------------

RUN apk add --no-cache musl musl-dev gcc py-pip \
 && pip install exodus_bundler\
 && ln -s /usr/bin/gcc /usr/bin/musl-gcc

ADD php-package.sh ./
RUN ./php-package.sh ${INSTALL_DIR}

# ----------
FROM scratch
# ----------
COPY --from=bundler /opt /
ADD bootstrap /
ADD php.ini /etc/php/php.ini
RUN ["/bin/php", "-v"]
RUN ["/bin/php", "-i"]
RUN ["/bin/php", "-m"]
ENTRYPOINT ["/bin/php"]
