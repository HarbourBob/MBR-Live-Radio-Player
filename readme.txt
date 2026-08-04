=== MBR Live Radio Player ===
Contributors: harbourbob
Plugin URI: https://littlewebshack.com/radio/
Tags: radio, player, live stream, audio, hls, podcast, mp3, file player, playlist
Author: Robert Palmer
Author URI: https://madebyrobert.co.uk
Requires at least: 5.2
Tested up to: 6.8
Stable tag: 3.9.27
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
