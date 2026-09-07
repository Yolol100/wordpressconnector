# WordPress Connector consolidation

This branch consolidates the remaining live-runtime responsibilities of `Yolol100/Elementorconnector` into `Yolol100/wordpressconnector` while keeping `Yolol100/elementorjson` as a separate QA/import-export lab.

Implementation on this branch must preserve WordPress/Elementor read, write, capability, state, readback and rollback behavior. `Elementorconnector` is not removable until the parity checklist in `docs/ELEMENTORCONNECTOR-MIGRATION.md` is green on staging.
