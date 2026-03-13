# Pynarae_Tracking

Magento 2 / Adobe Commerce custom module for branded order tracking and shipment lookup.

This module provides:

- Guest order lookup by:
  - order number + email or phone
  - tracking number (only if the tracking number belongs to this store)
- Track123 integration
- Automatic tracking registration after shipment track creation
- Webhook endpoint support
- Local ownership validation before external tracking queries
- Automatic additional verification handling for carriers that may require:
  - postal code
  - phone suffix
- Branded storefront tracking UI

## Module identity

- Magento module name: `Pynarae_Tracking`
- Composer package type: `magento2-module`
- Namespace: `Pynarae\\Tracking`

## Supported platform

- Magento Open Source / Adobe Commerce 2.4.x
- PHP 8.1+

## Repository purpose

This repository contains the **module source code only**.

The repository root is the Magento module root.  
When deploying to Magento, copy the repository contents into:

```bash
app/code/Pynarae/Tracking
```

## Core features

### Guest lookup modes

#### Order lookup

order number

email or phone

#### Tracking lookup

tracking number

only returns results if the tracking number already exists in this Magento store

### Track123 integration

The module integrates with Track123 for:

courier detection

tracking registration

tracking query

webhook ingestion

### Additional carrier verification

For carriers that require extra verification (for example postal code or phone suffix):

The module first tries normal tracking without extra fields

If Track123 indicates extra verification is required:

it automatically retries using order-derived values from Magento

if still unresolved, it renders a customer-facing verification form

If the Track123 response is unclear, the module tries `postalCode` first

### Important implementation rules

Never return tracking results for shipment tracking numbers that do not belong to this Magento store

Never expose order-level private customer data in tracking-number-only mode

Prefer Magento local validation before any external tracking request

Prefer adaptive verification over blindly sending postal code / phone suffix for every carrier

### Required Magento module files

This module is expected to include:

composer.json

registration.php

etc/module.xml

## Installation

Copy the module into Magento:

```bash
cp -R Pynarae_Tracking /path/to/magento/app/code/Pynarae/Tracking
```

Then run:

```bash
php bin/magento module:enable Pynarae_Tracking
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy -f en_US
php bin/magento cache:flush
```

## Configuration

Magento Admin:

Stores -> Configuration -> Pynarae -> Order Tracking

Sensitive config should be set via CLI when possible:

```bash
php bin/magento config:sensitive:set pynarae_tracking/api/api_secret 'YOUR_TRACK123_SECRET'
php bin/magento cache:flush
```

## Frontend routes

/track-order

/track-order/lookup

/track-order/webhook


## API integration reference

- See `TRACK123_API_REFERENCE.md` for a module-focused Track123 API reference and a concrete list of fields sent during registration.

## Development notes

This repository is intended to be edited by humans and AI coding agents.

Please read:

AGENTS.md

.github/copilot-instructions.md

CONTRIBUTING.md

before making non-trivial changes.

### Safety notes

Do not commit:

production API secrets

Magento generated/

Magento pub/static/

local environment dumps

database dumps

private customer data

webhook payloads containing real personal information

## License

Proprietary unless explicitly changed.

After confirming real webhook headers and signatures from Track123, turn strict validation back on.
