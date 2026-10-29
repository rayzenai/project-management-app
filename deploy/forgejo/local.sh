#!/usr/bin/env bash
# A Forgejo on this machine that signs people in through this tracker, with
# the production settings of deploy/forgejo/compose.yml. The differences:
# SQLite instead of Postgres, plain http on 127.0.0.1, no mail. Guide:
# docs/forgejo.md, "Try it locally".
#
#   deploy/forgejo/local.sh up      install, start and set up (safe to run again)
#   deploy/forgejo/local.sh status  running or not, and whether the sign-in source exists
#   deploy/forgejo/local.sh down    stop it
#   deploy/forgejo/local.sh reset   stop it and delete its data, after you type "reset"
#
#   FORGEJO_LOCAL_DIR  the forge's data, an absolute path (default ~/.local/share/sdoc-forgejo)
#   TRACKER_URL        the tracker it signs people in with (default APP_URL)
#
# It never prints a token or a secret.

set -euo pipefail

say() { printf '==> %s\n' "$*"; }
die() {
    printf 'local.sh: %s\n' "$*" >&2
    exit 1
}

# The data directory as an absolute path (symlinks resolved once it exists).
# Never empty, relative, / or your home directory: reset deletes it.
data_dir() {
    local dir=$1 home
    [[ -n $dir ]] || die "FORGEJO_LOCAL_DIR is empty."
    [[ $dir == /* ]] || die "FORGEJO_LOCAL_DIR must be an absolute path, not $dir."
    if [[ -d $dir ]]; then
        dir=$(cd "$dir" && pwd -P)
    fi
    while [[ $dir == */ ]]; do dir=${dir%/}; done
    home=$(cd "$HOME" && pwd -P)
    if [[ -z $dir || $dir == "$HOME" || $dir == "$home" ]]; then
        die "FORGEJO_LOCAL_DIR can't be / or your home directory."
    fi
    printf '%s\n' "$dir"
}

REPO=$(cd "$(dirname "$0")/../.." && pwd)
DIR=$(data_dir "${FORGEJO_LOCAL_DIR-$HOME/.local/share/sdoc-forgejo}")
# The first line of every app.ini this script writes; reset deletes nothing without it.
MARKER="Written once by deploy/forgejo/local.sh"
FORGE_URL=http://127.0.0.1:3300
SOURCE=sdoc-tasks
CALLBACK=$FORGE_URL/user/oauth2/$SOURCE/callback
TOKEN_FILE=$DIR/sdoc-bot.token
# "<tracker url> <client id>" the sign-in source was made for (no secret).
CLIENT_FILE=$DIR/oidc-client
ADMIN_TOKEN=

fj() { forgejo --config "$DIR/app.ini" --work-path "$DIR" "$@"; }
sql() { sqlite3 -cmd '.timeout 5000' "$DIR/data/forgejo.db" "$@"; }
artisan() { (cd "$REPO" && php artisan --no-ansi "$@"); }

# TRACKER_URL, else APP_URL from the environment or .env, without a trailing slash.
tracker_url() {
    local url=${TRACKER_URL:-${APP_URL:-}}
    if [[ -z $url && -f $REPO/.env ]]; then
        url=$(sed -n 's/^APP_URL=//p' "$REPO/.env" | tr -d "\"'")
    fi
    [[ -n $url ]] || die "Set TRACKER_URL, or APP_URL in .env."
    printf '%s\n' "${url%/}"
}

# --- The server ----------------------------------------------------------

install_forgejo() {
    command -v forgejo >/dev/null && return
    command -v brew >/dev/null || die "Forgejo is not installed, and Homebrew isn't either. Install Homebrew first."
    say "Installing Forgejo with Homebrew (brew install forgejo)"
    brew install forgejo
}

write_config() {
    if [[ -f $DIR/app.ini ]]; then
        return
    fi
    say "Writing $DIR/app.ini"
    local secret_key
    secret_key=$(openssl rand -hex 32)
    (
        umask 077
        mkdir -p "$DIR/data"
        chmod 700 "$DIR"
        cat >"$DIR/app.ini" <<EOF
; $MARKER.
; [security], [service], [openid], [admin], [repository], [oauth2_client],
; [webhook] and [migrations] carry the values of deploy/forgejo/compose.yml;
; the rest fits a laptop.
APP_NAME = SDOC git (local)
RUN_MODE = prod
WORK_PATH = $DIR

[server]
PROTOCOL = http
HTTP_ADDR = 127.0.0.1
HTTP_PORT = 3300
DOMAIN = 127.0.0.1
ROOT_URL = $FORGE_URL/
APP_DATA_PATH = $DIR/data
START_SSH_SERVER = true
SSH_DOMAIN = 127.0.0.1
SSH_LISTEN_HOST = 127.0.0.1
SSH_PORT = 2222
SSH_LISTEN_PORT = 2222
BUILTIN_SSH_SERVER_USER = git
; Leave ~/.ssh alone: the built-in server keeps its keys in the database.
SSH_ROOT_PATH = $DIR/ssh
SSH_CREATE_AUTHORIZED_KEYS_FILE = false

[database]
DB_TYPE = sqlite3
PATH = $DIR/data/forgejo.db

[repository]
ROOT = $DIR/repositories
DEFAULT_PRIVATE = private

[security]
INSTALL_LOCK = true
SECRET_KEY = $secret_key
; No proxy here: trust only this machine.
REVERSE_PROXY_TRUSTED_PROXIES = 127.0.0.0/8,::1/128

[service]
REQUIRE_SIGNIN_VIEW = true
ALLOW_ONLY_EXTERNAL_REGISTRATION = true
ENABLE_INTERNAL_SIGNIN = false
ENABLE_BASIC_AUTHENTICATION = false
ADD_MEMBERS_BY_INVITATIONS = false
DEFAULT_ORG_VISIBILITY = private
DEFAULT_ALLOW_CREATE_ORGANIZATION = false

[openid]
ENABLE_OPENID_SIGNIN = false
ENABLE_OPENID_SIGNUP = false

[admin]
DISABLE_REGULAR_ORG_CREATION = true

[oauth2_client]
ENABLE_AUTO_REGISTRATION = true
USERNAME = nickname
ACCOUNT_LINKING = login

[webhook]
; The tracker on this machine.
ALLOWED_HOST_LIST = loopback

[migrations]
ALLOW_LOCALNETWORKS = false

[mailer]
ENABLED = false

[log]
MODE = console
LEVEL = Info
ROOT_PATH = $DIR/log
EOF
    )
}

running() {
    local pid
    [[ -f $DIR/forgejo.pid ]] || return 1
    pid=$(cat "$DIR/forgejo.pid")
    kill -0 "$pid" 2>/dev/null && ps -p "$pid" -o command= | grep -q 'forgejo.* web'
}

# The pids and commands listening on a port of this machine ("p123" and "cpython" lines).
listeners() { lsof -nP -iTCP:"$1" -sTCP:LISTEN -Fpc 2>/dev/null || true; }

check_port_free() {
    local command
    command=$(listeners "$1" | sed -n 's/^c//p' | head -n 1)
    [[ -z $command ]] || die "port $1 is in use by $command"
}

# Our process, and no other, answers on 3300.
ours_is_listening() {
    running && listeners 3300 | grep -qx "p$(cat "$DIR/forgejo.pid")"
}

start_forge() {
    if running; then
        say "Forgejo is already running at $FORGE_URL"
        return
    fi
    check_port_free 3300
    check_port_free 2222
    say "Starting Forgejo at $FORGE_URL (log: $DIR/forgejo.log)"
    nohup forgejo web --config "$DIR/app.ini" --work-path "$DIR" >>"$DIR/forgejo.log" 2>&1 &
    echo $! >"$DIR/forgejo.pid"

    local second
    for second in $(seq 60); do
        sleep 1
        if ! running; then
            tail -n 20 "$DIR/forgejo.log" >&2
            die "Forgejo stopped while starting (its log is above)."
        fi
        if ours_is_listening && curl -fsS -o /dev/null "$FORGE_URL/api/healthz" 2>/dev/null; then
            return
        fi
    done
    die "Forgejo didn't answer $FORGE_URL/api/healthz within $second seconds; see $DIR/forgejo.log."
}

stop_forge() {
    if ! running; then
        rm -f "$DIR/forgejo.pid"
        return
    fi
    say "Stopping Forgejo"
    kill "$(cat "$DIR/forgejo.pid")"
    local second
    for second in $(seq 30); do
        running || break
        sleep 1
    done
    if running; then
        die "Forgejo didn't stop within $second seconds (pid $(cat "$DIR/forgejo.pid"))."
    fi
    rm -f "$DIR/forgejo.pid"
}

# --- Accounts: as in docs/forgejo.md, Bootstrap ----------------------------

name_taken() { [[ $(sql "SELECT count(*) FROM user WHERE lower_name = '$1'") != 0 ]]; }

# create_user NAME [--admin]: no password anyone knows (there is no password sign-in).
create_user() {
    local name=$1
    shift
    if name_taken "$name"; then
        say "$name exists"
        return
    fi
    say "Creating $name"
    fj admin user create "$@" --username "$name" --email "$name@sdoc.gov.np" \
        --random-password --must-change-password=false >/dev/null
}

# Users can't create organizations, so forge-admin creates sdoc with a one-off
# token, makes sdoc-bot an owner and leaves; the token is then deleted. A run
# that stopped half-way is finished by the next one.
create_org() {
    if bot_owns_sdoc && ! admin_in_sdoc; then
        say "sdoc exists, owned by sdoc-bot"
        return
    fi
    say "Setting up the private organization sdoc, owned by sdoc-bot"
    trap delete_bootstrap_token EXIT
    ADMIN_TOKEN=$(fj admin user generate-access-token --username forge-admin \
        --token-name bootstrap --scopes write:organization --raw) \
        || die "making forge-admin's one-off token failed (see above)."
    if ! name_taken sdoc; then
        admin_api "creating the sdoc organization" POST /orgs \
            -d '{"username":"sdoc","full_name":"SDOC","visibility":"private"}'
    fi
    local owners
    owners=$(owners_team_id)
    [[ -n $owners ]] || die "sdoc has no Owners team."
    admin_api "making sdoc-bot an owner of sdoc" PUT "/teams/$owners/members/sdoc-bot"
    admin_api "taking forge-admin out of sdoc" DELETE /orgs/sdoc/members/forge-admin
    ADMIN_TOKEN=
    delete_bootstrap_token
    trap - EXIT
}

# admin_api STEP METHOD PATH [curl args]: a forge API call with the one-off
# token (in a header file, never on a command line); a failure names the step.
# Only the status is shown: an error body can quote the token.
admin_api() {
    local step=$1 method=$2 path=$3 status
    shift 3
    status=$(curl -sS -o /dev/null -w '%{http_code}' -X "$method" "$FORGE_URL/api/v1$path" \
        -H @<(printf 'Authorization: token %s\n' "$ADMIN_TOKEN") \
        -H 'Content-Type: application/json' "$@") || die "$step failed: the forge didn't answer."
    [[ $status == 2?? ]] || die "$step failed: the forge answered $status."
}

# True while forge-admin is still a member of sdoc: a run that stopped after
# the bot became an owner but before forge-admin left.
admin_in_sdoc() {
    [[ $(sql "SELECT count(*) FROM org_user JOIN user AS u ON u.id = org_user.uid
        JOIN user AS o ON o.id = org_user.org_id
        WHERE u.lower_name = 'forge-admin' AND o.lower_name = 'sdoc'") != 0 ]]
}

bot_owns_sdoc() {
    local owners
    owners=$(owners_team_id)
    [[ -n $owners && $(sql "SELECT count(*) FROM team_user JOIN user ON user.id = team_user.uid
        WHERE team_user.team_id = $owners AND user.lower_name = 'sdoc-bot'") != 0 ]]
}

owners_team_id() {
    sql "SELECT team.id FROM team JOIN user ON user.id = team.org_id
         WHERE user.lower_name = 'sdoc' AND team.lower_name = 'owners'"
}

# Forgejo deletes tokens over its API only with basic auth, which is off.
delete_bootstrap_token() {
    sql "DELETE FROM access_token WHERE name = 'bootstrap'
         AND uid = (SELECT id FROM user WHERE lower_name = 'forge-admin')"
}

BOT_TOKEN_ROW="FROM access_token WHERE name = 'tasks-sdoc'
    AND uid = (SELECT id FROM user WHERE lower_name = 'sdoc-bot')"

write_bot_token() {
    if [[ -s $TOKEN_FILE && $(sql "SELECT count(*) $BOT_TOKEN_ROW") != 0 ]]; then
        say "sdoc-bot's token is in $TOKEN_FILE"
        return
    fi
    if [[ -s $TOKEN_FILE ]]; then
        say "The forge no longer has the token in $TOKEN_FILE; turn git on again with the new one"
    fi
    say "Writing a read:user,write:organization token for sdoc-bot to $TOKEN_FILE"
    sql "DELETE $BOT_TOKEN_ROW"
    (
        umask 077
        fj admin user generate-access-token --username sdoc-bot --token-name tasks-sdoc \
            --scopes read:user,write:organization --raw | tr -d '\n' >"$TOKEN_FILE"
    )
}

# --- Sign-in through the tracker -------------------------------------------

ensure_passport_keys() {
    if [[ -f $REPO/storage/oauth-private.key || -n ${PASSPORT_PRIVATE_KEY:-} ]] \
        || grep -q '^PASSPORT_PRIVATE_KEY=.' "$REPO/.env" 2>/dev/null; then
        return
    fi
    say "Making the tracker's signing keys (php artisan passport:keys)"
    artisan passport:keys
}

source_id() { fj admin auth list | awk -v name="$SOURCE" '$2 == name { print $1 }'; }

# The tracker's current Forgejo client, as php artisan git:oidc-client keeps
# it. Only our own line counts, so a stray warning can't change the answer.
tracker_client_id() {
    artisan tinker --execute 'echo PHP_EOL, "client=", App\Models\OAuthClient::query()
        ->where("name", App\Console\Commands\CreateForgeOidcClient::NAME)
        ->where("revoked", false)->value("id"), PHP_EOL;' | sed -n 's/^client=//p' | tail -n 1
}

check_tracker() {
    local tracker=$1 issuer
    issuer=$(curl -fsS "$tracker/.well-known/openid-configuration" 2>/dev/null \
        | php -r 'echo json_decode(stream_get_contents(STDIN), true)["issuer"] ?? "";') || true
    [[ -n $issuer ]] || die "The tracker doesn't answer $tracker/.well-known/openid-configuration. Start the tracker with composer dev, and run php artisan migrate."
    [[ $issuer == "$tracker" ]] || die "The tracker at $tracker calls itself $issuer (its APP_URL). Run with TRACKER_URL=$issuer, or set APP_URL to $tracker."
}

setup_sign_in() {
    local tracker=$1 id client
    id=$(source_id)
    if [[ -n $id && "$(cat "$CLIENT_FILE" 2>/dev/null)" == "$tracker $(tracker_client_id)" ]]; then
        say "Reusing the sign-in source $SOURCE"
        return
    fi

    check_tracker "$tracker"
    say "Registering the forge with the tracker (php artisan git:oidc-client)"
    local out secret
    out=$(artisan git:oidc-client "$CALLBACK") \
        || die "php artisan git:oidc-client failed. Start the tracker with composer dev, and run php artisan migrate."
    client=$(printf '%s\n' "$out" | sed -n 's/^Client ID: //p')
    secret=$(printf '%s\n' "$out" | sed -n 's/^Client secret: //p')
    [[ -n $client && -n $secret ]] || die "Couldn't read the client ID and secret from php artisan git:oidc-client."

    if [[ -z $id ]]; then
        say "Adding the sign-in source $SOURCE"
        fj admin auth add-oauth --name "$SOURCE" --provider openidConnect \
            --key "$client" --secret "$secret" \
            --auto-discover-url "$tracker/.well-known/openid-configuration" \
            --scopes openid --scopes profile --scopes email --scopes git \
            --group-claim-name groups --admin-group forge-admins \
            --required-claim-name git_orgs \
            --skip-local-2fa
    else
        say "Updating the sign-in source $SOURCE for the new client"
        fj admin auth update-oauth --id "$id" --key "$client" --secret "$secret" \
            --auto-discover-url "$tracker/.well-known/openid-configuration" \
            --skip-local-2fa
        # The running server keeps a source's old settings until it restarts.
        stop_forge
        start_forge
    fi
    printf '%s %s\n' "$tracker" "$client" >"$CLIENT_FILE"
}

next_steps() {
    cat <<EOF

The forge is at $FORGE_URL (git over SSH on 127.0.0.1:2222), signing people
in through $1.

1. Turn git on for SDOC. Copy the bot token with

     pbcopy < $TOKEN_FILE

   then, as a super-admin in the tracker: Organizations, Git on SDOC,
   forge address $FORGE_URL, forge organization sdoc, paste the bot
   token, Turn on git. It says "Git is on."

2. Sign in. Open $FORGE_URL in a private window, a new one for each
   person, and choose "Sign in with sdoc-tasks":
   - an SDOC member lands on the forge signed in, with the username the
     tracker stored (git_username);
   - someone outside SDOC and on no SDOC project team gets the tracker's 403
     page, "You don't have access to git. Ask your office admin.", and no
     forge account;
   - a super-admin arrives as a site admin (Site administration in the user
     menu);
   - back in step 1, a wrong token gets "The forge refused that token."

3. Push, as the SDOC member, to a repository they create on the forge:
   - over SSH, after adding a public key (Settings, SSH / GPG keys):
       git push ssh://git@127.0.0.1:2222/<username>/<repo>.git
   - over http, giving an access token that can read and write repositories
     (Settings, Applications) as the password:
       git push http://127.0.0.1:3300/<username>/<repo>.git

4. Offboarding. The super-admin, on the forge, puts the member in a team of
   sdoc (sdoc, Teams, New team, then add the member). In the tracker,
   deactivate the member: the confirmation says "<git_username> was removed
   from the forge.", and they are gone from sdoc's Members on the forge.

deploy/forgejo/local.sh down stops the forge, up starts it again, and reset
deletes it.
EOF
}

# --- Commands --------------------------------------------------------------

cmd_up() {
    local tracker
    tracker=$(tracker_url)
    install_forgejo
    write_config
    start_forge
    create_user forge-admin --admin
    create_user sdoc-bot
    create_org
    write_bot_token
    ensure_passport_keys
    setup_sign_in "$tracker"
    next_steps "$tracker"
}

cmd_status() {
    if [[ ! -f $DIR/app.ini ]]; then
        echo "There is no local forge in $DIR. Start one with: deploy/forgejo/local.sh up"
        return
    fi
    if running; then
        echo "Forgejo is running at $FORGE_URL (git over SSH on 127.0.0.1:2222)."
    else
        echo "Forgejo is stopped. Start it with: deploy/forgejo/local.sh up"
    fi
    if [[ -n $(source_id) ]]; then
        echo "The sign-in source $SOURCE exists, for $(cut -d' ' -f1 "$CLIENT_FILE" 2>/dev/null || echo 'an unknown tracker')."
    else
        echo "The sign-in source $SOURCE doesn't exist yet. Run: deploy/forgejo/local.sh up"
    fi
    echo "Data: $DIR"
}

cmd_reset() {
    [[ -f $DIR/app.ini ]] || die "There is no local forge in $DIR."
    grep -qF "$MARKER" "$DIR/app.ini" \
        || die "$DIR/app.ini wasn't written by this script, so $DIR stays."
    printf 'This stops the forge and deletes %s: every account, repository and token in it.\nType reset to go on: ' "$DIR"
    local answer=
    read -r answer || true
    [[ $answer == reset ]] || die "Nothing was stopped or deleted."
    stop_forge
    rm -rf "$DIR"
    say "Deleted $DIR"
    echo "Now turn git off for SDOC in the tracker (Organizations, Git on SDOC, Turn off git): its bot token is gone."
}

case ${1:-} in
    up) cmd_up ;;
    down)
        if [[ -f $DIR/app.ini ]]; then stop_forge; fi
        echo "Forgejo is stopped."
        ;;
    status) cmd_status ;;
    reset) cmd_reset ;;
    *)
        echo "Usage: deploy/forgejo/local.sh up|down|status|reset" >&2
        exit 2
        ;;
esac
