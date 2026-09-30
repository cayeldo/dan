#!/bin/bash
set -euo pipefail

REPO_DIR="/opt/dan-repo"
WEB_DIR="/home/cayeldo/domains/dan.cayelli.us/public_html"
KEY_FILE="/root/.ssh/dan_deploy"
DEPLOY_USER="cayeldo"
DEPLOY_GROUP="cayeldo"
MARKER_FILE="$WEB_DIR/.dan-deployed"
LOCK_FILE="/tmp/dan-deploy.lock"

exec 9>"$LOCK_FILE"
flock -n 9 || exit 0

cd "$REPO_DIR"

export GIT_SSH_COMMAND="ssh -i $KEY_FILE -o IdentitiesOnly=yes"

# Get the latest version from GitHub.
git fetch origin main

REMOTE_SHA="$(git rev-parse origin/main)"
CURRENT_SHA="$(git rev-parse HEAD 2>/dev/null || true)"

# Reuse the existing minute-by-minute job for bank sync and AI categorization.
# Run as the app user; API failures never fail a deployment.
run_background_workers() {
    if [ -f "$REPO_DIR/deploy/sync-simplefin.php" ]; then
        timeout 30s sudo -u "$DEPLOY_USER" env \
            DAN_CONFIG="$(dirname "$WEB_DIR")/dan-config.php" \
            php "$REPO_DIR/deploy/sync-simplefin.php" || true
    fi
    if [ -f "$REPO_DIR/deploy/categorize.php" ]; then
        timeout 50s sudo -u "$DEPLOY_USER" env \
            DAN_CONFIG="$(dirname "$WEB_DIR")/dan-config.php" \
            DAN_AI_CONFIG="$(dirname "$WEB_DIR")/dan-ai.json" \
            php "$REPO_DIR/deploy/categorize.php" || true
    fi
    if [ -f "$REPO_DIR/deploy/review-months.php" ]; then
        timeout 40s sudo -u "$DEPLOY_USER" env \
            DAN_CONFIG="$(dirname "$WEB_DIR")/dan-config.php" \
            DAN_AI_CONFIG="$(dirname "$WEB_DIR")/dan-ai.json" \
            php "$REPO_DIR/deploy/review-months.php" || true
    fi
}

# Nothing to do if this commit is already deployed.
if [ -f "$MARKER_FILE" ] && [ "$(cat "$MARKER_FILE")" = "$REMOTE_SHA" ]; then
    run_background_workers
    exit 0
fi

# Update the local checkout to exactly match GitHub main.
git reset --hard origin/main

# Apply additive schema changes before the new PHP files are served.
if [ -f "$REPO_DIR/deploy/migrate.php" ]; then
    sudo -u "$DEPLOY_USER" env DAN_CONFIG="$(dirname "$WEB_DIR")/dan-config.php" \
        php "$REPO_DIR/deploy/migrate.php"
fi

# Make sure the web directory exists.
install -d -m 0755 -o "$DEPLOY_USER" -g "$DEPLOY_GROUP" "$WEB_DIR"

# Deploy the application, but don't expose Git metadata or deployment files.
rsync -a --delete \
    --exclude='.git/' \
    --exclude='.DS_Store' \
    --exclude='deploy/' \
    --exclude='database/' \
    --exclude='tests/' \
    --exclude='README.md' \
    --exclude='.gitignore' \
    --exclude='.env*' \
    --exclude='dan-config.php' \
    --exclude='dan-ai.json' \
    --exclude='plaid.env' \
    --exclude='plaid-items/' \
    --exclude='simplefin/' \
    --exclude='.dan-deployed' \
    "$REPO_DIR/" "$WEB_DIR/"

# Restore DirectAdmin ownership.
chown -R "$DEPLOY_USER:$DEPLOY_GROUP" "$WEB_DIR"

# Record exactly which Git commit is live.
printf '%s\n' "$REMOTE_SHA" > "$MARKER_FILE"
chown "$DEPLOY_USER:$DEPLOY_GROUP" "$MARKER_FILE"
chmod 0644 "$MARKER_FILE"

echo "$(date -Is) dan.cayelli.us deployed $REMOTE_SHA"
run_background_workers
