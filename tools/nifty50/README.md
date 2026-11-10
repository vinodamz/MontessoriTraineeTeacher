# NIFTY 50 — 3:00–3:05 PM candle fetcher

Fetches the NIFTY 50 index **5-minute candle for the 15:00–15:05 IST window**
for each trading day in the last 90 days (configurable) and prints a table /
writes a CSV.

## Run

Requires only Python 3.9+ and internet access — no API key, no pip installs.

```bash
python3 tools/nifty50/fetch_3pm_candle.py                 # last 90 days
python3 tools/nifty50/fetch_3pm_candle.py --days 30
python3 tools/nifty50/fetch_3pm_candle.py --csv nifty_3pm.csv
```

Output columns: `date, day, open, high, low, close, change` — one row per
trading day, where OHLC are the values of the single 5-minute candle that
opens at 15:00 IST.

## Data sources

- **Default (`--source upstox`)** — Upstox public historical-candle API
  (`api.upstox.com/v3/historical-candle`). Free, no authentication, minute
  data going back years. The script chunks the 90-day range into ~monthly
  requests as the API requires.
- **Fallback (`--source yahoo`)** — Yahoo Finance chart API for `^NSEI`.
  Yahoo only serves 5-minute candles for roughly the **last 60 days**, so it
  cannot cover a full 90-day window.

## Why the data isn't checked in

This was built in a sandboxed Claude Code environment whose network policy
blocks all market-data hosts, so the script could not be executed against the
live APIs there. Run it on any machine with normal internet access to get the
actual candles.
