# Elementorconnector → wordpressconnector migratie

Doel: één canonieke live WordPress-bridge voor WordPress, Elementor, ACF, WooCommerce en media, zonder bestaande veiligheidsgrenzen te verlagen.

## Canonieke richting

- `Yolol100/wordpressconnector` wordt de enige actieve Webactueel live WordPress read/write/rollback-bridge.
- `Yolol100/Elementorconnector` blijft tijdelijk onderhoud-only als consolidatiebron.
- `elementor` blijft eigenaar van Elementor-structuur en JSON-besluiten.
- `wordpressqualityarchitect` blijft eigenaar van technische mutatieveiligheid en runtimecorrectheid.
- `webactueel-workflow` blijft controller voor approvals, bronbinding, handoff en closure.

## Te behouden uit Elementorconnector

1. Live Elementor/Core/Pro/add-on capability inventory.
2. WordPress Abilities discovery met permission- en schemasemantiek.
3. Site-scoped fresh-state tokens voor update/delete.
4. Idempotency en compare-and-swap/stale-state blokkade.
5. Elementor document API create/save/readback zonder directe `_elementor_data`-writes.
6. WooCommerce CRUD- en ACF-identitygrenzen waar zij sterker zijn dan de huidige route.
7. Exacte readback en geverifieerde rollback op mislukte mutaties.
8. Same-site media-identiteit en gecontroleerde request-assets.

## Harde parity-gates

De oude bridge mag pas verdwijnen wanneer wordpressconnector aantoonbaar slaagt voor:

- `discover` en capability inventory;
- page/post/CPT read-create-update-trash-restore;
- bestaande en nieuwe Elementor-documenten;
- Elementor page settings en Theme Builder-metadata;
- ACF-waarden en ondersteunde field-group/ability-routes;
- WooCommerce product, variation, taxonomy en couponroutes;
- media import, metadata, featured image en galleries;
- stale state-token rejection vóór write;
- immutable request-ID/idempotency;
- dry-run, confirm, privileged, sensitive en system-update gates;
- exact readback na iedere mutatie;
- rollback na een geforceerde fout;
- dezelfde PR/head-SHA/run/result-correlatie;
- stagingtests op een representatieve WordPress/Elementor/WooCommerce/ACF-combinatie.

## Uitvoeringsvolgorde

1. Maak een capability-diff tussen beide action catalogs.
2. Voeg alleen ontbrekende, herbruikbare acties toe aan wordpressconnector.
3. Voeg per actie positieve, negatieve, stale-state, permission- en rollbacktests toe.
4. Test eerst lokaal/CI, daarna disposable runtime, daarna staging.
5. Migreer één read-only route en één dry-run write als proef.
6. Laat owner/controller de readback en evidence accepteren.
7. Migreer resterende routes in kleine capabilitygroepen.
8. Zet Elementorconnector daarna read-only/deprecated en behoud tijdelijk rollbackdocumentatie.
9. Verwijder de oude actieve route pas na één stabiele regressieperiode.

## Stopvoorwaarden

Stop bij bredere permissions, verlies van state-token/CAS-beveiliging, directe meta/databasewrites, ontbrekende rollback, onvolledige capability inventory, oncorreleerbare resultaten of afwijkende stagingreadback.

Geen paritybewijs betekent `NO_CHANGE`: Elementorconnector blijft dan tijdelijk actief als oude route.
