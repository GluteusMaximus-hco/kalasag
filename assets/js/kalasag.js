// shared js for KALASAG pages
(function () {
    'use strict';

    // ---------- map pin picker (report form, household, evacuation centers) ----------
    // usage: KALASAG.pinPicker({ map: 'pickMap', lat: 'lat', lng: 'lng', acc: 'acc', locate: 'locateBtn', note: 'pinNote', center: [lat, lng], autoLocate: true })
    function pinPicker(o) {
        const latEl = document.getElementById(o.lat), lngEl = document.getElementById(o.lng);
        const accEl = o.acc ? document.getElementById(o.acc) : null;
        const note = o.note ? document.getElementById(o.note) : null;
        const say = (msg, bad) => { if (note) { note.textContent = msg; note.classList.toggle('text-danger', !!bad); } };

        const start = latEl.value && lngEl.value ? [+latEl.value, +lngEl.value] : o.center;
        const map = L.map(o.map, { scrollWheelZoom: false }).setView(start, latEl.value ? 18 : 16);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(map);

        let marker = null;
        function place(latlng, acc, msg) {
            // saved pins come in as [lat, lng] arrays, gps/taps as {lat, lng}, so make them the same
            // (without this a saved pin turned into NaN after a form error and every resend failed)
            latlng = L.latLng(latlng);
            if (!marker) {
                marker = L.marker(latlng, { draggable: true }).addTo(map);
                marker.on('dragend', () => { const p = marker.getLatLng(); set(p, null); say('Pin moved by hand.'); });
            } else {
                marker.setLatLng(latlng);
            }
            set(latlng, acc);
            if (msg) say(msg);
        }
        function set(p, acc) {
            latEl.value = (+p.lat).toFixed(7);
            lngEl.value = (+p.lng).toFixed(7);
            // empty = pinned by hand, so dont turn it into 0 m
            if (accEl) accEl.value = (acc == null || acc === '') ? '' : Math.round(acc);
            latEl.dispatchEvent(new Event('change'));
        }
        if (latEl.value && lngEl.value) place(start, accEl ? accEl.value : null);

        // tap the map to drop or move the pin
        map.on('click', e => place(e.latlng, null, 'Pin placed by hand.'));

        function locate() {
            if (!('geolocation' in navigator)) { say('This browser cannot share location. Tap the map to place the pin.', true); return; }
            say('Getting your location...');
            navigator.geolocation.getCurrentPosition(
                pos => {
                    const p = { lat: pos.coords.latitude, lng: pos.coords.longitude };
                    place(p, pos.coords.accuracy, 'Pinned using your GPS' + (pos.coords.accuracy >= 1 ? ' (about ' + Math.round(pos.coords.accuracy) + ' m accurate)' : '') + '. Drag the pin if it is off.');
                    map.setView(p, 18);
                },
                () => say('Location is off or blocked. Tap the map to place the pin instead.', true),
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
            );
        }
        if (o.locate) document.getElementById(o.locate).addEventListener('click', locate);
        if (o.autoLocate && !latEl.value) locate();

        // leaflet needs this when the map starts hidden (tabs, modals)
        setTimeout(() => map.invalidateSize(), 200);
        return map;
    }

    // ---------- small read-only maps ----------
    function miniMaps() {
        document.querySelectorAll('.mini-map').forEach(el => {
            if (typeof L === 'undefined') return;
            const p = [+el.dataset.lat, +el.dataset.lng];
            const m = L.map(el, { scrollWheelZoom: false, dragging: !L.Browser.mobile, zoomControl: true }).setView(p, 17);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(m);
            L.marker(p).addTo(m);
        });
    }

    // ---------- photo previews with the 3 photo / 5 MB rule ----------
    function photoPreviews() {
        document.querySelectorAll('input[type=file][data-preview]').forEach(input => {
            const box = document.getElementById(input.dataset.preview);
            const max = +(input.dataset.max || 3), maxMb = +(input.dataset.maxMb || 5);
            input.addEventListener('change', () => {
                box.innerHTML = '';
                input.setCustomValidity('');
                const files = [...input.files];
                if (files.length > max) input.setCustomValidity('You can attach up to ' + max + ' photos only.');
                if (files.some(f => f.size > maxMb * 1024 * 1024)) input.setCustomValidity('Each photo must be ' + maxMb + ' MB or smaller.');
                if (input.validationMessage) { box.innerHTML = '<div class="text-danger small">' + input.validationMessage + '</div>'; return; }
                files.forEach(f => {
                    const img = document.createElement('img');
                    img.src = URL.createObjectURL(f);
                    img.alt = 'Selected photo';
                    img.onload = () => URL.revokeObjectURL(img.src);
                    box.appendChild(img);
                });
            });
        });
    }

    // ---------- "are you sure" on dangerous buttons ----------
    function confirms() {
        document.addEventListener('submit', e => {
            const btn = e.submitter;
            const msg = (btn && btn.dataset.confirm) || e.target.dataset.confirm;
            if (msg && !window.confirm(msg)) e.preventDefault();
        });
    }

    // ---------- stop double submits on slow connections ----------
    function oneSubmit() {
        document.addEventListener('submit', e => {
            if (e.defaultPrevented || !e.target.checkValidity()) return;
            const btn = e.submitter;
            if (btn && !btn.dataset.noLock) {
                // let the click go through first, then lock the button
                setTimeout(() => { btn.disabled = true; btn.classList.add('is-busy'); }, 0);
            }
        });
    }

    function autoscroll() {
        document.querySelectorAll('[data-autoscroll]').forEach(el => el.scrollTop = el.scrollHeight);
    }

    // coming back with the browser Back button shows the old page from cache with the button still locked
    window.addEventListener('pageshow', e => {
        if (e.persisted) document.querySelectorAll('.is-busy').forEach(b => { b.disabled = false; b.classList.remove('is-busy'); });
    });

    window.KALASAG = { pinPicker, miniMaps };
    document.addEventListener('DOMContentLoaded', () => { photoPreviews(); confirms(); oneSubmit(); autoscroll(); miniMaps(); });
})();
