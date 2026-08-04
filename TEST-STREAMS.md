# Test Stream URLs for MBR Live Radio Player

## ⚠️ IMPORTANT: Mixed Content Security

Your WordPress site uses HTTPS, so all stream URLs must also use HTTPS. 
HTTP streams will be blocked by modern browsers for security reasons.

# Test Stream URLs for MBR Live Radio Player

## ⚠️ HTTP Streams on HTTPS Sites

Good news! The plugin now includes a **built-in proxy** that automatically converts HTTP streams to HTTPS. You can use HTTP streams without any issues!

## ✅ Working Test Streams (HTTP - Will Auto-Proxy)

### UK Commercial Stations

**Capital UK (HTTP - MP3)**
```
http://media-ice.musicradio.com/CapitalMP3
```

**Classic FM (HTTP - MP3)**
```
http://media-ice.musicradio.com/ClassicFMMP3
```

**LBC UK (HTTP - MP3)**
```
http://media-ice.musicradio.com/LBCUKMP3Low
```

**Heart FM (HTTP - MP3)**
```
http://media-ice.musicradio.com/HeartNorthWest
```

**Smooth Radio (HTTP - MP3)**
```
http://media-ice.musicradio.com/SmoothUKMP3
```

## 🎙️ Shoutcast Streams

**Shoutcast Playlist Example**
```
http://yp.shoutcast.com/sbin/tunein-station.m3u?id=1206978
```
*Note: Shoutcast uses .m3u playlists that point to the actual stream. The plugin automatically fetches and parses these playlists.*

## 🌍 Stream Directories

**BBC Streams (M3U8)**
```
https://gist.github.com/bpsib/67089b959e4fa898af69fea59ad74bc3
```

**ShoutCast Directory (M3U)**
```
https://directory.shoutcast.com/
```

**IceCast Directory (MP3)**
```
https://dir.xiph.org/codecs/MP3
```

**Directory of UK Small DAB Radio Stations (AAC / MP3)**
```
http://www.radiofeeds.co.uk/aac.asp
```


## 📝 Notes

- **HTTP streams work perfectly** thanks to the built-in proxy!
- The proxy is enabled by default and automatically converts HTTP streams to HTTPS
- HTTPS streams play directly without proxy
- **Shoutcast and Icecast streams** are fully supported with metadata extraction
- **.m3u playlists** are automatically fetched and parsed to get the actual stream URL
- You can enable/disable the proxy in: Radio Stations → Proxy Settings
- Most UK commercial radio uses HTTP MP3 streams at 128kbps
- All stream URLs have been tested and work correctly

## 🔧 Proxy Settings

Access proxy settings in your WordPress admin:
**Radio Stations → Proxy Settings**

Options:
- Enable/disable proxy
- Proxy HTTP streams only (recommended)
- Proxy all streams

## 🎵 More Stream Sources

- **Radio Browser:** https://www.radio-browser.info/
- **Internet Radio UK:** https://www.internetradiouk.com/
- **GarfNet:** https://garfnet.org.uk/cms/tables/radio-frequencies/internet-radio-player/

## 🐛 Troubleshooting

**Stream not playing?**
1. Check the proxy is enabled (Radio Stations → Proxy Settings)
2. Try the stream URL in VLC to verify it works
3. Check browser console (F12) for errors
4. Some streams may be geo-restricted

**Mixed content error?**
- The proxy should handle this automatically
- Make sure proxy is enabled in settings
- HTTP streams will be automatically converted to HTTPS
