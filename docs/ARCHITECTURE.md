# Architecture

WordPress Connector is the advanced WordPress execution layer behind WP Agent.

## Default live flow

`ChatGPT -> WP Agent -> WordPress REST -> WordPress Connector REST controller -> Runner -> Registry -> semantic adapter -> WordPress`

GitHub is not part of the live request path. It remains the source-control, CI, review and release system for this repository.

## Runtime ownership

- ChatGPT and the relevant Webactueel skill own the requested business/domain decision.
- WP Agent owns the standard ChatGPT-to-WordPress transport and site connection.
- WordPress Connector owns only the advanced semantic operations it explicitly registers.
- WordPress owns authentication, authorization and runtime state.
- GitHub owns code history, CI and releases only.

## Shared runtime

REST and optional local WP-CLI use the same semantic Registry, Runner, policy gates, fingerprint/idempotency logic and rollback engine. A transport must not bypass those layers.

The connector keeps `/health`, `/assets` and `/execute` under `webactueel-wordpress-connector/v1` because WP Agent can reach advanced connector actions through authenticated WordPress REST.

## Capability selection

Use the smallest safe capability that already exists:

1. WP Agent native capability when sufficient.
2. Native WordPress/WooCommerce/plugin Ability when it exposes the required typed contract.
3. WordPress Connector semantic action when extra Elementor, ACF, filesystem, plugin-specific, rollback or administrative behavior is required.

Do not duplicate a native WP Agent or WordPress Ability merely to create another route. Keep a connector adapter only when it adds a real capability, safety boundary, compatibility layer, readback or rollback contract.

## WordPress Abilities API

`AbilitiesAdapter` discovers selected exposed Abilities API entries and their schemas. This is a discovery/interop layer. The connector's own Registry remains necessary for advanced operations that are not represented by a suitable native Ability.

## State and safety

A connector request has a stable request ID and normalized fingerprint. Mutations can reject stale state, remain idempotent and register rollback snapshots. Batches compensate completed operations in reverse order when supported.

WordPress-side gates remain authoritative for writes, privileged actions, sensitive actions, system updates and filesystem writes.

## Repository boundary

The repository contains reusable implementation, tests and documentation only. Runtime `requests/**`, `results/**`, request schemas/examples and GitHub execution workflows are not part of the product architecture.

CI is allowed to lint, test and package the plugin. It must not become a second production transport path.
