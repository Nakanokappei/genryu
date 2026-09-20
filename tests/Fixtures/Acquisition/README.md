# Acquisition fixtures

Saved HTTP responses used by the default (network-free) test run.

## Layout

```text
tests/Fixtures/Acquisition/
  <source_key>/               darpa, nedo, or `synthetic` for hand-made cases
    <fixture-name>/
      response.json           request/response metadata (see below)
      body.<ext>              the exact response body bytes, untouched
```

`response.json`:

```json
{
  "url": "https://www.example.org/news/",
  "final_url": "https://www.example.org/news/",
  "status": 200,
  "headers": {"content-type": "text/html; charset=utf-8", "etag": "\"abc\""},
  "media_type": "text/html",
  "retrieved_at": "2026-09-21T00:00:00Z",
  "body_file": "body.html",
  "note": "why this fixture exists"
}
```

## Rules

- Never edit `body.*` by hand after capture. If a case needs different
  bytes, add a new fixture.
- Fixtures taken from a real site record the capture time and URL in
  `response.json`. They are public primary sources; still, keep them small.
- Synthetic fixtures live under `synthetic/` and are the place for failure
  cases (empty body, challenge page, malformed XML, XXE payload, scanned PDF).
- No personal data in fixtures.
