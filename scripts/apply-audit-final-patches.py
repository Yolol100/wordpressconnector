from pathlib import Path


def replace_exact(path, old, new, expected=1):
    p = Path(path)
    text = p.read_text()
    count = text.count(old)
    if count != expected:
        raise SystemExit(f'{path}: expected {expected} match(es), got {count}: {old!r}')
    p.write_text(text.replace(old, new, expected))

replace_exact(
    '.github/workflows/wordpress-execute.yml',
    "if [[ -n \"$mode\" && \"$mode\" != '100644' && \"$mode\" != '100755' ]]; then\n              echo \"Symlinks/submodules/special file modes are not allowed: $path ($mode)\" >&2",
    "if [[ -n \"$mode\" && \"$mode\" != '100644' ]]; then\n              echo \"Only regular non-executable request files are allowed: $path ($mode)\" >&2",
)

replace_exact(
    'plugin/wordpressconnector/includes/Adapters/ConnectorUpdateAdapter.php',
    "'public_repository_safe' => true,\n            'description' => 'Check the canonical GitHub release",
    "'public_repository_safe' => true,\n            'capability' => 'update_plugins',\n            'description' => 'Check the canonical GitHub release",
)
replace_exact(
    'plugin/wordpressconnector/includes/Adapters/ConnectorUpdateAdapter.php',
    "'public_repository_safe' => true,\n            'description' => 'Update WordPress Connector",
    "'public_repository_safe' => true,\n            'capability' => 'update_plugins',\n            'description' => 'Update WordPress Connector",
)
replace_exact(
    'plugin/wordpressconnector/includes/Adapters/PluginPackageAdapter.php',
    "'system_update' => true,\n                'description' => 'Install or overwrite a plugin from a verified ZIP",
    "'system_update' => true,\n                'capability' => 'install_plugins',\n                'description' => 'Install or overwrite a plugin from a verified ZIP",
)
