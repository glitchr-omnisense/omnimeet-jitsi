# omnimeet/jitsi

A video meeting on a **Jitsi Meet** instance, for [omniscient/omnimeet](https://github.com/glitchr-studio/omnimeet):
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

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by 8x8, Inc. (Jitsi, meet.jit.si, JaaS), nor by whoever runs the instance it is pointed
to. It reaches their service on behalf of whoever runs it, and it does not redistribute their data
beyond the short excerpts recorded as test fixtures. Its MIT licence covers this package's code only
and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms of the instance
it reaches (for meet.jit.si, [meet.jit.si Terms of
Service](https://jitsi.org/meet-jit-si-terms-of-service/); for JaaS, [8x8's JaaS
terms](https://8x8.com/cpaas-order-terms/jaas-module)), for obtaining whatever they require (an
account, a key, a licence or subscription, an attribution or a citation, a rate limit to respect),
and for any use made of what it returns. The authors publish it free of charge, make no commercial
use of it, and accept no liability for how it is used (see [LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
