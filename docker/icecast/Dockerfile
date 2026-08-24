# Icecast sidecar of a RadioRing station.
#
# One container per station, started by the container driver next to the Liquidsoap
# container. The configuration comes entirely from the env, so RadioRing can set the
# source password and the limits without mounting a file.
FROM alpine:3.20

# mailcap provides /etc/mime.types. Without it Icecast warns on every start and guesses
# the content types of its web interface.
RUN apk add --no-cache icecast mailcap

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Traefik routes to this port. Deliberately not configurable: the labels the driver sets
# have to be correct without looking inside this image.
EXPOSE 8000

USER icecast

ENTRYPOINT ["/entrypoint.sh"]
