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
        
        // Check if any player needs HLS
        var needsHls = false;
        players.forEach(function(player) {
            var streamUrl = player.dataset.stream;
            if (streamUrl && streamUrl.indexOf('.m3u8') !== -1) {
                needsHls = true;
            }
        });
        
        // If HLS is needed, wait for it to load
        if (needsHls) {
            waitForHls(function() {
                players.forEach(function(player) {
                    initPlayer(player);
                });
            });
        } else {
            players.forEach(function(player) {
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
                
                // Configure HLS with custom loader for proxying
                var hlsConfig = {
                    maxBufferLength: 10,
                    maxMaxBufferLength: 20,
                    liveBackBufferLength: 0,
                    enableWorker: true,
                    lowLatencyMode: true,
                    xhrSetup: function(xhr, url) {
                        // Only proxy HTTP URLs
                        if (needsProxy && proxyUrl && url.indexOf('http://') === 0) {
                            // This will be called for every request HLS.js makes
                            // We'll modify the URL in the loader instead
                        }
                    }
                };
                
                // If we're using a proxy, create custom loader that handles manifest rewriting
                if (needsProxy && proxyUrl) {
                    var DefaultLoader = Hls.DefaultConfig.loader;
                    var _streamUrl = streamUrl;
                    var _baseDir = _streamUrl.substring(0, _streamUrl.lastIndexOf('/') + 1);
                    
                    function CustomLoader(config) {
                        DefaultLoader.call(this, config);
                        var _proxyUrl = proxyUrl;
                        var self = this;
                        
                        // Helper to check if URL is proxied (checks both rewrite and actual file)
                        function isProxiedUrl(url) {
                            return url.indexOf(_proxyUrl) !== -1 || url.indexOf('proxy-stream.php') !== -1;
                        }
                        
                        var originalLoad = this.load.bind(this);
                        this.load = function(context, config, callbacks) {
                            var url = context.url;
                            var originalUrl = url;
                            var isManifest = false;
                            
                            console.log('HLS.js requesting:', url);
                            
                            // Determine if this is a manifest request by checking the URL
                            // Check both the current URL and if it contains .m3u8 anywhere
                            if (url.indexOf('.m3u8') !== -1) {
                                isManifest = true;
                                console.log('Detected manifest request');
                            }
                            
                            // Check if this is a relative URL
                            if (url && !/^https?:\/\//i.test(url)) {
                                url = _baseDir + url;
                                console.log('Resolved relative URL to:', url);
                            }
                            
                            // Proxy HTTP URLs
                            if (url && url.indexOf('http://') === 0) {
                                var proxiedUrl = _proxyUrl + 'url=' + encodeURIComponent(url);
                                console.log('Proxying:', url, '->', proxiedUrl);
                                context.url = proxiedUrl;
                                
                                // If this is a manifest file, intercept the response to rewrite segment URLs
                                if (isManifest) {
                                    var originalCallbacks = callbacks;
                                    var originalOnSuccess = callbacks.onSuccess;
                                    
                                    callbacks = {
                                        onSuccess: function(response, stats, context, networkDetails) {
                                            // Modify the manifest text to use absolute proxied URLs
                                            if (response.data && typeof response.data === 'string') {
                                                console.log('Rewriting manifest URLs...');
                                                var lines = response.data.split('\n');
                                                var modifiedLines = lines.map(function(line) {
                                                    var trimmedLine = line.trim();
                                                    // If line is a segment file (relative path)
                                                    if (trimmedLine && !trimmedLine.startsWith('#') && trimmedLine.indexOf('.ts') !== -1) {
                                                        // Check if it's not already a full proxy URL
                                                        if (!isProxiedUrl(trimmedLine)) {
                                                            // If it's not an absolute URL, make it one
                                                            var absoluteUrl;
                                                            if (!/^https?:\/\//i.test(trimmedLine)) {
                                                                absoluteUrl = _baseDir + trimmedLine;
                                                            } else {
                                                                absoluteUrl = trimmedLine;
                                                            }
                                                            // Then proxy it (only if it's HTTP)
                                                            if (absoluteUrl.indexOf('http://') === 0) {
                                                                var proxiedSegmentUrl = _proxyUrl + 'url=' + encodeURIComponent(absoluteUrl);
                                                                console.log('Rewrote segment:', trimmedLine, '->', proxiedSegmentUrl);
                                                                return proxiedSegmentUrl;
                                                            }
                                                        }
                                                    }
                                                    return line;
                                                });
                                                response.data = modifiedLines.join('\n');
                                            }
                                            
                                            // Call the original success callback
                                            originalOnSuccess(response, stats, context, networkDetails);
                                        },
                                        onError: originalCallbacks.onError,
                                        onTimeout: originalCallbacks.onTimeout,
                                        onProgress: originalCallbacks.onProgress
                                    };
                                }
                            } else if (url && isProxiedUrl(url) && isManifest) {
                                // This is an already-proxied manifest URL - still need to rewrite it!
                                console.log('Already-proxied manifest detected, will rewrite segments');
                                var originalCallbacks = callbacks;
                                var originalOnSuccess = callbacks.onSuccess;
                                
                                callbacks = {
                                    onSuccess: function(response, stats, context, networkDetails) {
                                        if (response.data && typeof response.data === 'string') {
                                            console.log('Rewriting manifest URLs (proxied manifest)...');
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
                                                            console.log('Rewrote segment (reload):', trimmedLine, '->', proxiedSegmentUrl);
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
                                context.url = url; // Keep the already-proxied URL
                            } else {
                                context.url = url;
                            }
                            
                            // Call the original load method with potentially modified callbacks
                            originalLoad(context, config, callbacks);
                        };
                    }
                    
                    CustomLoader.prototype = Object.create(DefaultLoader.prototype);
                    CustomLoader.prototype.constructor = CustomLoader;
                    
                    hlsConfig.loader = CustomLoader;
                }
                
                hls = new Hls(hlsConfig);
                
                hls.on(Hls.Events.MANIFEST_PARSED, function() {
                    console.log('HLS manifest parsed successfully');
                });
                
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
                        
                        // Try to get artwork from Last.fm or other service
                        // For now, we'll just show the track art if URL is provided
                        if (trackArtElement && data.data.url) {
                            trackArtElement.src = data.data.url;
                            trackArtElement.style.display = 'block';
                            trackArtElement.classList.add('active');
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
        
        } // End of initializeAudioPlayer function
    }
    
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
    
})();
