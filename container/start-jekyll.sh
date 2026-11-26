#!/bin/bash
# Sourced by Kickstart after configuration has been written.
(
    set -e
    case "${JEKYLL_BUILD_MODE:-fork}" in
        command) exit 0 ;;
        fork) ;;
        *) echo "Invalid JEKYLL_BUILD_MODE" >&2; exit 1 ;;
    esac
    . /etc/apache2/envvars
    install -d -m 0700 -o "$APACHE_RUN_USER" -g "$APACHE_RUN_GROUP" /run/jekyll-builder
    rm -f /run/jekyll-builder/build.sock /run/jekyll-builder/preloader.pid

    start-stop-daemon --start --background --make-pidfile \
        --pidfile /run/jekyll-builder/preloader.pid \
        --chuid "$APACHE_RUN_USER:$APACHE_RUN_GROUP" \
        --startas /usr/bin/env -- -i PATH="$PATH" \
        HOME=/run/jekyll-builder JEKYLL_NO_BUNDLER_REQUIRE=true \
        GEM_HOME=/opt/jekyll-gems GEM_PATH=/opt/jekyll-gems \
        JEKYLL_ENV="${JEKYLL_ENV:-production}" \
        /usr/bin/ruby /opt/src/jekyll-preloader.rb

    for ((i=0; i<300; i++)); do
        if [ -S /run/jekyll-builder/build.sock ]; then
            exit 0
        fi
        sleep 0.1
    done
    echo "Jekyll preloader did not start" >&2
    exit 1
)
