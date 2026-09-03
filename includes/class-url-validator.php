<?php
/**
 * Shared outbound-URL validation for every part of the plugin that fetches a
 * remote address on a visitor's behalf.
 *
 * There used to be two copies of this logic -- one in includes/class-proxy.php
 * and a second, weaker one in proxy-metadata.php -- which meant a hardening
 * change could be made in one place and forgotten in the other, leaving the
 * forgotten endpoint as the weaker way in. Both now call this class and there
 * is only one implementation to keep correct.
 *
 * This file is deliberately written to work under SHORTINIT as well as inside
 * a fully booted WordPress, because proxy-metadata.php can be requested
 * directly. It therefore uses only plain PHP plus apply_filters(), and never
 * assumes formatting.php, l10n or the rest of WordPress is loaded.
 *
 * @package MBR_Live_Radio_Player
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'MBR_LRP_URL_Validator' ) ) {

class MBR_LRP_URL_Validator {

    /**
     * Maximum redirect hops any caller should follow.
     */
    const MAX_REDIRECTS = 5;

    /**
     * Ports that are always acceptable.
     *
     * @var int[]
     */
    private static $always_allowed_ports = array( 80, 443 );

    /**
     * High ports belonging to well-known services that a radio stream has no
     * business being on. Everything else above 1024 is permitted.
     *
     * The previous design was the other way round: a 13-entry allow list of
     * "common streaming ports". Shoutcast and Icecast hosts routinely run
     * mounts on 8010, 8020, 8030, 8090, 8100, 8500, 2199 and dozens more --
     * very often with the MP3 mount on the base port and the AAC mount ten
     * or twenty above it -- so the allow list silently blocked a large share
     * of perfectly ordinary stations. Since every destination address is
     * already required to be a public one, an allow list of ports bought
     * little beyond those false rejections.
     *
     * @var int[]
     */
    private static $blocked_ports = array(
        // Databases and caches.
        1433, 1521, 3306, 5432, 5984, 6379, 7199, 9042, 9200, 9300, 11211, 27017, 27018, 27019,
        // Remote access and orchestration.
        2049, 2375, 2376, 2379, 2380, 3389, 5900, 5901, 5902, 6443, 10250, 10255,
        // Mail and directory services on high ports.
        1080, 4369, 5672, 15672,
    );

    /**
     * Validate an outbound URL and, on success, hand back the addresses it
     * resolved to so the caller can pin the connection to them.
     *
     * @param string     $url        URL to check.
     * @param array|null $pinned_ips Filled with the validated IP addresses.
     * @return bool True when the URL is safe to request.
     */
    public static function validate( $url, &$pinned_ips = null ) {
        $pinned_ips = array();

        $parsed = parse_url( $url );

        if ( ! is_array( $parsed ) || ! isset( $parsed['scheme'], $parsed['host'] ) ) {
            return false;
        }

        if ( ! in_array( strtolower( $parsed['scheme'] ), array( 'http', 'https' ), true ) ) {
            return false;
        }

        $host = strtolower( $parsed['host'] );

        $blocked_hosts = array( 'localhost', '127.0.0.1', '::1', '0.0.0.0', 'metadata.google.internal', '169.254.169.254' );
        if ( in_array( $host, $blocked_hosts, true ) ) {
            return false;
        }

        // Bracketed IPv6 literals arrive from parse_url() as "[::1]".
        $host_ip = trim( $host, '[]' );

        if ( isset( $parsed['port'] ) && ! self::is_port_allowed( (int) $parsed['port'] ) ) {
            return false;
        }

        if ( filter_var( $host_ip, FILTER_VALIDATE_IP ) ) {
            if ( ! self::is_safe_ip( $host_ip ) ) {
                return false;
            }

            $pinned_ips = array( $host_ip );
            return true;
        }

        // Hostnames: resolve A *and* AAAA records and reject if any resolved
        // address is private or reserved.
        //
        // SECURITY: gethostbynamel() on its own only returns IPv4 A records.
        // A host publishing a public A record and a loopback or private AAAA
        // record would pass validation on the strength of the IPv4 answer and
        // could then be connected to over IPv6, which was exactly the gap the
        // v3.12.6 audit identified. Both families are resolved now, and every
        // returned address has to pass.
        $ips = self::resolve_host( $host );

        if ( empty( $ips ) ) {
            return false;
        }

        foreach ( $ips as $ip ) {
            if ( ! self::is_safe_ip( $ip ) ) {
                return false;
            }
        }

        $pinned_ips = $ips;

        return true;
    }

    /**
     * Resolve a hostname to every address it publishes, IPv4 and IPv6.
     *
     * @param string $host Hostname.
     * @return string[] Resolved addresses, empty on failure.
     */
    public static function resolve_host( $host ) {
        $ips = array();

        if ( function_exists( 'dns_get_record' ) ) {
            // Queried separately rather than as DNS_A | DNS_AAAA: the combined
            // constant is unreliable on several platforms and quietly returns
            // only one family, which would put us straight back where we
            // started.
            foreach ( array( DNS_A => 'ip', DNS_AAAA => 'ipv6' ) as $type => $field ) {
                $records = @dns_get_record( $host, $type );

                if ( ! is_array( $records ) ) {
                    continue;
                }

                foreach ( $records as $record ) {
                    if ( ! empty( $record[ $field ] ) ) {
                        $ips[] = $record[ $field ];
                    }
                }
            }
        }

        // dns_get_record() is disabled on some shared hosts. Fall back to the
        // IPv4-only resolver, and note that we could not see the AAAA records
        // so callers can pin to IPv4 rather than let the connection pick an
        // address nothing validated.
        if ( empty( $ips ) ) {
            $fallback = @gethostbynamel( $host );

            if ( is_array( $fallback ) ) {
                $ips = $fallback;
            }
        }

        return array_values( array_unique( array_filter( $ips ) ) );
    }

    /**
     * Can this hostname's IPv6 records be seen at all?
     *
     * When they cannot, the caller should force the request onto IPv4 so the
     * connection can only use an address that was actually checked.
     *
     * @return bool
     */
    public static function can_resolve_ipv6() {
        return function_exists( 'dns_get_record' );
    }

    /**
     * Is a literal IP address safe to connect to?
     *
     * Rejects loopback, private, link-local, reserved and cloud metadata
     * addresses for both IPv4 and IPv6, including IPv4-mapped IPv6 forms.
     *
     * @param string $ip Address to test.
     * @return bool
     */
    public static function is_safe_ip( $ip ) {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return false;
        }

        if ( $ip === '169.254.169.254' || strtolower( $ip ) === 'fd00:ec2::254' ) {
            return false;
        }

        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
            $packed = @inet_pton( $ip );

            if ( $packed !== false && strlen( $packed ) === 16 ) {
                $hex = bin2hex( $packed );

                // ::ffff:0:0/96 (IPv4-mapped) and ::/96 (IPv4-compatible) are
                // unwrapped so the underlying IPv4 address is judged on its
                // own merits.
                if ( substr( $hex, 0, 24 ) === str_repeat( '0', 20 ) . 'ffff'
                    || ( substr( $hex, 0, 24 ) === str_repeat( '0', 24 ) && substr( $hex, 24 ) !== str_repeat( '0', 8 ) ) ) {
                    $mapped = long2ip( (int) hexdec( substr( $hex, 24, 8 ) ) );
                    return self::is_safe_ip( $mapped );
                }

                // 64:ff9b::/96 (NAT64) hides an IPv4 address too.
                if ( substr( $hex, 0, 24 ) === '0064ff9b' . str_repeat( '0', 16 ) ) {
                    $mapped = long2ip( (int) hexdec( substr( $hex, 24, 8 ) ) );
                    return self::is_safe_ip( $mapped );
                }
            }
        }

        // Blocks ::1, fc00::/7, fe80::/10, 10/8, 172.16/12, 192.168/16,
        // 127/8, 0/8, 169.254/16 and friends.
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * Is this port acceptable for an outbound stream or metadata request?
     *
     * @param int $port Port number.
     * @return bool
     */
    public static function is_port_allowed( $port ) {
        $port    = (int) $port;
        $allowed = true;

        if ( $port < 1 || $port > 65535 ) {
            $allowed = false;
        } elseif ( in_array( $port, self::$always_allowed_ports, true ) ) {
            $allowed = true;
        } elseif ( $port < 1024 ) {
            // Privileged ports other than 80 and 443 are system services.
            $allowed = false;
        } else {
            $blocked = self::$blocked_ports;

            if ( function_exists( 'apply_filters' ) ) {
                $filtered = apply_filters( 'mbr_lrp_blocked_stream_ports', $blocked );
                if ( is_array( $filtered ) ) {
                    $blocked = array_map( 'intval', $filtered );
                }
            }

            $allowed = ! in_array( $port, $blocked, true );
        }

        if ( function_exists( 'apply_filters' ) ) {
            $allowed = (bool) apply_filters( 'mbr_lrp_is_stream_port_allowed', $allowed, $port );
        }

        return $allowed;
    }

    /**
     * Resolve a Location header against the URL that produced it.
     *
     * @param string $base     Absolute URL the redirect came from.
     * @param string $relative Absolute, root-relative or relative target.
     * @return string|false Absolute URL, or false if it cannot be built.
     */
    public static function absolute_url( $base, $relative ) {
        $relative = trim( (string) $relative );

        if ( $relative === '' ) {
            return false;
        }

        if ( preg_match( '#^https?://#i', $relative ) ) {
            return $relative;
        }

        $parts = parse_url( $base );

        if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
            return false;
        }

        $origin = $parts['scheme'] . '://' . $parts['host']
            . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

        if ( strpos( $relative, '//' ) === 0 ) {
            return $parts['scheme'] . ':' . $relative;
        }

        if ( strpos( $relative, '/' ) === 0 ) {
            return $origin . $relative;
        }

        $dir = isset( $parts['path'] ) ? rtrim( dirname( $parts['path'] ), '/' ) : '';

        return $origin . $dir . '/' . $relative;
    }

    /**
     * Build the CURLOPT_RESOLVE entry that pins a request to the addresses we
     * validated.
     *
     * This closes the time-of-check/time-of-use gap the audit described: we
     * resolve the hostname, check every address, and then tell cURL to use
     * exactly those addresses instead of performing a second lookup of its
     * own that an attacker's DNS could answer differently. The hostname is
     * still what gets sent in the Host header and used for TLS SNI and
     * certificate validation, so nothing about the TLS check is weakened.
     *
     * @param string   $url URL about to be requested.
     * @param string[] $ips Validated addresses.
     * @return string[] Entries for CURLOPT_RESOLVE (may be empty).
     */
    public static function curl_resolve_entries( $url, $ips ) {
        if ( empty( $ips ) ) {
            return array();
        }

        $parsed = parse_url( $url );

        if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
            return array();
        }

        $host = strtolower( trim( $parsed['host'], '[]' ) );

        // A literal-IP URL has nothing to pin.
        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return array();
        }

        $scheme = isset( $parsed['scheme'] ) ? strtolower( $parsed['scheme'] ) : 'http';
        $port   = isset( $parsed['port'] ) ? (int) $parsed['port'] : ( $scheme === 'https' ? 443 : 80 );

        // Every validated address is offered, so a station with several
        // servers still fails over normally if the first one is down.
        $addresses = array();
        foreach ( $ips as $ip ) {
            $addresses[] = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? '[' . $ip . ']' : $ip;
        }

        return array( $host . ':' . $port . ':' . implode( ',', $addresses ) );
    }

    /**
     * Apply validated-address pinning to a cURL handle.
     *
     * @param resource|CurlHandle $handle cURL handle.
     * @param string              $url    URL being requested.
     * @param string[]            $ips    Validated addresses.
     * @return void
     */
    public static function pin_curl_handle( $handle, $url, $ips ) {
        if ( ! $handle ) {
            return;
        }

        $entries = self::curl_resolve_entries( $url, $ips );

        if ( ! empty( $entries ) ) {
            curl_setopt( $handle, CURLOPT_RESOLVE, $entries );
        }

        // If AAAA records could not be read at all, the connection is held to
        // IPv4 so it cannot reach an address that was never checked.
        if ( ! self::can_resolve_ipv6() && defined( 'CURL_IPRESOLVE_V4' ) ) {
            curl_setopt( $handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
        }
    }
}

}
