FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        git \
        mariadb-server \
        php8.3-cli \
        php8.3-curl \
        php8.3-gd \
        php8.3-mbstring \
        php8.3-mysql \
        php8.3-xml \
        php8.3-zip \
    && rm -rf /var/lib/apt/lists/*

USER ubuntu
