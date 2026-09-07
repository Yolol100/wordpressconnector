# Intentional exclusions

WordPress Connector must not expose:

- arbitrary PHP, shell/process or WP-CLI command passthrough;
- arbitrary SQL or database-table writes;
- unrestricted filesystem read/write/delete;
- generic remote HTTP proxying;
- passwords, salts, cookies, tokens, API keys or credential stores;
- generic order/refund/payment/subscription/customer-record operations;
- generic export of private or medical/intake records.

If WP Agent, WordPress core, WooCommerce or a stable WordPress/plugin Ability already covers a capability safely, do not duplicate it here.

Add a connector adapter only for a proven capability gap with a supported API, explicit security classification and appropriate validation/readback/rollback.
