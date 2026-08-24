#!/bin/sh
set -eu

# ===================== Config (set by the container driver) =====================
: "${ICECAST_SOURCE_PASSWORD:?ICECAST_SOURCE_PASSWORD is required}"
: "${ICECAST_ADMIN_PASSWORD:=$ICECAST_SOURCE_PASSWORD}"
: "${ICECAST_HOSTNAME:=localhost}"
: "${ICECAST_MAX_LISTENERS:=100}"
: "${ICECAST_BURST_SIZE:=65536}"
: "${ICECAST_LOCATION:=RadioRing}"
: "${ICECAST_ADMIN_MAIL:=noreply@localhost}"

CONFIG=/tmp/icecast.xml

# The passwords are generated as hex or alphanumeric (install.sh: openssl rand -hex,
# station: Str::random), so they need no XML escaping. Anyone setting them by hand should
# stay within that character set.
cat > "$CONFIG" <<XMLEOF
<icecast>
    <location>${ICECAST_LOCATION}</location>
    <admin>${ICECAST_ADMIN_MAIL}</admin>
    <hostname>${ICECAST_HOSTNAME}</hostname>

    <limits>
        <clients>${ICECAST_MAX_LISTENERS}</clients>
        <sources>4</sources>
        <queue-size>524288</queue-size>
        <client-timeout>30</client-timeout>
        <header-timeout>15</header-timeout>
        <source-timeout>10</source-timeout>
        <!-- Sends the player some audio right away, so playback starts without the
             usual buffering pause. -->
        <burst-on-connect>1</burst-on-connect>
        <burst-size>${ICECAST_BURST_SIZE}</burst-size>
    </limits>

    <authentication>
        <source-password>${ICECAST_SOURCE_PASSWORD}</source-password>
        <relay-password>${ICECAST_SOURCE_PASSWORD}</relay-password>
        <admin-user>admin</admin-user>
        <admin-password>${ICECAST_ADMIN_PASSWORD}</admin-password>
    </authentication>

    <listen-socket>
        <port>8000</port>
    </listen-socket>

    <!-- Lets players on other sites use the Web Audio API. A plain audio element does
         not need this, visualisations and fetch-based players do.

         The comment sits BEFORE the element on purpose: Icecast stops reading
         <http-headers> at the first child that is not a <header>, so a comment inside
         would silently drop the headers. -->
    <http-headers>
        <header name="Access-Control-Allow-Origin" value="*" />
    </http-headers>

    <paths>
        <basedir>/usr/share/icecast</basedir>
        <!-- Logs go to the container output, not to files: the sidecar is stateless and
             recreated on every change. -->
        <logdir>/dev</logdir>
        <webroot>/usr/share/icecast/web</webroot>
        <adminroot>/usr/share/icecast/admin</adminroot>
        <alias source="/" destination="/status.xsl"/>
    </paths>

    <logging>
        <accesslog>stdout</accesslog>
        <errorlog>stderr</errorlog>
        <loglevel>2</loglevel>
        <logsize>0</logsize>
    </logging>

    <security>
        <chroot>0</chroot>
    </security>
</icecast>
XMLEOF

exec icecast -c "$CONFIG"
