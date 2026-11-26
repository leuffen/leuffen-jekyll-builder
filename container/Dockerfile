FROM ghcr.io/nfra-project/kickstart-flavor-php:8.4
ARG BUILD_DATE
LABEL org.opencontainers.image.created="${BUILD_DATE}"
ENV DEV_CONTAINER_NAME="leuffen-jekyll-builder"

COPY container/ /opt/
RUN ["bash", "-c", "chown -R user /opt"]
RUN ["/kickstart/run/entrypoint.sh", "build"]
RUN install -m 755 /opt/start-jekyll.sh /kickstart/run/start.d/05-jekyll-preloader.sh
ENTRYPOINT ["/kickstart/run/entrypoint.sh", "standalone"]
