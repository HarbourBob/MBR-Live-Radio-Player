=== MBR Live Radio Player ===
 * Contributors: harbourbob
 * Plugin URI: https://robertp419.sg-host.com/radio/
 * Tags: radio, player, live stream, audio, hls
 * Author: Little Web Shack
 * Author URI: https://littlewebshack.com
 * Requires at least: 5.2
 * Tested up to: 6.8
 * Stable tag: 3.8.5
 * Requires PHP: 7.2
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html

Beautiful, modern live radio player for WordPress with HLS stream support and real-time preview.

== Description ==

MBR Live Radio Player is a powerful yet simple WordPress plugin that allows you to embed unlimited beautiful live radio streams on your website. Perfect for radio stations, podcasters, churches, and anyone who wants to broadcast live audio.

= Features =

* **Beautiful Modern Design** - Stunning gradient player with smooth animations
* **HLS Stream Support** - Full support for .m3u8 HLS streams (like BBC Radio)
* **Multiple Audio Formats** - Works with MP3, AAC, M3U, OGG, and more
* **Live Preview** - See your player in real-time as you build it
* **Sticky Player** - Create unlimited full-width sticky players for top or bottom
* **Station Artwork** - Upload custom artwork for each station
* **Volume Controls** - Slider and mute button included
* **Fully Responsive** - Looks great on desktop, tablet, and mobile
* **Easy Integration** - Simple shortcode system
* **WordPress Standards** - Clean, secure, well-documented code

= Perfect For =

* Radio stations (AM/FM, Internet radio)
* Podcasters with live shows
* Churches streaming sermons
* Music venues & DJs
* Event streaming
* Corporate communications

= How It Works =

1. Install and activate the plugin
2. Go to Radio Stations → Add New
3. Enter your station name and stream URL
4. Upload artwork (optional)
5. See live preview as you build
6. Copy the shortcode and paste it anywhere

= Stream Format Support =

* HLS (.m3u8) - Adaptive streaming
* Shoutcast - .m3u playlists with automatic URL detection
* Icecast - Full metadata support
* MP3 - Standard audio
* AAC - High quality
* OGG - Open format
* And more!

= Developer Friendly =

Clean, well-documented code following WordPress coding standards. Easy to customize and extend.

== Installation ==

= From WordPress Dashboard =

1. Go to Plugins → Add New
2. Search for "MBR Live Radio Player"
3. Click "Install Now" and then "Activate"
4. Go to Radio Stations → Add New to create your first station

= Manual Installation =

1. Download the plugin ZIP file
2. Go to Plugins → Add New → Upload Plugin
3. Choose the ZIP file and click "Install Now"
4. Click "Activate Plugin"

= After Activation =

1. Navigate to Radio Stations in your WordPress dashboard
2. Click "Add New" to create your first radio station
3. Fill in the station details and stream URL
4. Upload artwork (optional)
5. Publish and copy the shortcode
6. Paste the shortcode on any page or post

== Frequently Asked Questions ==

= What stream formats are supported? =

The player supports HLS (.m3u8), MP3, AAC, OGG, and most common audio streaming formats.

= Does it work with BBC Radio streams? =

Yes! The plugin has full HLS support and works perfectly with BBC Radio and similar services.

= Can I have multiple stations? =

Absolutely! Create as many radio stations as you need, each with its own player.

= Is it mobile responsive? =

Yes, the player is fully responsive and works beautifully on all devices.

= Can I customize the appearance? =

The current version includes one beautiful gradient skin. Future versions will include multiple skins and customization options.

= Does it require any external services? =

No, the plugin works entirely within WordPress. HLS.js is bundled locally with the plugin for HLS stream support - no CDN or external dependencies required.

= Will it slow down my site? =

No! Assets only load on pages where the shortcode is used, ensuring optimal performance.

== Screenshots ==

1. Beautiful modern player with gradient designs
2. Admin interface with live preview
3. Station builder with stream URL and artwork


== Third-Party Libraries ==

This plugin includes the following third-party library:

**HLS.js v1.4.12**

* **Purpose:** Enables HLS (HTTP Live Streaming) playback in browsers without native support
* **Developer:** video-dev team (open-source project)
* **Source:** https://github.com/video-dev/hls.js
* **License:** Apache License 2.0
* **License URL:** https://github.com/video-dev/hls.js/blob/master/LICENSE
* **Privacy:** HLS.js is a client-side JavaScript library that does not collect, store, or transmit any user data. It operates entirely within the user's browser and only connects to the streaming URLs configured by the site administrator. No external services are contacted, and no tracking or analytics are performed by this library.
* **Why Included:** Required for playing .m3u8 HLS streams (like BBC Radio, SomaFM, and other modern streaming services) in Chrome, Firefox, Edge, and other browsers that lack native HLS support. Safari browsers use native HLS playback and do not require this library.
* **Data Processing:** None. The library processes audio/video data locally in the browser. No user data, analytics, or tracking information is collected or transmitted.

== Changelog ==

= 3.8.0 =
Security fixes
Minor bug fixes

= 3.7.8 =
Added a full browser-width sticky player for top or bottom

= 3.5.2 =
Minor bug fixes

= 3.5.1 =
* Removed settings page (appearance controls now on station edit screen)
* Increased popup player width from 400px to 500px for better visibility
* Cleaner admin interface with consolidated settings

= 3.5.0 =
* Fixed all WordPress.org coding standards compliance issues
* Added proper escape functions for all output
* Replaced parse_url() with wp_parse_url()
* Added phpcs:ignore comments for binary stream outputs
* Bundled HLS.js locally (no longer using CDN)
* Added comprehensive third-party library documentation
* Removed development test files
* Enhanced security and code quality

= 3.4.8 =
* Fixed popup player layout issues with theme CSS conflicts
* Added !important flags to critical flexbox layout properties
* Improved horizontal inline layout for popup player
* Clean rebuild from last known good version

= 1.9.5 =
* Fixed admin preview to use second stream from playlists (matches frontend behavior)
* Added Shoutcast URL fixing to admin preview player
* Admin preview now properly handles multiple stream URLs from Shoutcast playlists

= 1.9.4 =
* Added AAC+ content-type normalization for better browser compatibility
* Enhanced audio sync detection to support both MP3 and AAC/ADTS formats
* Updated playlist parser to prefer second stream URL (often better quality/format)
* Improved logging for stream format detection

= 1.9.3 =
* Added Shoutcast stream detection and automatic URL fixing
* Improved .m3u playlist parsing to handle Shoutcast server URLs
* Fixed issue where Shoutcast base URLs would return HTML instead of audio
* Automatically appends correct stream path (/;) for Shoutcast servers
* Enhanced URL validation with multiple common Shoutcast stream paths
* Better error logging for troubleshooting stream connection issues

= 1.9.2 =
* Transparent marquee support
* Various bug fixes and improvements

= 1.0.0 =
* Initial release
* HLS stream support
* Beautiful gradient design
* Live admin preview
* Volume controls
* Responsive design
* Shortcode integration

== Upgrade Notice ==

= 3.8.0 =
Sticky player support added

= 3.5.1 =
Streamlined admin interface and wider popup player for better user experience.

= 3.5.0 =
Major update with WordPress.org compliance improvements, bundled HLS.js library, enhanced security, and better code quality.

= 1.0.0 =
Initial release of MBR Live Radio Player.

== Credits ==

* HLS.js - https://github.com/video-dev/hls.js/
* Made with ❤️ by Little Web Shack
