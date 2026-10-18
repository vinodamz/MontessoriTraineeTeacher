#!/usr/bin/env python3
"""
nifty50_3pm_candle.py
=====================

Fetch the Nifty 50 (NSE index, Yahoo ticker ^NSEI) 5-minute candle that
covers the **3:00 PM -> 3:05 PM IST** window for each trading day over the
last N days (default 90) and print / save them as OHLCV rows.

In a 5-minute candle series the candle stamped 15:00 IST represents the
interval [15:00:00, 15:05:00), i.e. exactly "3pm to 3.05pm".

Usage
-----
    python3 nifty50_3pm_candle.py                # last 90 days, prints table + writes CSV
    python3 nifty50_3pm_candle.py --days 60
    python3 nifty50_3pm_candle.py --out nifty.csv
    python3 nifty50_3pm_candle.py --symbol ^NSEI --window 15:00

No third-party packages required (standard library only).

Network note
------------
This script reaches Yahoo Finance at runtime:
    https://query1.finance.yahoo.com/v8/finance/chart/^NSEI
If you run it inside a sandbox with an egress allowlist, add
`query1.finance.yahoo.com` to the allowed hosts first.

Data-availability caveat
------------------------
Yahoo Finance only serves 5-minute intraday history for roughly the last
**60 days**. Requesting 90 days will therefore return at most ~60 trading
days of candles. The script asks for the widest 5m range Yahoo allows and
keeps whatever falls inside your --days window. For a full 90 days of 5m
data you need a paid/broker feed (e.g. an NSE-authorised data vendor); point
--symbol / the fetch function at that source if you have one.
"""

from __future__ import annotations

import argparse
import csv
import datetime as dt
import json
import sys
import urllib.error
import urllib.parse
import urllib.request
from zoneinfo import ZoneInfo

IST = ZoneInfo("Asia/Kolkata")
CHART_URL = "https://query1.finance.yahoo.com/v8/finance/chart/{symbol}"
UA = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
)


def fetch_chart(symbol: str, rng: str = "60d", interval: str = "5m") -> dict:
    """Fetch raw chart JSON from Yahoo Finance."""
    url = CHART_URL.format(symbol=urllib.parse.quote(symbol, safe=""))
    url += f"?range={rng}&interval={interval}&includePrePost=false"
    req = urllib.request.Request(url, headers={"User-Agent": UA, "Accept": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        sys.exit(f"HTTP error from Yahoo ({e.code}): {e.reason}\n"
                 f"If you are in a sandbox, allow host query1.finance.yahoo.com.")
    except urllib.error.URLError as e:
        sys.exit(f"Network error: {e.reason}\n"
                 f"If you are in a sandbox, allow host query1.finance.yahoo.com.")


def extract_window_candles(payload: dict, window_hhmm: str, days: int):
    """Return list of OHLCV dicts for the candle whose IST start == window."""
    result = payload.get("chart", {}).get("result")
    if not result:
        err = payload.get("chart", {}).get("error")
        sys.exit(f"No data returned. Error: {err}")
    res = result[0]
    timestamps = res.get("timestamp") or []
    quote = res["indicators"]["quote"][0]
    opens, highs = quote.get("open", []), quote.get("high", [])
    lows, closes = quote.get("low", []), quote.get("close", [])
    volumes = quote.get("volume", [])

    target_h, target_m = (int(x) for x in window_hhmm.split(":"))
    cutoff = dt.datetime.now(IST) - dt.timedelta(days=days)

    rows = []
    for i, ts in enumerate(timestamps):
        t_ist = dt.datetime.fromtimestamp(ts, IST)
        if t_ist.hour == target_h and t_ist.minute == target_m and t_ist >= cutoff:
            o, h, l, c = opens[i], highs[i], lows[i], closes[i]
            if None in (o, h, l, c):  # skip gaps/holidays
                continue
            rows.append({
                "date": t_ist.strftime("%Y-%m-%d"),
                "window": f"{window_hhmm}-{(t_ist + dt.timedelta(minutes=5)).strftime('%H:%M')} IST",
                "open": round(o, 2),
                "high": round(h, 2),
                "low": round(l, 2),
                "close": round(c, 2),
                "volume": volumes[i] if i < len(volumes) and volumes[i] is not None else 0,
            })
    rows.sort(key=lambda r: r["date"])
    return rows


def print_table(rows):
    if not rows:
        print("No candles found for the requested window.")
        return
    hdr = f"{'Date':<12}{'Window':<20}{'Open':>10}{'High':>10}{'Low':>10}{'Close':>10}{'Volume':>12}"
    print(hdr)
    print("-" * len(hdr))
    for r in rows:
        print(f"{r['date']:<12}{r['window']:<20}{r['open']:>10.2f}{r['high']:>10.2f}"
              f"{r['low']:>10.2f}{r['close']:>10.2f}{r['volume']:>12}")
    print(f"\n{len(rows)} trading days.")


def write_csv(rows, path):
    with open(path, "w", newline="") as f:
        w = csv.DictWriter(f, fieldnames=["date", "window", "open", "high", "low", "close", "volume"])
        w.writeheader()
        w.writerows(rows)
    print(f"Wrote {len(rows)} rows to {path}")


def main():
    ap = argparse.ArgumentParser(description="Nifty 50 3:00-3:05 PM IST candle, last N days.")
    ap.add_argument("--symbol", default="^NSEI", help="Yahoo ticker (default ^NSEI = Nifty 50)")
    ap.add_argument("--days", type=int, default=90, help="Look-back window in days (default 90)")
    ap.add_argument("--window", default="15:00", help="Candle start HH:MM IST (default 15:00 = 3pm)")
    ap.add_argument("--out", default="nifty50_3pm_candles.csv", help="Output CSV path")
    args = ap.parse_args()

    # Yahoo caps 5m history at ~60 days; ask for the max valid range.
    rng = "60d" if args.days > 60 else f"{args.days}d"
    payload = fetch_chart(args.symbol, rng=rng, interval="5m")
    rows = extract_window_candles(payload, args.window, args.days)

    if args.days > 60 and rows:
        print("NOTE: Yahoo serves only ~60 days of 5-minute data; "
              f"requested {args.days}d, got {len(rows)} trading days.\n")
    print_table(rows)
    if rows:
        write_csv(rows, args.out)


if __name__ == "__main__":
    main()
