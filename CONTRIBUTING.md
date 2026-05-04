# Contributing

## Developer Certificate of Origin (DCO)

Every commit must be signed off:

```bash
git commit -s -m "Your message"
```

CI enforces this on every PR. See https://developercertificate.org for the full text.

## License

By contributing you agree your contribution is licensed under Apache 2.0 (the project's LICENSE).

Every source file must carry an `SPDX-License-Identifier: Apache-2.0` header.

## Dev install (while the repo is private)

The connector consumes the OpenSalesTax PHP SDK at `ejosterberg/opensalestax`, which is also currently private. Both consume each other via Composer **path repositories**. Recommended layout:

```
~/projects/
├── opensalestax-php/                 ← the SDK
└── opensalestax-stripe-php/          ← this connector
```

`composer.json` here points at `../opensalestax-php` via a path repo. `composer install` will junction it into `vendor/ejosterberg/opensalestax`.

## Running tests

Unit tests (no network):

```bash
composer test
```

Integration tests (gated on env vars; skipped when absent):

```bash
export STRIPE_TEST_KEY=sk_test_...
export OPENSALESTAX_BASE_URL=http://your-engine:8080
composer test-live
```

## Static analysis

```bash
composer stan      # phpstan --level=max
composer lint      # php-cs-fixer dry-run
composer lint-fix  # php-cs-fixer apply
```

## Reporting issues

GitHub issues. Include:
- The OpenSalesTax engine version (from `GET /v1/health`)
- The connector version (from `composer show ejosterberg/opensalestax-stripe-php`)
- A minimal reproducer (Stripe object JSON if applicable)

Security issues: email ejosterberg@gmail.com directly.
