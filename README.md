# omnimeet/jitsi

A video meeting on a **Jitsi Meet** instance, for [glitchr/omnimeet](https://github.com/glitchr-studio/omnimeet):
your own instance, a public one, or 8x8's Jitsi as a Service - embedded in the page through
Jitsi's IFrame API and driven from it, a JSON Web Token per participant where the instance
checks one, several people. Nothing is asked of the instance by this package: a Jitsi room
exists from the moment its first participant comes in, and a token is signed on this side.

```php
$gateway = (new JitsiGatewayFactory())->create(['domain' => 'meet.example.org', 'app_id' => 'my_app', 'app_secret' => $secret]);

$meeting = $gateway->open(new Meeting($token, $startsAt, $endsAt));
$meeting->reference;                       // the room's name: 32 hex characters, the same for the same key

$access = $gateway->join($meeting, Participant::host('u42', 'Dr Tilleul', 'fr'));
$access->url;                              // https://meet.example.org/<room>?jwt=...#config...: a frame, or a link
$access->script;                           // public/js/jitsi.js: drives the frame through the IFrame API
$access->options;                          // domain, api, room, role, jwt, name, locale, config
$access->origins;                          // ['https://meet.example.org']: say so before loading anything
```

```yaml
omnimeet:
    gateways:
        group:
            factory: jitsi
            options:
                domain: meet.example.org                  # required
                app_id: my_app                            # with app_secret: a token per participant (HS256)
                app_secret: '%env(JITSI_APP_SECRET)%'
```

It does `open` and `join`, and `notify` with a JaaS `webhook_secret`. **Not `close` nor
`fetch`**: Jitsi's documentation shows no way to end a conference or read its state from a
server - a moderator's page ends it through the IFrame API (`jitsi.js` does, on `omnimeet:end`).
`thirdParty: true` unless the instance is yours; `endToEnd: false`: the instance's bridge carries
the media.

[Documentation](docs/index.md): the three kinds of instance, the token, the frame, an instance
that refuses to be framed (`embed: false`), the webhooks, and what was verified - a real meeting
on a public open instance; tokens, JaaS and webhooks against the documentation only.

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
