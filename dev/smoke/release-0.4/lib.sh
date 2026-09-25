#!/usr/bin/env bash
# Shared bash helpers for release-0.4.sh, following the idioms already proven
# in dev/smoke/woo-native-mcp/setup.sh (disk check, wp-env start, cli
# container discovery). Sourced, never executed directly.

# Fails loudly if the Docker VM disk is over 90% full — the same guard
# woo-native-mcp/setup.sh runs before doing anything else.
check_disk_or_die() {
    echo "== disk check =="
    local disk_line
    disk_line="$(docker run --rm alpine df -h / | tail -1)"
    echo "$disk_line"
    local use_pct
    use_pct="$(echo "$disk_line" | awk '{print $5}' | tr -d '%')"
    if [ "$use_pct" -gt 90 ]; then
        echo "Docker VM disk is ${use_pct}% full (>90%). Stopping — do not prune automatically." >&2
        exit 1
    fi
}

# Starts wp-env in the directory holding .wp-env.json ($1), retrying up to 3
# attempts total with a 15s pause between attempts (wp-env occasionally fails
# a cold start with a DeadlineExceeded pulling images). On the 3rd
# consecutive failure, prints a clear message and exits 1 — it never loops
# forever.
start_wpenv_with_retry() {
    local env_dir="$1"
    local attempt=1
    local max_attempts=3

    while [ "$attempt" -le "$max_attempts" ]; do
        echo "== starting wp-env (attempt ${attempt}/${max_attempts}) =="
        if (cd "$env_dir" && npx @wordpress/env start); then
            return 0
        fi

        if [ "$attempt" -eq "$max_attempts" ]; then
            echo "wp-env failed to start after ${max_attempts} attempts. Giving up." >&2
            exit 1
        fi

        echo "wp-env start failed (attempt ${attempt}/${max_attempts}); retrying in 15s..." >&2
        sleep 15
        attempt=$((attempt + 1))
    done
}

# Finds the running wp-env cli container name (excludes the -tests- variant).
# Exits 1 with a message if none is found.
find_cli_container() {
    local name
    name="$(docker ps --format '{{.Names}}' | grep -- '-cli-1$' | grep -v tests | head -1)"
    if [ -z "$name" ]; then
        echo "Could not find the wp-env cli container." >&2
        exit 1
    fi
    echo "$name"
}

# Runs `wp <args...>` inside the given cli container as www-data. Arrays
# don't export well across bash function boundaries/subshells, so this is a
# function rather than a global CLI array — callers pass the container name
# explicitly every time: cli_exec "$CLI_NAME" plugin list
cli_exec() {
    local container="$1"
    shift
    docker exec --user www-data "$container" wp "$@"
}
