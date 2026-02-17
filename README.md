# MBR Live Radio Player

A beautiful, modern live radio player for WordPress with support for HLS streams, custom gradients, glassmorphism effects, and real-time metadata display.

[![GitHub Release](https://img.shields.io/github/v/release/harbourbob/MBR-Live-Radio-Player)](https://github.com/harbourbob/MBR-Live-Radio-Player/releases)
[![GitHub Downloads](https://img.shields.io/github/downloads/harbourbob/MBR-Live-Radio-Player/total)](https://github.com/harbourbob/MBR-Live-Radio-Player/releases)
[![GitHub Stars](https://img.shields.io/github/stars/harbourbob/MBR-Live-Radio-Player?style=social)](https://github.com/harbourbob/MBR-Live-Radio-Player)
[![GitHub Forks](https://img.shields.io/github/forks/harbourbob/MBR-Live-Radio-Player?style=social)](https://github.com/harbourbob/MBR-Live-Radio-Player)
[![GitHub Issues](https://img.shields.io/github/issues/harbourbob/MBR-Live-Radio-Player)](https://github.com/harbourbob/MBR-Live-Radio-Player/issues)

---

## 📋 Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Customization](#customization)
- [Shortcodes](#shortcodes)
- [Supported Stream Formats](#supported-stream-formats)
- [Browser Support](#browser-support)
- [Frequently Asked Questions](#frequently-asked-questions)
- [Changelog](#changelog)
- [Support](#support)
- [License](#license)

---

## ✨ Features

### Player Features
- 🎵 **HLS Stream Support** - Play HTTP Live Streaming (HLS) formats with automatic fallback
- 📻 **Multiple Stream Formats** - Support for MP3, AAC, OGG, and HLS streams
- 📌 **Sticky Player** - Full-width player that stays fixed to top or bottom of viewport
- 🎨 **Real-time Metadata** - Display current track information with automatic updates
- 🖼️ **Album Artwork** - Show track artwork from streaming metadata
- 🔊 **Volume Control** - Adjustable volume slider with mute functionality
- ↗️ **Pop-out Player** - Open player in floating window for multitasking
- 📱 **Fully Responsive** - Works beautifully on desktop, tablet, and mobile devices

### Appearance Options
- 🎨 **Custom Gradients** - Choose any colors with WordPress color picker
- 🌈 **8 Preset Gradients** - Quick-select from beautiful pre-designed color schemes
- 🔮 **Glassmorphism Effect** - Modern frosted glass aesthetic with blur and transparency
- 🌓 **Dark Mode** - Elegant dark color scheme for low-light environments
- ✨ **Multiple Style Combinations** - Mix and match effects for unique looks

### Technical Features
- ⚡ **Performance Optimized** - Minimal resource usage, GPU-accelerated effects
- 🔄 **CORS Proxy** - Built-in proxy for streams that require CORS headers
- 📊 **Metadata Caching** - Efficient caching system for stream metadata
- 🔧 **HTML Entity Decoding** - Properly display apostrophes and special characters
- 🎯 **WordPress Integration** - Native WordPress custom post types and settings API

---

## 📦 Requirements

### Minimum Requirements

| Component | Requirement |
|-----------|------------|
| **WordPress** | 5.0 or higher |
| **PHP** | 7.4 or higher |
| **MySQL** | 5.6 or higher (or MariaDB 10.1+) |
| **HTTPS** | Recommended for best compatibility |

### Recommended Requirements

| Component | Recommendation |
|-----------|---------------|
| **WordPress** | 6.0 or higher |
| **PHP** | 8.0 or higher |
| **MySQL** | 5.7 or higher (or MariaDB 10.3+) |
| **Memory Limit** | 128 MB or higher |
| **HTTPS** | Required for secure streaming |

### Server Requirements

- **PHP Extensions Required:**
  - `curl` - For fetching stream metadata
  - `json` - For JSON parsing
  - `mbstring` - For character encoding
  - `allow_url_fopen` - For stream proxy functionality

- **WordPress Permissions:**
  - Ability to create custom post types
  - Ability to add rewrite rules
  - Ability to enqueue scripts and styles

### Browser Requirements

| Browser | Minimum Version |
|---------|----------------|
| **Chrome** | 76+ |
| **Firefox** | 103+ |
| **Safari** | 9+ |
| **Edge** | 79+ |
| **Opera** | 63+ |

**Note:** Internet Explorer is not supported.

---

## 🚀 Installation

### Method 1: WordPress Admin (Recommended)

1. Download the plugin ZIP file
2. Log in to your WordPress admin panel
3. Navigate to **Plugins → Add New**
4. Click **Upload Plugin** button at the top
5. Choose the downloaded ZIP file
6. Click **Install Now**
7. Click **Activate Plugin**
8. Go to **Settings → Permalinks** and click **Save Changes** (this is required for the pop-out player to work)

### Method 2: FTP Upload

1. Download and extract the plugin ZIP file
2. Connect to your server via FTP
3. Navigate to `/wp-content/plugins/`
4. Upload the `mbr-live-radio-player` folder
5. Log in to WordPress admin
6. Go to **Plugins** and activate "MBR Live Radio Player"
7. Go to **Settings → Permalinks** and click **Save Changes**

### Method 3: WP-CLI

```bash
wp plugin install mbr-live-radio-player.zip --activate
wp rewrite flush
```

### Post-Installation

After activation, you should see:
- New **"Radio Stations"** menu item in WordPress admin
- Settings page accessible via **Radio Stations → Settings**

---

## ⚙️ Configuration

### Initial Setup

1. **Create Your First Station:**
   - Go to **Radio Stations → Add New**
   - Enter station name (e.g., "Classic Rock Radio")
   - Add stream URL in the **Stream URL** field
   - Optionally add station artwork (featured image)
   - Click **Publish**

2. **Configure Appearance:**
   - Go to **Radio Stations → Settings**
   - Choose your preferred appearance options:
     - Enable/disable Dark Mode
     - Enable/disable Glassmorphism
     - Select custom gradient colors
   - Click **Save Settings**

3. **Test Your Player:**
   - Add the shortcode to any page or post:
     ```
     [mbr_radio_player id="123"]
     ```
     (Replace 123 with your station ID)

### Settings Explained

#### Player Appearance

**Dark Mode**
- Switches player to dark color scheme
- Better for low-light environments
- Automatic white text on dark background

**Glassmorphism Effect**
- Modern frosted glass aesthetic
- Background blur with transparency
- Works with both light and dark modes
- Best on pages with background images or colors

**Gradient Background**
- Customize start and end colors
- 8 beautiful presets available
- Only applies when Dark Mode and Glassmorphism are OFF
- Uses CSS variables for smooth transitions

---

## 📖 Usage

### Creating a Radio Station

1. Navigate to **Radio Stations → Add New**
2. Fill in the required fields:

   **Station Title** (Required)
   - The name of your radio station
   - Example: "Jazz FM 24/7"

   **Stream URL** (Required)
   - Direct URL to your radio stream
   - Supports: MP3, AAC, OGG, HLS (.m3u8)
   - Example: `https://example.com/stream.mp3`

   **Featured Image** (Optional)
   - Station logo or artwork
   - Recommended size: 300x300px minimum
   - Supports: JPG, PNG, WebP

   **Stream Format** (Optional)
   - Auto-detected from URL
   - Manually specify if needed: mp3, aac, ogg, hls

3. Click **Publish**

### Adding Player to Your Site

**Using Shortcode:**

Regular inline player:
```
[mbr_radio_player id="123"]
```

Sticky player (stays fixed to top/bottom of screen):
```
[mbr_radio_player_sticky id="123"]
```

**Sticky Player Setup:**
1. Create and style your radio station (artwork, colors, stream URL)
2. Go to **Radio Stations → Sticky Player**
3. Choose position (top or bottom of page)
4. Save settings
5. Use the shortcode: `[mbr_radio_player_sticky id="123"]`

The sticky player inherits all appearance settings (colors, artwork) from the station itself.

**Using Gutenberg Block:**
1. Add a new block
2. Search for "Shortcode"
3. Insert: `[mbr_radio_player id="123"]` or `[mbr_radio_player_sticky id="123"]`

**Using Classic Editor:**
1. Switch to Text mode
2. Insert: `[mbr_radio_player id="123"]`

**Using PHP in Theme:**
```php
<?php echo do_shortcode('[mbr_radio_player id="123"]'); ?>
```

**Using Widget:**
1. Go to **Appearance → Widgets**
2. Add "Custom HTML" widget
3. Insert: `[mbr_radio_player id="123"]`

### Finding Your Station ID

**Method 1: In Station List**
- Go to **Radio Stations → All Stations**
- Hover over station name
- Look at browser status bar (bottom left)
- ID is in the URL: `post=123`

**Method 2: When Editing**
- Open the station for editing
- Look at the browser URL
- ID is after `post=`: `.../post.php?post=123&action=edit`

---

## 🎨 Customization

### Appearance Combinations

The plugin offers multiple appearance options that can be combined:

#### Classic Vibrant (Default)
```
☐ Dark Mode
☐ Glassmorphism
✓ Custom Gradient Colors
```
**Result:** Solid gradient with your custom colors
**Best for:** Energetic content, bright pages

#### Dark Elegance
```
✓ Dark Mode
☐ Glassmorphism
☐ Custom Gradient Colors
```
**Result:** Solid dark navy gradient
**Best for:** Professional broadcasts, night mode

#### Light Glass
```
☐ Dark Mode
✓ Glassmorphism
☐ Custom Gradient Colors
```
**Result:** Frosted glass with light transparency
**Best for:** Modern sites, over background images

#### Dark Glass (Premium Look)
```
✓ Dark Mode
✓ Glassmorphism
☐ Custom Gradient Colors
```
**Result:** Dark frosted glass effect
**Best for:** Ultra-premium aesthetic, luxury brands

### Custom Gradient Presets

1. **Purple (Default)**
   - Colors: `#667eea` → `#764ba2`
   - Vibe: Vibrant, modern, energetic

2. **Dark Navy**
   - Colors: `#1a1a2e` → `#16213e`
   - Vibe: Professional, sophisticated

3. **Pink Sunset**
   - Colors: `#f093fb` → `#f5576c`
   - Vibe: Romantic, warm

4. **Ocean Blue**
   - Colors: `#4facfe` → `#00f2fe`
   - Vibe: Fresh, calming

5. **Mint Green**
   - Colors: `#43e97b` → `#38f9d7`
   - Vibe: Natural, energizing

6. **Warm Flame**
   - Colors: `#fa709a` → `#fee140`
   - Vibe: Hot, attention-grabbing

7. **Cosmic**
   - Colors: `#30cfd0` → `#330867`
   - Vibe: Mystical, dreamy

8. **Cotton Candy**
   - Colors: `#ff6e7f` → `#bfe9ff`
   - Vibe: Sweet, playful

### Custom CSS

Add custom styling in your theme's CSS:

```css
/* Adjust player width */
.mbr-radio-player {
    max-width: 800px;
}

/* Customize button colors */
.mbr-play-btn {
    background: #your-color !important;
}

/* Modify text color */
.mbr-player-title {
    color: #your-color !important;
}
```

---

## 📝 Shortcodes

### Main Shortcode

```
[mbr_radio_player id="STATION_ID"]
```

**Parameters:**

- `id` (required) - The ID of the radio station post

**Examples:**

Display station with ID 123:
```
[mbr_radio_player id="123"]
```

Display in a column:
```
<div class="my-column">
    [mbr_radio_player id="123"]
</div>
```

Multiple players on one page:
```
[mbr_radio_player id="123"]
[mbr_radio_player id="456"]
```

---

## 📻 Supported Stream Formats

### Direct Streaming URLs

**MP3 Streams**
```
https://example.com/stream.mp3
https://example.com:8000/radio
```

**AAC Streams**
```
https://example.com/stream.aac
https://example.com:8000/stream
```

**OGG Streams**
```
https://example.com/stream.ogg
```

### HLS (HTTP Live Streaming)

**M3U8 Playlists**
```
https://example.com/playlist.m3u8
https://example.com/stream/index.m3u8
```

**Features:**
- Adaptive bitrate streaming
- Automatic quality switching
- Better buffering
- Wide device compatibility

### Stream Providers Tested

✅ **Icecast** - Full support with metadata
✅ **SHOUTcast** - Full support with metadata
✅ **Wowza** - HLS support
✅ **SomaFM** - Full support with API integration
✅ **Radio.co** - Full support
✅ **Azuracast** - Full support
✅ **Centova Cast** - Full support

### Metadata Support

The player automatically extracts and displays:
- 🎵 Track title
- 👤 Artist name
- 💿 Album name
- 🖼️ Album artwork (when available)

Supported metadata formats:
- Icecast metadata
- SHOUTcast metadata
- HLS ID3 tags
- Custom API integrations

---

## 🌐 Browser Support

### Desktop Browsers

| Browser | Support | Notes |
|---------|---------|-------|
| Chrome 76+ | ✅ Full | All features supported |
| Firefox 103+ | ✅ Full | All features supported |
| Safari 9+ | ✅ Full | All features supported |
| Edge 79+ | ✅ Full | All features supported |
| Opera 63+ | ✅ Full | All features supported |
| IE 11 | ❌ No | Not supported |

### Mobile Browsers

| Browser | Support | Notes |
|---------|---------|-------|
| Chrome Mobile | ✅ Full | Pop-out opens in new tab |
| Safari iOS | ✅ Full | Pop-out opens in new tab |
| Firefox Mobile | ✅ Full | Pop-out opens in new tab |
| Samsung Internet | ✅ Full | Pop-out opens in new tab |

### Feature Support

| Feature | Desktop | Mobile |
|---------|---------|--------|
| Stream Playback | ✅ | ✅ |
| Volume Control | ✅ | ✅ |
| Metadata Display | ✅ | ✅ |
| Glassmorphism | ✅ | ✅ |
| Pop-out Window | ✅ | ⚠️ New Tab |
| HLS Streaming | ✅ | ✅ |

---

## ❓ Frequently Asked Questions

### General Questions

**Q: How do I find my station ID for the shortcode?**
A: There are several easy ways to find your station ID:

**Method 1: In the Station List**
1. Go to **Radio Stations → All Stations**
2. Hover over your station name
3. Look at the bottom left of your browser (status bar)
4. The ID appears in the URL: `post=123` (123 is your ID)

**Method 2: When Editing a Station**
1. Click to edit your station
2. Look at the browser's address bar
3. The ID is in the URL after `post=`: `.../post.php?post=123&action=edit`
4. In this example, your station ID is `123`

**Method 3: Quick View**
1. Go to **Radio Stations → All Stations**
2. Hover over your station name
3. The ID appears in the URL preview at the bottom of your screen

Once you have the ID, use it in your shortcode:
- Regular player: `[mbr_radio_player id="123"]`
- Sticky player: `[mbr_radio_player_sticky id="123"]`

**Q: Is this plugin free?**
A: Yes, the plugin is completely free and open source under GPL-2.0+ license.

**Q: Do I need coding knowledge to use this plugin?**
A: No! The plugin is designed to be user-friendly with an intuitive admin interface.

**Q: Can I use multiple players on one page?**
A: Yes, you can add as many players as you want using different station IDs.

**Q: Does this work with Gutenberg?**
A: Yes, use the Shortcode block and insert the player shortcode.

**Q: After installing/reinstalling the plugin, my streams don't play and I see "format not supported" errors. What's wrong?**
A: This happens because WordPress needs to refresh its URL rewrite rules for the plugin's proxy system to work. Simply go to Settings → Permalinks and click Save Changes (you don't need to change anything). Then do a hard refresh of your browser (Ctrl+Shift+F5 or Cmd+Shift+R on Mac). Your streams should work perfectly after this!
Note: You should do this whenever you:

Install or reinstall the plugin
Activate the plugin after deactivation
Update to a new version

### Technical Questions

**Q: Why do I get a 404 error when clicking the pop-out button?**
A: Go to **Settings → Permalinks** and click **Save Changes** to flush rewrite rules.

**Q: My stream isn't playing. What should I check?**
A: Verify:
1. Stream URL is correct and accessible
2. Your site uses HTTPS (required for many streams)
3. Stream is actually live and broadcasting
4. Check browser console for error messages

**Q: How do I get metadata to display?**
A: Metadata is automatically extracted from:
- Icecast streams (stream-name and stream-url headers)
- SHOUTcast streams (StreamTitle)
- HLS streams (ID3 tags)
- Custom APIs (like SomaFM)

**Q: Can I customize the player colors?**
A: Yes! Go to **Radio Stations → Settings** and use the gradient color pickers or choose from 8 presets.

**Q: Does this support HTTPS streams?**
A: Yes, HTTPS streams are fully supported and recommended.

**Q: Why isn't glassmorphism showing?**
A: Glassmorphism requires:
- Modern browser (Chrome 76+, Firefox 103+, Safari 9+)
- Background content behind the player (works best over images/colors)
- The option enabled in settings

**Q: Can I use my own domain for streaming?**
A: Yes, but ensure your stream server sends proper CORS headers or enable the built-in proxy.

### Troubleshooting

**Q: Player shows but doesn't play**
A: Check:
1. Stream URL is correct
2. Stream is currently live
3. Browser console for errors
4. CORS/mixed content issues (HTTP vs HTTPS)

**Q: Metadata shows HTML entities (like `&#039;`)**
A: Update to version 3.1.1 or higher which includes HTML entity decoding.

**Q: Custom colors don't save**
A: Ensure you:
1. Click "Save Settings" button
2. Wait for success message
3. Hard refresh browser (Ctrl+Shift+R)

**Q: Pop-out window is blank**
A: Go to **Settings → Permalinks** → **Save Changes** to flush rewrite rules.

---

## 📋 Changelog

### Version 3.7.8 (Current)
- **Added** sticky player feature with full-width layout
- **Added** sticky player settings page (position: top/bottom)
- **Added** new shortcode: `[mbr_radio_player_sticky id="123"]`
- **Improved** sticky player inherits all station appearance settings
- **Fixed** layout and button positioning for sticky player
- **Fixed** close button visibility with X icon

### Version 3.5.0
- **Removed** preview from settings page
- **Improved** settings page layout

### Version 3.2.9
- **Added** live preview color updates in settings
- **Improved** preset button functionality

### Version 3.2.8
- **Fixed** screen ID mismatch preventing JavaScript from loading
- **Fixed** color picker and preset buttons now working correctly

### Version 3.2.1
- **Fixed** critical syntax error (duplicate function)

### Version 3.2.0
- **Added** custom gradient background colors
- **Added** WordPress color picker integration
- **Added** 8 beautiful preset gradients
- **Added** CSS variables for gradient customization

### Version 3.1.1
- **Fixed** metadata truncation at apostrophes
- **Added** HTML entity decoder for metadata display
- **Improved** support for special characters in track titles

### Version 3.1.0
- **Added** glassmorphism effect option
- **Added** frosted glass aesthetic with backdrop blur
- **Added** glassmorphism compatibility with dark mode
- **Added** enhanced shadows and borders

### Version 3.0.9
- **Added** dark mode theme option
- **Added** settings page for appearance customization
- **Improved** contrast ratios for accessibility

### Version 3.0.8
- **Improved** pop-out player window size (reduced height)
- **Optimized** compact layout for better UX

### Version 3.0.7
- **Improved** pop-out player dimensions
- **Fixed** window sizing for better visibility

### Version 3.0.6
- **Fixed** pop-out button icon visibility
- **Fixed** 404 errors for popup URLs
- **Added** admin notice for permalink flushing

### Version 3.0.5
- **Added** pop-out player functionality
- **Added** floating window capability
- **Added** dedicated popup template
- **Improved** multitasking support

### Version 3.0.0
- **Added** HLS stream support
- **Added** real-time metadata display
- **Added** SomaFM API integration
- **Added** metadata caching system
- **Improved** stream compatibility

---

## 💬 Support

### Getting Help

**Documentation:**
- Full documentation included in plugin files
- README files in `/mnt/user-data/outputs/`

**Community Support:**
- WordPress.org forums (coming soon)
- GitHub Issues (coming soon)

**Professional Support:**
- Email: support@madebyrobert.co.uk
- Website: https://madebyrobert.co.uk

### Reporting Bugs

When reporting bugs, please include:
1. WordPress version
2. PHP version
3. Plugin version
4. Browser and version
5. Steps to reproduce
6. Error messages from browser console
7. Screenshots if applicable

### Feature Requests

We welcome feature requests! Please provide:
1. Detailed description of the feature
2. Use case / why it's needed
3. Examples from other plugins/sites
4. Mockups if applicable

---

## 📄 License

This plugin is licensed under the GPL v2 or later.

```
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA
```

---

## 👨‍💻 Credits

**Developed by:** Little Web Shack  
**Website:** https://littlewebshack.com
**Version:** 3.7.8  
**Last Updated:** December 2025  

### Third-Party Libraries

- **HLS.js** - For HLS stream support
- **WordPress Color Picker** - For gradient customization
- Built on WordPress core functionality

---

## 🎯 Roadmap

Planned features for future releases:

- [ ] Multiple station playlist support
- [ ] Equalizer visualization
- [ ] Favorites/bookmarking system
- [ ] Social sharing integration
- [ ] Keyboard shortcuts
- [ ] Accessibility improvements (WCAG 2.1 AA)
- [ ] More preset gradients
- [ ] Schedule-based streaming
- [ ] Analytics integration
- [ ] Multi-language support

---

## 🤝 Contributing

Contributions are welcome! If you'd like to contribute:

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Submit a pull request

Please follow WordPress coding standards and include documentation for new features.

---

**Made with ❤️ by Little Web Shack**

For more WordPress plugins and themes, visit [Little Web Shack](https://littlewebshack.com)
