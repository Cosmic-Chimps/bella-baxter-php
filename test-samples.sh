#!/usr/bin/env bash
# test-samples.sh — Run and validate all Bella Baxter PHP SDK samples.
#
# Usage:  ./test-samples.sh <api-key>
#         ./test-samples.sh bax-myKeyId-mySecret
#
# Samples tested:
#   01-dotenv-file    — bella secrets get -o .env → php app.php
#   02-process-inject — bella run -- php app.php
#   03-laravel        — bella exec → php artisan serve (HTTP server, port 8097)
#   04-symfony        — bella exec → php -S localhost:8096 (HTTP server, port 8096)
#   05-typed-secrets  — bella run -- php app.php (typed AppSecrets class)

set -uo pipefail

# ─── Paths ──────────────────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SAMPLES_DIR="$SCRIPT_DIR/samples"
DEMO_ENV_FILE="$SCRIPT_DIR/../../../demo.env"

# ─── Arguments ──────────────────────────────────────────────────────────────
BELLA_API_KEY="${1:-}"
if [[ -z "$BELLA_API_KEY" ]]; then
  echo "Usage: $0 <api-key>   e.g. $0 bax-myKeyId-mySecret"
  exit 1
fi
if [[ ! -f "$DEMO_ENV_FILE" ]]; then
  echo "demo.env not found: $DEMO_ENV_FILE"
  exit 1
fi

# ─── Config ─────────────────────────────────────────────────────────────────
export BELLA_BAXTER_URL="http://localhost:5522"

# ─── Composer ───────────────────────────────────────────────────────────────
# Find or download composer
COMPOSER="$(command -v composer 2>/dev/null || true)"
if [[ -z "$COMPOSER" ]]; then
  COMPOSER="/tmp/bella-composer.phar"
  if [[ ! -f "$COMPOSER" ]]; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/tmp --filename=bella-composer.phar \
      > /dev/null 2>&1
  fi
  COMPOSER="php $COMPOSER"
fi

# ─── Expected values from demo.env ──────────────────────────────────────────
get_env() { grep -m1 "^${1}=" "$DEMO_ENV_FILE" | cut -d'=' -f2-; }

# APP_CONFIG is stored with outer double-quotes in dotenv; strip them + unescape
raw_app_config="$(get_env APP_CONFIG)"
raw_app_config="${raw_app_config#\"}"
raw_app_config="${raw_app_config%\"}"
EXP_APP_CONFIG="${raw_app_config//\\\"/\"}"

EXP_PORT="$(get_env PORT)"
EXP_DB_URL="$(get_env DATABASE_URL)"
EXP_API_KEY="$(get_env EXTERNAL_API_KEY)"
EXP_GLEAP_KEY="$(get_env GLEAP_API_KEY)"
EXP_ENABLE_FEATURES="$(get_env ENABLE_FEATURES)"
EXP_APP_ID="$(get_env APP_ID)"
EXP_CONN_STRING="$(get_env ConnectionStrings__Postgres)"

# ─── Tracking ────────────────────────────────────────────────────────────────
PASS=0
FAIL=0
RESULTS=()

pass() {
  printf "  \xE2\x9C\x85 %s\n" "$1"
  RESULTS=("${RESULTS[@]+"${RESULTS[@]}"}" "PASS: $1")
  PASS=$((PASS + 1))
}
fail() {
  printf "  \xE2\x9D\x8C %s -- %s\n" "$1" "$2"
  RESULTS=("${RESULTS[@]+"${RESULTS[@]}"}" "FAIL: $1 -- $2")
  FAIL=$((FAIL + 1))
}
section() { printf "\n\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80 %s %s\n" "$1" "$(printf '\xe2\x94\x80%.0s' {1..50})"; }

check() {
  local name="$1" output="$2" pattern="$3"
  if printf '%s' "$output" | grep -qF "$pattern"; then
    pass "$name"
  else
    fail "$name" "expected '$pattern'"
  fi
}

check_all_secrets() {
  local prefix="$1" output="$2"
  check "$prefix: PORT"                         "$output" "PORT=$EXP_PORT"
  check "$prefix: DATABASE_URL"                 "$output" "DATABASE_URL=$EXP_DB_URL"
  check "$prefix: EXTERNAL_API_KEY"             "$output" "EXTERNAL_API_KEY=$EXP_API_KEY"
  check "$prefix: GLEAP_API_KEY"                "$output" "GLEAP_API_KEY=$EXP_GLEAP_KEY"
  check "$prefix: ENABLE_FEATURES"              "$output" "ENABLE_FEATURES=$EXP_ENABLE_FEATURES"
  check "$prefix: APP_ID"                       "$output" "APP_ID=$EXP_APP_ID"
  check "$prefix: ConnectionStrings__Postgres"  "$output" "ConnectionStrings__Postgres=$EXP_CONN_STRING"
  check "$prefix: APP_CONFIG"                   "$output" "APP_CONFIG=$EXP_APP_CONFIG"
}

# Check JSON response from a server endpoint (uses jq to extract raw values)
check_server_secrets() {
  local prefix="$1" response="$2"
  check "$prefix PORT"                         "$(printf '%s' "$response" | jq -r '.PORT // empty')"                         "$EXP_PORT"
  check "$prefix DATABASE_URL"                 "$(printf '%s' "$response" | jq -r '.DATABASE_URL // empty')"                 "$EXP_DB_URL"
  check "$prefix EXTERNAL_API_KEY"             "$(printf '%s' "$response" | jq -r '.EXTERNAL_API_KEY // empty')"             "$EXP_API_KEY"
  check "$prefix GLEAP_API_KEY"                "$(printf '%s' "$response" | jq -r '.GLEAP_API_KEY // empty')"                "$EXP_GLEAP_KEY"
  check "$prefix ENABLE_FEATURES"              "$(printf '%s' "$response" | jq -r '.ENABLE_FEATURES // empty')"              "$EXP_ENABLE_FEATURES"
  check "$prefix APP_ID"                       "$(printf '%s' "$response" | jq -r '.APP_ID // empty')"                       "$EXP_APP_ID"
  check "$prefix ConnectionStrings__Postgres"  "$(printf '%s' "$response" | jq -r '.["ConnectionStrings__Postgres"] // empty')" "$EXP_CONN_STRING"
  check "$prefix APP_CONFIG"                   "$(printf '%s' "$response" | jq -r '.APP_CONFIG // empty')"                   "$EXP_APP_CONFIG"
}

wait_for_server() {
  local url="$1" timeout="${2:-60}" i
  for (( i=0; i<timeout; i++ )); do
    if curl -fs "$url" -o /dev/null 2>/dev/null; then
      return 0
    fi
    sleep 1
  done
  return 1
}

summary_and_exit() {
  printf "\n\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80 Summary %s\n" "$(printf '\xe2\x94\x80%.0s' {1..50})"
  for r in "${RESULTS[@]+"${RESULTS[@]}"}"; do
    echo "  $r"
  done
  printf "\nPASS: %d  FAIL: %d  TOTAL: %d\n" "$PASS" "$FAIL" "$((PASS + FAIL))"
  [[ $FAIL -eq 0 ]] && exit 0 || exit 1
}

# ─── Auth ────────────────────────────────────────────────────────────────────
section "Authentication"
if bella login --force --api-key "$BELLA_API_KEY" > /dev/null 2>&1; then
  pass "bella login --api-key"
else
  fail "bella login --api-key" "login failed — cannot continue"
  exit 1
fi

# ─── Install dependencies ─────────────────────────────────────────────────────
section "Install dependencies"
for sample in 01-dotenv-file 03-laravel 04-symfony 05-typed-secrets; do
  pushd "$SAMPLES_DIR/$sample" > /dev/null
  if $COMPOSER install -q --no-interaction > /dev/null 2>&1; then
    pass "composer install $sample"
  else
    fail "composer install $sample" "run manually to see errors"
  fi
  popd > /dev/null
done

# ─── Sample 01: dotenv-file ──────────────────────────────────────────────────
section "01-dotenv-file"
SAMPLE_01="$SAMPLES_DIR/01-dotenv-file"
pushd "$SAMPLE_01" > /dev/null
  ENV_FILE="$(pwd)/.env"
  if bella secrets get --app php-01-dotenv-file -o "$ENV_FILE" > /dev/null 2>&1; then
    pass "bella secrets get -o .env"
  else
    fail "bella secrets get -o .env" "command failed"
  fi

  if [[ ! -f "$ENV_FILE" || ! -s "$ENV_FILE" ]]; then
    fail "01: .env file created" ".env missing or empty"
  else
    OUTPUT="$(php app.php 2>&1)"
    check_all_secrets "01" "$OUTPUT"
  fi

  rm -f "$ENV_FILE"
popd > /dev/null

# ─── Sample 02: process-inject ───────────────────────────────────────────────
section "02-process-inject"
SAMPLE_02="$SAMPLES_DIR/02-process-inject"
pushd "$SAMPLE_02" > /dev/null
  OUTPUT="$(bella run --app php-02-process-inject -- php app.php 2>&1)"
  check_all_secrets "02" "$OUTPUT"
popd > /dev/null

# ─── Sample 05: typed-secrets ────────────────────────────────────────────────
section "05-typed-secrets"
SAMPLE_05="$SAMPLES_DIR/05-typed-secrets"
pushd "$SAMPLE_05" > /dev/null
  OUTPUT="$(bella run --app php-05-typed-secrets -- php app.php 2>&1)"
  check_all_secrets "05" "$OUTPUT"
popd > /dev/null

# ─── Samples 03: laravel ─────────────────────────────────────────────────────
section "03-laravel"
SAMPLE_03="$SAMPLES_DIR/03-laravel"
LARAVEL_PORT=8097
LARAVEL_PID=""

pushd "$SAMPLE_03" > /dev/null
  # Kill any leftover process on the port from a previous run
  lsof -ti tcp:$LARAVEL_PORT | xargs kill -9 2>/dev/null || true
  # Clear compiled caches from previous runs
  rm -rf bootstrap/cache/*.php

  bella exec --app php-03-laravel -- php artisan serve --port=$LARAVEL_PORT --no-ansi \
    > /tmp/bella-laravel.log 2>&1 &
  LARAVEL_PID=$!
  
  if wait_for_server "http://localhost:$LARAVEL_PORT/health" 60; then
    pass "03-laravel: server started"

    RESP_03="$(curl -sf "http://localhost:$LARAVEL_PORT/" 2>/dev/null || true)"
    check_server_secrets "03 /" "$RESP_03"

    RESP_03T="$(curl -sf "http://localhost:$LARAVEL_PORT/typed" 2>/dev/null || true)"
    check_server_secrets "03 /typed" "$RESP_03T"
  else
    fail "03-laravel: server started" "did not respond within 60s"
    [[ -f /tmp/bella-laravel.log ]] && cat /tmp/bella-laravel.log >&2 || true
  fi
  
  [[ -n "$LARAVEL_PID" ]] && kill "$LARAVEL_PID" 2>/dev/null || true
popd > /dev/null

# ─── Sample 04: symfony ───────────────────────────────────────────────────────
section "04-symfony"
SAMPLE_04="$SAMPLES_DIR/04-symfony"
SYMFONY_PORT=8096
SYMFONY_PID=""

pushd "$SAMPLE_04" > /dev/null
  # Kill any leftover process on the port from a previous run
  lsof -ti tcp:$SYMFONY_PORT | xargs kill -9 2>/dev/null || true
  # Clear Symfony container cache from previous runs
  rm -rf var/cache/*

  bella exec --app php-04-symfony -- php -S "localhost:$SYMFONY_PORT" public/index.php \
    > /tmp/bella-symfony.log 2>&1 &
  SYMFONY_PID=$!
  
  if wait_for_server "http://localhost:$SYMFONY_PORT/health" 60; then
    pass "04-symfony: server started"

    RESP_04="$(curl -sf "http://localhost:$SYMFONY_PORT/" 2>/dev/null || true)"
    check_server_secrets "04 /" "$RESP_04"

    RESP_04T="$(curl -sf "http://localhost:$SYMFONY_PORT/typed" 2>/dev/null || true)"
    check_server_secrets "04 /typed" "$RESP_04T"
  else
    fail "04-symfony: server started" "did not respond within 60s"
    [[ -f /tmp/bella-symfony.log ]] && cat /tmp/bella-symfony.log >&2 || true
  fi
  
  [[ -n "$SYMFONY_PID" ]] && kill "$SYMFONY_PID" 2>/dev/null || true
popd > /dev/null

# ─── Summary ─────────────────────────────────────────────────────────────────
summary_and_exit
