/*
 * omnimeet/jitsi - drives an embedded Jitsi Meet through its IFrame API
 * (https://jitsi.github.io/handbook/docs/dev-guide/dev-guide-iframe). No
 * dependency but the instance's own external_api.js, loaded when the
 * participant enters and not before: until then the instance is not asked
 * anything.
 *
 * The page gives it a root element:
 *
 *   data-omnimeet-jitsi     marks the root
 *   data-options            the Access options, as JSON: domain, api (the address of
 *                           external_api.js), room, role, and when there are any jwt, name,
 *                           locale, subject, config, interfaceConfig; timeout, the seconds
 *                           after which a meeting that has not started is an error (60)
 *   data-autostart="1"      enter at once; otherwise the page says when
 *
 * and, inside it, [data-omnimeet-frame] where the frame goes (the root itself
 * when there is none).
 *
 * The page tells it what to do with events on the root - "omnimeet:join",
 * "omnimeet:leave" (hang up), "omnimeet:end" (a host ends the conference for
 * everyone, where the instance allows it; hangs up otherwise) - or through
 * root.omnimeet.{join, leave, end}(). It tells the page where it stands on
 * the root: data-visio-state and an "omnimeet:state" event ({state}:
 * loading, joined, left, error - an error has a message, and the name
 * "timeout" when the frame never started), and "omnimeet:participants"
 * ({count}).
 */
(function () {
    'use strict';

    var scripts = {};
    function load(src) {
        if (window.JitsiMeetExternalAPI) return Promise.resolve();
        if (!scripts[src]) {
            scripts[src] = new Promise(function (resolve, reject) {
                var script = document.createElement('script');
                script.src = src;
                script.async = true;
                script.onload = function () { window.JitsiMeetExternalAPI ? resolve() : reject(new Error('external_api.js did not define JitsiMeetExternalAPI')); };
                script.onerror = function () { delete scripts[src]; reject(new Error('external_api.js could not be loaded from ' + src)); };
                document.head.appendChild(script);
            });
        }
        return scripts[src];
    }

    function start(root) {
        if (root.dataset.jitsiBound) return;
        root.dataset.jitsiBound = '1';

        var options = {};
        try { options = JSON.parse(root.dataset.options || '{}'); } catch (e) {}
        var holder = root.querySelector('[data-omnimeet-frame]') || root;
        var api = null, joining = false, waiting = null;

        function emit(name, detail) {
            try { root.dispatchEvent(new CustomEvent(name, { bubbles: true, detail: detail || {} })); } catch (e) {}
        }
        function state(key, detail) {
            root.dataset.visioState = key;
            emit('omnimeet:state', Object.assign({ state: key }, detail || {}));
        }
        function count() {
            if (api) emit('omnimeet:participants', { count: api.getNumberOfParticipants() });
        }
        function dispose() {
            clearTimeout(waiting);
            if (!api) return;
            try { api.dispose(); } catch (e) {}
            api = null;
            joining = false;
        }

        function join() {
            if (api || joining || !options.domain || !options.room) return;
            joining = true;
            state('loading');
            load(options.api || ('https://' + options.domain + '/external_api.js')).then(function () {
                var settings = { roomName: options.room, parentNode: holder, width: '100%', height: '100%' };
                if (options.jwt) settings.jwt = options.jwt;
                if (options.locale) settings.lang = options.locale;
                if (options.name) settings.userInfo = { displayName: options.name };
                if (options.config) settings.configOverwrite = options.config;
                if (options.interfaceConfig) settings.interfaceConfigOverwrite = options.interfaceConfig;
                api = new window.JitsiMeetExternalAPI(options.domain, settings);
                // No event says a frame was refused (an instance may forbid being embedded): after a while without
                // the conference, say so - the page can offer the address instead.
                var started = api;
                waiting = setTimeout(function () {
                    if (api === started && root.dataset.visioState === 'loading') state('error', { name: 'timeout', message: 'The meeting did not start: the instance may refuse to be embedded.' });
                }, (options.timeout || 60) * 1000);
                api.addListener('videoConferenceJoined', function (event) {
                    clearTimeout(waiting);
                    if (options.subject && options.role === 'host') api.executeCommand('subject', options.subject);
                    state('joined', { id: event && event.id });
                    count();
                });
                api.addListener('participantJoined', count);
                api.addListener('participantLeft', count);
                api.addListener('videoConferenceLeft', function () { state('left'); });
                api.addListener('readyToClose', function () { dispose(); state('left'); });
                api.addListener('errorOccurred', function (error) { if (error && error.isFatal) state('error', { name: error.name, message: error.message }); });
            }).catch(function (error) {
                joining = false;
                state('error', { message: String(error && error.message || error) });
            });
        }
        function leave() {
            if (!api) return;
            try { api.executeCommand('hangup'); } catch (e) { dispose(); state('left'); return; }
            // "readyToClose" says the hang up is done; a frame that never started says nothing: it goes all the same.
            setTimeout(function () { if (api) { dispose(); state('left'); } }, 4000);
        }
        function end() {
            if (!api) return;
            // "endConference": for everyone, a moderator only, where the deployment enables it; a hang up otherwise.
            var supported = [];
            try { supported = api.getSupportedCommands() || []; } catch (e) {}
            if (options.role === 'host' && supported.indexOf('endConference') >= 0) {
                try { api.executeCommand('endConference'); } catch (e) {}
                setTimeout(function () { if (api) leave(); }, 1500);
                return;
            }
            leave();
        }

        root.omnimeet = { join: join, leave: leave, end: end, dispose: dispose, api: function () { return api; } };
        root.addEventListener('omnimeet:join', join);
        root.addEventListener('omnimeet:leave', leave);
        root.addEventListener('omnimeet:end', end);
        window.addEventListener('pagehide', dispose);

        if (root.dataset.autostart === '1') join();
    }

    function boot() { document.querySelectorAll('[data-omnimeet-jitsi]').forEach(start); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
    window.OmnimeetJitsi = { boot: boot, start: start };
    ['transparent:load', 'turbo:load'].forEach(function (name) { window.addEventListener(name, boot); });
})();
