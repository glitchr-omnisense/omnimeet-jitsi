# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release. Omnimeet Jitsi: a video meeting on a Jitsi Meet instance - one's own, a public
one, or 8x8's Jitsi as a Service - embedded through the IFrame API and driven from the page, a JSON Web
Token per participant (shared secret or key pair), several people, JaaS webhooks read and their
signature checked.

### Release

- Requires `omnisense/omnimeet: ^1.0`: stable versions, so `composer require omnimeet/jitsi` installs in
  a project at Composer's default minimum-stability. `extra.branch-alias` names 1.x (`1.x-dev`); the CI
  (`.github/workflows/ci.yml`, glitchr-studio/ci's php-package.yml) is callable, and `release.yml` runs
  it on a tag before glitchr-studio/ci's php-release.yml.
