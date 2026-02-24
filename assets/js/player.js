/**
 * Frontend JavaScript for MBR Live Radio Player
 */

(function() {
    'use strict';
    
    // Wait for HLS.js to load
    function waitForHls(callback) {
        if (typeof Hls !== 'undefined') {
            callback();
        } else {
            setTimeout(function() {
                waitForHls(callback);
            }, 100);
        }
    }
    
    // Fix Shoutcast URLs that point to server root
    function fixShoutcastUrl(url) {
        try {
            var urlObj = new URL(url);
            
            // Check if this looks like a Shoutcast base URL (has port, no path or root path)
            var hasPort = urlObj.port !== '';
            var isRoot = urlObj.pathname === '' || urlObj.pathname === '/';
            
            if (hasPort && isRoot) {
                // This might be a Shoutcast server root, append default stream path
                // Most Shoutcast servers use /; as the default stream endpoint
                console.log('Detected potential Shoutcast base URL, appending /;');
                return url + (url.endsWith('/') ? ';' : '/;');
            }
        } catch (e) {
            console.error('Error parsing URL:', e);
        }
        
        return url;
    }
    
    // Initialize all players on page
    function initPlayers() {
        var players = document.querySelectorAll('.mbr-radio-player');
        
        if (players.length === 0) {
            return;
        }
        
        // Split players by mode — file players initialise immediately,
        // stream players may need HLS.js to load first
        var streamPlayers = [];
        players.forEach(function(player) {
            if (player.classList.contains('mbr-mode-files')) {
                initFilePlayer(player);
            } else {
                streamPlayers.push(player);
            }
        });
        
        if (!streamPlayers.length) return;
        
        // Check if any stream player needs HLS
        var needsHls = false;
        streamPlayers.forEach(function(player) {
            var streamUrl = player.dataset.stream;
            if (streamUrl && streamUrl.indexOf('.m3u8') !== -1) {
                needsHls = true;
            }
        });
        
        // If HLS is needed, wait for it to load
        if (needsHls) {
            waitForHls(function() {
                streamPlayers.forEach(function(player) {
                    initPlayer(player);
                });
            });
        } else {
            streamPlayers.forEach(function(player) {
                initPlayer(player);
            });
        }
    }
    
    // Initialize single player
    function initPlayer(playerElement) {
        var streamUrl = playerElement.dataset.stream;
        var proxyUrl = playerElement.dataset.proxyUrl;
        var proxyEnabled = playerElement.dataset.proxyEnabled === '1';
        
        if (!streamUrl) {
            console.error('No stream URL provided');
            return;
        }
        
        // Check if we need to proxy the stream
        // Only proxy HTTP streams when the page is HTTPS (mixed content would be blocked)
        var pageIsHttps = window.location.protocol === 'https:';
        var streamIsHttp = streamUrl.indexOf('http://') === 0;
        var needsProxy = proxyEnabled && streamIsHttp && pageIsHttps;
        var finalStreamUrl = streamUrl;
        
        if (needsProxy && proxyUrl) {
            // Use proxy for HTTP streams on HTTPS pages
            finalStreamUrl = proxyUrl + 'url=' + encodeURIComponent(streamUrl);
            console.log('Using proxy for HTTP stream on HTTPS page:', streamUrl);
        } else if (streamIsHttp && !pageIsHttps) {
            console.log('HTTP stream on HTTP page - no proxy needed:', streamUrl);
        }
        
        var audio = new Audio();
        audio.preload = 'metadata'; // Changed from 'auto' - only preload metadata, not the whole stream
        audio.crossOrigin = 'anonymous'; // Enable CORS for better compatibility
        
        // CRITICAL: For live streams, we need to prevent excessive buffering
        // Browsers can buffer minutes of audio, causing the stream to "loop" old content
        var bufferCheckInterval = null;
        var MAX_BUFFER_SECONDS = 30; // If buffer exceeds 30 seconds, reconnect to live edge
        
        function startBufferMonitoring() {
            if (bufferCheckInterval) {
                clearInterval(bufferCheckInterval);
            }
            
            // Check buffer every 10 seconds
            bufferCheckInterval = setInterval(function() {
                if (audio.buffered.length > 0 && !audio.paused) {
                    var bufferedEnd = audio.buffered.end(audio.buffered.length - 1);
                    var currentTime = audio.currentTime;
                    var bufferAhead = bufferedEnd - currentTime;
                    
                    // If we have more than MAX_BUFFER_SECONDS buffered ahead, reconnect
                    if (bufferAhead > MAX_BUFFER_SECONDS) {
                        console.log('Buffer too large (' + bufferAhead.toFixed(1) + 's ahead) - reconnecting to live edge...');
                        
                        // Save source and reconnect
                        var currentSrc = audio.src;
                        audio.pause();
                        audio.src = '';
                        audio.load();
                        
                        setTimeout(function() {
                            audio.src = currentSrc;
                            audio.load();
                            audio.play().catch(function(error) {
                                console.error('Buffer reconnect failed:', error);
                            });
                        }, 100);
                    }
                }
            }, 10000); // Check every 10 seconds
        }
        
        function stopBufferMonitoring() {
            if (bufferCheckInterval) {
                clearInterval(bufferCheckInterval);
                bufferCheckInterval = null;
            }
        }
        
        var hls = null;
        var isPlaying = false;
        var actualStreamUrl = streamUrl; // Store the actual stream URL for metadata polling
        
        // Create a hidden "metadata extraction" audio element
        // This keeps the stream connection alive long enough to extract metadata
        // even if the main player gets suspended by the browser
        // DISABLED: Now using server-side metadata extraction via AJAX polling
        var metadataAudio = null;
        function createMetadataExtractor() {
            // Disabled - causing audio interference due to constant restart loop
            // Server-side AJAX polling is handling metadata extraction instead
            return;
            
            if (metadataAudio) return;
            
            metadataAudio = new Audio();
            metadataAudio.preload = 'auto';
            metadataAudio.volume = 0; // Silent
            metadataAudio.crossOrigin = 'anonymous';
            
            // Monitor metadata extractor status
            metadataAudio.addEventListener('loadstart', function() {
                console.log('Metadata extractor: Loading started');
            });
            
            metadataAudio.addEventListener('progress', function() {
                var buffered = metadataAudio.buffered.length > 0 ? metadataAudio.buffered.end(0) : 0;
                console.log('Metadata extractor: Downloaded ' + buffered.toFixed(2) + 's (~' + Math.round(buffered * 16) + 'KB)');
            });
            
            metadataAudio.addEventListener('suspend', function() {
                var buffered = metadataAudio.buffered.length > 0 ? metadataAudio.buffered.end(0) : 0;
                console.log('Metadata extractor: SUSPENDED at ' + buffered.toFixed(2) + 's');
                
                // If we haven't reached enough data yet, force continue
                if (buffered < 5) {
                    console.log('Metadata extractor: Forcing continue (need 5s for ~80KB)');
                    setTimeout(function() {
                        if (metadataAudio) {
                            metadataAudio.load();
                            metadataAudio.play().catch(function() {});
                        }
                    }, 100);
                }
            });
            
            metadataAudio.addEventListener('error', function(e) {
                console.error('Metadata extractor error:', e);
            });
            
            // Set the same source as main player
            metadataAudio.src = audio.src;
            
            // Start playing silently in background to extract metadata
            metadataAudio.play().catch(function(error) {
                console.log('Metadata extractor autoplay blocked (expected):', error.message);
            });
            
            // Clean up after metadata is extracted (~60 seconds)
            setTimeout(function() {
                if (metadataAudio) {
                    metadataAudio.pause();
                    metadataAudio.src = '';
                    metadataAudio = null;
                    console.log('Metadata extractor cleaned up');
                }
            }, 60000);
            
            console.log('Created silent metadata extraction stream');
        }
        
        console.log('Initializing player with stream:', streamUrl);
        
        // Check if stream is a playlist that needs parsing
        if (streamUrl.indexOf('.m3u') !== -1 && streamUrl.indexOf('.m3u8') === -1) {
            // This is a Shoutcast/Icecast .m3u playlist, fetch and parse it
            console.log('Detected .m3u playlist, fetching actual stream URL...');
            
            // If the playlist URL is HTTP and we're on HTTPS, use the proxy to fetch it
            var playlistFetchUrl = streamUrl;
            if (proxyEnabled && streamUrl.indexOf('http://') === 0 && pageIsHttps && proxyUrl) {
                playlistFetchUrl = proxyUrl + 'playlist=1&url=' + encodeURIComponent(streamUrl);
                console.log('Using proxy to fetch playlist:', playlistFetchUrl);
            }
            
            // Fetch the playlist
            fetch(playlistFetchUrl)
                .then(function(response) {
                    return response.text();
                })
                .then(function(playlistText) {
                    console.log('Playlist content:', playlistText);
                    
                    // Parse M3U playlist and collect ALL stream URLs
                    var lines = playlistText.split('\n');
                    var streamUrls = [];
                    
                    for (var i = 0; i < lines.length; i++) {
                        var line = lines[i].trim();
                        // Skip comments and empty lines
                        if (line && !line.startsWith('#')) {
                            streamUrls.push(line);
                        }
                    }
                    
                    if (streamUrls.length > 0) {
                        // Try the first stream URL (or second if available, as it might be better quality)
                        // Shoutcast often lists multiple quality options
                        actualStreamUrl = streamUrls.length > 1 ? streamUrls[1] : streamUrls[0];
                        console.log('Found ' + streamUrls.length + ' stream URL(s), using:', actualStreamUrl);
                        if (streamUrls.length > 1) {
                            console.log('Alternative streams available:', streamUrls);
                        }
                        
                        // Fix Shoutcast URLs that might be pointing to server root
                        actualStreamUrl = fixShoutcastUrl(actualStreamUrl);
                        console.log('After Shoutcast fix:', actualStreamUrl);
                        
                        // Store the actual stream URL for metadata polling (without proxy)
                        var metadataStreamUrl = actualStreamUrl;
                        
                        // Check if we need to proxy the actual stream URL
                        // Only proxy HTTP streams when the page is HTTPS
                        if (proxyEnabled && actualStreamUrl.indexOf('http://') === 0 && pageIsHttps && proxyUrl) {
                            actualStreamUrl = proxyUrl + 'url=' + encodeURIComponent(actualStreamUrl);
                            console.log('Using proxy for stream:', actualStreamUrl);
                        }
                        
                        audio.src = actualStreamUrl;
                        actualStreamUrl = metadataStreamUrl; // Use non-proxied URL for metadata
                        initializeAudioPlayer();
                    } else {
                        console.error('Could not find stream URL in playlist');
                        playerElement.querySelector('.mbr-status-text').textContent = 'Invalid playlist';
                    }
                })
                .catch(function(error) {
                    console.error('Failed to fetch playlist:', error);
                    playerElement.querySelector('.mbr-status-text').textContent = 'Playlist error';
                });
                
            // We'll initialize the player after getting the actual URL
            return;
        }
        
        // Check if stream is HLS
        else if (streamUrl.indexOf('.m3u8') !== -1) {
            if (typeof Hls !== 'undefined' && Hls.isSupported()) {
                console.log('Using HLS.js');
                
                // Build HLS instance with proxy-aware custom loader (shared with station switcher)
                hls = buildHlsInstance(streamUrl);
                hls.on(Hls.Events.ERROR, function(event, data) {
                    console.error('HLS Event Error:', data);
                    if (data.fatal) {
                        console.error('HLS Fatal Error:', data);
                        switch(data.type) {
                            case Hls.ErrorTypes.NETWORK_ERROR:
                                console.error('Network error, trying to recover...');
                                hls.startLoad();
                                break;
                            case Hls.ErrorTypes.MEDIA_ERROR:
                                console.error('Media error, trying to recover...');
                                hls.recoverMediaError();
                                break;
                            default:
                                console.error('Fatal error, destroying player');
                                hls.destroy();
                                playerElement.querySelector('.mbr-status-text').textContent = 'Stream error';
                                break;
                        }
                    }
                });
                
                // Load the ORIGINAL stream URL (not proxied)
                // The custom loader will intercept and proxy individual requests
                hls.loadSource(streamUrl);
                hls.attachMedia(audio);
            } else if (audio.canPlayType('application/vnd.apple.mpegurl')) {
                // iOS Safari native HLS support
                console.log('Using native HLS support');
                audio.src = finalStreamUrl;
            } else {
                console.error('HLS not supported in this browser');
                playerElement.querySelector('.mbr-status-text').textContent = 'HLS not supported';
                return;
            }
        } else {
            // Standard audio stream (MP3, AAC, etc.)
            console.log('Using standard audio for:', streamUrl);
            console.log('Final URL (may be proxied):', finalStreamUrl);
            audio.src = finalStreamUrl;
        }
        
        /**
         * Build an HLS.js instance with the proxy-aware custom loader.
         * Used for both initial player load and station switching so both
         * paths get identical proxy/manifest-rewriting behaviour.
         * @param {string} sourceUrl  The raw (unproxied) .m3u8 URL
         */
        function buildHlsInstance(sourceUrl) {
            var _baseDir = sourceUrl.substring(0, sourceUrl.lastIndexOf('/') + 1);
            var urlNeedsProxy = proxyEnabled && proxyUrl && sourceUrl.indexOf('http://') === 0 && pageIsHttps;
            
            var instanceConfig = {
                maxBufferLength: 10,
                maxMaxBufferLength: 20,
                liveBackBufferLength: 0,
                enableWorker: true,
                lowLatencyMode: true
            };
            
            if (urlNeedsProxy) {
                var DefaultLoader = Hls.DefaultConfig.loader;
                
                function ProxyLoader(config) {
                    DefaultLoader.call(this, config);
                    var _proxyUrl = proxyUrl;
                    var _bd       = _baseDir;
                    
                    function isProxied(url) {
                        return url.indexOf(_proxyUrl) !== -1 || url.indexOf('proxy-stream.php') !== -1;
                    }
                    
                    var originalLoad = this.load.bind(this);
                    this.load = function(context, config, callbacks) {
                        var url = context.url;
                        var isManifest = url.indexOf('.m3u8') !== -1;
                        
                        console.log('HLS.js requesting:', url);
                        
                        // Resolve relative URLs
                        if (url && !/^https?:\/\//i.test(url)) {
                            url = _bd + url;
                        }
                        
                        // Intercept manifest responses to rewrite segment URLs
                        function wrapManifestCallbacks(cb) {
                            var orig = cb.onSuccess;
                            return {
                                onSuccess: function(response, stats, ctx, networkDetails) {
                                    if (response.data && typeof response.data === 'string') {
                                        var lines = response.data.split('\n');
                                        lines = lines.map(function(line) {
                                            var t = line.trim();
                                            if (t && t.charAt(0) !== '#' && t.indexOf('.ts') !== -1 && !isProxied(t)) {
                                                var abs = /^https?:\/\//i.test(t) ? t : _bd + t;
                                                if (abs.indexOf('http://') === 0) {
                                                    var proxied = _proxyUrl + 'url=' + encodeURIComponent(abs);
                                                    console.log('Rewrote segment:', t, '->', proxied);
                                                    return proxied;
                                                }
                                            }
                                            return line;
                                        });
                                        response.data = lines.join('\n');
                                    }
                                    orig(response, stats, ctx, networkDetails);
                                },
                                onError:    cb.onError,
                                onTimeout:  cb.onTimeout,
                                onProgress: cb.onProgress
                            };
                        }
                        
                        if (url && url.indexOf('http://') === 0) {
                            context.url = _proxyUrl + 'url=' + encodeURIComponent(url);
                            console.log('Proxying:', url, '->', context.url);
                            if (isManifest) callbacks = wrapManifestCallbacks(callbacks);
                        } else if (isManifest && isProxied(url)) {
                            console.log('Already-proxied manifest, rewriting segments');
                            callbacks = wrapManifestCallbacks(callbacks);
                            context.url = url;
                        } else {
                            context.url = url;
                        }
                        
                        originalLoad(context, config, callbacks);
                    };
                }
                
                ProxyLoader.prototype = Object.create(DefaultLoader.prototype);
                ProxyLoader.prototype.constructor = ProxyLoader;
                instanceConfig.loader = ProxyLoader;
            }
            
            return new Hls(instanceConfig);
        }
        
        initializeAudioPlayer();
        
        // Function to initialize audio player controls
        function initializeAudioPlayer() {
        
        // Get UI elements
        var playBtn = playerElement.querySelector('.mbr-play-btn');
        var volumeBtn = playerElement.querySelector('.mbr-volume-btn');
        var volumeSlider = playerElement.querySelector('.mbr-volume-slider');
        var statusText = playerElement.querySelector('.mbr-status-text');
        
        // Track user-initiated pause vs automatic pause
        var userPaused = false;
        
        // Preventive reconnection every 15 minutes to avoid 20-minute server timeout
        var reconnectInterval = null;
        var RECONNECT_INTERVAL = 15 * 60 * 1000; // 15 minutes in milliseconds
        
        function startReconnectTimer() {
            // Clear any existing timer
            if (reconnectInterval) {
                clearInterval(reconnectInterval);
            }
            
            console.log('Starting 15-minute preventive reconnection timer');
            
            // Set up new timer for preventive reconnection
            reconnectInterval = setInterval(function() {
                console.log('15-minute timer fired. isPlaying:', isPlaying, 'userPaused:', userPaused, 'audio.paused:', audio.paused);
                
                if (isPlaying && !userPaused && !audio.paused) {
                    console.log('Preventive reconnection (avoiding 20-min timeout)...');
                    
                    // Save current state
                    var wasPlaying = !audio.paused;
                    
                    // Reconnect by reloading the source
                    var currentSrc = audio.src;
                    audio.src = '';
                    audio.load();
                    
                    // Small delay to ensure clean reload
                    setTimeout(function() {
                        audio.src = currentSrc;
                        audio.load();
                        
                        if (wasPlaying) {
                            audio.play().catch(function(error) {
                                console.error('Preventive reconnect failed:', error);
                            });
                        }
                    }, 100);
                } else {
                    console.log('Skipping reconnection - conditions not met');
                }
            }, RECONNECT_INTERVAL);
        }
        
        function stopReconnectTimer() {
            if (reconnectInterval) {
                clearInterval(reconnectInterval);
                reconnectInterval = null;
            }
        }
        
        // Play/Pause button
        playBtn.addEventListener('click', function(e) {
            e.preventDefault();
            
            if (isPlaying) {
                audio.pause();
                userPaused = true; // User explicitly paused
            } else {
                playerElement.classList.add('loading');
                userPaused = false; // User wants to play
                
                // Start metadata extraction stream
                createMetadataExtractor();
                
                audio.play().catch(function(error) {
                    console.error('Playback error:', error);
                    playerElement.classList.remove('loading');
                    
                    // Show user-friendly error
                    if (error.name === 'NotAllowedError') {
                        alert('Playback was prevented. Please interact with the page first or check your browser settings.');
                    } else {
                        alert('Could not play the stream. Please try again.');
                    }
                });
            }
        });
        
        // Audio event listeners
        audio.addEventListener('playing', function() {
            playerElement.classList.remove('loading');
            playerElement.classList.add('playing');
            statusText.textContent = 'Now Playing';
            isPlaying = true;
            
            // Start preventive reconnection timer
            startReconnectTimer();
            
            // Start buffer monitoring to prevent old audio looping
            startBufferMonitoring();
        });
        
        audio.addEventListener('pause', function() {
            playerElement.classList.remove('playing');
            statusText.textContent = 'Paused';
            isPlaying = false;
            
            // Stop reconnection timer when paused
            stopReconnectTimer();
            
            // Stop buffer monitoring when paused
            stopBufferMonitoring();
            
            // If pause wasn't user-initiated and we should be playing, try to resume
            if (!userPaused && !audio.error) {
                console.log('Unexpected pause detected, attempting auto-resume...');
                setTimeout(function() {
                    if (audio.paused && !userPaused) {
                        console.log('Auto-resuming stream...');
                        audio.play().catch(function(error) {
                            console.error('Auto-resume failed:', error);
                        });
                    }
                }, 1000);
            }
        });
        
        audio.addEventListener('waiting', function() {
            playerElement.classList.add('loading');
            console.log('Stream buffering...');
        });
        
        audio.addEventListener('canplay', function() {
            playerElement.classList.remove('loading');
        });
        
        // Handle stalled streams - auto-reconnect
        var stallTimeout;
        var reconnectAttempts = 0;
        var maxReconnectAttempts = 3;
        
        audio.addEventListener('stalled', function() {
            console.log('Stream stalled, attempting recovery...');
            
            if (reconnectAttempts < maxReconnectAttempts && isPlaying) {
                reconnectAttempts++;
                console.log('Reconnection attempt ' + reconnectAttempts + ' of ' + maxReconnectAttempts);
                
                // Force reload the stream
                var currentSrc = audio.src;
                audio.src = '';
                audio.load();
                audio.src = currentSrc;
                audio.load();
                audio.play().catch(function(error) {
                    console.error('Auto-reconnect failed:', error);
                });
            }
        });
        
        // Reset reconnection counter when playing successfully
        audio.addEventListener('playing', function() {
            reconnectAttempts = 0;
        });
        
        // Handle suspend event (browser paused download)
        // DISABLED: Suspend handling was causing audio interference
        // Browser will handle buffering naturally
        /*
        audio.addEventListener('suspend', function() {
            console.log('Stream suspended by browser');
            console.log('Network state:', audio.networkState, 'Ready state:', audio.readyState);
            console.log('Buffered ranges:', audio.buffered.length > 0 ? audio.buffered.end(0) : 0);
            
            // REMOVED: Metadata extraction logic - now handled by server-side AJAX polling
            // The old code was forcing stream restarts which caused audio interference
            
            // If we were playing, try to resume after a short delay  
            // Only try once per suspend event to avoid creating a loop
            if (isPlaying && reconnectAttempts < maxReconnectAttempts && audio.paused) {
                reconnectAttempts++;
                setTimeout(function() {
                    if (audio.paused && isPlaying) {
                        console.log('Attempting to resume suspended stream (attempt ' + reconnectAttempts + ')...');
                        audio.play().catch(function(error) {
                            console.error('Resume failed:', error);
                        });
                    }
                }, 2000); // Increased to 2 seconds to avoid rapid loops
            }
        });
        */
        
        audio.addEventListener('error', function(e) {
            playerElement.classList.remove('loading', 'playing');
            isPlaying = false;
            
            // Check error type
            var errorMessage = 'Error loading stream';
            if (audio.error) {
                switch (audio.error.code) {
                    case audio.error.MEDIA_ERR_NETWORK:
                        errorMessage = 'Station offline or network error';
                        break;
                    case audio.error.MEDIA_ERR_DECODE:
                        errorMessage = 'Stream format error';
                        console.error('MEDIA_ERR_DECODE: The stream is corrupted or in an unsupported format');
                        break;
                    case audio.error.MEDIA_ERR_SRC_NOT_SUPPORTED:
                        errorMessage = 'Stream format not supported';
                        console.error('MEDIA_ERR_SRC_NOT_SUPPORTED: Browser cannot decode this stream format');
                        break;
                    case audio.error.MEDIA_ERR_ABORTED:
                        errorMessage = 'Stream loading aborted';
                        console.error('MEDIA_ERR_ABORTED: Stream loading was interrupted');
                        break;
                }
            }
            
            console.error('Audio error details:', {
                error: audio.error,
                code: audio.error ? audio.error.code : 'unknown',
                message: audio.error ? audio.error.message : 'unknown',
                networkState: audio.networkState,
                readyState: audio.readyState,
                currentSrc: audio.currentSrc
            });
            
            statusText.textContent = errorMessage;
            console.error('Audio error:', e, audio.error);
        });
        
        // Volume button (mute/unmute)
        volumeBtn.addEventListener('click', function(e) {
            e.preventDefault();
            audio.muted = !audio.muted;
            playerElement.classList.toggle('muted', audio.muted);
        });
        
        // Volume slider
        volumeSlider.addEventListener('input', function() {
            var volume = this.value / 100;
            audio.volume = volume;
            
            if (volume === 0) {
                playerElement.classList.add('muted');
            } else {
                playerElement.classList.remove('muted');
                audio.muted = false;
            }
        });
        
        // Set initial volume
        audio.volume = volumeSlider.value / 100;
        
        // Cleanup on page unload
        window.addEventListener('beforeunload', function() {
            if (hls) {
                hls.destroy();
            }
            audio.pause();
            audio.src = '';
        });
        
        // Metadata polling
        var metadataInterval = null;
        var marqueeElement = playerElement.querySelector('.mbr-now-playing');
        var stickyMetadataElement = playerElement.querySelector('.mbr-now-playing-text');
        var trackArtElement = playerElement.querySelector('.mbr-track-art');
        var lastMetadataTitle = '';
        
        console.log('Metadata elements found:', {
            marquee: !!marqueeElement,
            stickyMetadata: !!stickyMetadataElement,
            trackArt: !!trackArtElement,
            ajaxUrl: typeof mbrPlayerData !== 'undefined' ? mbrPlayerData.ajaxUrl : 'undefined'
        });
        
        // Helper function to decode HTML entities
        function decodeHtmlEntities(text) {
            var textArea = document.createElement('textarea');
            textArea.innerHTML = text;
            return textArea.value;
        }
        
        function pollMetadata() {
            if (!isPlaying) return;
            
            if (typeof mbrPlayerData === 'undefined' || !mbrPlayerData.ajaxUrl) {
                console.error('mbrPlayerData not available');
                return;
            }
            
            var metadataUrl = mbrPlayerData.ajaxUrl + '?action=mbr_get_metadata&stream_url=' + encodeURIComponent(actualStreamUrl);
            console.log('[' + new Date().toISOString() + '] Polling metadata from:', metadataUrl);
            
            fetch(metadataUrl)
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    console.log('[' + new Date().toISOString() + '] Metadata response:', data);
                    console.log('  - Title:', data.success ? data.data.title : 'no title');
                    console.log('  - URL:', data.success ? data.data.url : 'no url');
                    console.log('  - Timestamp:', data.success ? data.data.timestamp : 'no timestamp');
                    console.log('  - Cached?:', (data.success && data.data.cached) ? 'YES' : 'NO');
                    console.log('  - DEBUG:', data.success && data.data.debug ? data.data.debug : 'no debug info');
                    console.log('  - JS time:', Math.floor(Date.now() / 1000));
                    
                    if (data.success && data.data.title && data.data.title !== lastMetadataTitle) {
                        lastMetadataTitle = data.data.title;
                        console.log('✓ New track detected:', data.data.title);
                        
                        // Decode HTML entities in the title
                        var decodedTitle = decodeHtmlEntities(data.data.title);
                        console.log('✓ Decoded title:', decodedTitle);
                        
                        // Update marquee (regular player)
                        if (marqueeElement) {
                            marqueeElement.textContent = '♫ Now Playing: ' + decodedTitle + ' ';
                            
                            // Restart animation
                            var marqueeContent = marqueeElement.parentElement;
                            marqueeContent.style.animation = 'none';
                            setTimeout(function() {
                                marqueeContent.style.animation = '';
                            }, 10);
                        }
                        
                        // Update sticky metadata text (sticky player)
                        if (stickyMetadataElement) {
                            stickyMetadataElement.textContent = 'Now Playing: ' + decodedTitle;
                        }
                        
                        // Track artwork from stream metadata (only available for some streams e.g. SomaFM)
                        if (trackArtElement) {
                            if (data.data.url) {
                                trackArtElement.src = data.data.url;
                                trackArtElement.style.display = 'block';
                                trackArtElement.classList.add('active');
                            } else {
                                trackArtElement.style.display = 'none';
                                trackArtElement.classList.remove('active');
                            }
                        }
                    }
                })
                .catch(function(error) {
                    console.error('Metadata polling error:', error);
                });
        }
        
        // Start polling when playing
        audio.addEventListener('playing', function() {
            if (!metadataInterval) {
                pollMetadata(); // Poll immediately
                metadataInterval = setInterval(pollMetadata, 30000); // Then every 30 seconds (reduced from 5 to avoid rate limits)
            }
        });
        
        // Stop polling when paused
        audio.addEventListener('pause', function() {
            if (metadataInterval) {
                clearInterval(metadataInterval);
                metadataInterval = null;
            }
        });
        
        // Pop-out button handler
        var popoutBtn = playerElement.querySelector('.mbr-popout-btn');
        if (popoutBtn) {
            popoutBtn.addEventListener('click', function(e) {
                e.preventDefault();
                openPopupPlayer();
            });
        }
        
        // Open popup player window
        function openPopupPlayer() {
            var stationId = playerElement.dataset.stationId;
            if (!stationId) {
                console.error('No station ID found');
                return;
            }
            
            // Get the popup URL
            var popupUrl = mbrRadioPlayer.popupUrl + '?station_id=' + stationId;
            
            // Define popup window features
            var width = 500;
            var height = 160; // Compact height - increased by 10px for better visibility
            var left = (screen.width - width) / 2;
            var top = (screen.height - height) / 2;
            
            var features = 'width=' + width + 
                          ',height=' + height + 
                          ',left=' + left + 
                          ',top=' + top +
                          ',resizable=yes' +
                          ',scrollbars=no' +
                          ',toolbar=no' +
                          ',menubar=no' +
                          ',location=no' +
                          ',status=no';
            
            // Open the popup window
            var popupWindow = window.open(popupUrl, 'MBR_Radio_Player_' + stationId, features);
            
            if (popupWindow) {
                popupWindow.focus();
            } else {
                alert('Please allow popups for this site to use the pop-out player feature.');
            }
        }
        
        // ---- Multi-station list ----
        var stationGroupRaw = playerElement.dataset.stationGroup;
        if (stationGroupRaw) {
            var stationGroup = [];
            try { stationGroup = JSON.parse(stationGroupRaw); } catch(e) {}
            
            var stationsBtn  = playerElement.querySelector('.mbr-stations-btn');
            var stationList  = playerElement.querySelector('.mbr-station-list');
            var listItems    = playerElement.querySelector('.mbr-station-list-items');
            var closeBtn     = playerElement.querySelector('.mbr-station-list-close');
            
            // Build the group station rows (other stations)
            if (listItems && stationGroup.length) {
                stationGroup.forEach(function(s) {
                    var item = document.createElement('div');
                    item.className = 'mbr-station-item';
                    item.dataset.stream = s.stream;
                    item.dataset.title  = s.title;
                    item.dataset.art    = s.art || '';
                    
                    var artHtml = s.art
                        ? '<img src="' + s.art + '" alt="" class="mbr-station-item-art" />'
                        : '<span class="mbr-station-item-art mbr-station-item-art--placeholder"></span>';
                    
                    item.innerHTML = artHtml +
                        '<span class="mbr-station-item-title">' + s.title + '</span>' +
                        '<span class="mbr-station-item-playing-indicator">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="14" height="14"><path d="M8 5v14l11-7z"/></svg>' +
                        '</span>';
                    
                    listItems.appendChild(item);
                });
            }
            
            // Open / close panel
            var playerInner = playerElement.querySelector('.mbr-player-inner');

            function openStationList() {
                stationList.classList.add('mbr-station-list--open');
                stationList.setAttribute('aria-hidden', 'false');
                if (stationsBtn) stationsBtn.classList.add('mbr-stations-btn--active');
                if (playerInner) playerInner.classList.add('mbr-station-list-visible');
                // Mark the currently active station
                updateActiveStation(playerElement.dataset.stream);
            }
            
            function closeStationList() {
                stationList.classList.remove('mbr-station-list--open');
                stationList.setAttribute('aria-hidden', 'true');
                if (stationsBtn) stationsBtn.classList.remove('mbr-stations-btn--active');
                if (playerInner) playerInner.classList.remove('mbr-station-list-visible');
            }
            
            function updateActiveStation(currentStream) {
                if (!listItems) return;
                var items = listItems.querySelectorAll('.mbr-station-item');
                items.forEach(function(item) {
                    if (item.dataset.stream === currentStream) {
                        item.classList.add('mbr-station-item--current');
                    } else {
                        item.classList.remove('mbr-station-item--current');
                    }
                });
            }
            
            if (stationsBtn) {
                stationsBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (stationList.classList.contains('mbr-station-list--open')) {
                        closeStationList();
                    } else {
                        openStationList();
                    }
                });
            }
            
            if (closeBtn) {
                closeBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    closeStationList();
                });
            }
            
            // Station switching
            if (listItems) {
                listItems.addEventListener('click', function(e) {
                    var item = e.target.closest('.mbr-station-item');
                    if (!item) return;
                    // Only skip if this station is already the one actually playing
                    if (item.dataset.stream === playerElement.dataset.stream) {
                        closeStationList();
                        return;
                    }
                    
                    var newStream = item.dataset.stream;
                    var newTitle  = item.dataset.title;
                    var newArt    = item.dataset.art;
                    
                    // Fully tear down the current stream before switching.
                    // CRITICAL: destroy HLS *before* clearing audio.src — if HLS is attached
                    // and we clear audio.src first, HLS fights the element and causes stalls.
                    function teardownCurrentStream() {
                        if (hls) {
                            hls.destroy();
                            hls = null;
                        }
                        audio.pause();
                        audio.removeAttribute('src');
                        audio.load();
                        isPlaying = false;
                        stopBufferMonitoring();
                        // Clear metadata polling so it restarts with the new stream URL
                        if (metadataInterval) {
                            clearInterval(metadataInterval);
                            metadataInterval = null;
                        }
                        lastMetadataTitle = '';
                        playerElement.classList.remove('playing', 'loading');
                    }
                    
                    teardownCurrentStream();
                    
                    // Update player data and UI
                    playerElement.dataset.stream = newStream;
                    
                    var titleEl = playerElement.querySelector('.mbr-player-title');
                    if (titleEl) titleEl.textContent = newTitle;
                    
                    var artworkWrapper = playerElement.querySelector('.mbr-player-inner > .mbr-player-artwork');
                    var stationArtEl   = artworkWrapper ? artworkWrapper.querySelector('.mbr-station-art') : null;
                    if (artworkWrapper) {
                        if (newArt) {
                            if (stationArtEl) {
                                stationArtEl.src = newArt;
                                stationArtEl.alt = newTitle;
                            }
                            artworkWrapper.style.display = '';
                        } else {
                            artworkWrapper.style.display = 'none';
                        }
                    }
                    
                    var statusEl = playerElement.querySelector('.mbr-status-text');
                    if (statusEl) statusEl.textContent = 'Ready to play';
                    
                    // Clear metadata marquee and track art
                    var marqueeEl = playerElement.querySelector('.mbr-now-playing');
                    if (marqueeEl) marqueeEl.textContent = '';
                    if (trackArtElement) {
                        trackArtElement.style.display = 'none';
                        trackArtElement.classList.remove('active');
                        trackArtElement.src = '';
                    }
                    
                    // Load and play the new stream, handling all stream types correctly
                    function loadAndPlayStream(rawUrl) {
                        playerElement.classList.add('loading');
                        
                        // Path 1: M3U playlist — fetch and resolve to real URL first
                        if (rawUrl.indexOf('.m3u') !== -1 && rawUrl.indexOf('.m3u8') === -1) {
                            var playlistFetchUrl = rawUrl;
                            if (proxyEnabled && rawUrl.indexOf('http://') === 0 && pageIsHttps && proxyUrl) {
                                playlistFetchUrl = proxyUrl + 'playlist=1&url=' + encodeURIComponent(rawUrl);
                            }
                            fetch(playlistFetchUrl)
                                .then(function(r) { return r.text(); })
                                .then(function(text) {
                                    var lines = text.split('\n');
                                    var urls  = [];
                                    for (var i = 0; i < lines.length; i++) {
                                        var l = lines[i].trim();
                                        if (l && l.charAt(0) !== '#') urls.push(l);
                                    }
                                    if (!urls.length) {
                                        playerElement.classList.remove('loading');
                                        var se = playerElement.querySelector('.mbr-status-text');
                                        if (se) se.textContent = 'Playlist error';
                                        return;
                                    }
                                    var resolved = fixShoutcastUrl(urls.length > 1 ? urls[1] : urls[0]);
                                    // Update actualStreamUrl so metadata polls the right station
                                    actualStreamUrl = resolved;
                                    var src = (proxyEnabled && resolved.indexOf('http://') === 0 && pageIsHttps && proxyUrl)
                                        ? proxyUrl + 'url=' + encodeURIComponent(resolved)
                                        : resolved;
                                    audio.src = src;
                                    audio.play().catch(function(err) {
                                        console.error('MBR: Station switch (m3u) playback error', err);
                                        playerElement.classList.remove('loading');
                                    });
                                })
                                .catch(function() {
                                    playerElement.classList.remove('loading');
                                    var se = playerElement.querySelector('.mbr-status-text');
                                    if (se) se.textContent = 'Playlist error';
                                });
                            return;
                        }
                        
                        // Path 2: HLS — hls is guaranteed null here (teardownCurrentStream ran above).
                        // Wait for the 'emptied' event before attaching to avoid a race condition
                        // where audio.load() hasn't finished aborting the previous stream yet.
                        if (rawUrl.indexOf('.m3u8') !== -1) {
                            // Update actualStreamUrl for metadata polling
                            actualStreamUrl = rawUrl;
                            
                            // Use setTimeout(0) to push HLS attachment to the next event loop
                            // tick — gives the browser one tick to finish the audio.load() abort
                            // before HLS attaches. buildHlsInstance gives the new HLS instance the
                            // same proxy-aware custom loader as the initial player setup.
                            setTimeout(function() {
                                console.log('MBR Switch: attachHls — audio.readyState:', audio.readyState, 'hls?', !!hls);
                                if (typeof Hls !== 'undefined' && Hls.isSupported()) {
                                    hls = buildHlsInstance(rawUrl);
                                    hls.on(Hls.Events.ERROR, function(event, data) {
                                        if (data.fatal) {
                                            switch (data.type) {
                                                case Hls.ErrorTypes.NETWORK_ERROR:
                                                    hls.startLoad(); break;
                                                case Hls.ErrorTypes.MEDIA_ERROR:
                                                    hls.recoverMediaError(); break;
                                                default:
                                                    hls.destroy(); hls = null;
                                                    var se = playerElement.querySelector('.mbr-status-text');
                                                    if (se) se.textContent = 'Stream error';
                                            }
                                        }
                                    });
                                    hls.loadSource(rawUrl);
                                    hls.attachMedia(audio);
                                    hls.once(Hls.Events.MANIFEST_PARSED, function() {
                                        audio.play().catch(function(err) {
                                            console.error('MBR: Station switch (hls) playback error', err);
                                            playerElement.classList.remove('loading');
                                        });
                                    });
                                } else if (audio.canPlayType('application/vnd.apple.mpegurl')) {
                                    audio.src = rawUrl;
                                    audio.play().catch(function(err) {
                                        console.error('MBR: Station switch (hls-native) playback error', err);
                                        playerElement.classList.remove('loading');
                                    });
                                } else {
                                    playerElement.classList.remove('loading');
                                    var se = playerElement.querySelector('.mbr-status-text');
                                    if (se) se.textContent = 'Stream format not supported';
                                }
                            }, 0);
                            return;
                        }
                        
                        // Path 3: Direct MP3/AAC
                        // Update actualStreamUrl for metadata polling
                        actualStreamUrl = rawUrl;
                        var src = (proxyEnabled && proxyUrl && rawUrl.indexOf('http://') === 0 && pageIsHttps)
                            ? proxyUrl + 'url=' + encodeURIComponent(rawUrl)
                            : rawUrl;
                        audio.src = src;
                        audio.play().catch(function(err) {
                            console.error('MBR: Station switch (direct) playback error', err);
                            playerElement.classList.remove('loading');
                        });
                    }
                    
                    loadAndPlayStream(newStream);
                    
                    updateActiveStation(newStream);
                    closeStationList();
                });
            }
        }
        
        } // End of initializeAudioPlayer function
    }

    
    // ─── File Player ─────────────────────────────────────────────────────────
    function initFilePlayer(playerElement) {
        var tracksData  = JSON.parse(playerElement.dataset.tracks || '[]');
        if (!tracksData.length) return;

        var audio        = playerElement.querySelector('.mbr-audio');
        var playBtn      = playerElement.querySelector('.mbr-play-btn');
        var rewindBtn    = playerElement.querySelector('.mbr-rewind-btn');
        var forwardBtn   = playerElement.querySelector('.mbr-forward-btn');
        var volumeBtn    = playerElement.querySelector('.mbr-volume-btn');
        var volumeSlider = playerElement.querySelector('.mbr-volume-slider');
        var progressBar  = playerElement.querySelector('.mbr-progress-bar');
        var progressFill = playerElement.querySelector('.mbr-progress-fill');
        var progressHandle = playerElement.querySelector('.mbr-progress-handle');
        var timeCurrent  = playerElement.querySelector('.mbr-time-current');
        var timeDuration = playerElement.querySelector('.mbr-time-duration');
        var trackNameEl  = playerElement.querySelector('.mbr-file-track-name');
        var statusText   = playerElement.querySelector('.mbr-status-text');
        var tracklistBtn = playerElement.querySelector('.mbr-tracklist-btn');
        var tracklistPanel = playerElement.querySelector('.mbr-tracklist-panel');
        var closeBtn     = tracklistPanel ? tracklistPanel.querySelector('.mbr-station-list-close') : null;
        var trackItems   = tracklistPanel ? tracklistPanel.querySelectorAll('.mbr-track-item') : [];

        var currentIndex = 0;
        var isPlaying    = false;
        var isSeeking    = false;
        var intendingToPlay = false; // guard against spurious pause during src assignment
        var savePositionTimer = null; // throttle localStorage writes
        
        // ── Resume position (localStorage) ───────────────────────────────────
        var STORAGE_PREFIX = 'mbr_fp_pos_';
        
        function storageKey(url) {
            // Simple hash — strip query strings for stability
            return STORAGE_PREFIX + url.split('?')[0];
        }
        
        function savePosition(url, seconds) {
            try {
                if (seconds > 5) { // don't bother saving the very start
                    localStorage.setItem(storageKey(url), Math.floor(seconds));
                }
            } catch(e) {} // storage may be disabled/full
        }
        
        function getSavedPosition(url) {
            try {
                var val = localStorage.getItem(storageKey(url));
                return val ? parseInt(val, 10) : 0;
            } catch(e) { return 0; }
        }
        
        function clearSavedPosition(url) {
            try { localStorage.removeItem(storageKey(url)); } catch(e) {}
        }
        function formatTime(secs) {
            if (isNaN(secs) || !isFinite(secs)) return '0:00';
            var m = Math.floor(secs / 60);
            var s = Math.floor(secs % 60);
            return m + ':' + (s < 10 ? '0' : '') + s;
        }
        
        function updateTrackUI(index) {
            var track = tracksData[index];
            var title = track.title || track.url.split('/').pop().replace(/\.[^.]+$/, '');
            if (trackNameEl) {
                trackNameEl.textContent = '♫ ' + title + ' ';
                var mc = trackNameEl.parentElement;
                mc.style.animation = 'none';
                setTimeout(function() { mc.style.animation = ''; }, 10);
            }
            // Update active track in list
            if (trackItems.length) {
                for (var i = 0; i < trackItems.length; i++) {
                    trackItems[i].classList.toggle('mbr-station-item--current', parseInt(trackItems[i].dataset.trackIndex, 10) === index);
                }
            }
            playerElement.dataset.trackIndex = index;
        }
        
        function loadTrack(index, autoPlay) {
            var track = tracksData[index];
            if (!track) return;
            // Save position of the track we're leaving
            var leaving = tracksData[currentIndex];
            if (leaving && audio.currentTime > 5) savePosition(leaving.url, audio.currentTime);
            currentIndex = index;
            updateTrackUI(index);
            if (autoPlay) {
                audio.src = track.url;
                // Seek to saved position after metadata loads
                var seeked = false;
                audio.addEventListener('canplay', function onCanPlay() {
                    audio.removeEventListener('canplay', onCanPlay);
                    if (seeked) return;
                    seeked = true;
                    var saved = getSavedPosition(track.url);
                    if (saved > 5 && saved < (audio.duration - 3)) {
                        audio.currentTime = saved;
                    }
                });
                audio.play().catch(function(err) {
                    console.error('MBR File Player: play() error:', err);
                    playerElement.classList.remove('loading');
                    if (statusText) statusText.textContent = 'Playback error';
                });
            }
        }
        
        // ── Play / Pause ──────────────────────────────────────────────────────
        playBtn.addEventListener('click', function() {
            if (isPlaying) {
                intendingToPlay = false;
                audio.pause();
            } else {
                intendingToPlay = true;
                // Set src from current track if not already set
                var track = tracksData[currentIndex];
                if (track && (!audio.src || audio.src === window.location.href || audio.src === '')) {
                    audio.src = track.url;
                    // Setting src fires a spurious 'pause' event — intendingToPlay guards against it
                    
                    // After metadata loads, seek to saved position (once only)
                    var seeked = false;
                    audio.addEventListener('canplay', function onCanPlay() {
                        audio.removeEventListener('canplay', onCanPlay);
                        if (seeked) return;
                        seeked = true;
                        var saved = getSavedPosition(track.url);
                        if (saved > 5 && saved < (audio.duration - 3)) {
                            audio.currentTime = saved;
                        }
                    });
                }
                playerElement.classList.add('loading');
                audio.play().catch(function(err) {
                    intendingToPlay = false;
                    console.error('MBR File Player: play() rejected:', err);
                    playerElement.classList.remove('loading');
                    if (statusText) statusText.textContent = 'Playback error';
                });
            }
        });
        
        // ── Rewind / Forward ──────────────────────────────────────────────────
        if (rewindBtn) {
            rewindBtn.addEventListener('click', function() {
                if (audio.duration) audio.currentTime = Math.max(0, audio.currentTime - 15);
            });
        }
        if (forwardBtn) {
            forwardBtn.addEventListener('click', function() {
                if (audio.duration) audio.currentTime = Math.min(audio.duration, audio.currentTime + 15);
            });
        }
        
        // ── Volume ────────────────────────────────────────────────────────────
        audio.volume = 0.7;
        if (volumeSlider) {
            volumeSlider.addEventListener('input', function() {
                audio.volume = this.value / 100;
                if (audio.volume === 0) {
                    audio.muted = true;
                    playerElement.classList.add('muted');
                } else {
                    audio.muted = false;
                    playerElement.classList.remove('muted');
                }
            });
        }
        if (volumeBtn) {
            volumeBtn.addEventListener('click', function() {
                audio.muted = !audio.muted;
                playerElement.classList.toggle('muted', audio.muted);
                if (volumeSlider) volumeSlider.value = audio.muted ? 0 : audio.volume * 100;
            });
        }
        
        // ── Progress bar ──────────────────────────────────────────────────────
        function updateProgress() {
            if (!audio.duration || isSeeking) return;
            var pct = (audio.currentTime / audio.duration) * 100;
            if (progressFill)  progressFill.style.width  = pct + '%';
            if (progressHandle) progressHandle.style.left = pct + '%';
            if (timeCurrent)   timeCurrent.textContent   = formatTime(audio.currentTime);
        }
        
        function seekTo(e) {
            if (!audio.duration || !progressBar) return;
            var rect = progressBar.getBoundingClientRect();
            var pct  = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
            audio.currentTime = pct * audio.duration;
            if (progressFill)   progressFill.style.width   = (pct * 100) + '%';
            if (progressHandle) progressHandle.style.left  = (pct * 100) + '%';
            if (timeCurrent)    timeCurrent.textContent    = formatTime(audio.currentTime);
        }
        
        if (progressBar) {
            progressBar.addEventListener('mousedown', function(e) {
                isSeeking = true;
                seekTo(e);
                function onMove(e) { seekTo(e); }
                function onUp()   { isSeeking = false; document.removeEventListener('mousemove', onMove); document.removeEventListener('mouseup', onUp); }
                document.addEventListener('mousemove', onMove);
                document.addEventListener('mouseup',  onUp);
            });
            // Touch support
            progressBar.addEventListener('touchstart', function(e) {
                isSeeking = true;
                seekTo(e.touches[0]);
                function onMove(e) { seekTo(e.touches[0]); }
                function onEnd()   { isSeeking = false; document.removeEventListener('touchmove', onMove); document.removeEventListener('touchend', onEnd); }
                document.addEventListener('touchmove', onMove, { passive: true });
                document.addEventListener('touchend',  onEnd);
            }, { passive: true });
        }
        
        // ── Audio events ──────────────────────────────────────────────────────
        audio.addEventListener('playing', function() {
            intendingToPlay = false;
            isPlaying = true;
            playerElement.classList.add('playing');
            playerElement.classList.remove('loading');
            if (statusText) statusText.textContent = 'Now Playing';
        });
        audio.addEventListener('pause', function() {
            // Ignore spurious pause events that fire when src is assigned
            if (intendingToPlay) return;
            isPlaying = false;
            playerElement.classList.remove('playing');
            if (statusText) statusText.textContent = 'Paused';
        });
        audio.addEventListener('waiting', function() {
            playerElement.classList.add('loading');
        });
        audio.addEventListener('canplay', function() {
            playerElement.classList.remove('loading');
        });
        audio.addEventListener('timeupdate', function() {
            updateProgress();
            // Save position every 5 seconds (throttled to avoid hammering localStorage)
            if (!savePositionTimer && audio.currentTime > 0) {
                savePositionTimer = setTimeout(function() {
                    savePositionTimer = null;
                    var track = tracksData[currentIndex];
                    if (track && isPlaying) savePosition(track.url, audio.currentTime);
                }, 5000);
            }
        });
        audio.addEventListener('durationchange', function() {
            if (timeDuration) timeDuration.textContent = formatTime(audio.duration);
        });
        audio.addEventListener('ended', function() {
            // Clear saved position — track completed naturally
            var track = tracksData[currentIndex];
            if (track) clearSavedPosition(track.url);
            // Auto-advance
            var next = currentIndex + 1;
            if (next < tracksData.length) {
                loadTrack(next, true);
            } else {
                // End of playlist — reset to track 1, don't auto-play
                isPlaying = false;
                playerElement.classList.remove('playing');
                if (statusText) statusText.textContent = 'Ready to play';
                loadTrack(0, false);
            }
        });
        audio.addEventListener('error', function() {
            intendingToPlay = false;
            // Only show error if we were actually trying to play something
            if (audio.src && audio.src !== window.location.href && audio.src !== '') {
                var err = audio.error;
                var msg = err ? 'Code ' + err.code + ': ' + (err.message || '') : 'unknown';
                console.error('MBR File Player: audio error —', msg, 'src:', audio.src);
                playerElement.classList.remove('loading', 'playing');
                if (statusText) statusText.textContent = 'Error loading file';
            }
        });
        
        // ── Track list panel ──────────────────────────────────────────────────
        if (tracklistBtn && tracklistPanel) {
            tracklistBtn.addEventListener('click', function() {
                var open = tracklistPanel.getAttribute('aria-hidden') === 'false';
                tracklistPanel.setAttribute('aria-hidden', open ? 'true' : 'false');
                tracklistPanel.classList.toggle('mbr-station-list--open', !open);
            });
            if (closeBtn) {
                closeBtn.addEventListener('click', function() {
                    tracklistPanel.setAttribute('aria-hidden', 'true');
                    tracklistPanel.classList.remove('mbr-station-list--open');
                });
            }
            // Track item click
            tracklistPanel.addEventListener('click', function(e) {
                var item = e.target.closest('.mbr-track-item');
                if (!item) return;
                var idx = parseInt(item.dataset.trackIndex, 10);
                loadTrack(idx, true);
                tracklistPanel.setAttribute('aria-hidden', 'true');
                tracklistPanel.classList.remove('mbr-station-list--open');
            });
        }
        
        // Show first track title in UI without triggering any load
        updateTrackUI(0);
    }
    // ─── End File Player ──────────────────────────────────────────────────────
    

    // Initialize sticky players - MUST be defined before calling
    function initStickyPlayers() {
        var stickyPlayers = document.querySelectorAll('.mbr-radio-player-sticky');
        
        console.log('MBR Sticky: Found ' + stickyPlayers.length + ' sticky player(s)');
        
        // If no players found, check if there's an old one in body from previous page
        if (stickyPlayers.length === 0) {
            var oldPlayersInBody = document.body.querySelectorAll('.mbr-radio-player-sticky');
            if (oldPlayersInBody.length > 0) {
                console.log('MBR Sticky: Removing ' + oldPlayersInBody.length + ' old player(s) from previous page');
                oldPlayersInBody.forEach(function(oldPlayer) {
                    oldPlayer.remove();
                });
            }
            console.log('MBR Sticky: No players found in current page');
            return;
        }
        
        // Check for old players in body that are NOT in the current page content
        var oldPlayersInBody = document.body.querySelectorAll('.mbr-radio-player-sticky');
        oldPlayersInBody.forEach(function(oldPlayer) {
            var isCurrentPlayer = false;
            stickyPlayers.forEach(function(currentPlayer) {
                if (oldPlayer === currentPlayer) {
                    isCurrentPlayer = true;
                }
            });
            
            // If it's not a current player, it's from a previous page - remove it
            if (!isCurrentPlayer) {
                console.log('MBR Sticky: Removing old player from previous page');
                oldPlayer.remove();
            }
        });
        
        stickyPlayers.forEach(function(stickyPlayer) {
            // Skip if already initialized
            if (stickyPlayer.dataset.mbrInitialized === 'true') {
                console.log('MBR Sticky: Player already initialized, skipping');
                return;
            }
            
            // Mark as initialized
            stickyPlayer.dataset.mbrInitialized = 'true';
            
            // CRITICAL: Move sticky player to body to ensure it's truly sticky
            // This fixes issues with Elementor and other page builders that wrap content
            // in containers with transform/overflow properties that break position:fixed
            
            console.log('MBR Sticky: Current parent:', stickyPlayer.parentElement);
            
            // CRITICAL: Initialize the player FIRST (while still in original position)
            // This sets up the audio element with the correct source URL
            // Strip station group from sticky player — the switcher belongs on the main player only
            stickyPlayer.removeAttribute('data-station-group');
            initPlayer(stickyPlayer);
            console.log('MBR Sticky: Player initialized in original position');
            
            // Get the audio element and save its source before moving
            var audioElement = stickyPlayer.querySelector('audio');
            var audioSrc = audioElement ? audioElement.src : null;
            console.log('MBR Sticky: Audio element found:', !!audioElement);
            console.log('MBR Sticky: Audio src before move:', audioSrc);
            
            // THEN move to body
            console.log('MBR Sticky: Moving player to body element');
            
            // Remove from current position and append to body
            if (stickyPlayer.parentElement !== document.body) {
                document.body.appendChild(stickyPlayer);
                console.log('MBR Sticky: Successfully moved to body');
                
                // Re-get the audio element after move (DOM reference might change)
                audioElement = stickyPlayer.querySelector('audio');
                console.log('MBR Sticky: Audio element after move:', !!audioElement);
                console.log('MBR Sticky: Audio src after move:', audioElement ? audioElement.src : 'no element');
                
                // CRITICAL FIX: Reload audio source after DOM move
                // Moving media elements can cause browsers to drop the source
                if (audioElement && audioSrc) {
                    console.log('MBR Sticky: Reloading audio source:', audioSrc);
                    audioElement.load(); // Reset the element
                    audioElement.src = audioSrc; // Restore the source
                    audioElement.load(); // Load it again
                    console.log('MBR Sticky: Audio reloaded, new src:', audioElement.src);
                } else {
                    console.log('MBR Sticky: Cannot reload - audioElement:', !!audioElement, 'audioSrc:', !!audioSrc);
                }
                
                // Force layout recalculation to prevent flexbox collapse
                void stickyPlayer.offsetHeight;
                stickyPlayer.style.display = 'block';
                void stickyPlayer.offsetHeight;
                stickyPlayer.style.display = '';
            } else {
                console.log('MBR Sticky: Already in body');
            }
            
            // Add close button handler
            var closeBtn = stickyPlayer.querySelector('.mbr-sticky-close');
            if (closeBtn) {
                closeBtn.addEventListener('click', function() {
                    stickyPlayer.classList.add('mbr-sticky-hidden');
                    
                    // Pause the player when closing
                    var playBtn = stickyPlayer.querySelector('.mbr-play-btn');
                    if (playBtn && stickyPlayer.classList.contains('playing')) {
                        playBtn.click();
                    }
                });
            }
        });
    }
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPlayers);
        document.addEventListener('DOMContentLoaded', initStickyPlayers);
    } else {
        initPlayers();
        initStickyPlayers();
    }
    
    // Additional initialization attempts for sticky players
    // This handles cases where content loads dynamically (Elementor, AJAX, etc.)
    setTimeout(function() {
        console.log('MBR Sticky: Retry initialization after 100ms');
        initStickyPlayers();
    }, 100);
    
    setTimeout(function() {
        console.log('MBR Sticky: Retry initialization after 500ms');
        initStickyPlayers();
    }, 500);
    
    setTimeout(function() {
        console.log('MBR Sticky: Final retry after 1 second');
        initStickyPlayers();
    }, 1000);
    
    // Note: Sticky players are designed to persist in document.body
    // When navigating to a new page, the browser naturally clears the DOM
    // No manual cleanup needed - simplified approach
    
    // Expose initFilePlayer for admin preview use
    window.mbrInitFilePlayer = initFilePlayer;
    
})();
