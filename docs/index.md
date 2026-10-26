---
title: omnimeet/jitsi
order: 1
---

# omnimeet/jitsi

## Installation

```sh
composer require omnimeet/jitsi
```

PHP 8.2 or later, and `omnisense/omnimeet`: nothing else (`ext-openssl` only for tokens signed with
a private key). No HTTP client: this package calls nothing.

```php
use Omnimeet\Jitsi\JitsiGatewayFactory;

$gateway = (new JitsiGatewayFactory())->create(['domain' => 'meet.example.org']);
```

## The sources

Written from Jitsi's documentation as it read on 2026-10-05, and nothing else:

- the handbook's [IFrame API](https://jitsi.github.io/handbook/docs/dev-guide/dev-guide-iframe)
  (`JitsiMeetExternalAPI(domain, options)`, its
  [commands](https://jitsi.github.io/handbook/docs/dev-guide/dev-guide-iframe-commands),
  [events](https://jitsi.github.io/handbook/docs/dev-guide/dev-guide-iframe-events) and
  [functions](https://jitsi.github.io/handbook/docs/dev-guide/dev-guide-iframe-functions)), its
  [configuration](https://jitsi.github.io/handbook/docs/dev-guide/dev-guide-configuration) and
  [URL parameters](https://jitsi.github.io/handbook/docs/user-guide/user-guide-advanced);
- lib-jitsi-meet's [doc/tokens.md](https://github.com/jitsi/lib-jitsi-meet/blob/master/doc/tokens.md)
  (the JWT a Prosody instance checks) and the handbook's token authentication pages;
- Jitsi as a Service: [the JWT](https://developer.8x8.com/jaas/docs/api-keys-jwt), the
  [IFrame integration](https://developer.8x8.com/jaas/docs/iframe-api-integration), the webhooks'
  [payload](https://developer.8x8.com/jaas/docs/webhooks-payload) and
  [signature](https://developer.8x8.com/jaas/docs/webhooks-signatures).

What they do not show is not done: closing a room or reading its state from a server
(`close()`, `fetch()`: `NotSupportedException`), callbacks outside JaaS, a moderator claim for a
self-hosted instance.

## Three kinds of instance

| | Options | A participant enters with |
|---|---|---|
| **Open** | `domain` | the room's name: it is the only secret |
| **Token authentication** | `domain`, `app_id`, and `app_secret` (HS256) or `private_key` + `key_id` (RS256, an instance that validates against a key server) | a token made for them and this room |
| **Jitsi as a Service** | `domain: 8x8.vc`, `app_id` (the AppID, `vpaas-magic-cookie-...`), `private_key`, `key_id` (`<AppID>/<key id>`) | JaaS's token; rooms and the script live under the AppID |

| Option | Default | |
|---|---|---|
| `domain` | required | the instance's host (`meet.example.org`, `8x8.vc`) |
| `app_id`, `app_secret` | | Prosody's `app_id` and `app_secret` |
| `private_key`, `key_id` | | a PEM or the path of its file; the token's `kid` |
| `audience`, `issuer`, `subject` | `jitsi`, `app_id`, the domain | the token's `aud`, `iss`, `sub`, as the instance accepts them (`asap_accepted_audiences`, `asap_accepted_issuers`; a tenant for `sub`) |
| `token_ttl` | 7200 | seconds a token lasts when the meeting has no end; with one it lapses five minutes after |
| `room_prefix`, `room_secret` | `''`, none | before the room's name; set the secret when meeting keys can be guessed |
| `config` | `{disableInviteFunctions: true, disableThirdPartyRequests: true}` | `config.js` overrides (`configOverwrite`) |
| `interface_config` | `{}` | `interface_config.js` overrides |
| `features` | all off | JaaS: `recording`, `livestreaming`, `transcription`, `outbound-call` |
| `webhook_secret`, `webhook_tolerance` | none, 300 | JaaS: the endpoint's signing secret; seconds a callback may be old |
| `jaas` | told from the domain or the AppID | force it |

## The room

`open()` asks nothing of the instance: it names the room - the first 32 hexadecimal characters of
a SHA-256 of the meeting's key (lower case: Jitsi reads room names so), after `room_prefix`. The
same key gives the same room; the name says nothing of the key. **On an open instance the name is
all that protects the room**: open meetings with an unguessable key (a random token), or set
`room_secret` (the name is then an HMAC of the key).

## The token

Self-hosted, as lib-jitsi-meet's token plugin reads it:

```json
{"aud": "jitsi", "iss": "my_app", "sub": "meet.example.org", "room": "<the room>", "exp": 1791175385,
 "context": {"user": {"id": "u42", "name": "Dr Tilleul"}}}
```

`room` is this room and no other; `context.user` carries the opaque identifier and the name the
application gave - strings only, no e-mail, no avatar. Who moderates is the instance's
configuration: the documentation read shows no claim for it.

JaaS: `aud: "jitsi"`, `iss: "chat"`, `sub` the AppID, `nbf`, `context.user.moderator` as the
string `"true"` for a host, `context.features`, signed RS256 with `kid` in the header.

`Jwt::sign()` and `Jwt::verify()` are plain PHP (`hash_hmac`, `openssl_sign`): no JWT library.

## The frame

`join()` answers an `Access` of kind `FRAME`:

- `url` - the room's address, usable as it is in an `<iframe allow="camera; microphone; display-capture">`
  or as a link: the token as the `jwt` parameter, the overrides after the `#`
  (`config.prejoinConfig.enabled=false`), the participant's language as `config.defaultLanguage`;
- `script` and `options` - `public/js/jitsi.js` and what `JitsiMeetExternalAPI(domain, options)`
  takes, to drive the frame from the page:

```html
<div data-omnimeet-jitsi data-options='{{ access.options|json_encode }}'>
    <div data-omnimeet-frame style="height: 70vh"></div>
</div>
<script src="/the/engine/jitsi.js" defer></script>
<script>
    const root = document.querySelector('[data-omnimeet-jitsi]');
    enterButton.onclick = () => root.omnimeet.join();        // only now is external_api.js loaded from the instance
    leaveButton.onclick = () => root.omnimeet.leave();       // "hangup"
    endButton.onclick = () => root.omnimeet.end();           // a host: "endConference" where the instance allows it, a hang up otherwise
    root.addEventListener('omnimeet:state', (e) => e.detail.state);         // loading, joined, left, error
    root.addEventListener('omnimeet:participants', (e) => e.detail.count);
</script>
```

The same can be said with events on the root: `omnimeet:join`, `omnimeet:leave`, `omnimeet:end`.
`data-autostart="1"` enters at once. `root.omnimeet.api()` is the `JitsiMeetExternalAPI` object,
for anything else the IFrame API does.

**Nothing is loaded from the instance before the participant enters.** `access.origins` names it:
say so on the page and wait for the visitor's consent; allow it in `Permissions-Policy`
(`camera=(self "https://meet.example.org")`) and in the Content-Security-Policy (`frame-src`,
`script-src`).

A waiting room, "the host has arrived", the end of the meeting: keep them in the application, on
its own record - a room on Jitsi is entered by whoever has its name (and a token, where one is
checked).

## Webhooks (JaaS)

```php
$notification = $gateway->notify($request->getContent(), $request->headers->all());
$notification->event;        // OPENED, JOINED, LEFT, ENDED, RECORDING_STARTED, RECORDING_ENDED, RECORDING_READY, OTHER
$notification->type;         // ROOM_CREATED, PARTICIPANT_JOINED, RECORDING_UPLOADED...
$notification->reference;    // the room, as open() named it ("fqn" without the AppID)
$notification->participant;  // JOINED and LEFT: the id the token carried, the role, the name
$notification->id;           // "idempotencyKey": the same for a callback sent twice
$notification->data;         // the whole payload (data.preAuthenticatedLink for a recording)
```

`X-Jaas-Signature` (`t=<timestamp>,v1=<base64 HMAC-SHA256 of "<t>.<body>">`) is checked with
`webhook_secret`, in constant time, and refused when older than `webhook_tolerance`:
`InvalidNotificationException`. Without `webhook_secret` the gateway does not support `notify`.

## An instance that refuses to be framed, or to start

Two things an instance decides, seen on public ones on 2026-10-05:

- **It may forbid being embedded** (`X-Frame-Options: SAMEORIGIN`, a `frame-ancestors` policy):
  the frame stays empty, and no event says so. `jitsi.js` reports `error` with the name `timeout`
  when the conference has not started after 60 seconds (`timeout` in its options): offer
  `access.url` in a tab of its own then - or set `embed: false`, and `join()` answers the address
  alone (an `Access` of kind `LINK`).
- **It may wait for a moderator who signs in** ("secure domain"): the room does not start for
  anonymous participants. Such an instance needs its own accounts, or tokens.

| Option | Default | |
|---|---|---|
| `embed` | `true` | `false`: never a frame - the participant is sent to the room's address |

## Verified, and not

| | |
|---|---|
| A meeting between two browsers on a public open instance | **done on 2026-10-05**, through `omnibase/office`'s room on the Maison de santé site, two headless Chromes with fake cameras, `meet.evolix.org` (open, embeddable): nothing asked of the instance before each had entered, both in the conference (`videoConferenceJoined`, two participants on each side, the host named and moderator, the guest unnamed), ended from the host's page |
| An instance that forbids framing | seen: `meet.ffmuc.net` - the frame stays empty; hence the timeout and `embed: false` |
| An instance that waits for a signed-in moderator | seen: `jitsi.hadoly.fr` - "Waiting for a moderator" |
| Tokens (HS256, RS256): their shape, claims and signature | by the tests, against the documentation; **not verified against a real instance** - no instance with token authentication at hand |
| Jitsi as a Service: token, rooms, script address | by the tests, against the documentation; **not verified in real**: no account |
| JaaS webhooks | by the tests, on the documentation's payloads and signature scheme; **not verified in real** |
| `endConference` from the host's page | sent when the instance lists it among its commands; what the instance then does was **not checked** (each page left the conference when the room closed) |
| `embed: false` | by the tests; not tried in a browser |
