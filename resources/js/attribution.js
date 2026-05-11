(function () {
    'use strict';

    var KEY = window.__attributionKey || 'wc_attribution';

    var params = new URLSearchParams(window.location.search);
    var referrer = document.referrer || null;

    // Only update when the current page has a marketing signal.
    // A "signal" means there is at least one attribution parameter.
    var hasSignal = !!(
        params.get('gclid') ||
        params.get('fbclid') ||
        params.get('ttclid') ||
        params.get('utm_source') ||
        params.get('utm_medium') ||
        params.get('utm_campaign')
    );

    function extractDomain(url) {
        if (!url) return null;
        try { return new URL(url).hostname || null; }
        catch (e) { return null; }
    }

    function detectDevice() {
        var ua = navigator.userAgent.toLowerCase();
        var mobileRe = /mobile|android|iphone|ipad|ipod|blackberry|opera mini|iemobile/;
        if (mobileRe.test(ua)) {
            return /ipad|tablet/.test(ua) ? 'tablet' : 'mobile';
        }
        
        return 'desktop';
    }

    function buildTouch() {
        return {
            gclid: params.get('gclid'),
            fbclid: params.get('fbclid'),
            ttclid: params.get('ttclid'),
            utm_source: params.get('utm_source'),
            utm_medium: params.get('utm_medium'),
            utm_campaign: params.get('utm_campaign'),
            utm_content: params.get('utm_content'),
            utm_term: params.get('utm_term'),
            promo_code: params.get('promo'),
            landing_page: window.location.href,
            referrer: referrer,
            referring_domain: extractDomain(referrer),
            device_type: detectDevice(),
            captured_at: new Date().toISOString(),
        };
    }

    var existing = null;
    try {
        var raw = localStorage.getItem(KEY);
        if (raw) existing = JSON.parse(raw);
    } catch (e) { /* malformed — treat as missing */ }

    if (!existing) {
        // First visit — store as both initial and last
        var touch = buildTouch();
        var src = touch.utm_source;
        localStorage.setItem(KEY, JSON.stringify({
            initial: touch,
            last: touch,
            total_visits: 1,
            sources: src ? [src] : [],
        }));
    } else if (hasSignal) {
        // Subsequent visit with a signal — update last only
        var newTouch = buildTouch();
        existing.last = newTouch;
        existing.total_visits = (existing.total_visits || 1) + 1;

        var newSrc = newTouch.utm_source;
        if (newSrc) {
            if (!Array.isArray(existing.sources)) {
                existing.sources = [];
            }
            if (existing.sources.indexOf(newSrc) === -1) {
                existing.sources.push(newSrc);
            }
        }

        localStorage.setItem(KEY, JSON.stringify(existing));
    }
})();
