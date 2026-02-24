# MBR Live Radio Player

<div align="center">

![MBR Live Radio Player](https://img.shields.io/badge/MBR-Live%20Radio%20Player-667eea?style=for-the-badge&logo=radio&logoColor=white)

**A beautiful, fully-featured audio player plugin for WordPress**  
*Live radio streams · HLS · File player · Multi-station switching · Sticky player*

[![WordPress Plugin Version](https://img.shields.io/badge/version-3.9.26-blue?style=flat-square&logo=wordpress)](https://github.com/harbourbob/mbr-live-radio-player/releases)
[![WordPress Tested](https://img.shields.io/badge/WordPress-5.2%20–%206.8-21759b?style=flat-square&logo=wordpress&logoColor=white)](https://wordpress.org)
[![PHP Version](https://img.shields.io/badge/PHP-7.2%2B-777bb4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![License](https://img.shields.io/badge/license-GPL--2.0%2B-green?style=flat-square)](https://www.gnu.org/licenses/gpl-2.0.html)
[![GitHub Downloads](https://img.shields.io/github/downloads/harbourbob/mbr-live-radio-player/total?style=flat-square&color=brightgreen&logo=github)](https://github.com/harbourbob/mbr-live-radio-player/releases)
[![GitHub Stars](https://img.shields.io/github/stars/harbourbob/mbr-live-radio-player?style=flat-square&color=yellow&logo=github)](https://github.com/harbourbob/mbr-live-radio-player/stargazers)
[![GitHub Issues](https://img.shields.io/github/issues/harbourbob/mbr-live-radio-player?style=flat-square&logo=github)](https://github.com/harbourbob/mbr-live-radio-player/issues)
[![Maintained](https://img.shields.io/badge/maintained-actively-brightgreen?style=flat-square)](https://github.com/harbourbob/mbr-live-radio-player/commits/main)

</div>

---

<div align="center">

![MBR Live Radio Player Screenshot](mbr-radio-1.webp)

</div>

---

## 📋 Table of Contents

- [Overview](#-overview)
- [Features](#-features)
- [Player Modes](#-player-modes)
- [Appearance & Skins](#-appearance--skins)
- [Requirements](#-requirements)
- [Installation](#-installation)
- [Quick Start](#-quick-start)
- [Shortcode Reference](#-shortcode-reference)
- [Multi-Station Groups](#-multi-station-groups)
- [Sticky Player](#-sticky-player)
- [Stream Format Support](#-stream-format-support)
- [Proxy & Mixed Content](#-proxy--mixed-content)
- [Admin Preview](#-admin-preview)
- [Browser Support](#-browser-support)
- [FAQ](#-frequently-asked-questions)
- [Changelog](#-changelog)
- [Contributing](#-contributing)
- [Support](#-support)
- [License](#-license)

---

## 🎙️ Overview

**MBR Live Radio Player** is a professional-grade WordPress plugin built for anyone who needs to embed audio on their website without compromise. Whether you're running a 24/7 internet radio station, streaming live church services, or publishing a podcast archive, this plugin delivers the right tool for the job.

There are no premium tiers, no locked features, no upsells. Everything described here is included, free, forever.

Built by [Little Web Shack](https://littlewebshack.com) and released under GPL-2.0+ — because good tools should be accessible to everyone.

---

## ✨ Features

### 🎵 Audio Playback
- **HLS (HTTP Live Streaming)** — adaptive bitrate streaming via bundled HLS.js (no CDN dependency)
- **Shoutcast & Icecast** — full ICY metadata support with automatic stream URL detection from .m3u playlists
- **MP3 / AAC / OGG / FLAC** — all browser-native audio formats supported
- **File Player** — full-featured audio file player with progress bar, seek scrubbing, and playlist
- **15-minute reconnection timer** — automatic preventive reconnection to avoid server-side timeout drops

### 📻 Live Stream Features
- Real-time now-playing metadata display with scrolling marquee
- Animated status indicator (live dot) while streaming
- SomaFM API integration for rich metadata on SomaFM stations
- Automatic metadata polling with intelligent caching

### 📁 File Player Features
- Upload audio files directly from the WordPress Media Library
- Multiple files per station, organised as a numbered playlist
- Drag-and-drop reorder in the admin
- Progress bar with click-to-seek and touch scrubbing
- Rewind 15 seconds / Forward 15 seconds buttons
- Auto-advance to next track on completion
- Browseable track list panel (slide-up)
- **Resume from last position** — localStorage saves exact position per track, per listener; clears automatically on completion

### 🎛️ Player Controls
- Play / Pause with animated loading spinner
- Volume slider with mute toggle
- Pop-out floating window — keeps audio playing while visitors browse elsewhere
- Multi-station switcher panel — switch streams without leaving the page
- Sticky player — docks to top or bottom of page, always accessible
- Keyboard-accessible controls throughout

### 🖼️ Appearance & Customisation
- **6 professionally designed skins** — Default, Classic, Gradient Dark, Minimal, Retro, Slim Bar
- Custom gradient colour picker with 8 built-in presets (Default skin)
- Dark mode variant
- Glassmorphism (frosted glass) variant — stunning over background images
- Station artwork via WordPress Featured Image
- Fully responsive — pixel-perfect on desktop, tablet, and mobile

### ⚙️ Technical
- **Live admin preview** — see exactly what visitors will see before publishing, with a fully interactive player inside the WordPress admin
- Assets only enqueued on pages where a shortcode is present — zero performance impact elsewhere
- Built-in CORS proxy for streams with cross-origin restrictions
- Automatic HTTP→HTTPS proxy routing for mixed-content streams on HTTPS sites
- Clean, documented code following WordPress coding standards throughout
- No CDN dependencies — all JavaScript bundled locally

---

## 🎛️ Player Modes

### Live Stream Mode

Connect to any internet radio stream and the player handles everything: stream negotiation, format detection, metadata polling, and reconnection. Set the stream URL in the admin, publish, and embed the shortcode — that's it.

Supports virtually every stream format in common use. If your stream plays in VLC, it will play in MBR Live Radio Player.

### File Player Mode

Switch any station into File Player mode in the admin and it becomes a polished audio file player — complete with progress bar, playlist, seek, skip controls, and position memory. Ideal for:

- Podcast episode archives
- Sermon recordings
- Lecture or educational audio series
- Music samples or demos

Both modes share the same skins, colour options, artwork, and shortcode system.

---

## 🎨 Appearance & Skins

Six skins are available for every station:

| Skin | Description |
|------|-------------|
| **Default** | Clean modern card with customisable gradient and artwork |
| **Classic** | Traditional bar layout with horizontal controls |
| **Gradient Dark** | Rich dark background with gradient accent |
| **Minimal** | Stripped back — just the essentials |
| **Retro** | Warm retro-inspired aesthetic |
| **Slim Bar** | Compact single-row bar, ideal for headers and footers |

All skins support artwork, dark mode, glassmorphism, and responsive layout. The Default skin additionally supports custom gradient colours and 8 presets.

---

## 📦 Requirements

| Component | Minimum | Recommended |
|-----------|---------|-------------|
| **WordPress** | 5.2 | 6.4+ |
| **PHP** | 7.2 | 8.1+ |
| **MySQL** | 5.6 | 8.0+ |
| **PHP Extensions** | `curl`, `json` | + `mbstring` |
| **HTTPS** | Recommended | Required for mixed-content streams |

---

## 🚀 Installation

### Method 1: Upload via WordPress Admin

1. Download the latest ZIP from [GitHub Releases](https://github.com/harbourbob/mbr-live-radio-player/releases)
2. Go to **Plugins → Add New → Upload Plugin**
3. Choose the ZIP file and click **Install Now**
4. **Activate** the plugin
5. Go to **Settings → Permalinks** and click **Save Changes** *(required to register the proxy and pop-out rewrite rules)*

### Method 2: FTP

1. Extract the ZIP and upload the `mbr-radio-player-live` folder to `/wp-content/plugins/`
2. Activate via **Plugins** in WordPress admin
3. Go to **Settings → Permalinks → Save Changes**

### Method 3: WP-CLI

```bash
wp plugin install mbr-live-radio-player.zip --activate
wp rewrite flush
```

> **Important:** Always flush permalink rules after installation, activation, or updates — either via **Settings → Permalinks → Save Changes** or `wp rewrite flush`. This registers the built-in proxy and pop-out player routes.

---

## ⚡ Quick Start

### 1. Create a station

Go to **Radio Stations → Add New**, give it a name, paste in your stream URL, and click **Publish**.

### 2. Embed the player

Copy your station's post ID (visible in the URL when editing: `post=123`) and add the shortcode to any page, post, or widget:

```
[mbr_radio_player id="123"]
```

### 3. Done

Your player is live. Visit the page and click play.

---

## 📝 Shortcode Reference

### Standard Player

```
[mbr_radio_player id="123"]
```

Embeds the player inline, using whatever skin and settings are configured for that station.

### Sticky Player

```
[mbr_radio_player id="123" sticky="true"]
```

Or use the dedicated sticky shortcode:

```
[mbr_radio_player_sticky id="123"]
```

Renders a full-width player docked to the top or bottom of the viewport. Position is set in **Radio Stations → Settings → Sticky Player**.

### PHP Template Usage

```php
<?php echo do_shortcode('[mbr_radio_player id="123"]'); ?>
```

---

## 📻 Multi-Station Groups

Group multiple stations together so visitors can switch between streams without leaving the page. A station-switching panel slides up inside the player, listing all stations in the group with their artwork.

**How to set up a group:**

1. Create all your stations (each with its own stream URL and artwork)
2. In the admin sidebar, go to **Radio Station Groups → Add New**
3. Give the group a name and select which stations to include
4. On your primary station, set the **Station Group** field to this group
5. Use that station's shortcode — the switcher panel will appear automatically

**Switching behaviour:**
- Clicking a station in the list switches the stream immediately with no page reload
- Artwork, metadata, and title all update to reflect the new station
- The starting station is always available in the list — tap it to return after switching
- The sticky player (if present) stays in sync

---

## 📌 Sticky Player

The sticky player docks to the top or bottom of every page on your site and keeps audio controls permanently accessible as visitors browse.

**Setup:**

1. Go to **Radio Stations → Settings → Sticky Player**
2. Choose **Top** or **Bottom** position
3. Select the station to use
4. Save — the sticky player appears automatically site-wide

The sticky player inherits all station settings (artwork, colours, skin) and continues playing through page navigation.

---

## 📡 Stream Format Support

| Format | Details |
|--------|---------|
| **HLS (.m3u8)** | Adaptive bitrate via HLS.js — including BBC, UK DAB stations, and most major broadcasters |
| **Shoutcast** | .m3u playlist detection, ICY metadata, auto stream URL resolution |
| **Icecast** | Full ICY metadata headers, stream-name and stream-url extraction |
| **MP3** | Direct HTTP/HTTPS streams |
| **AAC / AAC+** | Direct HTTP/HTTPS streams |
| **OGG Vorbis** | Browser-native support |
| **Audio files** | MP3, AAC, OGG, FLAC, WAV via File Player mode |

If your stream plays in a browser or VLC, it will work with MBR Live Radio Player.

---

## 🔒 Proxy & Mixed Content

Many internet radio streams still serve over HTTP. If your WordPress site is on HTTPS (which it should be), the browser will block HTTP audio as mixed content.

MBR Live Radio Player includes a **built-in server-side proxy** that routes HTTP streams through your WordPress server, solving mixed-content blocking transparently — no configuration needed.

The proxy also handles CORS issues for streams that don't send cross-origin headers.

**How it works:**
- HTTP stream URLs are automatically detected
- On HTTPS sites, they are transparently routed through the plugin's proxy endpoint
- HLS streams are fully supported — both the manifest and all segments are proxied correctly
- Multi-station switching correctly re-evaluates each stream URL against the proxy on every switch

> **Note:** The proxy adds a small amount of server load proportional to the number of simultaneous listeners. For high-traffic deployments, a dedicated streaming CDN is recommended.

---

## 🖥️ Admin Preview

Every station has a **fully interactive live preview** in the WordPress admin. As you edit — change the skin, adjust colours, upload artwork, add tracks — the preview updates in real time. You can click play and listen before you publish.

This means:
- No guessing what it will look like on the front end
- Test your stream URL directly in the admin
- Preview File Player playlists and verify track order before going live
- See exactly how your chosen skin renders with your artwork and colours

---

## 🌐 Browser Support

| Browser | Minimum | Notes |
|---------|---------|-------|
| **Chrome** | 76+ | Full support including HLS |
| **Firefox** | 103+ | Full support including HLS |
| **Safari** | 9+ | Native HLS support on Apple devices |
| **Edge** | 79+ | Full support |
| **Opera** | 63+ | Full support |
| **iOS Safari** | 9+ | Native HLS, touch controls |
| **Android Chrome** | 76+ | Full support |

Internet Explorer is not supported.

---

## ❓ Frequently Asked Questions

**Is this plugin completely free?**  
Yes. No premium version, no feature locks, no upsells — ever.

**Can I use multiple players on one page?**  
Yes. Each shortcode is independently initialised. You can have as many players as you like, each with different stations and settings.

**My streams aren't playing after installation or update. What do I do?**  
Go to **Settings → Permalinks** and click **Save Changes** without changing anything. Then hard-refresh your browser (Ctrl+Shift+F5 / Cmd+Shift+R on Mac). This flushes the rewrite rules that register the proxy and pop-out player endpoints. Do this after every install, update, or reactivation.

**The pop-out window shows a 404.**  
Same fix — **Settings → Permalinks → Save Changes** to flush rewrite rules.

**Why won't my BBC or UK DAB station play?**  
BBC and many UK DAB stations serve HLS streams over HTTP. On an HTTPS site, browsers block these as mixed content. The plugin's built-in proxy handles this automatically — ensure **Proxy** is enabled in **Radio Stations → Settings**.

**My stream plays but no metadata is showing.**  
Metadata is extracted automatically from Icecast (ICY headers), Shoutcast (StreamTitle), and SomaFM (API). Some streams don't broadcast metadata at all. Check the browser console for polling errors.

**Glassmorphism doesn't seem to be doing anything.**  
Glassmorphism requires content behind the player. It looks best on pages with a background image or strong colour. Also requires Chrome 76+, Firefox 103+, or Safari 9+.

**Can I use this with Gutenberg, Elementor, or other page builders?**  
Yes. Use any Shortcode block or HTML widget and insert `[mbr_radio_player id="123"]`.

**Does resume-from-position work across different devices?**  
No — position is stored in the browser's localStorage, so it's per-device and per-browser. No server-side storage and no user accounts required.

**Is this plugin GDPR compliant?**  
The plugin only uses localStorage for the optional resume-from-position feature in File Player mode, and only when the listener has interacted with the player. No data is sent to third parties. No cookies are set.

---

## 📋 Changelog

### 3.9.26
- **Fixed** Multi-station HLS proxy — HTTP streams now correctly proxied on station switch regardless of the starting station's protocol

### 3.9.25
- **Removed** Track artwork overlay feature — standard Icecast/Shoutcast metadata does not carry artwork; the feature was producing visual artefacts

### 3.9.23
- **Fixed** Station list scroll clipped by parent `overflow: hidden` — JS now toggles `overflow: visible` on open and closed states

### 3.9.19 – 3.9.22
- **Fixed** Station list scrollbar positioning and panel overflow across multiple CSS/HTML iterations
- **Improved** Slim 4px custom scrollbar, `overscroll-behavior: contain`, touch scroll support

### 3.9.17
- **Fixed** Premier/starting station now clickable in the switcher after switching away

### 3.9.16
- **Fixed** Sticky player stripped of `data-station-group` before init — prevents phantom switcher panel intercepting clicks on the main multi-station player

### 3.9.14 – 3.9.15
- **Fixed** Station artwork `querySelector` scoped correctly — prevented sticky player's element being updated instead of the main player

### 3.9.12 – 3.9.13
- **Fixed** Artwork wrapper always rendered in DOM (hidden when empty) so JS always has a target element

### 3.9.11
- **Fixed** Autoplay blocking — `intendingToPlay` guard prevents premature play attempts
- **Added** localStorage resume-from-position (GDPR-aware) for File Player mode
- **Added** Admin preview for File Player mode

### 3.9.5 – 3.9.10
- **Added** File Player mode — progress bar, seek, rewind/forward, auto-advance playlist, Media Library integration

### 3.9.4
- **Fixed** HLS stream switching — streams now correctly tear down and reinitialise on station switch

### 3.9.3
- **Fixed** Mixed-content blocking for HTTP HLS streams on HTTPS sites

### 3.9.0 – 3.9.2
- **Added** Multi-station group support with slide-up station switcher
- **Added** Station artwork updates on switch
- **Added** Six visual skins

### 3.8.8
- **Added** Visual skins, metadata polling improvements, popout player improvements, classic skin resize

### 3.7.8
- **Added** Sticky player with top/bottom position option and dedicated shortcode

### 3.5.0
- **Improved** Settings page layout

### 3.2.0
- **Added** Custom gradient colour picker with 8 presets and WordPress colour picker integration

### 3.1.1
- **Fixed** Metadata truncation at apostrophes and special characters

### 3.1.0
- **Added** Glassmorphism (frosted glass) effect

### 3.0.9
- **Added** Dark mode

### 3.0.5
- **Added** Pop-out floating player window

### 3.0.0
- **Added** HLS stream support via HLS.js, real-time metadata, SomaFM API, metadata caching

---

## 🤝 Contributing

Contributions are welcome and appreciated. If you've found a bug, have an idea, or want to improve something:

1. **Fork** the repository
2. **Create a branch** — `git checkout -b fix/your-fix-name`
3. **Make your changes**, following [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/)
4. **Submit a pull request** with a clear description of what changed and why

For bug reports, please include: WordPress version, PHP version, plugin version, browser, steps to reproduce, and any relevant console output.

For feature requests, open a GitHub Issue describing what you're trying to achieve and why the current plugin doesn't address it.

---

## 💬 Support

| Channel | Details |
|---------|---------|
| 🐛 **Bug Reports** | [GitHub Issues](https://github.com/harbourbob/mbr-live-radio-player/issues) |
| 💡 **Feature Requests** | [GitHub Issues](https://github.com/harbourbob/mbr-live-radio-player/issues) |
| 🌐 **Website** | [littlewebshack.com](https://littlewebshack.com) |
| 📧 **Email** | support@madebyrobert.co.uk |
| 👨‍💻 **Developer** | [madebyrobert.co.uk](https://madebyrobert.co.uk) |

---

## 📄 License

MBR Live Radio Player is free software, released under the [GNU General Public License v2.0 or later](https://www.gnu.org/licenses/gpl-2.0.html).

You are free to use, modify, and distribute this plugin in accordance with that licence.

---

## 🙏 Credits

Developed with care by **Robert Palmer** at [Little Web Shack](https://littlewebshack.com), Cleethorpes, England.

**Third-party libraries:**
- [HLS.js](https://github.com/video-dev/hls.js/) — MIT Licence — bundled locally, no CDN
- WordPress Color Picker — bundled with WordPress core

---

<div align="center">

**Made with ❤️ in Cleethorpes, England**

[littlewebshack.com](https://littlewebshack.com) · [madebyrobert.co.uk](https://madebyrobert.co.uk)

*No premium version. No upsells. Just a good plugin.*

</div>
