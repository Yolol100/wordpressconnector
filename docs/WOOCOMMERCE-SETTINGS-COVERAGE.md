# WooCommerce settings coverage: TPS Pack/ISOHULK

This table is an interface contract, not a claim that a WooCommerce website has been configured.
The running site must be updated to the matching verified connector release before these actions exist there.

## Installed WooCommerce: group, tab and subtab fidelity

1. `woocommerce.settings.catalog` queries `/wc/v3/settings` for actual groups and the WooCommerce admin settings-page registry for current tabs/subtabs. It does **not** invent missing tabs.
2. `woocommerce.settings.inspect` pages through real REST options and masks private values.
3. `woocommerce.settings.section.inspect` reads provider-defined fields for a selected admin tab and subtab; it does not change third-party plugin options.
4a. `woocommerce.fulfillment.locations.inspect/update` manages zone location coverage using the provider JSON body contract with a separate verified state and rollback; `woocommerce.fulfillment.create` can add zones, disabled methods and (with an additional tax policy gate) rates, without deleting existing business resources.
4. `woocommerce.settings.update` updates WooCommerce-owned nonsecret settings using `PUT /wc/v3/settings/<group>/<id>`. Low-risk fields are bounded and immediately reversible. Critical settings additionally require the **site-local** `WPCONNECTOR_ALLOW_WOO_CRITICAL` gate, `critical_confirm=true`, `restore_verified=true`, and an exact dry-run fingerprint.
5. `woocommerce.operations.list/inspect/update` use WooCommerce-owned shipping/tax/payment REST endpoints. Critical updates require the same server gate, external restore verification and an exact state fingerprint. Activating a payment gateway requires an additional site-local `WPCONNECTOR_ALLOW_WOO_PAYMENT_ENABLE` gate and `sandbox_verified=true`.
6. No API credentials, passwords, customer data, billing addresses or order contents are exported through GitHub. Setting names resembling secrets, irreversible data erasure or opaque feature-migration options are unavailable for general writes.

| WooCommerce settings tab | Coverage | Important exceptions |
| --- | --- | --- |
| General | REST group inventory and nonsecret guarded updates | Sensitive business choices (tax enablement, selling territories, shop currency) require critical gate |
| Products: General | REST settings and admin definitions | Current product catalog/order data are not changed by settings update |
| Products: Inventory | Admin subtab definitions; REST-owned settings can be updated after risk checks | Existing reservations/stock are separate WooCommerce operations |
| Products: Downloadable | Admin subtab definitions, supported REST options | File access and download directories require a dedicated filesystem-security workflow |
| Products: Advanced | Admin definitions and REST options where exposed | Scheduled operations and provider-specific features are not arbitrary writes |
| Shipping | REST options, shipping zone/method list/inspect/update, location inspection and gated location updates; guarded creation of zones and disabled methods | Deleting existing zones/methods and enabling new shipping providers require separate owner-controlled validation |
| Payments | Woo payment gateway list/inspect/update, including provider gateways exposed to REST | Payment credentials, live/test switches, actual charges/refunds and webhooks remain outside GitHub |
| Tax | REST options, tax class/rate inventory, provider tax rate guarded update and critical-gated new tax rates | Tax-rate creation requires an additional site-local fiscal gate; tax class creation/deletion and jurisdiction policy still need dedicated fiscal approval |
| Accounts & Privacy | REST options with critical approvals | Personal-data erasure, retention/cleanup and customer account operations are not generic writes |
| Emails | REST email group/subgroup settings, admin tab inspection | SMTP/OAuth credentials, email delivery and email sending require provider-native setup and live delivery tests |
| Integration | REST and admin-defined settings inventory | Extension-owned settings require their own version-specific schemas and vendor readback |
| Site visibility | REST options if provider exposes them | Live/staging publication has to be explicitly authorized |
| Point of Sale | Read available Woo/extension settings and admin definitions | POS connector/payment hardware/settings may not be exposed through WC REST |
| Mollie settings (extension tab) | Admin tab/subtab inventory; nonsecret gateway-level settings via WC REST | Account keys must be inserted into Mollie's local authenticated settings and never committed to GitHub |
| Advanced | REST and admin-defined settings | HPOS migrations, store API key changes, checkout route changes need guarded, staging-first operations |

## Evidence before asserting completion

- Installed-site plugin version and expected connector action discovery.
- Baseline per tab/subtab, type, options, field owner and current value (secret fields withheld).
- Dry-run for every write and expected-fingerprint check; successful provider readback and rollback checkpoint.
- Two stable WooCommerce checkout, shipping/tax, email and payment **sandbox** test rounds.
- Responsiveness and accessibility checks for admin/mobile storefront where relevant.
- No production charge, refund, destructive cleanup or actual order submission from GitHub.

**No amount of connector code can determine missing merchant-specific VAT choices, shipping prices, SMTP credentials, Mollie API keys or WhatsApp number.** Such fields need authoritative merchant/provider data, after which an approved, secrets-safe path must be used.
