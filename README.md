# alvora-erpnext-link

A [Fleetbase](https://github.com/fleetbase/fleetbase) extension for AlvoraVector (Alvora's fleet and delivery product built on Fleetbase). It adds an **ERPNext** tab and an Overview panel to a platform admin's view of an organization, and connects that organization to its client's ERPNext site in a few steps:

1. checks the site address (public `https://` only, never a private network),
2. checks the ERPNext key and secret without changing anything,
3. creates a Fleetbase key and a signed webhook for that one organization,
4. hands the pair to the connector app on the client's ERPNext, then removes any old pair,
5. sends a signed test event and waits for ERPNext to confirm it.

The ERPNext key and secret are used for one connection and never stored: they travel from the web request to the queue job through a one-time, encrypted cache entry that lives at most two minutes.

Licence: AGPL-3.0-or-later (see `LICENSE`). This repository holds no client names, hosts, keys or secrets; `pnpm lint:privacy` fails if any appear.

## Layout

| Path | What |
|---|---|
| `server/src` | Laravel package: routes (`/int/v1/alvora/...`, platform admins only), controller, queued job, outbound-call guard, migration |
| `addon`, `app` | Ember engine: the tab and the Overview panel, registered through the console's `console:admin:organization:tabs` and `...:overview-panels` registries |
| `translations/en-us.yaml` | every visible string |
| `server/tests` | `unit` (no application needed) and `Integration` (boots the AlvoraVector api shell) |
| `tests` | Ember tests |

## Tests

```sh
# JavaScript (Node 22)
pnpm install && pnpm lint && pnpm test:ember

# PHP: unit tests on their own
composer install && composer test:unit -- --testsuite unit

# PHP: integration tests need the AlvoraVector api shell with this package installed, a throwaway MySQL and Redis
ALVORA_API_PATH=/path/to/api vendor/bin/phpunit -c phpunit.xml.dist --testsuite integration
```

## Installing

Pinned by exact version in the host's `api/composer.json` (a VCS repository entry) and `console/package.json` (a git dependency on the same tag), with the engine named in the console's `EXTENSIONS`. Never a branch.
