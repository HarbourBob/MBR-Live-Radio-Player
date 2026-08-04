# 📻 MBR Live Radio Player

### Gorgeous live radio for WordPress. Free. Forever. No catch.

![Version](https://img.shields.io/badge/version-3.9.27-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-5.2%2B-blue.svg)
![Tested](https://img.shields.io/badge/tested%20up%20to-6.8-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.2%2B-purple.svg)
![License](https://img.shields.io/badge/license-GPL--2.0%2B-green.svg)

Most WordPress audio players look like they were designed in 2009. This one doesn't.

**MBR Live Radio Player** is a beautiful, fully-featured audio player that handles live radio streams *and* Media Library playlists — with six skins, glassmorphism, custom gradients, live now-playing metadata, and a sticky player that follows your listeners around the site.

No subscriptions. No "pro" tier waiting in the wings. No data harvesting. Built by one developer in Cleethorpes, UK, who just wanted radio streaming that works.

**[⬇️ Download v3.9.27](https://littlewebshack.com/downloads/radio-player/mbr-live-radio-player-3.9.27.zip)** · **[🌐 littlewebshack.com](https://littlewebshack.com)** · **[👨‍💻 madebyrobert.co.uk](https://madebyrobert.co.uk)**

---

## 🎯 Two players in one

### 📡 Live Stream Mode
Point it at any stream URL and go. **HLS (.m3u8), Shoutcast, Icecast, MP3, AAC and OGG** all supported, with automatic format detection, a built-in CORS proxy for awkward streams, and live now-playing metadata — track titles and album artwork updating in real time as the station plays.

### 🎵 File Player Mode
Build playlists straight from your Media Library. Seek bar, 15-second skip, auto-advance, and **resume-from-last-position** — your listeners pick up exactly where they left off, even after closing the tab.

---

## ✨ Why people like it

- 🎨 **Six skins + unlimited gradients** — pick a preset, or build your own colour scheme with the WordPress colour picker. Glassmorphism and dark mode included.
- 📌 **Sticky player** — a full-width player pinned to the top or bottom of the viewport, so the music never stops while visitors browse.
- ↗️ **Pop-out player** — listeners can float the player in its own window and carry on multitasking.
- 📻 **Unlimited stations** — every station is a WordPress post with its own artwork, colours and stream. Drop any of them anywhere with a shortcode.
- 👀 **Live admin preview** — style your player in the admin and watch it update in real time. Fully interactive — it actually plays.
- ⚡ **Performance-first** — pure PHP, no external dependencies, no telemetry, GPU-accelerated effects, metadata caching. Your PageSpeed score is safe.
- 🔄 **Self-hosted updates** — updates arrive right in your WordPress dashboard via Plugin Update Checker, served from GitHub. No marketplace, no account, no nagging.

---

## 🚀 Up and running in two minutes

1. **Install** — upload the zip via *Plugins → Add New → Upload Plugin* and activate
2. **Create a station** — *Radio Stations → Add New*, give it a title, paste your stream URL, add artwork
3. **Drop it on a page:**

```
[mbr_radio_player id="123"]
```

Want it pinned to the viewport instead?

```
[mbr_radio_player_sticky id="123"]
```

Works in Gutenberg (Shortcode block), the Classic Editor, and any page builder that renders shortcodes — Elementor included. Multiple players on one page? Absolutely fine.

---

## 📻 Streams it plays nicely with

| Format | Support |
|--------|---------|
| **MP3 / AAC / OGG** | Direct stream URLs, including port-based (`:8000`) streams |
| **HLS (.m3u8)** | Native in Safari, hls.js everywhere else |
| **Shoutcast / Icecast** | Fully supported, with now-playing metadata |

Tested against major stream providers, with a built-in proxy for streams that don't send CORS headers.

---

## 💻 Requirements

WordPress **5.2+** · PHP **7.2+** · Tested up to WordPress **6.8**

Works in every modern desktop and mobile browser. HTTPS recommended (and required by some browsers for audio autoplay policies).

---

## ❓ Quick answers

**Is it really free?**
Yes. GPL-2.0-or-later, all features included, forever. There is no premium version and never will be.

**Does it phone home?**
No. No telemetry, no tracking, no external services beyond the streams you configure.

**How do updates work without WordPress.org?**
The plugin checks a GitHub-hosted manifest via Plugin Update Checker. When a new version ships, it appears on your Plugins screen like any other update — one click to install.

**My stream won't play — help?**
Nine times out of ten it's a CORS issue or an HTTP stream on an HTTPS site. Enable the built-in proxy in the station settings, or check the stream URL loads directly in a browser tab first.

---

## 💬 Support & credits

- 📧 **Email:** support@madebyrobert.co.uk
- 🌐 **More free plugins & dev tools:** [Little Web Shack](https://littlewebshack.com)
- 👨‍💻 **Hire the developer:** [Made by Robert](https://madebyrobert.co.uk)

Bundled with [hls.js](https://github.com/video-dev/hls.js/) for HLS playback in Chrome, Firefox and Edge (Safari plays HLS natively).

If this plugin saved you a licence fee, you can [☕ buy me a coffee](https://buymeacoffee.com/robertpalmer/) — entirely optional, always appreciated.

---

## 📄 License

GPL-2.0-or-later. Use it, fork it, ship it on client sites — it's yours.

---

<p align="center"><strong><a href="https://littlewebshack.com/downloads/radio-player/mbr-live-radio-player-3.9.27.zip">⬇️ Download MBR Live Radio Player v3.9.27</a></strong><br>Free. Forever. No catch.</p>
