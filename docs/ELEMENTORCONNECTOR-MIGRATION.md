# Elementorconnector → wordpressconnector migratie

Doel: één canonieke live WordPress-bridge voor WordPress, Elementor, Gutenberg, ACF, WooCommerce, Yoast SEO en media, zonder bestaande veiligheidsgrenzen te verlagen.

## Canonieke richting

- `Yolol100/wordpressconnector` is de enige nieuwe actieve Webactueel live WordPress read/write/rollback-bridge.
- `Yolol100/Elementorconnector` blijft tijdelijk onderhoud-only als consolidatie- en rollbackbron.
- De oude directe WordPress → GitHub DeviceAuth/repository-synctransport wordt niet in de nieuwe plugin gedupliceerd. De canonieke route blijft GitHub Actions → authenticated HTTPS REST → WordPress Connector.
- `elementor` blijft eigenaar van Elementor-structuur en JSON-besluiten.
- `wordpressqualityarchitect` blijft eigenaar van technische mutatieveiligheid en runtimecorrectheid.
- `webactueel-workflow` blijft controller voor approvals, bronbinding, handoff en closure.

## Source-consolidatie in 1.2.0

De volgende onderdelen zijn nu in `wordpressconnector` opgenomen of versterkt:

1. Elementor capability inventory voor documenttypes, widgets, elementen, dynamic tags en breakpoints.
2. WordPress Abilities catalog discovery met schema- en annotation-metadata; generieke ability-executie blijft bewust niet blootgesteld.
3. Bestaande request-ID/idempotency, mutation lock en `expected_fingerprint` stale-state guard blijven de canonieke schrijfbeveiliging.
4. Elementor elementdata en page settings worden opgeslagen via de Elementor document `save()` API; directe `_elementor_data` writes zijn uit de adapter verwijderd.
5. Elementor create/replace/patch doet na write een readbackcontrole en levert rollbackmetadata.
6. WooCommerce CRUD- en ACF-identityroutes blijven onderdeel van dezelfde plugin.
7. Yoast SEO heeft first-class inspect/update-acties met dry-run, fingerprint, readback en rollback.
8. Media blijft request-scoped via de bestaande HTTPS assettransport- en same-site attachmentroutes.
9. De WordPress adminpagina toont alleen de canonieke GitHub Actions/REST-setup en waarschuwt wanneer de legacy Elementor JSON Bridge nog actief is.

## Harde parity-gates die runtimebewijs vereisen

De oude bridge mag pas definitief worden gedeactiveerd/gearchiveerd wanneer de doelwebsite aantoonbaar slaagt voor:

- `connector.discover`, `system.doctor`, `elementor.capabilities` en waar beschikbaar `wordpress.abilities`;
- page/post/CPT read-create-update-trash-restore;
- bestaande en nieuwe Elementor-documenten;
- Elementor page settings en Theme Builder-metadata;
- ACF-waarden en field-group discovery;
- WooCommerce product, variation, taxonomy en couponroutes;
- Yoast inspect/update;
- media import, metadata, featured image en galleries;
- stale `expected_fingerprint` rejection vóór write;
- immutable request-ID/idempotency;
- dry-run, confirm, privileged, sensitive en system-update gates;
- exact readback na representatieve mutaties;
- rollback na een geforceerde fout;
- dezelfde PR/head-SHA/run/result-correlatie;
- stagingtests op de daadwerkelijke WordPress/Elementor/WooCommerce/ACF/Yoast-combinatie.

## Uitvoeringsvolgorde na merge

1. Bouw en installeer de 1.2.0 ZIP over WordPress Connector.
2. Laat alle mutation gates uit en voer `connector.discover` en `system.doctor` uit.
3. Voer `elementor.capabilities` en een read-only Elementor inspect uit.
4. Test ACF, WooCommerce en Yoast read-only.
5. Voer dry-runs uit voor Elementor, ACF, WooCommerce en Yoast.
6. Op staging: enable confirmed writes plus alleen de benodigde privileged gate, voer disposable writes uit en rollback ze.
7. Herhaal representatieve tests na de laatste configuratiewijziging.
8. Deactiveer Elementor JSON Bridge pas wanneer deze runtime-readback is geaccepteerd.

## Stopvoorwaarden

Stop bij bredere permissions, verlies van stale-state/idempotencybeveiliging, directe `_elementor_data`-writes, ontbrekende rollback, onvolledige capability inventory, oncorreleerbare resultaten of afwijkende stagingreadback.

Source-CI is noodzakelijk maar geen productiepariteitsbewijs. Zonder doelruntimebewijs blijft de laatste deactivatiestap `staging-first`.
