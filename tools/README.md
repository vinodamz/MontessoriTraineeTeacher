# Nifty 50 — 3:00 PM to 3:05 PM candle fetcher

`nifty50_3pm_candle.py` pulls the Nifty 50 (`^NSEI`) **5-minute candle that
covers 3:00 PM → 3:05 PM IST** for each trading day over the last N days
(default 90) and prints them plus writes a CSV.

The 5-minute candle stamped `15:00` IST represents the interval
`[15:00:00, 15:05:00)` — i.e. exactly "3pm to 3.05pm".

## Run

```bash
python3 tools/nifty50_3pm_candle.py              # last 90 days
python3 tools/nifty50_3pm_candle.py --days 60
python3 tools/nifty50_3pm_candle.py --out out.csv
```

No third-party packages — Python 3.9+ standard library only.

Output columns: `date, window, open, high, low, close, volume`.

## Network requirement

The script calls Yahoo Finance at runtime:

```
https://query1.finance.yahoo.com/v8/finance/chart/^NSEI
```

If you run it in a sandbox / CI with an **egress allowlist** (as in the
Claude Code web environment), add `query1.finance.yahoo.com` to the allowed
hosts first — otherwise you'll get a `403 Forbidden` from the proxy. See
https://code.claude.com/docs/en/claude-code-on-the-web for how the network
policy is configured.

## Data-availability caveat (important)

Yahoo Finance serves only about **60 days** of 5-minute intraday history.
Asking for 90 days returns at most ~60 trading days of candles, and the
script prints a NOTE when that happens.

For a full **90 days of 5-minute data** you need an NSE-authorised /
broker data feed (Upstox, Zerodha Kite, Dhan, Fyers, TrueData, GlobalDataFeeds,
etc.). Replace the `fetch_chart()` function with a call to that API and keep
the rest of the extraction logic (`extract_window_candles`) as-is — it just
needs timestamped 5-minute OHLCV.
