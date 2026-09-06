# Elementor forms through JSON

The connector supports two Elementor form families without converting one into the other:

- V3/classic: one Elementor `widget` with `widgetType: form`; fields and form actions live in the widget `settings` object.
- V4/Atomic: one `e-form` root with nested `e-form-*` atoms and typed settings using `$$type` + `value`.

Always derive the form structure from the target runtime. Do not copy a fixed field/action catalog from another site or Elementor version.

## Safe workflow

1. Run `elementor.form_capabilities` read-only.
2. Prefer V4 only when the target reports a real registered `e-form` plus its required form atoms. Otherwise use V3 when the classic `form` widget is registered.
3. Run `elementor.form_inspect` when modifying an existing page/form and start from the complete returned subtree.
4. Change the complete form JSON locally. Keep all stable Elementor element IDs unless intentionally inserting new elements.
5. Run `elementor.form_upsert` with `dry_run: true` and the `schema_fingerprint` returned by step 1.
6. For an approved write, run the same request with `confirm: true` while the connector write gate is enabled.
7. The connector saves through Elementor's document API, reads the form back exactly and restores the prior document snapshot if the save/readback fails.

## V3/classic form

The connector preserves the complete supplied Form widget settings. This includes runtime-supported fields such as:

- `form_fields` repeater entries and their IDs, labels, types, placeholders, defaults, required flags and widths;
- `submit_actions`;
- recipient, subject, message/body, from address, from name, reply-to, CC and BCC controls;
- success/error messages;
- styling and any other settings Elementor keeps on the Form widget.

`elementor.form_capabilities` exposes the target Form widget control schema and the actual registered `submit_actions` choice keys. `elementor.form_upsert` rejects submit actions that are not registered on that target and rejects duplicate field `_id` or `custom_id` values.

A newly inserted V3 form requires `parent_element_id`; classic widgets are inserted into an existing Elementor layout element rather than guessed as a new top-level layout.

## V4/Atomic Form

V4 form JSON is treated as a complete subtree. The root is `elType: e-form`; form fields and messages are nested Atomic elements such as `e-form-input`, `e-form-textarea`, `e-form-select`, `e-form-file-upload` and `e-form-submit-button` when those types are actually registered by the target runtime.

Atomic settings remain typed props. For example, an email action is not flattened into classic V3 keys: its value remains a typed Atomic property as produced by the active Elementor runtime. Current Elementor email property schemas can contain recipient(s), subject, message, from, from-name, reply-to, CC, BCC, send-as and metadata. The connector preserves the complete supplied typed value rather than reducing it to a fixed subset.

Before save the connector checks:

- the V4 root and every nested Atomic type are registered in the current runtime;
- every Atomic element has an element ID, schema version, settings, editor settings, styles and children array;
- supplied Atomic settings use typed `$$type/value` or multi-prop encoding;
- forms are not nested inside forms;
- there is exactly one `e-form-submit-button`;
- success and error message elements exist and contain an `e-paragraph` child;
- final document element IDs remain unique.

## Full replacement versus insertion

When the supplied form ID already exists, `elementor.form_upsert` replaces that complete form subtree. This is the preferred way to change all form details at once, including email fields and message content.

When the supplied form ID does not exist, the action inserts it. Use `parent_element_id` and optional zero-based `position` for a specific layout location. V4 may also be inserted at document root where the target document structure supports it.

Changing an existing form ID from V3 to V4 or the reverse is blocked by default. Set `allow_family_change: true` only when the conversion JSON has already been built and validated as a complete target-family subtree.

## Request shape

The request payload for `elementor.form_upsert` contains:

```json
{
  "id": 123,
  "family": "auto",
  "parent_element_id": "optional-layout-element-id",
  "position": 0,
  "expected_schema_fingerprint": "value-from-elementor.form_capabilities",
  "form": {
    "id": "stable-form-element-id",
    "...": "complete runtime-proven V3 Form widget or V4 Atomic Form subtree"
  }
}
```

The placeholder above is documentation only; it is intentionally not a fabricated Elementor form schema. Obtain the real subtree and schema from `elementor.form_inspect` / `elementor.form_capabilities` on the target runtime.

## Evidence boundary

Repository CI and the controlled contract harness prove structure validation, schema discovery behavior, preservation of complete JSON, placement/replacement rules and source-level rollback/readback contracts. Actual email delivery, webhook delivery, submissions storage, spam integrations, file uploads, browser validation and visual rendering require a real target Elementor/Elementor Pro runtime and remain staging-first until tested there.
