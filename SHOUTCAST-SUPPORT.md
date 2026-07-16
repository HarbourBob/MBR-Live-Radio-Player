# Shoutcast Support in MBR Live Radio Player

## ✅ Current Status: FULLY SUPPORTED

Your MBR Live Radio Player **already has complete Shoutcast support** built-in! No code changes are needed.

## How It Works

### 1. **Playlist Parsing** (Already Implemented)
- Shoutcast URLs typically use `.m3u` playlist files (e.g., `http://yp.shoutcast.com/sbin/tunein-station.m3u?id=1206978`)
- Your player automatically detects `.m3u` URLs (see `player.js` line 143)
- The plugin fetches the playlist and extracts the actual stream URL
- If the playlist is HTTP and your site is HTTPS, it automatically uses the proxy

**Code Location:** `/assets/js/player.js` lines 143-197

```javascript
// Check if stream is a playlist that needs parsing
if (streamUrl.indexOf('.m3u') !== -1 && streamUrl.indexOf('.m3u8') === -1) {
    // This is a Shoutcast/Icecast .m3u playlist, fetch and parse it
    console.log('Detected .m3u playlist, fetching actual stream URL...');
    // ... fetches and parses the playlist ...
}
```

### 2. **Metadata Extraction** (Already Implemented)
- Shoutcast uses the ICY metadata protocol (same as Icecast)
- Your proxy already sends the `Icy-MetaData: 1` header to request metadata
- The proxy parses the `icy-metaint` header and extracts metadata blocks
- Metadata is parsed for `StreamTitle` and `StreamUrl` fields

**Code Location:** `/includes/class-proxy.php` lines 136-280

```php
// Request Icecast metadata
curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
    'Icy-MetaData: 1'
));

// Parse metadata from response
if ( preg_match( "/StreamTitle='([^']*)'/", $metadata_string, $matches ) ) {
    $meta_array['title'] = trim( $matches[1] );
}
```

### 3. **HTTP to HTTPS Proxy** (Already Implemented)
- Most Shoutcast streams use HTTP
- Your plugin automatically proxies HTTP streams on HTTPS sites
- The proxy maintains the stream connection and forwards it securely

**Code Location:** `/includes/class-proxy.php` lines 300-600

## Testing Shoutcast Streams

### Example Shoutcast URL
```
http://yp.shoutcast.com/sbin/tunein-station.m3u?id=1206978
```

### What Happens When You Use This URL:

1. **Player detects .m3u playlist** → Fetches the playlist file
2. **Playlist contains actual stream URL** → Extracts the stream URL (e.g., `http://example.com:8000/stream`)
3. **Detects HTTP stream** → Routes through proxy if needed
4. **Connects to stream** → Sends `Icy-MetaData: 1` header
5. **Receives metadata** → Parses and displays current track info

## Supported Shoutcast Features

✅ **M3U playlist parsing**
✅ **HTTP to HTTPS proxying**
✅ **ICY metadata extraction** (StreamTitle, StreamUrl)
✅ **Real-time track updates**
✅ **Album artwork** (if StreamUrl points to artwork)
✅ **Cross-origin streaming** (CORS handled)

## Common Shoutcast URL Formats

### Format 1: Playlist URL
```
http://yp.shoutcast.com/sbin/tunein-station.m3u?id=[STATION_ID]
```
✅ Automatically parsed

### Format 2: Direct Stream URL
```
http://example.com:8000/stream
```
✅ Works directly

### Format 3: Directory Listing
```
http://example.com:8000/
```
✅ May require manual stream path

## Metadata Display

Your player will display:
- **Artist - Title** format from `StreamTitle` field
- **Album artwork** from `StreamUrl` field (if provided)
- **Live updates** as tracks change

Example metadata:
```
StreamTitle='Artist Name - Song Title'
StreamUrl='https://example.com/artwork.jpg'
```

## Proxy Configuration

Access proxy settings in WordPress admin:
**Radio Stations → Proxy Settings**

Recommended settings for Shoutcast:
- ✅ Enable Stream Proxy: **Checked**
- ✅ Proxy Mode: **HTTP streams only** (Recommended)

## Troubleshooting

### Stream Not Playing?

1. **Check the URL in VLC Media Player first**
   - If it doesn't work in VLC, the stream may be offline or geo-restricted

2. **Check browser console (F12)**
   - Look for playlist fetch errors
   - Look for stream connection errors

3. **Verify proxy is enabled**
   - Go to Radio Stations → Proxy Settings
   - Ensure "Enable Stream Proxy" is checked

4. **Test the playlist URL directly**
   - Open the .m3u URL in a browser
   - Verify it returns a valid stream URL

### No Metadata Showing?

1. **Check if the stream supports metadata**
   - Not all Shoutcast streams send metadata
   - Test in VLC: View → Playlist → Right-click stream → Information

2. **Check WordPress error logs**
   - Look for "MBR Metadata:" entries
   - These show metadata extraction attempts

3. **Try a known-good stream**
   - Use one from TEST-STREAMS.md to verify functionality

## Technical Notes

### ICY Protocol
Both Shoutcast and Icecast use the ICY metadata protocol:
- Client sends: `Icy-MetaData: 1` header
- Server responds with: `icy-metaint: [bytes]` header
- Metadata is inserted every N bytes in the stream
- Format: Length byte + metadata string (null-padded to 16-byte blocks)

### Your Implementation
Your proxy handles this elegantly:
1. Sends the ICY header request
2. Reads the metaint value from headers
3. Fetches just enough data to capture metadata
4. Parses the metadata block
5. Caches it in a WordPress transient (5 min expiry)
6. Provides it to the frontend via AJAX

## Summary

🎉 **Your plugin already has full Shoutcast support!**

No code changes needed. Just use Shoutcast URLs in your radio station settings, and everything will work automatically:
- ✅ Playlist parsing
- ✅ Metadata extraction
- ✅ HTTP/HTTPS handling
- ✅ Real-time track updates

The example URL you provided will work perfectly as-is.
