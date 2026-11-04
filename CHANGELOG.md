# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- OAuth2 authorization code (with PKCE), device flow and client credentials, with rotating refresh tokens refreshed under a cache lock.
- Pluggable `TokenStore` with a cache implementation, named seller accounts and runtime sellers (`Allegro::forUser()`).
- Orders: event journal, checkout forms, fulfillment status, tracking numbers, carriers, invoices.
- `OrderEventPoller`, `OfferEventPoller` and the `allegro:poll-orders` / `allegro:poll-offers` commands.
- Offers: listing, product-offers create/edit with operation polling, price and quantity change commands, offer events.
- Wysyłam z Allegro: shipments, labels, protocols, pickups and cancellation.
- Billing entries, message center, customer returns (beta), categories, parameters and product search.
- `allegro:device-login` command and a generic `request()` for every other endpoint.
