#!/usr/bin/env bash
set -euo pipefail

# Keep one isolated, versioned Jekyll runtime for both build backends.
export GEM_HOME=/opt/jekyll-gems GEM_PATH=/opt/jekyll-gems

gem install --no-document --bindir /opt/jekyll-gems/bin \
    jekyll-sass-converter:3.1.0 \
    jekyll:4.4.1 \
    jekyll-optional-front-matter:0.3.2 \
    jekyll-sitemap:1.4.0 \
    jekyll-seo-tag:2.8.0 \
    jekyll-feed:0.17.0 \
    jekyll-polyglot:1.14.0

ruby -e 'require "jekyll"; require "sass-embedded"; require "jekyll-polyglot"; abort "Unexpected Sass converter" unless Gem.loaded_specs.fetch("jekyll-sass-converter").version.to_s == "3.1.0"; abort "Remote theme must not be installed" unless Gem::Specification.find_all_by_name("jekyll-remote-theme").empty?'

# Register the fork preloader for both Kickstart and standalone containers.
install -m 755 "$(dirname "$0")/start-jekyll.sh" /kickstart/run/start.d/05-jekyll-preloader.sh
