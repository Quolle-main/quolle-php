# Quolle PHP SDK

Official PHP client for the [Quolle](https://quolle.com) email API.

## Install

```bash
composer require quolle/quolle-php
```

Requires PHP 7.4+ with the `curl` and `json` extensions.

## Quick start

```php
require 'vendor/autoload.php';

$quolle = new \Quolle\Client('qle_...'); // or set QUOLLE_API_KEY

$result = $quolle->emails->send([
    'from'    => 'hello@mail.yourdomain.com',
    'to'      => 'customer@example.com',
    'subject' => 'Welcome!',
    'html'    => '<h1>Thanks for signing up</h1>',
]);

echo "Queued: " . $result['id'];
```

## Sending

### Multiple recipients

```php
$quolle->emails->send([
    'from'    => 'hello@mail.yourdomain.com',
    'to'      => ['a@example.com', 'b@example.com'],
    'subject' => 'Announcement',
    'html'    => '<p>Hello everyone</p>',
]);
```

### Templates

```php
$quolle->emails->send([
    'from'      => 'hello@mail.yourdomain.com',
    'to'        => 'customer@example.com',
    'template'  => 'welcome-email',
    'variables' => ['firstName' => 'Amaka', 'planName' => 'Starter'],
]);
```

### Scheduled send

```php
$quolle->emails->send([
    'from'        => 'hello@mail.yourdomain.com',
    'to'          => 'customer@example.com',
    'subject'     => 'Your weekly digest',
    'html'        => '<p>Here is what happened this week.</p>',
    'scheduledAt' => '2026-12-25T09:00:00.000Z',
]);
```

### Idempotency

```php
$quolle->emails->send([
    'from'    => 'billing@mail.yourdomain.com',
    'to'      => 'customer@example.com',
    'subject' => 'Invoice #1234',
    'html'    => '<p>Your invoice is attached.</p>',
], 'order_invoice_12345');
```

### Batch

Up to 100 emails in one all-or-nothing request:

```php
$result = $quolle->emails->sendBatch([
    ['from' => 'hello@mail.yourdomain.com', 'to' => 'a@example.com',
     'subject' => 'Hi Alice', 'html' => '<p>Hi Alice</p>'],
    ['from' => 'hello@mail.yourdomain.com', 'to' => 'b@example.com',
     'subject' => 'Hi Bob', 'html' => '<p>Hi Bob</p>'],
]);

echo "Queued {$result['queued']}";
```

### Attachments

Add an `attachments` array — each with `filename`, base64 `content`, and an optional
`contentType`. Up to 20 files, 10 MB total.

```php
$quolle->emails->send([
    'from'    => 'billing@mail.yourdomain.com',
    'to'      => 'customer@example.com',
    'subject' => 'Your invoice',
    'html'    => '<p>Invoice attached.</p>',
    'attachments' => [
        [
            'filename'    => 'invoice.pdf',
            'content'     => base64_encode(file_get_contents('invoice.pdf')),
            'contentType' => 'application/pdf',
        ],
    ],
]);
```

## Retrieve & cancel

```php
$email = $quolle->emails->get('a1b2c3d4-...');
echo $email['status']; // queued | sending | sent | delivered | bounced | failed

$quolle->emails->cancel('a1b2c3d4-...'); // only while status == "scheduled"
```

## Error handling

```php
use Quolle\QuolleException;

try {
    $quolle->emails->send([
        'from' => 'hello@mail.yourdomain.com', 'to' => 'customer@example.com',
        'subject' => 'Welcome!', 'html' => '<h1>Welcome</h1>',
    ]);
} catch (QuolleException $e) {
    echo $e->statusCode;      // e.g. 402
    echo $e->errorCode;       // e.g. "monthly_quota_exceeded" — branch on this
    echo $e->getMessage();    // e.g. "Monthly limit reached"
    print_r($e->data);        // extra fields, e.g. ['limit' => 3000]
}
```

## Testing your integration

Send to a reserved test address to simulate any outcome without touching your
sending reputation: `delivered@test.quolle.com`, `bounced@test.quolle.com`,
`complained@test.quolle.com`, `suppressed@test.quolle.com`.

## Verifying webhooks

Confirm an incoming webhook really came from Quolle. Pass the **raw** request body, the `Quolle-Signature` header, and your signing secret (`whsec_…`):

```php
use Quolle\QuolleException;

$rawBody = file_get_contents('php://input');
try {
    $event = $quolle->webhooks->verify(
        $rawBody,
        $_SERVER['HTTP_QUOLLE_SIGNATURE'],
        'whsec_your_signing_secret'
    );
    // $event['event'] === 'email.delivered'
} catch (QuolleException $e) {
    http_response_code(400);
}
```

HMAC-SHA256 with a 5-minute timestamp window (replay protection).

## Automatic retries

Transient failures — HTTP 429 (rate limit) and 5xx, plus network errors — are retried automatically with exponential backoff, honoring the `Retry-After` header. To avoid double-sending, a POST is only retried on a 5xx/network error when you pass an idempotency key; a 429 is always safe to retry (the request was never processed). Quota limits are the exception: `daily_quota_exceeded` and `warmup_limit_exceeded` (and any `Retry-After` over 60 seconds) are thrown immediately, because they reset at a fixed time, not in seconds; check `$e->errorCode` and queue the send for later. Tune with `max_retries` in the options array (default 3).

## License

MIT
# quolle-php
