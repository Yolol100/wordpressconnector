# Repository Hygiene Policy

`main` contains only reusable connector implementation, tests, documentation and development/release automation.

The default branch must not retain:

- live WordPress request payloads or generated results;
- client domains or target-specific exports;
- production credentials, Application Passwords, tokens or secrets;
- date-stamped debugging/audit residue;
- runtime transport queues, inboxes or state;
- obsolete GitHub request/execution workflows or schemas used only by that retired transport.

WP Agent is the standard live transport. Runtime state belongs in WordPress/WP Agent and the relevant workflow evidence layer, not in this source repository.

GitHub Actions in this repository are limited to development concerns such as static validation, tests and packaging. They must not become a parallel production WordPress transport.

Do not commit private customer/order/patient/medical data, WordPress configuration secrets or temporary target artifacts.

Implementation completion requires static validation, a clean default tree and separate reporting of source/package proof versus actual WordPress runtime proof.
