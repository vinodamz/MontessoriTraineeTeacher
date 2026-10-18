#!/usr/bin/env python3
"""Fetch the NIFTY 50 index 5-minute candle for the 15:00-15:05 IST window
for each trading day in the last N days (default 90).

Primary source: Upstox public historical-candle API (no API key required).
Fallback:       Yahoo Finance chart API (^NSEI) — note Yahoo only serves
                5-minute data for roughly the last 60 days.

Usage:
    python3 fetch_3pm_candle.py            # last 90 days, prints table
    python3 fetch_3pm_candle.py --days 30
    python3 fetch_3pm_candle.py --csv out.csv
    python3 fetch_3pm_candle.py --source yahoo

Only the Python standard library is used.
"""

import argparse
import csv
import json
import sys
import urllib.parse
import urllib.request
from datetime import date, datetime, timedelta, timezone

IST = timezone(timedelta(hours=5, minutes=30))
TARGET_TIME = "15:00"  # candle covering 15:00:00-15:04:59 IST

UPSTOX_INSTRUMENT = "NSE_INDEX|Nifty 50"
# Upstox caps minute-interval requests at about one month per call.
UPSTOX_CHUNK_DAYS = 28


def http_get_json(url, headers=None):
    req = urllib.request.Request(url, headers=headers or {})
    with urllib.request.urlopen(req, timeout=30) as resp:
        return json.loads(resp.read().decode("utf-8"))


def fetch_upstox(start, end):
    """Yield (datetime_ist, open, high, low, close) for every 5-min candle."""
    instrument = urllib.parse.quote(UPSTOX_INSTRUMENT, safe="")
    chunk_end = end
    while chunk_end >= start:
        chunk_start = max(start, chunk_end - timedelta(days=UPSTOX_CHUNK_DAYS))
        url = (
            f"https://api.upstox.com/v3/historical-candle/{instrument}"
            f"/minutes/5/{chunk_end:%Y-%m-%d}/{chunk_start:%Y-%m-%d}"
        )
        payload = http_get_json(url, headers={"Accept": "application/json"})
        if payload.get("status") != "success":
            raise RuntimeError(f"Upstox error for {url}: {payload}")
        for ts, o, h, l, c, *_ in payload["data"]["candles"]:
            yield datetime.fromisoformat(ts), o, h, l, c
        chunk_end = chunk_start - timedelta(days=1)


def fetch_yahoo(start, end):
    """Yield (datetime_ist, open, high, low, close). Max ~60 days of 5m data."""
    p1 = int(datetime.combine(start, datetime.min.time(), IST).timestamp())
    p2 = int(datetime.combine(end + timedelta(days=1), datetime.min.time(), IST).timestamp())
    url = (
        "https://query1.finance.yahoo.com/v8/finance/chart/%5ENSEI"
        f"?interval=5m&period1={p1}&period2={p2}"
    )
    payload = http_get_json(url, headers={"User-Agent": "Mozilla/5.0"})
    result = payload["chart"]["result"][0]
    quote = result["indicators"]["quote"][0]
    for i, ts in enumerate(result["timestamp"]):
        o = quote["open"][i]
        if o is None:
            continue
        dt = datetime.fromtimestamp(ts, IST)
        yield dt, o, quote["high"][i], quote["low"][i], quote["close"][i]


def collect(source, start, end):
    fetch = fetch_upstox if source == "upstox" else fetch_yahoo
    rows = {}
    for dt, o, h, l, c in fetch(start, end):
        if dt.strftime("%H:%M") == TARGET_TIME and start <= dt.date() <= end:
            rows[dt.date()] = (o, h, l, c)
    return [
        {
            "date": d.isoformat(),
            "day": d.strftime("%a"),
            "open": rows[d][0],
            "high": rows[d][1],
            "low": rows[d][2],
            "close": rows[d][3],
            "change": round(rows[d][3] - rows[d][0], 2),
        }
        for d in sorted(rows)
    ]


def main():
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("--days", type=int, default=90, help="lookback window in days (default 90)")
    ap.add_argument("--source", choices=["upstox", "yahoo"], default="upstox")
    ap.add_argument("--csv", metavar="FILE", help="also write the rows to this CSV file")
    args = ap.parse_args()

    end = datetime.now(IST).date()
    start = end - timedelta(days=args.days)
    rows = collect(args.source, start, end)

    if not rows:
        print("No 15:00 candles found — check connectivity or source limits.", file=sys.stderr)
        return 1

    print(f"NIFTY 50 — 15:00-15:05 IST candle, {start} to {end} ({len(rows)} trading days)\n")
    print(f"{'Date':<12}{'Day':<5}{'Open':>10}{'High':>10}{'Low':>10}{'Close':>10}{'Chg':>8}")
    for r in rows:
        print(
            f"{r['date']:<12}{r['day']:<5}{r['open']:>10.2f}{r['high']:>10.2f}"
            f"{r['low']:>10.2f}{r['close']:>10.2f}{r['change']:>8.2f}"
        )

    if args.csv:
        with open(args.csv, "w", newline="") as f:
            writer = csv.DictWriter(f, fieldnames=list(rows[0].keys()))
            writer.writeheader()
            writer.writerows(rows)
        print(f"\nWrote {len(rows)} rows to {args.csv}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
