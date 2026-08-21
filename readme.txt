=== MBR Live Radio Player ===
Contributors: harbourbob
Plugin URI: https://littlewebshack.com/radio/
Tags: radio, player, live stream, audio, hls, podcast, mp3, file player, playlist
Author: Robert Palmer
Author URI: https://madebyrobert.co.uk
Requires at least: 5.2
Tested up to: 7.0
Stable tag: 3.12.6
Requires PHP: 7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A beautiful, fully-featured audio player for WordPress. Supports live radio streams, HLS, and a built-in file player with playlist, progress bar, and resume-from-last-position.

== Description ==

MBR Live Radio Player is a powerful yet straightforward WordPress plugin for embedding professional audio players on your website. Whether you're running a live internet radio station, streaming a church service, or publishing a series of podcast episodes or recordings, this plugin has you covered.

Every player is built and previewed live inside the WordPress admin — choose your skin, set your colours, upload artwork, and see exactly what your visitors will see before you publish.

A comprehensive User Guide (PDF) is bundled in the Zip file.

= Two Player Modes =

**Live Stream Mode**
Connect to any internet radio stream and the player displays live now-playing metadata, a real-time status indicator, and seamless station switching. Supports virtually every stream format in common use.

**File Player Mode**
Upload one or more audio files from the WordPress Media Library and the player becomes a full-featured audio file player, complete with progress bar, seek scrubbing, rewind/forward 15 seconds, auto-advancing playlist, and resume from where the listener left off.

= Stream Format Support =

* HLS (.m3u8) — adaptive bitrate streaming
* Shoutcast — .m3u playlists with automatic stream URL detection
* Icecast — full ICY metadata support
* MP3, AAC, OGG, and most other browser-native audio formats

= File Player Features =

* Upload MP3, AAC, OGG, FLAC, and other audio files directly from the WordPress Media Library
* Multiple files per station — organised as a numbered playlist
* Drag-and-drop reorder in the admin
* Progress bar with click-to-seek and touch scrubbing
* Rewind 15 seconds / Forward 15 seconds
* Auto-advance to the next track at the end of each file
* Browseable track list panel (slide-up, matching the multi-station UI)
* Resume playback from last position — uses localStorage to remember exactly where each listener left off, even after they close their browser. Position is cleared automatically when a track completes.

= Player Appearance =

* Six professionally designed skins: Default, Classic, Gradient Dark, Minimal, Retro, Slim Bar
* Custom gradient colour picker for the Default skin
* Dark mode and glassmorphism variants
* Station artwork (WordPress Featured Image)
* Fully responsive — desktop, tablet, and mobile

= Live Admin Preview =

Every change you make in the station editor is reflected instantly in the preview panel — skin, colours, artwork, track list, and player mode. The preview player is fully interactive: you can play audio directly from the admin before publishing. The preview updates automatically when tracks are added, removed, reordered, or picked from the Media Library.

= Multi-Station Support =

Group multiple stations together and a station-switching panel slides up inside the player, letting visitors switch between streams without leaving the page.

= Pop-out Player =

Every stream player includes a pop-out button that opens the station in a compact floating window, so listeners can keep the audio going while they navigate your site or browse elsewhere.

= Sticky Player =

Add a full-width sticky player that docks to the top or bottom of the page, keeping audio controls always accessible.

= Developer Friendly =

Clean, well-documented code following WordPress coding standards throughout. Assets only load on pages where a shortcode is present. No CDN dependencies — HLS.js is bundled locally.

= Perfect For =

* Internet radio stations (AM/FM simulcast, DAB, web-only)
* Podcast publishers with episode archives
* Churches streaming sermons or services
* Music venues, DJs, and event streaming
* Corporate audio communications
* Audio course content and membership sites

== Installation ==

= From the WordPress Dashboard =

1. Go to Plugins → Add New
2. Search for "MBR Live Radio Player"
3. Click Install Now, then Activate
4. Go to Radio Stations → Add New to create your first station

= Manual Installation =

1. Download the plugin ZIP file
2. Go to Plugins → Add New → Upload Plugin
3. Choose the ZIP file and click Install Now
4. Click Activate Plugin

= After Activation =

1. Go to Radio Stations → Add New
2. Enter a station name (used as the player title)
3. Choose Player Mode: Live Stream or File Player
4. For Live Stream: paste your stream URL
5. For File Player: add tracks using the Choose button to browse the Media Library
6. Upload a Featured Image to use as station artwork
7. Choose a skin and customise colours in the Player Appearance panel
8. Watch the live preview update as you make changes
9. Publish, then copy the shortcode from the Shortcode panel
10. Paste the shortcode into any page, post, or widget

== Frequently Asked Questions ==

= What stream formats are supported? =

HLS (.m3u8), MP3, AAC, OGG, Shoutcast (.m3u playlists), Icecast, and most browser-native audio formats.

= Does it work with BBC Radio, SomaFM, and similar services? =

Yes — full HLS support handles BBC Radio and any other HLS stream. MP3 and AAC streams from services like SomaFM work natively.

= What audio file formats can I use in File Player mode? =

Any format the visitor's browser supports natively: MP3, AAC/M4A, OGG/Vorbis, FLAC, WAV, and WebM audio. MP3 has the widest cross-browser support and is recommended.

= How does resume-from-position work? =

When a listener plays a file, their current position is saved to localStorage every five seconds. When they return and press play again, the player seeks to that saved position automatically. The saved position is cleared when the track plays to the end naturally, so the next listen starts from the beginning.

= Is localStorage subject to GDPR? =

localStorage is considered persistent client-side storage under ePrivacy regulations, similar to cookies. For a resume-position feature like this it generally falls under "functional" storage which is typically exempt from consent requirements — but you should assess this in the context of your own site and applicable regulations.

= Can I have multiple stations? =

Yes — create as many stations as you need. Each has its own shortcode.

= Can I group stations together for switching? =

Yes — use the Station Group panel on the station edit screen to select other stations. A station switcher panel will appear inside the player automatically.

= Is there a sticky player? =

Yes — use the [mbr_sticky_player id="X"] shortcode. The sticky player docks to the top or bottom of the viewport and stays visible as the visitor scrolls.

= Can I use File Player and Live Stream on the same site? =

Yes — each station is independently configured. You can have some stations in Live Stream mode and others in File Player mode on the same site.

= Does it slow down my site? =

No — player CSS and JavaScript only load on pages that contain a player shortcode. HLS.js is bundled locally and only loaded when an HLS stream is present.

= Does it require any external services? =

No external services, no CDN, no tracking, no API keys required. Everything runs within WordPress.

= Can I customise the player colours? =

Yes — on the Default skin you can choose any two gradient colours using the colour picker. Six alternative pre-designed skins are also available.

== Screenshots ==

1. File player with progress bar, rewind/forward controls, and track list
2. Live stream player with now-playing metadata marquee
3. Station editor with live admin preview
4. Player Appearance panel with skin selection and colour picker
5. File player track list panel
6. Multi-station switching panel
7. Sticky player docked to the bottom of the page

== Third-Party Libraries ==

This plugin bundles the following third-party library:

**HLS.js v1.4.12**

* Purpose: Enables HLS (.m3u8) playback in browsers without native HLS support
* Developer: video-dev (open source)
* Source: https://github.com/video-dev/hls.js
* License: Apache License 2.0
* License URL: https://github.com/video-dev/hls.js/blob/master/LICENSE
* Privacy: HLS.js is a client-side library. It does not collect, store, or transmit any user data. It connects only to the streaming URLs configured by the site administrator. No tracking or analytics are performed.
* Why bundled: Required for HLS stream playback in Chrome, Firefox, and Edge. Safari uses native HLS and does not require this library.

== Changelog ==

= 3.12.6 =
**Fixes stream playback for stations whose URL redirects. Please update.**

* FIX: Streams failed with "no supported source was found" when the station URL redirects to the real stream host, which is extremely common — Shoutcast directory links, most CDNs, and load balancers all do it. The proxy was passing the redirect's own small HTML page through to the listener labelled as audio, which no browser can decode.
* Redirects are now detected and followed on the actual streaming request instead of by a separate advance probe. The probe was unreliable: many stream servers answer a probe request differently from a real one, or refuse it, and when it missed a redirect the failure above was the result. Each hop is still validated before it is followed, so the SSRF protection is unchanged.
* A redirect response's headers and body are now discarded rather than forwarded to the listener.
* Shoutcast's "ICY 200 OK" status line is now recognised alongside standard HTTP status lines when reading a response.
* Removed the unused redirect-probe helper.

= 3.12.5 =
**Fixes a regression in 3.12.3/3.12.4. Please update.**

* FIX: Streams could stop playing with "no supported source was found" in the browser console, most often after switching stations a few times. 3.12.3 added a limit on simultaneous streams per listener, and that limit was set far too tight: three at once, with an abandoned slot held for two hours. A single page can legitimately use three at once — an embedded player, a sticky bar and a pop-out — so ordinary use, and station switching in particular, could exhaust it and lock a listener out for the rest of the session.
* The limit is now ten simultaneous streams, and a slot is held for five minutes rather than two hours. Slots are refreshed while audio is actually playing, so a genuine long listen keeps its place, while a slot left behind by a dropped connection clears itself within minutes.
* FIX: Two streams starting in the same second were given the same internal slot identifier, so ending one released both.
* FIX: Releasing a slot pushed the expiry of the whole record forward, the same pattern that caused the rate-limiter bug fixed in 3.11.2.
* Set `add_filter( 'mbr_lrp_max_concurrent_streams', '__return_zero' );` to switch the limit off entirely.

= 3.12.4 =
**Fixes a regression in 3.12.3. Please update if you installed that version.**

* FIX: Now-playing metadata stopped appearing for Icecast and Shoutcast stations whose stream URL redirects to the real mount point. Shoutcast v2 and most stream load balancers do exactly this, so a great many stations were affected. 3.12.3 stopped following redirects altogether as part of closing an SSRF issue; redirects are now followed again, but each destination is re-validated before it is used, so the security fix still holds.
* FIX: Connection failures during a metadata fetch were reported as "Stream does not support Icecast metadata", which pointed at the station's configuration when the real fault was the connection itself. The actual error is now reported.
* FIX: Album artwork could go missing for artwork hosted behind a redirect. Redirects are followed again for artwork lookups, validated at each hop by WordPress's own safe HTTP handling.
* The redirect probe now uses an aborted GET rather than a HEAD request. Shoutcast servers answer HEAD inconsistently, which would have misreported the redirect chain for exactly the stations most likely to use one.

= 3.12.3 =
**Security release — updating is strongly recommended.**

* SECURITY: Removed `proxy-stream-v2.php`, an obsolete standalone proxy file that was shipped in the plugin folder but never used. It was reachable over the web without an authentication token and performed no SSRF validation, so it could be used to make requests from your server to arbitrary addresses, including your own internal network. This is the most important fix in this release.
* SECURITY: TLS certificate verification is now enabled on every outbound connection. Previously the stream proxy and the metadata proxy accepted invalid, expired or forged certificates, meaning a party able to interfere with the connection could impersonate the remote station.
* SECURITY: Redirects are no longer followed automatically. A stream that passed validation could previously redirect the server to an internal address that was never re-checked. Every redirect is now resolved manually and re-validated before it is used.
* SECURITY: Removed the "trusted streaming domain" list that allowed certain hostnames to skip DNS validation entirely. Several of those providers issue user-controlled subdomains, so the exemption could be abused. Every hostname is now resolved and every resulting address checked, with no exceptions.
* SECURITY: Artwork addresses broadcast in stream metadata are now validated before the server will contact them. These come from the broadcaster rather than from you, and were previously trusted.
* SECURITY: Stream URL validation now blocks decimal, octal and short-form encoded addresses, bracketed IPv6 literals, and IPv4-mapped IPv6 addresses.
* SECURITY: Debug messages are no longer written to the PHP error log on production sites. Full stream URLs were being logged, which could expose access tokens or credentials embedded in a stream address. Logging is now gated behind WP_DEBUG and strips query strings.
* Proxied streams now have a maximum duration of two hours rather than running without limit, and each visitor is limited to three simultaneous streams. An unbounded connection could otherwise tie up a PHP worker indefinitely, which matters most on shared hosting. Both limits are filterable.
* HLS manifest requests are no longer completely exempt from rate limiting; they now have their own allowance, ten times the standard one. The previous check matched ".m3u8" anywhere in the address, so rate limiting could be avoided by adding it to the query string.
* NEW: Optional `mbr_lrp_restrict_proxy_to_stations` filter limits the proxy to hosts belonging to your configured stations. Off by default so existing setups are unaffected.
* Removed unused development files from the release package, including a duplicate proxy class containing a PHP parse error, a debug proxy class, and several internal notes files.
* Corrected the "Tested up to" value in the plugin header, which read 6.8 while the readme and user guide said 7.0.

With thanks to the third-party code audit that prompted this review.

= 3.12.2 =
* Classic (Vertical Card): artwork enlarged to a 260x260 square and no longer cropped, so square album covers display in full and wide station logos are shown complete rather than cut off
* Track metadata is now polled every 20 seconds instead of 30, so now-playing details and artwork update sooner
* An advert break or news bulletin is now detected on the first empty response, reverting to the station artwork within 20 seconds instead of a minute
* Failed or rate-limited requests still require two consecutive responses before reverting, since a single failure is not evidence that the music has stopped
* Default volume lowered to 25% across all players, so pressing play is no longer startling

= 3.12.1 =
* The player credit now sits directly beneath the scrolling track metadata rather than at the end of the player
* Classic (Vertical Card): credit rendered in dark text to suit the light background, below the track metadata
* Dark Flat: credit centred across the full width, below the controls
* Ghost Bar: credit removed entirely, in keeping with the skin's minimal styling
* Retro Boombox: credit placed below the track metadata
* Slim Bar, sticky and pop-out players keep their own compact placement

= 3.12.0 =
* NEW: A small "Made with (heart) by Robert" credit now appears on every player - all six skins, the multi-station player, the sticky bar and the pop-out window - linking to the plugin home page in a new tab
* The credit inherits each skin's own text colour, so it sits naturally on light, dark, gradient and glassmorphism treatments alike, and is hidden on the sticky bar at narrow widths where space is tight

= 3.11.2 =
* FIX: Important - listeners were wrongly hit with "Rate limit exceeded" after about 15 minutes of continuous listening, losing now-playing text and artwork. Setting a WordPress transient resets its expiry, so the per-minute and per-hour counters never rolled over while a listener kept polling; the "limit" had effectively become a lifetime total rather than a rate. Both windows are now fixed periods that expire properly
* The hourly ceiling is raised to 1200 requests to accommodate several genuine listeners sharing one IP address behind carrier NAT or an office connection
* A triggered block now lasts 15 minutes rather than an hour
* Burst protection against genuine abuse is unchanged, and logged-in administrators remain exempt

= 3.11.1 =
* FIX: Ad breaks, news bulletins and jingles left the previous song's title and artwork on screen. Many stations blank their metadata during a break; the player now detects this and reverts to the station name and station artwork, restoring the track details when music resumes
* FIX: The same stale display occurred when metadata could not be fetched at all - a rate-limited, failed or timed-out request would leave a long-finished song on screen. These now revert to the station display too
* Any reset requires two consecutive unknown responses (about 60 seconds), so brief gaps, single failed requests and short jingles cannot make the player flicker
* A song returning afterwards is correctly re-detected, even if it is the same track that was playing before

= 3.11.0 =
* NEW: Artwork lightbox - click or tap the player artwork to view it large in a responsive modal. Shows the current track artwork when one is displayed, otherwise the station's full-size logo, with the track or station name as a caption
* Modal closes via the close button, backdrop click, or Escape; fully keyboard accessible with focus handling, and honours prefers-reduced-motion
* Station artwork is now also stored at full size so the enlarged view is sharp rather than an upscaled thumbnail

= 3.10.3 =
* FIX: Station artwork failing to change in the multi-station player when an image optimisation plugin is active. Such plugins add a `srcset` or wrap images in `<picture><source>`, which browsers prioritise over `src` - so the player's artwork swap updated an attribute the browser was ignoring. All artwork changes now clear srcset, sizes, lazy-load attributes and any `<picture>` sources
* Logged-in administrators are now exempt from the metadata rate limiter, so site testing can no longer lock out metadata for an hour

= 3.10.2 =
* FIX: Stations that broadcast a website link instead of an image in the stream's artwork field (e.g. Capital Chill sending its homepage URL) no longer suppress artwork. The URL is now validated as a real image before use; if it isn't one, Track Artwork Lookup fills the gap instead
* Artwork URLs without a file extension are verified by content type, with the result cached for 6 hours

= 3.10.1 =
* NEW: Track Artwork Lookup (opt-in, Proxy Settings page) - when a stream broadcasts the track title but no artwork (most stations), the server looks up album artwork from the iTunes Search API and displays it in the player. Cached per track, at most one lookup per song, no visitor data ever sent
* NEW: Track artwork now displays in the embedded, multi-station, and sticky players (previously only the pop-out player supported it)
* FIX: Broken "Track artwork" placeholder image could appear when a stream sent no artwork URL - inactive artwork is now guaranteed hidden, protected against lazy-load plugins, and hidden automatically if the image fails to load
* Native stream artwork (StreamUrl / SomaFM album art) continues to take priority; the lookup only runs when the stream provides none
* Default skin: artwork enlarged from 80x80 to 100x100 and raised 10px for more presence
* Default skin: extra bottom padding on desktop and mobile so the metadata bar sits fully clear below the larger artwork
* FIX: Multi-station player artwork fallback - when a station has no track artwork, the station logo from settings now reliably shows; switching stations can no longer display a stale logo from the previous station, and the artwork panel now works even when the first station in the list has no logo configured

= 3.9.27 =
* Maintenance release
* Self-hosted updates now delivered via Plugin Update Checker 5.7, with the update manifest served from GitHub for reliable, cache-proof update detection

= 3.9.12 =
* Fixed station artwork not updating when switching stations in multi-station mode
* Root cause: artwork wrapper div was not rendered when initial station had no artwork, leaving JS with no element to update
* Artwork wrapper now always rendered in HTML (hidden when empty) so station switches can show/hide it reliably
* JS switch handler now targets wrapper div directly rather than via parentElement

= 3.9.11 =
* Added live admin preview for File Player mode — fully interactive, plays audio directly in the admin
* Preview updates automatically when tracks are added, removed, reordered, or selected from the Media Library
* Preview also responds to mode toggle, skin/colour changes, and title edits
* Exposed initFilePlayer as window.mbrInitFilePlayer for admin preview integration

= 3.9.10 =
* Added resume-from-last-position for File Player using localStorage
* Current position saved every 5 seconds while playing
* Saved position restored automatically on next play via canplay event
* Position cleared on natural track completion so replay starts from the beginning
* Position preserved when switching tracks; restored when returning to a track via the track list

= 3.9.9 =
* Fixed play button immediately showing "Paused" on first click
* Root cause: browser fires a spurious pause event when audio.src is assigned on an unloaded element
* Added intendingToPlay guard flag to suppress spurious pause events during src assignment
* Improved error reporting with error code and message logged to console

= 3.9.8 =
* Fixed tracklist button overlapping the volume slider
* Tracklist button now sits in normal flex flow at the end of the controls row
* Fixed eager audio.load() at initialisation firing the error handler before user interaction
* Audio src now assigned lazily on first play click only

= 3.9.7 =
* Fixed File Player skin and appearance settings having no effect
* Root cause: missing mbr-player-inner wrapper div in render_file_player HTML output
* File Player now reads all appearance meta fields (skin, dark mode, glassmorphism, custom gradient)
* Fixed allowed skins list in render_file_player to match actual skin identifiers

= 3.9.6 =
* Fixed critical PHP fatal error on any page containing a File Player shortcode
* Removed non-existent enqueue_player_assets() call from render_file_player

= 3.9.5 =
* New feature: File Player mode for hosted audio files
* Player Mode toggle in Station Details: Live Stream or File Player
* Progress bar with click-to-seek and touch scrubbing
* Rewind 15 seconds and Forward 15 seconds buttons
* Auto-advancing playlist with numbered track list panel
* WordPress Media Library picker for adding tracks (filtered to audio files)
* Drag-to-reorder tracks using jQuery UI Sortable
* Tracks saved to post meta; player mode saved as stream or files

= 3.9.4 =
* Fixed multi-station switching stalling when switching between HLS and MP3 streams
* Extracted buildHlsInstance() factory — initial load and station switch now use identical proxy-aware HLS configuration
* Custom ProxyLoader intercepts all HLS requests, proxies HTTP URLs, rewrites manifest segment URLs
* Eliminates mixed-content blocking that caused silent stalls on HTTP streams served from HTTPS sites
* Fixed metadata polling not restarting correctly after station switch

= 3.9.3 =
* Diagnostic release for multi-station switching investigation
* Added teardownCurrentStream() with correct HLS destroy / audio element clear sequencing

= 3.9.2 =
* Fixed metadata not updating after switching stations
* Fixed player stalling when switching between different stream formats

= 3.9.1 =
* Added multi-station support with slide-up station switcher panel
* Station Group meta box for selecting grouped stations
* Instant artwork and title update on station switch; volume preserved

= 3.9.0 =
* Added pop-out player button — opens station in a compact floating window
* Popout Player Settings meta box with configurable dimensions and window title

= 3.8.8 =
* Added live preview panel to the station edit screen
* Preview updates in real time as stream URL, skin, colours, and artwork change
* Supports desktop and mobile preview viewport modes
* Preview player is fully interactive — audio plays directly in the admin

= 3.8.0 =
* Added six player skins: Default, Classic, Gradient Dark, Minimal, Retro, Slim Bar
* Dark mode and glassmorphism options for Default skin
* Custom gradient colour picker with preset colour palette
* Security and coding standards improvements

= 3.7.8 =
* Added sticky player — full-width player docked to top or bottom of viewport
* Shortcode: [mbr_sticky_player id="X"]

= 3.5.0 =
* WordPress coding standards compliance throughout
* HLS.js bundled locally — CDN dependency removed
* All output properly escaped
* Third-party library documentation added

= 1.0.0 =
* Initial release
* HLS stream support via bundled HLS.js
* Gradient player design with artwork support
* Live admin preview
* Volume controls and responsive layout
* Shortcode integration

== Upgrade Notice ==

= 3.9.11 =
Adds live admin preview for File Player mode. Fully interactive — plays audio in the admin and updates automatically as you build your playlist.

= 3.9.10 =
Adds resume-from-last-position for the File Player. Listeners automatically return to where they left off.

= 3.9.5 =
Major new feature: File Player mode. Add hosted audio files with a full playlist player, progress bar, seek controls, and resume position.

= 3.9.4 =
Important fix for multi-station HLS switching stalls caused by mixed-content blocking.

= 3.8.0 =
Multiple skins and full appearance customisation added.

== Credits ==

* HLS.js — https://github.com/video-dev/hls.js/
* Made with ❤️ by Robert Palmer — https://madebyrobert.co.uk
