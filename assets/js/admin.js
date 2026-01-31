/**
 * Admin JavaScript for MBR Live Radio Player
 */

(function($) {
    'use strict';
    
    // Fix Shoutcast URLs that point to server root
    function fixShoutcastUrl(url) {
        try {
            var urlObj = new URL(url);
            
            // Check if this looks like a Shoutcast base URL (has port, no path or root path)
            var hasPort = urlObj.port !== '';
            var isRoot = urlObj.pathname === '' || urlObj.pathname === '/';
            
            if (hasPort && isRoot) {
                // This might be a Shoutcast server root, append default stream path
                console.log('Admin: Detected potential Shoutcast base URL, appending /;');
                return url + (url.endsWith('/') ? ';' : '/;');
            }
        } catch (e) {
            console.error('Admin: Error parsing URL:', e);
        }
        
        return url;
    }
    
    $(document).ready(function() {
        // Live preview update
        function updatePreview() {
            console.log('MBR Admin: updatePreview called');
            
            var title = $('#title').val() || 'Station Name';
            var streamUrl = $('#mbr_lrp_stream_url').val();
            var $container = $('#mbr-lrp-preview-container');
            
            if (!streamUrl) {
                $container.html('<p class="mbr-lrp-preview-notice">Enter a stream URL and station title to see the preview.</p>');
                return;
            }
            
            // Get featured image
            var artworkUrl = '';
            var $featuredImg = $('#set-post-thumbnail img');
            if ($featuredImg.length) {
                artworkUrl = $featuredImg.attr('src');
            }
            
            // Get appearance settings
            var darkMode = $('input[name="mbr_lrp_dark_mode"]').is(':checked');
            var glassmorphism = $('input[name="mbr_lrp_glassmorphism"]').is(':checked');
            var gradientColor1 = $('input[name="mbr_lrp_gradient_color_1"]').val() || '#667eea';
            var gradientColor2 = $('input[name="mbr_lrp_gradient_color_2"]').val() || '#764ba2';
            
            console.log('MBR Admin: Dark mode:', darkMode);
            console.log('MBR Admin: Glassmorphism:', glassmorphism);
            console.log('MBR Admin: Gradient colors:', gradientColor1, gradientColor2);
            
            // Build classes
            var playerClasses = 'mbr-radio-player';
            if (darkMode) playerClasses += ' mbr-dark-mode';
            if (glassmorphism) playerClasses += ' mbr-glassmorphism';
            
            console.log('MBR Admin: Player classes:', playerClasses);
            
            // Build gradient styles
            var gradientStyles = '';
            if (!darkMode && !glassmorphism) {
                gradientStyles = '#mbr-lrp-preview-container .mbr-radio-player .mbr-player-inner { ' +
                    'background: linear-gradient(135deg, ' + gradientColor1 + ' 0%, ' + gradientColor2 + ' 100%) !important; ' +
                '}';
            }
            
            console.log('MBR Admin: Gradient styles:', gradientStyles);
            
            // Build preview HTML
            var artworkHtml = artworkUrl ? 
                '<div class="mbr-player-artwork"><img src="' + artworkUrl + '" alt="' + title + '" /></div>' : '';
            
            var html = '<style>' +
                '/* Critical CSS - Force icon visibility in admin preview */' +
                '#mbr-lrp-preview-container .mbr-play-btn .mbr-icon-pause { display: none !important; }' +
                '#mbr-lrp-preview-container .mbr-radio-player.playing .mbr-play-btn .mbr-icon-play { display: none !important; }' +
                '#mbr-lrp-preview-container .mbr-radio-player.playing .mbr-play-btn .mbr-icon-pause { display: block !important; }' +
                '#mbr-lrp-preview-container .mbr-radio-player.loading .mbr-play-btn .mbr-icon { display: none !important; }' +
                '#mbr-lrp-preview-container .mbr-radio-player.loading .mbr-play-btn .mbr-loading-spinner { display: block !important; }' +
                '#mbr-lrp-preview-container .mbr-volume-btn .mbr-icon-volume-muted { display: none !important; }' +
                '#mbr-lrp-preview-container .mbr-radio-player.muted .mbr-volume-btn .mbr-icon-volume-high { display: none !important; }' +
                '#mbr-lrp-preview-container .mbr-radio-player.muted .mbr-volume-btn .mbr-icon-volume-muted { display: block !important; }' +
                gradientStyles +
                '</style>' +
                '<div class="' + playerClasses + '" data-stream="' + streamUrl + '">' +
                '<div class="mbr-player-inner">' +
                    artworkHtml +
                    '<div class="mbr-player-info">' +
                        '<h3 class="mbr-player-title">' + title + '</h3>' +
                        '<p class="mbr-player-status">' +
                            '<span class="mbr-status-dot"></span>' +
                            '<span class="mbr-status-text">Ready to play</span>' +
                        '</p>' +
                    '</div>' +
                    '<div class="mbr-player-controls">' +
                        '<button class="mbr-play-btn" aria-label="Play">' +
                            '<svg class="mbr-icon mbr-icon-play" viewBox="0 0 24 24" fill="currentColor">' +
                                '<path d="M8 5v14l11-7z"/>' +
                            '</svg>' +
                            '<svg class="mbr-icon mbr-icon-pause" viewBox="0 0 24 24" fill="currentColor">' +
                                '<path d="M6 4h4v16H6V4zm8 0h4v16h-4V4z"/>' +
                            '</svg>' +
                            '<div class="mbr-loading-spinner"></div>' +
                        '</button>' +
                        '<div class="mbr-volume-control">' +
                            '<button class="mbr-volume-btn" aria-label="Mute">' +
                                '<svg class="mbr-icon mbr-icon-volume-high" viewBox="0 0 24 24" fill="currentColor">' +
                                    '<path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/>' +
                                '</svg>' +
                                '<svg class="mbr-icon mbr-icon-volume-muted" viewBox="0 0 24 24" fill="currentColor">' +
                                    '<path d="M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z"/>' +
                                '</svg>' +
                            '</button>' +
                            '<input type="range" class="mbr-volume-slider" min="0" max="100" value="70" aria-label="Volume" />' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>';
            
            $container.html(html);
            
            console.log('MBR Admin: Preview updated');
            
            // Initialize preview player
            initPreviewPlayer($container.find('.mbr-radio-player'));
        }
        
        // Initialize preview player functionality
        function initPreviewPlayer($player) {
            var streamUrl = $player.data('stream');
            var audio = new Audio();
            audio.preload = 'metadata'; // Only preload metadata for live streams
            var hls = null;
            var isPlaying = false;
            
            // Buffer monitoring for admin preview
            var bufferCheckInterval = null;
            function startBufferMonitoring() {
                if (bufferCheckInterval) clearInterval(bufferCheckInterval);
                bufferCheckInterval = setInterval(function() {
                    if (audio.buffered.length > 0 && !audio.paused) {
                        var bufferAhead = audio.buffered.end(audio.buffered.length - 1) - audio.currentTime;
                        if (bufferAhead > 30) {
                            console.log('Admin: Buffer too large, reconnecting...');
                            var src = audio.src;
                            audio.pause();
                            audio.src = '';
                            audio.load();
                            setTimeout(function() { audio.src = src; audio.load(); audio.play(); }, 100);
                        }
                    }
                }, 10000);
            }
            function stopBufferMonitoring() {
                if (bufferCheckInterval) {
                    clearInterval(bufferCheckInterval);
                    bufferCheckInterval = null;
                }
            }
            
            // Check if stream is M3U playlist (not M3U8/HLS)
            if (streamUrl.indexOf('.m3u') !== -1 && streamUrl.indexOf('.m3u8') === -1) {
                console.log('Admin: Detected .m3u playlist, fetching actual stream URL...');
                
                // If HTTP playlist on HTTPS site, use proxy
                var playlistFetchUrl = streamUrl;
                if (typeof mbrLrpAdmin !== 'undefined' && mbrLrpAdmin.proxyEnabled && streamUrl.indexOf('http://') === 0) {
                    playlistFetchUrl = mbrLrpAdmin.proxyUrl + 'playlist=1&url=' + encodeURIComponent(streamUrl);
                    console.log('Admin: Using proxy to fetch playlist:', playlistFetchUrl);
                }
                
                $.get(playlistFetchUrl)
                    .done(function(playlistText) {
                        console.log('Admin: Playlist content:', playlistText);
                        
                        // Parse M3U playlist and collect ALL stream URLs
                        var lines = playlistText.split('\n');
                        var streamUrls = [];
                        
                        for (var i = 0; i < lines.length; i++) {
                            var line = lines[i].trim();
                            if (line && !line.startsWith('#')) {
                                streamUrls.push(line);
                            }
                        }
                        
                        if (streamUrls.length > 0) {
                            // Try the second stream URL if available (often better quality)
                            var actualStreamUrl = streamUrls.length > 1 ? streamUrls[1] : streamUrls[0];
                            console.log('Admin: Found ' + streamUrls.length + ' stream URL(s), using:', actualStreamUrl);
                            if (streamUrls.length > 1) {
                                console.log('Admin: Alternative streams available:', streamUrls);
                            }
                            
                            // Fix Shoutcast URLs that might be pointing to server root
                            actualStreamUrl = fixShoutcastUrl(actualStreamUrl);
                            console.log('Admin: After Shoutcast fix:', actualStreamUrl);
                            
                            // Debug proxy settings
                            console.log('Admin: mbrLrpAdmin:', typeof mbrLrpAdmin !== 'undefined' ? mbrLrpAdmin : 'undefined');
                            
                            // Check if we need to proxy the actual stream URL
                            if (typeof mbrLrpAdmin !== 'undefined' && mbrLrpAdmin.proxyEnabled && actualStreamUrl.indexOf('http://') === 0) {
                                actualStreamUrl = mbrLrpAdmin.proxyUrl + 'url=' + encodeURIComponent(actualStreamUrl);
                                console.log('Admin: Using proxy for stream:', actualStreamUrl);
                            } else {
                                console.log('Admin: NOT using proxy. Reasons:',
                                    'mbrLrpAdmin defined?', typeof mbrLrpAdmin !== 'undefined',
                                    'proxyEnabled?', typeof mbrLrpAdmin !== 'undefined' ? mbrLrpAdmin.proxyEnabled : 'N/A',
                                    'isHTTP?', actualStreamUrl.indexOf('http://') === 0
                                );
                            }
                            
                            audio.src = actualStreamUrl;
                            initPlayerControls();
                        } else {
                            console.error('Admin: Could not find stream URL in playlist');
                            $player.find('.mbr-status-text').text('Invalid playlist');
                        }
                    })
                    .fail(function() {
                        console.error('Admin: Failed to fetch playlist');
                        $player.find('.mbr-status-text').text('Playlist error');
                    });
                    
                return;
            }
            
            // Check if stream is HLS
            else if (streamUrl.indexOf('.m3u8') !== -1) {
                if (typeof Hls !== 'undefined' && Hls.isSupported()) {
                    var hlsConfig = {};
                    
                    // If we're using a proxy for HTTP streams, create custom loader
                    var needsAdminProxy = typeof mbrLrpAdmin !== 'undefined' && mbrLrpAdmin.proxyEnabled && streamUrl.indexOf('http://') === 0;
                    
                    if (needsAdminProxy) {
                        var DefaultLoader = Hls.DefaultConfig.loader;
                        var _streamUrl = streamUrl;
                        var _baseDir = _streamUrl.substring(0, _streamUrl.lastIndexOf('/') + 1);
                        
                        function CustomLoader(config) {
                            DefaultLoader.call(this, config);
                            var _proxyUrl = mbrLrpAdmin.proxyUrl;
                            
                            // Helper to check if URL is proxied
                            function isProxiedUrl(url) {
                                return url.indexOf(_proxyUrl) !== -1 || url.indexOf('proxy-stream.php') !== -1;
                            }
                            
                            var originalLoad = this.load.bind(this);
                            this.load = function(context, config, callbacks) {
                                var url = context.url;
                                var isManifest = false;
                                
                                console.log('Admin HLS.js requesting:', url);
                                
                                // Check if this is a manifest
                                if (url.indexOf('.m3u8') !== -1) {
                                    isManifest = true;
                                    console.log('Admin: Detected manifest request');
                                }
                                
                                // Check if this is a relative URL
                                if (url && !/^https?:\/\//i.test(url)) {
                                    url = _baseDir + url;
                                    console.log('Admin: Resolved relative URL to:', url);
                                }
                                
                                // Proxy HTTP URLs
                                if (url && url.indexOf('http://') === 0) {
                                    var proxiedUrl = _proxyUrl + 'url=' + encodeURIComponent(url);
                                    console.log('Admin proxying:', url, '->', proxiedUrl);
                                    context.url = proxiedUrl;
                                    
                                    if (isManifest) {
                                        var originalCallbacks = callbacks;
                                        var originalOnSuccess = callbacks.onSuccess;
                                        
                                        callbacks = {
                                            onSuccess: function(response, stats, context, networkDetails) {
                                                if (response.data && typeof response.data === 'string') {
                                                    console.log('Admin: Rewriting manifest URLs...');
                                                    var lines = response.data.split('\n');
                                                    var modifiedLines = lines.map(function(line) {
                                                        var trimmedLine = line.trim();
                                                        if (trimmedLine && !trimmedLine.startsWith('#') && trimmedLine.indexOf('.ts') !== -1) {
                                                            if (!isProxiedUrl(trimmedLine)) {
                                                                var absoluteUrl;
                                                                if (!/^https?:\/\//i.test(trimmedLine)) {
                                                                    absoluteUrl = _baseDir + trimmedLine;
                                                                } else {
                                                                    absoluteUrl = trimmedLine;
                                                                }
                                                                if (absoluteUrl.indexOf('http://') === 0) {
                                                                    var proxiedSegmentUrl = _proxyUrl + 'url=' + encodeURIComponent(absoluteUrl);
                                                                    console.log('Admin: Rewrote segment:', trimmedLine, '->', proxiedSegmentUrl);
                                                                    return proxiedSegmentUrl;
                                                                }
                                                            }
                                                        }
                                                        return line;
                                                    });
                                                    response.data = modifiedLines.join('\n');
                                                }
                                                originalOnSuccess(response, stats, context, networkDetails);
                                            },
                                            onError: originalCallbacks.onError,
                                            onTimeout: originalCallbacks.onTimeout,
                                            onProgress: originalCallbacks.onProgress
                                        };
                                    }
                                } else if (url && isProxiedUrl(url) && isManifest) {
                                    // Already-proxied manifest - still rewrite it
                                    console.log('Admin: Already-proxied manifest, will rewrite segments');
                                    var originalCallbacks = callbacks;
                                    var originalOnSuccess = callbacks.onSuccess;
                                    
                                    callbacks = {
                                        onSuccess: function(response, stats, context, networkDetails) {
                                            if (response.data && typeof response.data === 'string') {
                                                console.log('Admin: Rewriting manifest URLs (proxied manifest)...');
                                                var lines = response.data.split('\n');
                                                var modifiedLines = lines.map(function(line) {
                                                    var trimmedLine = line.trim();
                                                    if (trimmedLine && !trimmedLine.startsWith('#') && trimmedLine.indexOf('.ts') !== -1) {
                                                        if (!isProxiedUrl(trimmedLine)) {
                                                            var absoluteUrl;
                                                            if (!/^https?:\/\//i.test(trimmedLine)) {
                                                                absoluteUrl = _baseDir + trimmedLine;
                                                            } else {
                                                                absoluteUrl = trimmedLine;
                                                            }
                                                            if (absoluteUrl.indexOf('http://') === 0) {
                                                                var proxiedSegmentUrl = _proxyUrl + 'url=' + encodeURIComponent(absoluteUrl);
                                                                console.log('Admin: Rewrote segment (reload):', trimmedLine, '->', proxiedSegmentUrl);
                                                                return proxiedSegmentUrl;
                                                            }
                                                        }
                                                    }
                                                    return line;
                                                });
                                                response.data = modifiedLines.join('\n');
                                            }
                                            originalOnSuccess(response, stats, context, networkDetails);
                                        },
                                        onError: originalCallbacks.onError,
                                        onTimeout: originalCallbacks.onTimeout,
                                        onProgress: originalCallbacks.onProgress
                                    };
                                    context.url = url;
                                } else {
                                    context.url = url;
                                }
                                
                                originalLoad(context, config, callbacks);
                            };
                        }
                        
                        CustomLoader.prototype = Object.create(DefaultLoader.prototype);
                        CustomLoader.prototype.constructor = CustomLoader;
                        
                        hlsConfig.loader = CustomLoader;
                    }
                    
                    hls = new Hls(hlsConfig);
                    
                    // Use original stream URL (not proxied) so HLS.js resolves segments correctly
                    hls.loadSource(streamUrl);
                    hls.attachMedia(audio);
                } else if (audio.canPlayType('application/vnd.apple.mpegurl')) {
                    audio.src = streamUrl;
                }
            } else {
                // Direct MP3/AAC stream
                var finalStreamUrl = streamUrl;
                
                // Check if we need to proxy HTTP streams
                if (typeof mbrLrpAdmin !== 'undefined' && mbrLrpAdmin.proxyEnabled && streamUrl.indexOf('http://') === 0) {
                    finalStreamUrl = mbrLrpAdmin.proxyUrl + 'url=' + encodeURIComponent(streamUrl);
                    console.log('Admin: Using proxy for direct stream:', finalStreamUrl);
                }
                
                audio.src = finalStreamUrl;
            }
            
            initPlayerControls();
            
            function initPlayerControls() {
            
            // Play/Pause
            $player.on('click', '.mbr-play-btn', function(e) {
                e.preventDefault();
                
                if (isPlaying) {
                    audio.pause();
                } else {
                    $player.addClass('loading');
                    audio.play().catch(function(error) {
                        console.error('Playback error:', error);
                        $player.removeClass('loading');
                        alert('Could not play the stream. Please check the URL.');
                    });
                }
            });
            
            // Audio events
            audio.addEventListener('playing', function() {
                $player.removeClass('loading').addClass('playing');
                $player.find('.mbr-status-text').text('Now Playing');
                isPlaying = true;
                startBufferMonitoring();
            });
            
            audio.addEventListener('pause', function() {
                $player.removeClass('playing');
                $player.find('.mbr-status-text').text('Paused');
                isPlaying = false;
                stopBufferMonitoring();
            });
            
            audio.addEventListener('waiting', function() {
                $player.addClass('loading');
            });
            
            audio.addEventListener('canplay', function() {
                $player.removeClass('loading');
            });
            
            // Volume controls
            $player.on('click', '.mbr-volume-btn', function(e) {
                e.preventDefault();
                audio.muted = !audio.muted;
                $player.toggleClass('muted', audio.muted);
            });
            
            $player.on('input', '.mbr-volume-slider', function() {
                var volume = $(this).val() / 100;
                audio.volume = volume;
                if (volume === 0) {
                    $player.addClass('muted');
                } else {
                    $player.removeClass('muted');
                    audio.muted = false;
                }
            });
            
            // Set initial volume
            audio.volume = 0.7;
            } // End of initPlayerControls
        }
        
        // Update preview on changes
        $('#title, #mbr_lrp_stream_url').on('input', function() {
            updatePreview();
        });
        
        // Update preview when featured image changes
        $(document).on('click', '#set-post-thumbnail', function() {
            setTimeout(updatePreview, 500);
        });
        
        $(document).on('click', '#remove-post-thumbnail', function() {
            setTimeout(updatePreview, 500);
        });
        
        // Preview mode toggle
        $('input[name="mbr_lrp_preview_mode"]').on('change', function() {
            var mode = $(this).val();
            $('#mbr-lrp-preview-container').toggleClass('mobile-mode', mode === 'mobile');
        });
        
        // Initial preview
        updatePreview();
        
        // Initialize color pickers
        $('.mbr-color-picker').wpColorPicker({
            change: function(event, ui) {
                console.log('MBR Admin: Color picker changed');
                // Update preview when color changes
                updatePreview();
            }
        });
        
        console.log('MBR Admin: Color pickers initialized, found:', $('.mbr-color-picker').length);
        
        // Handle preset gradient buttons
        $('.mbr-preset-btn').on('click', function(e) {
            e.preventDefault();
            console.log('MBR Admin: Preset button clicked');
            
            var color1 = $(this).data('color1');
            var color2 = $(this).data('color2');
            
            $('input[name="mbr_lrp_gradient_color_1"]').wpColorPicker('color', color1);
            $('input[name="mbr_lrp_gradient_color_2"]').wpColorPicker('color', color2);
            
            // Update preview after preset is applied
            setTimeout(updatePreview, 100);
        });
        
        console.log('MBR Admin: Preset buttons initialized, found:', $('.mbr-preset-btn').length);
        
        // Update preview when dark mode or glassmorphism changes
        $('input[name="mbr_lrp_dark_mode"], input[name="mbr_lrp_glassmorphism"]').on('change', function() {
            console.log('MBR Admin: Checkbox changed:', $(this).attr('name'), 'checked:', $(this).is(':checked'));
            updatePreview();
        });
        
        console.log('MBR Admin: Checkboxes initialized, found:', $('input[name="mbr_lrp_dark_mode"], input[name="mbr_lrp_glassmorphism"]').length);
    });
    
})(jQuery);
