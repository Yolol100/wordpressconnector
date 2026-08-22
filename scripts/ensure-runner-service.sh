#!/usr/bin/env bash
set -euo pipefail

fail() {
  echo "ERROR: $*" >&2
  exit 1
}

if [[ "$(id -u)" -eq 0 ]]; then
  fail "Run this script as the dedicated runner account, not as root. It will use sudo only for systemd service operations."
fi

runner_dir="${1:-${RUNNER_DIR:-$HOME/actions-runner}}"
[[ -d "$runner_dir" ]] || fail "Runner directory not found: $runner_dir"
cd "$runner_dir"

for required in config.sh run.sh svc.sh .runner; do
  [[ -e "$required" ]] || fail "Configured GitHub Actions runner file is missing: $runner_dir/$required"
done

command -v sudo >/dev/null 2>&1 || fail "sudo is required to manage the runner service."
command -v systemctl >/dev/null 2>&1 || fail "systemd/systemctl is required. Shared hosting without systemd needs a persistent process supervisor instead."

runner_user="$(id -un)"

if [[ ! -s .service ]]; then
  echo "Installing GitHub Actions runner as a systemd service for user: $runner_user"
  sudo ./svc.sh install "$runner_user"
fi

service_name="$(tr -d '\r\n' < .service)"
[[ -n "$service_name" ]] || fail "Runner service name is empty in $runner_dir/.service"

# svc.sh installs the unit with WantedBy=multi-user.target. Enabling it here
# makes the service return after a host reboot; starting is idempotent.
sudo systemctl enable "$service_name"
sudo systemctl start "$service_name"

if ! sudo systemctl is-enabled --quiet "$service_name"; then
  fail "Runner service is not enabled: $service_name"
fi
if ! sudo systemctl is-active --quiet "$service_name"; then
  echo "Runner service failed to become active: $service_name" >&2
  sudo systemctl --no-pager --full status "$service_name" || true
  exit 1
fi

sudo ./svc.sh status
printf 'Runner service ready: %s (enabled + active)\n' "$service_name"
