/**
 * BirdNET-Pi Dashboard Charts
 * Renders interactive Chart.js charts for the Overview page,
 * replacing static PNG images from daily_plot.py.
 */

(function () {
    'use strict';

    var heatmapChart = null;
    var imageCache = {};
    // Thumbnails that failed to load, keyed by URL -> {at, count}. The retry
    // delay doubles with each consecutive failure (30s, 60s, ... 10 min) so an
    // unreachable image provider is not hammered forever.
    var imageFailures = {};
    var imageRetryTimers = {};
    var IMAGE_RETRY_MS = 30000;
    var IMAGE_RETRY_MAX_MS = 600000;
    function imageRetryDelay(count) {
        return Math.min(IMAGE_RETRY_MS * Math.pow(2, Math.max(0, count - 1)), IMAGE_RETRY_MAX_MS);
    }

    function fetchChartData(callback, errorCallback, attempt) {
        attempt = attempt || 1;
        // A busy station database (e.g. the analyzer writing) makes the
        // endpoint fail transiently; retry once before bothering the user.
        var retryOrFail = function (message) {
            if (attempt < 2) {
                setTimeout(function () {
                    fetchChartData(callback, errorCallback, attempt + 1);
                }, 2000);
            } else if (errorCallback) {
                errorCallback(message);
            }
        };
        var xhr = new XMLHttpRequest();
        xhr.onload = function () {
            if (xhr.status === 200 && xhr.responseText.length > 0) {
                // Parse and render fail differently: a bad response is worth
                // retrying, a rendering bug is deterministic and is not.
                var data;
                try {
                    data = JSON.parse(xhr.responseText);
                } catch (e) {
                    // Log what actually came back - the raw body is the only
                    // way to diagnose a corrupted response after the fact.
                    console.warn('Dashboard charts: could not parse JSON (attempt ' + attempt + ', ' +
                        xhr.responseText.length + ' bytes). Body starts with: ' +
                        JSON.stringify(xhr.responseText.slice(0, 300)), e);
                    retryOrFail('The station could not send heatmap data. It usually recovers on its own — try again in a moment.');
                    return;
                }
                try {
                    callback(data);
                } catch (e2) {
                    console.error('Dashboard charts: rendering failed', e2);
                    if (errorCallback) errorCallback('The heatmap could not be drawn. Refresh the page; if this keeps happening, check the browser console.');
                }
            } else {
                retryOrFail('The station database is busy right now. It usually recovers on its own — try again in a moment.');
            }
        };
        xhr.onerror = function () {
            console.warn('Dashboard charts: request failed');
            retryOrFail('The heatmap request failed. Check that the station is reachable, then try again.');
        };
        xhr.open('GET', 'overview.php?ajax_chart_data=true', true);
        xhr.send();
    }

    function renderHeatmap(canvas, data) {
        // Never replace the parent's innerHTML for the empty state: that
        // destroys the canvas, and every later cycle then crashes on the
        // detached element (the bug once misreported as a JSON parse error).
        var host = canvas.parentElement;
        if (!host) return;
        var emptyMsg = host.querySelector('.heatmap-empty-msg');
        if (!data || !data.species || data.species.length === 0) {
            canvas.style.display = 'none';
            if (!emptyMsg) {
                emptyMsg = document.createElement('p');
                emptyMsg.className = 'heatmap-empty-msg';
                emptyMsg.style.cssText = 'text-align:center;padding:20px;color:#888;';
                emptyMsg.textContent = 'No species yet today.';
                host.appendChild(emptyMsg);
            }
            return;
        }
        if (emptyMsg) emptyMsg.parentNode.removeChild(emptyMsg);
        canvas.style.display = '';

        var species = data.species;
        var hourly = data.hourly;
        var currentHour = data.currentHour;
        var weather = data.weather;
        var hasWeather = weather && Object.keys(weather).length > 0;

        var displayed = species;
        var speciesNames = displayed.map(function (s) { return s.name; });
        var hours = [];
        for (var h = 0; h < 24; h++) hours.push(h);

        // Build datasets: one dataset per species (row), each containing 24 values
        // We'll use a simple grid rendered on canvas since Chart.js 2.x doesn't have a built-in heatmap
        var ctx = canvas.getContext('2d');
        var width = Math.max(canvas.parentElement.clientWidth || canvas.width, 760);
        var cellHeight = 32;
        var labelWidth = Math.min(220, width * 0.35);
        var chartWidth = width - labelWidth - 10;
        var cellWidth = chartWidth / 24;

        // Make space for the weather row and the hour header
        var headerHeight = hasWeather ? 48 : 30;
        var totalHeight = headerHeight + (speciesNames.length * cellHeight) + 4;

        // Support High-DPI (Retina) displays for crystal clear text
        var dpr = window.devicePixelRatio || 1;
        canvas.width = width * dpr;
        canvas.height = totalHeight * dpr;
        canvas.style.width = width + 'px';
        canvas.style.height = totalHeight + 'px';
        ctx.scale(dpr, dpr);

        // Detect dark mode
        var bodyBg = getComputedStyle(document.body).backgroundColor;
        var isDark = false;
        if (bodyBg) {
            var match = bodyBg.match(/\d+/g);
            if (match && match.length >= 3) {
                isDark = (parseInt(match[0]) + parseInt(match[1]) + parseInt(match[2])) < 380;
            }
        }

        var textColor = isDark ? '#f1f5f9' : '#1e293b';
        var emptyColor = isDark ? '#1e293b' : '#e2e8f0';
        var borderColor = isDark ? '#334155' : '#cbd5e1';

        // Find max value for color scaling
        var maxVal = 1;
        speciesNames.forEach(function (name) {
            if (hourly[name]) {
                hours.forEach(function (h) {
                    if (hourly[name][h] && hourly[name][h] > maxVal) {
                        maxVal = hourly[name][h];
                    }
                });
            }
        });

        // Clear canvas
        ctx.clearRect(0, 0, canvas.width, canvas.height);

        function getWeatherEmoji(code, is_day) {
            if (code === undefined || code === null) return '';
            var isNight = is_day === 0;
            if (code === 0) return isNight ? '🌙' : '☀️';
            if (code >= 1 && code <= 3) return isNight ? '☁️' : '⛅';
            if (code === 45 || code === 48) return '🌫️';
            if (code >= 51 && code <= 55) return isNight ? '🌧️' : '🌦️';
            if (code >= 61 && code <= 65) return '🌧️';
            if (code >= 71 && code <= 75) return '❄️';
            if (code >= 80 && code <= 82) return isNight ? '🌧️' : '🌦️';
            if (code >= 95) return '⛈️';
            return '☁️';
        }

        // Draw hour headers and weather if available
        ctx.fillStyle = textColor;
        ctx.textAlign = 'center';
        hours.forEach(function (h) {
            var x = labelWidth + (h * cellWidth) + (cellWidth / 2);
            var label = h < 10 ? '0' + h : h.toString();
            var yHour = headerHeight - 8;

            if (h === currentHour) {
                ctx.fillStyle = isDark ? '#ffd54f' : '#e65100';
                ctx.font = 'bold 12px Roboto Flex, sans-serif';
            } else {
                ctx.fillStyle = textColor;
                ctx.font = '12px Roboto Flex, sans-serif';
            }
            ctx.fillText(label, x, yHour);

            // Draw Weather
            if (hasWeather && weather[h]) {
                var w = weather[h];
                var emoji = getWeatherEmoji(w.code, w.is_day);
                ctx.font = '13px sans-serif'; // Emoji font
                ctx.fillText(emoji, x, yHour - 16);
                ctx.font = '10px Roboto Flex, sans-serif';
                ctx.fillStyle = isDark ? '#aaaaaa' : '#666666';
                ctx.fillText(w.temp + '°', x, yHour - 28);
            }
        });

        // Draw grid
        species.forEach(function (s, rowIdx) {
            var name = s.name;
            var y = headerHeight + (rowIdx * cellHeight);

            // Species label & Thumbnail
            ctx.fillStyle = textColor;
            ctx.textAlign = 'right';
            ctx.font = '13px Roboto Flex, sans-serif';

            // Thumbnail
            if (s.image) {
                var img = imageCache[s.image];
                var failure = imageFailures[s.image];
                var retryDue = !failure || Date.now() - failure.at > imageRetryDelay(failure.count);
                if (!img && retryDue) {
                    img = new Image();
                    img.onload = function () {
                        img.onload = img.onerror = null; // release the captured data set
                        delete imageFailures[s.image];
                        // Redraw against whatever data is newest by now. A
                        // refresh that landed while this image was loading
                        // used to make the redraw bail, leaving the thumbnail
                        // undrawn until the next poll.
                        clearTimeout(window._heatmapTimer);
                        window._heatmapTimer = setTimeout(function () {
                            if (lastData) renderHeatmap(canvas, lastData);
                        }, 50);
                    };
                    img.onerror = function () {
                        // Without this, a thumbnail that failed once (network
                        // still reconnecting after the machine wakes, a CDN
                        // timeout) stayed cached as a broken image for the life
                        // of the page - blank until a manual refresh. Forget it
                        // and schedule one redraw, against whatever data is
                        // current by then, so the row self-heals without waiting
                        // for a poll. Hidden tabs wait for their next poll. One
                        // timer per image, so one failure cannot postpone
                        // another species' retry.
                        img.onload = img.onerror = null;
                        delete imageCache[s.image];
                        var count = (imageFailures[s.image] ? imageFailures[s.image].count : 0) + 1;
                        imageFailures[s.image] = { at: Date.now(), count: count };
                        clearTimeout(imageRetryTimers[s.image]);
                        imageRetryTimers[s.image] = setTimeout(function () {
                            delete imageRetryTimers[s.image];
                            if (document.visibilityState === 'visible' && lastData) renderHeatmap(canvas, lastData);
                        }, imageRetryDelay(count) + 1000);
                    };
                    img.src = s.image;
                    imageCache[s.image] = img;
                }
                if (img && img.complete && img.naturalWidth > 0) {
                    var imgSize = 24;
                    var imgX = 10;
                    var imgY = y + (cellHeight - imgSize) / 2;
                    ctx.save();
                    // Draw rounded thumbnail background
                    ctx.fillStyle = isDark ? '#334155' : '#f1f5f9';
                    ctx.beginPath();
                    ctx.roundRect ? ctx.roundRect(imgX, imgY, imgSize, imgSize, 4) : ctx.rect(imgX, imgY, imgSize, imgSize);
                    ctx.fill();
                    // Clip for rounded image
                    ctx.beginPath();
                    ctx.roundRect ? ctx.roundRect(imgX, imgY, imgSize, imgSize, 4) : ctx.rect(imgX, imgY, imgSize, imgSize);
                    ctx.clip();
                    ctx.drawImage(img, imgX, imgY, imgSize, imgSize);
                    ctx.restore();
                }
            }

            var displayName = name.length > 25 ? name.substring(0, 23) + '…' : name;
            ctx.fillStyle = textColor;
            ctx.fillText(displayName, labelWidth - 8, y + cellHeight / 2 + 4);

            // Hour cells
            hours.forEach(function (h) {
                var x = labelWidth + (h * cellWidth);
                var val = (hourly[name] && hourly[name][h]) ? hourly[name][h] : 0;

                if (val > 0) {
                    var intensity = Math.min(val / maxVal, 1);
                    // Sleek GitHub-like indigo/blue contribution scale
                    var r = Math.round(224 - intensity * 145);
                    var g = Math.round(231 - intensity * 160);
                    var b = Math.round(255 - intensity * 26);
                    if (isDark) {
                        r = Math.round(30 + intensity * 49);
                        g = Math.round(41 + intensity * 29);
                        b = Math.round(59 + intensity * 166);
                    }
                    ctx.fillStyle = 'rgb(' + r + ',' + g + ',' + b + ')';
                } else {
                    ctx.fillStyle = emptyColor;
                }

                // Rounded inner cells look much more premium
                var radius = 4;
                var rectX = x + 2.5;
                var rectY = y + 2.5;
                var rectW = cellWidth - 5;
                var rectH = cellHeight - 5;

                ctx.beginPath();
                ctx.moveTo(rectX + radius, rectY);
                ctx.arcTo(rectX + rectW, rectY, rectX + rectW, rectY + rectH, radius);
                ctx.arcTo(rectX + rectW, rectY + rectH, rectX, rectY + rectH, radius);
                ctx.arcTo(rectX, rectY + rectH, rectX, rectY, radius);
                ctx.arcTo(rectX, rectY, rectX + rectW, rectY, radius);
                ctx.fill();

                // Show count in cells
                if (val > 0) {
                    ctx.fillStyle = intensity > 0.4 ? '#fff' : textColor;
                    if (isDark) ctx.fillStyle = intensity > 0.4 ? '#fff' : '#94a3b8';
                    ctx.textAlign = 'center';
                    ctx.font = '600 12px Roboto Flex, sans-serif'; // Bolder font
                    ctx.fillText(val.toString(), x + cellWidth / 2, y + cellHeight / 2 + 4.5);
                }
            });
        });
    }

    // Tooltip for heatmap canvas
    function addHeatmapTooltip(canvas) {
        if (!canvas.parentElement) return;
        var tooltip = document.createElement('div');
        tooltip.className = 'chart-tooltip';
        tooltip.style.cssText = 'display:none;position:absolute;background:rgba(0,0,0,0.8);color:#fff;padding:6px 10px;border-radius:4px;font-size:12px;pointer-events:none;z-index:100;white-space:nowrap;';
        canvas.parentElement.style.position = 'relative';
        canvas.parentElement.appendChild(tooltip);

        var imgPreview = document.createElement('div');
        imgPreview.className = 'heatmap-img-preview';
        imgPreview.style.cssText = 'display:none;position:fixed;background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:6px;box-shadow:0 10px 25px rgba(0,0,0,0.3);z-index:99999;pointer-events:none;width:250px;height:250px;overflow:hidden;';
        imgPreview.innerHTML = '<img style="width:100%;height:100%;object-fit:cover;border-radius:8px;image-rendering:-webkit-optimize-contrast;image-rendering:high-quality;">';
        document.body.appendChild(imgPreview);

        canvas.addEventListener('mousemove', function (e) {
            if (!lastData) return;
            var species = lastData.species;
            var hourly = lastData.hourly;
            var weather = lastData.weather;
            var hasWeather = weather && Object.keys(weather).length > 0;

            var rect = canvas.getBoundingClientRect();
            // Use client coordinates relative to the bounding box (CSS pixels)
            var x = e.clientX - rect.left;
            var y = e.clientY - rect.top;
            var width = rect.width; // Use CSS width
            var labelWidth = Math.min(220, width * 0.35); // Matches renderHeatmap exactly
            var cellWidth = (width - labelWidth - 10) / 24;
            var headerHeight = hasWeather ? 48 : 30;
            var cellHeight = 32;

            var hour = Math.floor((x - labelWidth) / cellWidth);
            var row = Math.floor((y - headerHeight) / cellHeight);

            // The species label column (thumbnail + name) links to the Bird
            // page, mirroring the Timeline lanes; signal it with the cursor.
            var overSpeciesLabel = row >= 0 && row < species.length && x > 5 && x < labelWidth && !!species[row].sciName;
            canvas.style.cursor = overSpeciesLabel ? 'pointer' : 'default';

            // Thumbnail check (x: 10 to 34)
            if (row >= 0 && row < species.length && x > 5 && x < 40) {
                var s = species[row];
                if (s.image) {
                    var previewImg = imgPreview.querySelector('img');
                    if (previewImg.src !== s.image) previewImg.src = s.image;
                    imgPreview.style.display = 'block';

                    var previewX = e.clientX + 30;
                    var previewY = e.clientY - 125;

                    // Prevent going off screen
                    if (previewY < 10) previewY = 10;
                    if (previewX + 260 > window.innerWidth) previewX = e.clientX - 280;

                    imgPreview.style.left = previewX + 'px';
                    imgPreview.style.top = previewY + 'px';
                    tooltip.style.display = 'none';
                    return;
                }
            }
            imgPreview.style.display = 'none';

            if (x >= labelWidth && hour >= 0 && hour < 24 && row >= 0 && row < species.length) {
                var s = species[row];
                var name = s.name;
                var val = (hourly[name] && hourly[name][hour]) ? hourly[name][hour] : 0;
                var weatherStr = "";
                if (hasWeather && weather[hour]) {
                    var w = weather[hour];
                    var codes = { 
                        0: 'Clear', 1: 'Mostly Clear', 2: 'Partly Cloudy', 3: 'Overcast', 
                        45: 'Fog', 48: 'Rime Fog', 
                        51: 'Light Drizzle', 53: 'Moderate Drizzle', 55: 'Heavy Drizzle',
                        61: 'Slight Rain', 63: 'Moderate Rain', 65: 'Heavy Rain',
                        71: 'Slight Snow', 73: 'Moderate Snow', 75: 'Heavy Snow', 77: 'Snow Grains',
                        80: 'Slight Showers', 81: 'Moderate Showers', 82: 'Violent Showers',
                        85: 'Slight Snow Showers', 86: 'Heavy Snow Showers',
                        95: 'Thunderstorm', 96: 'Thunderstorm with Hail', 99: 'Thunderstorm with Heavy Hail'
                    };
                    var cond = codes[w.code] || 'Cloudy';
                    weatherStr = '<br><span style="color:#aaa;font-size:10px;">' + w.temp + ((window.BIRDNET_UNITS && window.BIRDNET_UNITS.tempSuffix) || '°F') + ' • ' + cond + '</span>';
                }
                tooltip.innerHTML = '<strong>' + name + '</strong><br>' + hour + ':00 — ' + val + ' detection' + (val !== 1 ? 's' : '') + weatherStr;
                tooltip.style.display = 'block';
                var tipX = e.clientX - rect.left + 30;
                // Flip to left side if near right edge
                if (tipX + 180 > canvas.parentElement.clientWidth) {
                    tipX = e.clientX - rect.left - 12;
                    // Measure actual tooltip width and shift left
                    tooltip.style.left = 'auto';
                    tooltip.style.right = (rect.right - e.clientX + 12) + 'px';
                    tooltip.style.top = (e.clientY - rect.top - 30) + 'px';
                } else {
                    tooltip.style.right = 'auto';
                    tooltip.style.left = tipX + 'px';
                    tooltip.style.top = (e.clientY - rect.top - 30) + 'px';
                }
            } else {
                tooltip.style.display = 'none';
            }
        });

        canvas.addEventListener('mouseleave', function () {
            tooltip.style.display = 'none';
            imgPreview.style.display = 'none';
            canvas.style.cursor = 'default';
        });

        canvas.addEventListener('click', function (e) {
            if (!lastData) return;
            var species = lastData.species;
            var rect = canvas.getBoundingClientRect();
            var x = e.clientX - rect.left;
            var y = e.clientY - rect.top;
            var labelWidth = Math.min(220, rect.width * 0.35);
            var hasWeather = lastData.weather && Object.keys(lastData.weather).length > 0;
            var headerHeight = hasWeather ? 48 : 30;
            var row = Math.floor((y - headerHeight) / 32);
            if (row >= 0 && row < species.length && x > 5 && x < labelWidth && species[row].sciName) {
                window.location = '?view=Bird&sci_name=' + encodeURIComponent(species[row].sciName);
            }
        });
    }

    // Cache last data for resize re-render
    var lastData = null;
    var refreshSeq = 0;

    // Public API
    window.DashboardCharts = {
        refresh: function () {
            var heatCanvas = document.getElementById('hourlyHeatmap');

            if (!heatCanvas) return;

            // Refreshes can overlap (a poll plus a visibility change); only the
            // newest request may replace the data and redraw, or an older,
            // slower response would overwrite a newer one.
            var mySeq = ++refreshSeq;
            fetchChartData(function (data) {
                if (mySeq !== refreshSeq) return;
                lastData = data;
                if (heatCanvas) {
                    var heatmapError = document.getElementById('heatmapError');
                    if (heatmapError) heatmapError.innerHTML = '';
                    renderHeatmap(heatCanvas, data);
                    var heatmapUpdated = document.getElementById('heatmapUpdated');
                    if (heatmapUpdated) {
                        heatmapUpdated.textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
                    }
                    // Only add tooltip once
                    if (!heatCanvas.dataset.tooltipInit) {
                        addHeatmapTooltip(heatCanvas);
                        heatCanvas.dataset.tooltipInit = 'true';
                    }
                }
            }, function (message) {
                var heatmapError = document.getElementById('heatmapError');
                if (heatmapError && window.BirdNETUI) {
                    BirdNETUI.setMessage(heatmapError, 'error', 'Heatmap unavailable', message);
                }
                var heatmapUpdated = document.getElementById('heatmapUpdated');
                if (heatmapUpdated) heatmapUpdated.textContent = 'Not updated';
            });
        }
    };

    // Re-render heatmap on resize/zoom so it fits the new container width
    var heatResizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(heatResizeTimer);
        heatResizeTimer = setTimeout(function () {
            if (lastData) {
                var heatCanvas = document.getElementById('hourlyHeatmap');
                if (heatCanvas) {
                    renderHeatmap(heatCanvas, lastData);
                }
            }
        }, 300);
    });

})();
