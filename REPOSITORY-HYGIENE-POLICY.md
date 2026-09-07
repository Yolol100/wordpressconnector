# Repository Hygiene Policy

`main` contains only reusable connector source, tests, documentation and CI.

Do not commit production request payloads, generated WordPress results, client exports, credentials, private runtime state or date-stamped debugging residue.

The retired GitHub request/result transport must not return. In particular, `requests/`, `results/`, request/result schemas, example transport payloads and WordPress request/execute workflows are forbidden on the implementation branch.

Runtime operations happen through the authenticated WordPress Connector REST interface (normally via WP Agent). WordPress remains the source of truth for content and runtime state.

Do not commit passwords, application passwords, tokens, salts, private customer/order/patient data or WordPress configuration.

Completion requires source/static validation plus separate staging/runtime proof for any claim about actual WordPress or Elementor mutation behavior.
