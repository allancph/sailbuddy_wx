# Sailbuddy OpenMeteo Weather

Drupal 10/11 custom module that shows current weather and a marine forecast on
harbour / anchorage nodes of the Sailbuddy site. It uses a **provider chain**
for resilience:

1. **Open-Meteo** (primary, live) — [Weather](https://open-meteo.com/en/docs) and
   [Marine Weather API](https://open-meteo.com/en/docs/marine-weather-api),
   free, no API key.
2. **Copernicus Marine Service (CMEMS)** (fallback) — batched forecast data
   written to disk by an offline cron job; served when Open-Meteo is
   unreachable or returns no data. Free for individuals.

![Harbour weather widget](docs/screenshot-harbour.png)

## Features

- Provider chain managed by `MarineWeatherManager` (service
  `openmeteo_weather.manager`): Open-Meteo first, Copernicus fallback,
  stale-cache as a last resort.
- Current conditions: temperature, wind (speed + compass direction), waves
  (height, period, direction), sea surface temperature, precipitation.
- 5-day forecast: day name (I dag / I morgen / weekday), min/max temperature,
  wind speed + direction, max wave height / period / direction.
- Widget footer shows the active data source and flags stale fallback data
  ("stale data" badge).
- WMO weather codes mapped to descriptions with inline SVG icons; compass
  arrows generated from degrees (16-point labels).
- Per-coordinate caching (TTL configurable) + page cache, respectful of the
  free API quota.
- `harbor_weather_block` block plugin to place in a region.
- Admin status page `/admin/config/services/openmeteo-weather/info`.

## Requirements

- Drupal 10 or 11, `geofield` module installed.
- Nodes of type `harbour` / `anchorage` with a geofield `field_geolocation`
  (`field_geofield` fallback is also checked).
- CMEMS fallback additionally needs a cron job (see below) that populates a
  JSON cache directory.

## Install

```sh
drush en openmeteo_weather -y
drush cr
```

Place the block **Harbor Weather (Open-Meteo)** (plugin id
`harbor_weather_block`) in the region of your choice. On nodes without usable
coordinates the widget shows the self-healing fallback message instead of
disappearing.

Configure the provider chain on `/admin/config/services/openmeteo-weather`:
cache TTL, Copernicus display label, cache directory (`private://cmems` by
default), freshness TTL and maximum accepted data age.

## Copernicus Marine fallback (batch)

The Drupal module never calls CMEMS live. An external job fetches forecasts
for every harbour coordinate and writes one JSON file per rounded coordinate
(`56.715_11.510.json`) into the configured directory.

### 1. Export coordinates

Inside the Drupal root:

```sh
drush openmeteo_weather:cmems-coordinates --nodes=harbour,anchorage > harbours.json
```

Returns a JSON array of `{id, title, lat, lon}` for all published harbour /
anchorage nodes (524 for Sailbuddy).

### 2. Fetch + build cache files

`scripts/cmems_fetch.py` runs on any machine that has the
`copernicusmarine` toolbox installed (a virtualenv is recommended):

```sh
python3 -m venv /opt/cmems-toolbox/.venv
/opt/cmems-toolbox/.venv/bin/pip install copernicusmarine
/opt/cmems-toolbox/.venv/bin/copernicusmarine login           # once

/opt/cmems-toolbox/.venv/bin/python scripts/cmems_fetch.py \
  --coordinates harbours.json \
  --outdir /opt/cmems-toolbox/cmems \
  --skip-fresh \
  --login-service allancph@gmail.com
```

Behaviour:

- Waves (`cmems_mod_glo_wav_anfc_0.083deg_PT3H-i`) are mandatory. When the
  nearest grid cell is land-masked (common for coastal harbours), the script
  scans offshore in 0.083° steps up to 0.33° until usable sea data is found.
- SST (`thetao`) and ocean currents (`uo`/`vo`, PT6H-i) are best-effort.
- `--skip-fresh` skips coordinates whose cache file is younger than the
  freshness TTL, so the 6-hourly cron job only refetches what it must.
- Files are written atomically; a `/tmp`+`os.replace` pattern avoids corrupt
  partial JSON.
- Daily aggregation reduces the 3-hourly wave series into a
  `{date, wave_max, wave_period_max, wave_dir}` list matching the widget.

### 3. Cron

Example crontab entry (host or dedicated box):

```cron
# Copernicus Marine cache refresh (every 6 hours)
17 */6 * * * /opt/cmems-toolbox/.venv/bin/python /opt/cmems-toolbox/scripts/cmems_fetch.py \
  --coordinates /opt/cmems-toolbox/harbours.json --outdir /opt/cmems-toolbox/cmems --skip-fresh \
  >> /opt/cmems-toolbox/cmems.log 2>&1
```

Then sync the resulting files into the Drupal site's private directory
(`web/private/cmems/` for a standard private filesystem) and ensure they are
readable by the web server user. Keep `harbours.json` current by re-exporting
it after content changes.

## Provider chain behaviour

| Open-Meteo | CMEMS cache | Result |
|---|---|---|
| OK | — | Live Open-Meteo data |
| down / error | fresh (< TTL) | CMEMS data, `stale: false` |
| down / error | old (< max age) | CMEMS data flagged as stale |
| down / error | expired / missing | "temporarily unavailable" message, max-age 300 |

## Attribution

Weather data is provided by [Open-Meteo](https://open-meteo.com/) (DWD/ECMWF
wave models). Fallback data is provided by the E.U. Copernicus Marine Service
(CMEMS / Mercator Ocean). The widget's footer shows which source served the
current forecast.

## License

GPL-2.0-or-later