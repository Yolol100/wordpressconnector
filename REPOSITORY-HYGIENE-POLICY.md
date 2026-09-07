# Repository Hygiene Policy

`main` contains only generic connector source, tests, current documentation and minimal CI/maintenance metadata.

Do not store on `main`:
- runtime requests or results;
- client domains or target-specific payloads;
- credentials, Application Passwords, tokens or private exports;
- generated debug residue or date-stamped run artifacts;
- retired transport workflows, schemas or validators;
- duplicate adapters for capabilities already safely covered by WP Agent, WordPress core or stable WordPress/plugin Abilities.

GitHub is source control, CI, review and release infrastructure only. It is not the live ChatGPT-to-WordPress transport.

Implementation completion requires source/static validation, no target-specific residue and separate reporting of source/package proof versus actual WordPress runtime proof.
