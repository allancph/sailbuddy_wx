#!/usr/bin/env python3
"""Sailbuddy Copernicus Marine (CMEMS) cache builder.

Periodically fetches wave/current/SST forecasts for all harbour coordinates
and writes normalized JSON files read by the Drupal module
openmeteo_weather (CopernicusMarineProvider).

Designed to run from a cron job (e.g. every 6 h) on a machine with the
`copernicusmarine` toolbox installed. Never called from the web request
path: it is a batch job only.

Output layout (one file per rounded coordinate):
  <outdir>/56.715_11.510.json   -> normalized payload (see below)

Normalized payload (mirrors what twig expects for current + forecast):
  {
    "provider": "copernicus_marine",
    "fetched": <unix ts>,
    "current": {"wave_height":..., "wave_period":..., "wave_direction":...,
                "sea_temp":...},
    "forecast": [{"date":"YYYY-MM-DD","wave_max":...,"wave_period_max":...,
                 "wave_dir":...}]
  }

Usage:
  python3 cmems_fetch.py --login allancph@gmail.com \
    --coordinates harbours.json --outdir ./cmems \
    --ttl 21600 --max-age 86400
"""

from __future__ import annotations

import argparse
import copy
import json
import logging
import os
import subprocess
import sys
import tempfile
import time
from datetime import datetime, timedelta, timezone

import csv
import io

LOG = logging.getLogger("cmems_fetch")

WAVE_DATASET = "cmems_mod_glo_wav_anfc_0.083deg_PT3H-i"
CURRENT_DATASET = "cmems_mod_glo_phy-cur_anfc_0.083deg_PT6H-i"
TEMP_DATASET = "cmems_mod_glo_phy-thetao_anfc_0.083deg_PT6H-i"

# Wave variables we care about.
WAVE_VARS = ["VHM0", "VTPK", "VMDR"]
CURRENT_VARS = ["uo", "vo"]
TEMP_VARS = ["thetao"]


def now() -> int:
    return int(time.time())


def cache_filename(lat: float, lon: float) -> str:
    return "%.3F_%.3F.json" % (lat, lon)


def ttl_valid(path: str, ttl_seconds: int) -> bool:
    """True when the cache file exists and is younger than the TTL."""
    try:
        return now() - os.path.getmtime(path) < ttl_seconds
    except OSError:
        return False

def run_subset(dataset: str, variables: list[str], lat: float, lon: float,
               days: int, cmd_binary: str) -> list[dict]:
    """Runs the copernicusmarine subset CLI and returns parsed rows.

    Returns a list of dicts (one per row) using raw CSV columns.
    """
    cmd = [
        cmd_binary,
        "subset",
        "-i", dataset,
    ]
    for var in variables:
        cmd += ["-v", var]
    cmd += [
        "-x", str(lon - 0.004), "-X", str(lon + 0.004),
        "-y", str(lat - 0.004), "-Y", str(lat + 0.004),
        "--coordinates-selection-method", "nearest",
        "-t", (datetime.now(timezone.utc) - timedelta(days=1)).strftime("%Y-%m-%d"),
        "-T", (datetime.now(timezone.utc) + timedelta(days=days)).strftime("%Y-%m-%d"),
        "--file-format", "csv",
    ]

    with tempfile.TemporaryDirectory() as tmp:
        cmd += ["-o", tmp]
        LOG.debug("Running: %s", " ".join(cmd))
        proc = subprocess.run(cmd, capture_output=True, text=True, timeout=600)
        if proc.returncode != 0:
            LOG.warning("subset failed (%d) for %s: %s",
                        proc.returncode, dataset, proc.stderr[-500:])
            return []
        # Find the generated CSV.
        csv_files = [f for f in os.listdir(tmp) if f.endswith(".csv")]
        if not csv_files:
            LOG.warning("No CSV produced for dataset %s", dataset)
            return []
        csv_path = os.path.join(tmp, csv_files[0])
        with open(csv_path, newline="", encoding="utf-8") as fh:
            reader = csv.DictReader(fh)
            rows = list(reader)
        return rows


def first_nonempty(rows: list[dict], field: str) -> float | None:
    """First numeric value of a column, or None."""
    for r in rows:
        val = r.get(field)
        if val not in (None, ""):
            try:
                return float(val)
            except ValueError:
                continue
    return None


def lat_lon_from_rows(rows: list[dict]) -> tuple[float, float] | None:
    """The nearest grid point actually used (from first row)."""
    for r in rows:
        if r.get("latitude") and r.get("longitude"):
            try:
                return float(r["latitude"]), float(r["longitude"])
            except ValueError:
                continue
    return None


def daily_agg(rows: list[dict]) -> list[dict]:
    """Aggregates 3-hourly wave rows into a daily maximum summary."""
    by_date: dict[str, list[dict]] = {}
    for r in rows:
        t = r.get("time", "")
        date = str(t)[:10]
        by_date.setdefault(date, []).append(r)

    out = []
    for date in sorted(by_date):
        day_rows = [r for r in by_date[date]
                    if r.get("VHM0") not in (None, "")]
        if not day_rows:
            continue
        peak = max(day_rows, key=lambda r: float(r["VHM0"]))
        out.append({
            "date": date,
            "wave_max": float(peak["VHM0"]),
            "wave_period_max": (float(peak["VTPK"])
                                if peak.get("VTPK") not in (None, "") else None),
            "wave_dir": (float(peak["VMDR"])
                         if peak.get("VMDR") not in (None, "") else None),
        })
    return out


def build_payload(lat: float, lon: float, wave_rows: list[dict],
                  current_rows: list[dict], temp_rows: list[dict]) -> dict:
    """Normalizes the raw CMEMS rows into the module payload shape."""
    wv_now = first_nonempty(wave_rows, "VHM0")
    wp_now = first_nonempty(wave_rows, "VTPK")
    wd_now = first_nonempty(wave_rows, "VMDR")
    sst_now = first_nonempty(temp_rows, "thetao")
    # Ocean current speed/direction from uo/vo.
    u_now = first_nonempty(current_rows, "uo")
    v_now = first_nonempty(current_rows, "vo")

    current = {
        "temperature": None,
        "wind_speed": None,
        "wind_direction": None,
        "precipitation": None,
        "weather_code": None,
        "wave_height": wv_now,
        "wave_period": wp_now,
        "wave_peak_period": wp_now,
        "wave_direction": wd_now,
        "sea_temp": sst_now,
    }
    if u_now is not None and v_now is not None:
        import math
        current["current_speed"] = round(math.hypot(u_now, v_now), 3)
        current["current_direction"] = round(
            (math.degrees(math.atan2(u_now, v_now)) + 360) % 360, 1)

    forecast = daily_agg(wave_rows)

    # Air fields are not provided by CMEMS; the twig renders dashes for them.
    for day in forecast:
        day.setdefault("temp_max", None)
        day.setdefault("temp_min", None)
        day.setdefault("weather_code", None)
        day.setdefault("wind_max", None)
        day.setdefault("wind_dir", None)

    return {
        "provider": "copernicus_marine",
        "fetched": now(),
        "current": current,
        "forecast": forecast,
        # Optional metadata for the info/status page.
        "meta": {
            "grid_point": lat_lon_from_rows(wave_rows),
            "datasets": [WAVE_DATASET, CURRENT_DATASET, TEMP_DATASET],
        },
    }


def process_coord(lat: float, lon: float, outdir: str, days: int,
                  ttl: int, skip_fresh: bool, cmd_binary: str) -> bool:
    """Fetches + writes the cache file for one coordinate."""
    fname = cache_filename(lat, lon)
    path = os.path.join(outdir, fname)

    # Try the requested point first. If the sea is masked out (coastal
    # harbours), the nearest grid cell often falls on land -> empty values.
    # Then progressively scan in all compass directions until usable wave
    # data is found. The grid step at these latitudes is ~0.083 deg (~8 km);
    # coastal cells may be masked for several steps (e.g. Wadden Sea), so we
    # go up to 0.5 deg in 0.083 deg increments. Positive dy/dx = N/E.
    steps = [0.0, 0.083, 0.166, 0.25, 0.333, 0.417, 0.5]
    for step in steps:
        for dy in (-step, 0.0, step):
            for dx in (-step, 0.0, step):
                candidate_lat = lat + dy
                candidate_lon = lon + dx
                wave_rows = run_subset(WAVE_DATASET, WAVE_VARS,
                                       candidate_lat, candidate_lon, days, cmd_binary)
                if first_nonempty(wave_rows, "VHM0") is None:
                    continue

                # Best-effort SST + current; failure here is non-fatal.
                temp_rows = []
                current_rows = []
                try:
                    temp_rows = run_subset(TEMP_DATASET, TEMP_VARS,
                                           candidate_lat, candidate_lon, days, cmd_binary)
                except Exception as exc:  # noqa: BLE001 - best-effort.
                    LOG.debug("SST fetch failed for %.3f,%.3f: %s", lat, lon, exc)
                try:
                    current_rows = run_subset(CURRENT_DATASET, CURRENT_VARS,
                                              candidate_lat, candidate_lon, days, cmd_binary)
                except Exception as exc:  # noqa: BLE001 - best-effort.
                    LOG.debug("Current fetch failed for %.3f,%.3f: %s", lat, lon, exc)

                payload = build_payload(candidate_lat, candidate_lon, wave_rows,
                                        current_rows, temp_rows)
                payload["meta"]["harbour"] = {"lat": lat, "lon": lon}
                payload["meta"]["used_point"] = {"lat": candidate_lat,
                                                 "lon": candidate_lon}
                write_atomic(path, payload)
                LOG.info("Cached %s (grid %.3f,%.3f)", fname,
                         candidate_lat, candidate_lon)
                return True

    LOG.warning("No usable ocean data for %s after offshore scan", fname)
    return False


def write_atomic(path: str, payload: dict) -> None:
    """Writes JSON atomically via a temp file + os.replace."""
    fd, tmp = tempfile.mkstemp(dir=os.path.dirname(path) or ".", suffix=".tmp")
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as fh:
            json.dump(payload, fh, ensure_ascii=False)
        os.replace(tmp, path)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--coordinates", required=True,
                        help="JSON array from `drush openmeteo_weather:cmems-coordinates`.")
    parser.add_argument("--outdir", required=True,
                        help="Directory to write JSON cache files (staging).")
    parser.add_argument("--days", type=int, default=4,
                        help="Forecast horizon in days.")
    parser.add_argument("--ttl", type=int, default=21600,
                        help="Seconds a file is considered fresh (default 21600).")
    parser.add_argument("--skip-fresh", action="store_true",
                        help="Skip coordinates whose cache file is fresh.")
    parser.add_argument("--limit", type=int, default=0,
                        help="Only process the first N coordinates (0 = all).")
    parser.add_argument("--copernicusmarine", default="copernicusmarine",
                        help="Path to the copernicusmarine CLI binary "
                             "(default: copernicusmarine on PATH).")
    parser.add_argument("-v", "--verbose", action="store_true")
    args = parser.parse_args()

    logging.basicConfig(
        stream=sys.stderr,
        level=logging.DEBUG if args.verbose else logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s: %(message)s",
    )

    with open(args.coordinates, encoding="utf-8") as fh:
        coords = json.load(fh)
    if not isinstance(coords, list):
        LOG.error("--coordinates must be a JSON array")
        return 1

    os.makedirs(args.outdir, exist_ok=True)
    processed = 0
    skipped = 0
    failed = 0
    for item in coords:
        lat = float(item["lat"])
        lon = float(item["lon"])
        path = os.path.join(args.outdir, cache_filename(lat, lon))
        if args.skip_fresh and ttl_valid(path, args.ttl):
            skipped += 1
            continue
        if process_coord(lat, lon, args.outdir, args.days, args.ttl,
                         args.skip_fresh, args.copernicusmarine):
            processed += 1
        else:
            failed += 1
        if args.limit and processed + skipped + failed >= args.limit:
            break

    LOG.info("Done: %d cached, %d skipped (fresh), %d failed",
             processed, skipped, failed)
    return 0


if __name__ == "__main__":
    sys.exit(main())