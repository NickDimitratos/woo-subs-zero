#!/usr/bin/env bash
set -euo pipefail

AUTHORIZE_ENDPOINT="${PAYNL_AUTHORIZE_ENDPOINT:-https://rest-api.pay.nl/v18/transaction/byRecurringId/json}"
TRANSACTION_INFO_ENDPOINT="${PAYNL_TRANSACTION_INFO_ENDPOINT:-https://rest.pay.nl/v2/transactions}"
AMOUNT_CENTS="${PAYNL_AMOUNT_CENTS:-100}"
CURRENCY="${PAYNL_CURRENCY:-EUR}"
DESCRIPTION="${PAYNL_DESCRIPTION:-WSZ recurring renewal test}"
REFERENCE="${PAYNL_REFERENCE:-WSZ-RTEST-$(date -u +%Y%m%d%H%M%S)}"
SEND_REQUEST=0
RESPONSE_FILE=""

cleanup() {
  if [[ -n "$RESPONSE_FILE" && -f "$RESPONSE_FILE" ]]; then
    rm -f "$RESPONSE_FILE"
  fi
}

trap cleanup EXIT

usage() {
  cat <<'USAGE'
PAY.nl recurring card curl test.

Dry-run by default. Pass --send to execute the byRecurringId request.

Required environment:
  PAYNL_TOKEN_CODE        Merchant token code, e.g. AT-1234-5678
  PAYNL_API_TOKEN         Merchant API token
  PAYNL_SERVICE_ID        Sales Location ID, e.g. SL-1234-5678
  PAYNL_RECURRING_ID      Token exchange recurring_id, e.g. VY-9212-9171-2390

Optional environment:
  PAYNL_AMOUNT_CENTS      Amount in cents. Default: 100
  PAYNL_CURRENCY          Currency. Default: EUR
  PAYNL_REFERENCE         Merchant reference. Default: WSZ-RTEST-{UTC timestamp}
  PAYNL_DESCRIPTION       Transaction description
  PAYNL_AUTHORIZE_ENDPOINT
  PAYNL_TRANSACTION_INFO_ENDPOINT

Examples:
  scripts/paynl-recurring-curl-test.sh
  scripts/paynl-recurring-curl-test.sh --send
USAGE
}

for arg in "$@"; do
  case "$arg" in
    --send)
      SEND_REQUEST=1
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      printf 'Unknown argument: %s\n\n' "$arg" >&2
      usage >&2
      exit 2
      ;;
  esac
done

require_env() {
  local name="$1"

  if [[ -z "${!name:-}" ]]; then
    printf 'Missing required environment variable: %s\n' "$name" >&2
    exit 2
  fi
}

require_env PAYNL_TOKEN_CODE
require_env PAYNL_API_TOKEN
require_env PAYNL_SERVICE_ID
require_env PAYNL_RECURRING_ID

if ! command -v php >/dev/null 2>&1; then
  printf 'Missing required command: php\n' >&2
  exit 2
fi

if ! command -v curl >/dev/null 2>&1; then
  printf 'Missing required command: curl\n' >&2
  exit 2
fi

PAYLOAD="$(PAYNL_SERVICE_ID="$PAYNL_SERVICE_ID" \
  PAYNL_RECURRING_ID="$PAYNL_RECURRING_ID" \
  PAYNL_AMOUNT_CENTS="$AMOUNT_CENTS" \
  PAYNL_CURRENCY="$CURRENCY" \
  PAYNL_DESCRIPTION="$DESCRIPTION" \
  PAYNL_REFERENCE="$REFERENCE" \
  php -r '
$payload = [
    "serviceId" => getenv("PAYNL_SERVICE_ID"),
    "recurringId" => getenv("PAYNL_RECURRING_ID"),
    "amount" => (int) getenv("PAYNL_AMOUNT_CENTS"),
    "currency" => getenv("PAYNL_CURRENCY"),
    "description" => getenv("PAYNL_DESCRIPTION"),
    "statsData" => [
        "extra3" => getenv("PAYNL_REFERENCE"),
        "object" => "Woo Subs-Zero curl test",
    ],
];

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
  ')"

if [[ "$SEND_REQUEST" -eq 0 ]]; then
  cat <<DRYRUN
Dry run only. No PAY.nl request was sent.

Recurring endpoint:
  $AUTHORIZE_ENDPOINT

Payload:
$PAYLOAD

Run with --send to execute the recurring byRecurringId payment request.
DRYRUN
  exit 0
fi

FORM_BODY="$(PAYLOAD="$PAYLOAD" php -r '
$payload = json_decode(getenv("PAYLOAD"), true);
echo http_build_query($payload, "", "&", PHP_QUERY_RFC3986);
')"

RESPONSE_FILE="$(mktemp)"
HTTP_STATUS="$(
  curl -sS \
    --request POST \
    --url "$AUTHORIZE_ENDPOINT" \
    --user "$PAYNL_TOKEN_CODE:$PAYNL_API_TOKEN" \
    --header 'accept: application/json' \
    --header 'content-type: application/x-www-form-urlencoded' \
    --data "$FORM_BODY" \
    --write-out '%{http_code}' \
    --output "$RESPONSE_FILE"
)"

printf 'Authorize HTTP status: %s\n' "$HTTP_STATUS"
printf 'Authorize response:\n'
cat "$RESPONSE_FILE"
printf '\n'

TRANSACTION_ID="$(php -r '
$body = file_get_contents($argv[1]);
$decoded = json_decode($body, true);
if (!is_array($decoded)) {
    exit;
}

$paths = [
    ["transactionId"],
    ["transaction_id"],
    ["transaction", "transactionId"],
    ["transaction", "transaction_id"],
    ["transaction", "id"],
    ["paymentSessionId"],
    ["payment_session_id"],
    ["orderId"],
    ["order_id"],
    ["id"],
];

foreach ($paths as $path) {
    $value = $decoded;
    foreach ($path as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            continue 2;
        }
        $value = $value[$segment];
    }
    if (is_scalar($value) && "" !== (string) $value) {
        echo (string) $value;
        exit;
    }
}
' "$RESPONSE_FILE")"

if [[ -z "$TRANSACTION_ID" ]]; then
  printf 'No transaction ID was found in the authorize response.\n' >&2
  exit 1
fi

printf 'Detected transaction ID: %s\n' "$TRANSACTION_ID"
printf 'Transaction info response:\n'
curl -sS \
  --request GET \
  --url "$TRANSACTION_INFO_ENDPOINT/$TRANSACTION_ID" \
  --user "$PAYNL_TOKEN_CODE:$PAYNL_API_TOKEN" \
  --header 'accept: application/json'
printf '\n'
