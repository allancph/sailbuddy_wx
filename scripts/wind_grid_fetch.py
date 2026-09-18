#!/usr/bin/env python3
"""Sailbuddy wind-grid batch fetcher.

Periodically fetches u/v wind components (m/s) over Danish waters from the
Open-Meteo Weather Forecast API and writes a single compact JSON file read
by the Drupal openmeteo_weather module / Leaflet particle overlay.

Grid: 0.25° resolution over a rectangular region covering Kattegat, Skagerrak,
the Danish Belts and the western Baltic.
"""

import json
import os
import sys
import tempfile
import time
import urllib.request
import urllib.error

# Grid configuration.
LAT0, LON0 = 53.5, 7.5   # south-west corner
LAT1, LON1 = 58.5, 16.5  # north-east corner (exclusive of final step)
STEP = 0.25              # degrees

# Open-Meteo endpoint.
FORECAST_URL = "https://api.open-meteo.com/v1/forecast"

# Maximum locations per HTTP request (Open-Meteo limit).
BATCH_SIZE = 900  # conservative vs. documented 1000 limit

# User-Agent identifier.
UA = "sailbuddy-wind-grid/1.0 (+https://sailbuddy.com)"


def grid_coords():
    """Return lists of (lat, lon) pairs for the grid."""
    latitudes = []
    longitudes = []
    lat = LAT0
    while lat <= LAT1 + 1e-9:
        lon = LON0
        while lon <= LON1 + 1e-9:
            latitudes.append(round(lat, 4))
            longitudes.append(round(lon, 4))
            lon = round(lon + STEP, 4)
        lat = round(lat + STEP, 4)
    return latitudes, longitudes


def fetch_batch(latitudes, longitudes):
    """Fetch u/v for one batch of coordinates via POST."""
    payload = json.dumps({
        "latitude": latitudes,
        "longitude": longitudes,
        "current": ["wind_u_component_10m", "wind_v_component_10m"],
        "wind_speed_unit": "ms",
        "forecast_days": 1,
    }).encode("utf-8")

    req = urllib.request.Request(
        FORECAST_URL,
        data=payload,
        headers={
            "Content-Type": "application/json",
            "User-Agent": UA,
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            return json.loads(resp.read())
    except urllib.error.HTTPError as exc:
        print(f"HTTP {exc.code}: {exc.read().decode()[:200]}", file=sys.stderr)
        return None
    except Exception as exc:
        print(f"Request failed: {exc}", file=sys.stderr)
        return None


def organize_grid(latitudes, longitudes, results):
    """Arrange flat result list into a 2-D row-major grid."""
    nlat = len(set(latitudes))
    nlon = len(set(longitudes))
    u_grid = [[0.0] * nlon for _ in range(nlat)]
    v_grid = [[0.0] * nlon for _ in range(nlat)]
    fail_count = 0

    for i, item in enumerate(results):
        lat = round(item.get("latitude", 0), 4)
        lon = round(item.get("longitude", 0), 4)
        lat_i = int(round((lat - LAT0) / STEP))
        lon_i = int(round((lon - LON0) / STEP))
        if lat_i < 0 or lat_i >= nlat or lon_i < 0 or lon_i >= nlon:
            continue
        current = item.get("current", {})
        u = current.get("wind_u_component_10m")
        v = current.get("wind_v_component_10m")
        if u is None or v is None:
            fail_count += 1
        else:
            u_grid[lat_i][lon_i] = round(float(u), 2)
            v_grid[lat_i][lon_i] = round(float(v), 2)

    return u_grid, v_grid, nlat, nlon, fail_count


def main():
    outdir = os.environ.get("OUTDIR", "/opt/cmems-toolbox/windgrid")
    os.makedirs(outdir, exist_ok=True)

    latitudes, longitudes = grid_coords()
    n = len(latitudes)
    print(f"Grid: {n} points (lat {LAT0}-{LAT1}, lon {LON0}-{LON1}, step {STEP}°)")

    all_results = []
    for start in range(0, n, BATCH_SIZE):
        chunk_lat = latitudes[start:start + BATCH_SIZE]
        chunk_lon = longitudes[start:start + BATCH_SIZE]
        print(f"  Fetching batch {start // BATCH_SIZE + 1} ({len(chunk_lat)} pts)...")
        result = fetch_batch(chunk_lat, chunk_lon)
        if result is None:
            print(f"  Batch {start // BATCH_SIZE + 1} FAILED", file=sys.stderr)
            sys.exit(1)
        if isinstance(result, list):
            all_results.extend(result)
        else:
            # Single-location result (unlikely with multi-loc POST)
            all_results.append(result)
        # Be polite: tiny delay between batches.
        time.sleep(0.2)

    u_grid, v_grid, nlat, nlon, fail_count = organize_grid(
        latitudes, longitudes, all_results
    )
    print(f"Grid assembled: {nlat}×{nlon} = {nlat * nlon} cells, {fail_count} missing")

    payload = {
        "fetched": int(time.time()),
        "lat0": LAT0,
        "lon0": LON0,
        "dlat": STEP,
        "dlon": STEP,
        "rows": nlat,
        "cols": nlon,
        "u": u_grid,
        "v": v_grid,
    }

    # Atomic write via temp-file + rename.
    fd, tmppath = tempfile.mkstemp(suffix=".json", dir=outdir)
    try:
        with os.fdopen(fd, "w") as fh:
            json.dump(payload, fh, separators=(",", ":"))
        outpath = os.path.join(outdir, "wind_grid.json")
        os.replace(tmppath, outpath)
        size = os.path.getsize(outpath)
        print(f"Written: {outpath} ({size:,} bytes)")
    except Exception:
        os.unlink(tmppath)
        raise


if __name__ == "__main__":
    main()
