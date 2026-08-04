# Sticky Player Feature

## Overview
The sticky player feature allows you to display a full-width radio player that stays fixed to either the top or bottom of the browser window as users scroll through your site.

## Features
- **Full browser width** - Spans the entire width of the viewport
- **Sticky positioning** - Stays fixed while users browse (top or bottom)
- **Close button** - Users can dismiss the player temporarily
- **Popout option** - Includes the popout button for separate window playback
- **Responsive design** - Adapts beautifully to mobile devices
- **Smooth animations** - Elegant slide-in/out transitions
- **All player features** - Metadata display, volume control, play/pause

## Usage

### Shortcode
```
[mbr_radio_player_sticky id="YOUR_STATION_ID"]
```

Replace `YOUR_STATION_ID` with the ID of your radio station post.

### Configuration
1. Go to **Radio Stations → Settings** in WordPress admin
2. Find the **Sticky Player Settings** section
3. Choose your preferred position:
   - **Top of page** - Player sticks to the top of the browser window
   - **Bottom of page** - Player sticks to the bottom (recommended for less intrusion)
4. Click **Save Settings**

### Appearance
The sticky player inherits the appearance settings from your station's configuration:
- Dark mode
- Glassmorphism effect
- Custom gradient colors (from station meta or global settings)

## Best Practices

### Positioning
- **Bottom position** (default) is generally less intrusive and more user-friendly
- **Top position** can work well for news/radio sites where constant access is expected

### User Experience
- The player does **not** auto-start - users must click play (prevents annoyance and respects Chrome's autoplay policies)
- Users can close the player at any time with the X button
- The close button automatically pauses playback
- Player includes a popout button to open in a separate window

### Where to Place
Add the shortcode to:
- Site-wide header/footer (via theme template)
- Widget areas
- Specific pages where you want constant radio access

## Technical Details

### CSS Classes
- `.mbr-radio-player-sticky` - Main container
- `.mbr-sticky-top` - Top-positioned variant
- `.mbr-sticky-bottom` - Bottom-positioned variant
- `.mbr-sticky-hidden` - Hidden state (after closing)

### Z-Index
The sticky player uses `z-index: 9999` to ensure it stays above most page content.

### Mobile Responsiveness
- Volume slider hidden on screens < 768px
- Compact design on screens < 480px
- Touch-friendly button sizes

## Compatibility
- Works with all WordPress themes
- Compatible with Elementor and page builders
- Supports all streaming formats (MP3, AAC, HLS, Shoutcast, Icecast)
- Chrome, Firefox, Safari, Edge compatible

## Version
Added in version 3.6.0
