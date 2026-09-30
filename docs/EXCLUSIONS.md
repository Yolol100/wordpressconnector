# Intentional exclusions

“Manage everything editable in WordPress” does not mean exposing arbitrary execution.

The generic connector intentionally excludes:

- arbitrary PHP, shell/process or WP-CLI command passthrough (REST-exposed abilities are supported only when explicitly marked read-only and are run through their registered API and permission callback);
- arbitrary SQL and database-table writes;
- arbitrary filesystem read/write/delete outside named media/deploy operations;
- generic remote HTTP proxying;
- passwords, salts, cookies, tokens, API keys or credential stores;
- order/refund/payment/subscription writes, refunds and payment operations (read-only WooCommerce order summaries are supported, with personal data opt-in per order);
- bulk customer exports and customer contact/address data by default (one customer can be read through WooCommerce CRUD with privileged sensitive access and explicit per-customer personal-data opt-in);
- generic WooCommerce settings, payment gateways, webhooks and shipping method settings because they can contain secrets or change checkout behavior; shipping-zone geography and tax-class/rate inventory are read-only;
- generic export of customer, patient, medical, intake or prescription records.

If a future plugin owns data that is not covered by the existing adapters, add a named semantic adapter against that plugin's supported API and classify its security/privacy risk explicitly.
