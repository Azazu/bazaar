# Bazaar

A multi-vendor e-commerce marketplace built with Laravel. Sellers manage their own storefronts and inventory; buyers browse a unified catalog, check out, and pay — with orders split into per-seller sub-orders behind the scenes.

## Tech stack

| Layer | Choice |
| --- | --- |
| Framework | Laravel 13 (PHP 8.4) |
| Database | MySQL 8 |
| Cache / queue / session | Redis 7 |
| Payments | Stripe (test mode) |
| Search | Laravel Scout |
| Testing | Pest |
| Quality | Pint, PHPStan (Larastan) |
| Runtime | Docker (nginx + php-fpm + MySQL + Redis) |

## Highlights

- **Money handled correctly** — amounts stored as integer minor units, never floats; all financial operations wrapped in DB transactions.
- **Concurrency-safe stock** — inventory is decremented under row locks (`lockForUpdate`) to prevent overselling on parallel orders.
- **Order state machine** — controlled transitions (`pending → paid → processing → shipped → delivered`, plus `cancelled` / `refunded`).
- **Idempotent payment webhooks** — replays never double-apply their effects.
- **Multi-vendor orders** — a single checkout fans out into per-seller sub-orders.
- **Roles & policies** — buyer / vendor / admin with fine-grained authorization.
- **REST API** — token auth (Sanctum), API Resources, Form Request validation, rate limiting.

## Getting started

**Requirements:** Docker and Docker Compose.

```bash
git clone git@github.com:Azazu/bazaar.git
cd bazaar
cp .env.example .env
make init        # build images, start the stack, install deps, generate key, migrate
```

The app is then available at **http://localhost:8080**.

## Common commands

```bash
make up / make down     # start / stop the stack
make sh                 # shell into the php container
make artisan <cmd>      # run an artisan command
make migrate            # apply migrations
make test               # run the test suite (Pest)
make pint               # format code
make stan               # static analysis
```

Run `make help` for the full list.

## Testing

```bash
make test
```

Critical paths are covered by feature tests: checkout, payment-webhook handling, stock decrement without overselling (including concurrent orders), order state transitions, and access control.

## Roadmap

- [ ] Catalog, product variants, categories, session cart
- [ ] Checkout, Stripe payments, order state machine, stock, reviews, coupons
- [ ] Multi-vendor: stores, sub-orders, seller dashboard, payouts, admin moderation
- [ ] REST API, faceted search, full test coverage, CI

## License

Released under the [MIT License](LICENSE).
