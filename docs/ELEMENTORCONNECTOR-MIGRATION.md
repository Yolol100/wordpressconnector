# Elementorconnector -> wordpressconnector migratie

## Doel

Gebruik nog maar één actieve WordPress-bridge:

`ChatGPT -> WP Agent -> WordPress REST -> WordPress Connector`

`Yolol100/Elementorconnector` is alleen nog een tijdelijke consolidatie/rollbackbron en krijgt geen nieuwe functies.

## Wat wordpressconnector overneemt

- Elementor capabilities, inspect, create, patch, replace en forms;
- Elementor JSON export via de Elementor APIs;
- ACF, WooCommerce, Yoast, media en Gutenberg;
- dry-run, confirmation gates, fingerprints, idempotency, readback en rollback.

## Wat niet terugkomt

- WordPress -> GitHub DeviceAuth/repository transport;
- GitHub Actions als live WordPress transport;
- een tweede Elementor-bridge naast wordpressconnector;
- directe `_elementor_data` writes of onbeperkte remote code execution.

## Deactivatiepoort oude Elementorconnector

Deactiveer de oude bridge pas nadat op staging minimaal groen is:

1. `connector.discover` en `elementor.capabilities`;
2. Elementor inspect + representatieve write + exacte readback;
3. ACF/WooCommerce/Yoast read-only en dry-run;
4. stale fingerprint rejection;
5. rollback na een testmutatie.

Daarna is `wordpressconnector` de enige live bridge. GitHub blijft uitsluitend voor broncode, CI, review en releases.
