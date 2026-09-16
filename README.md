# SaintapediaCargoPins

A small MediaWiki extension that adds one new [Cargo](https://www.mediawiki.org/wiki/Extension:Cargo)
map display format, `format=pins`, which resolves each marker's icon from
a **field on that same row** instead of Cargo core's position-indexed
`icon=` display parameter.

## Why this exists

Cargo's built-in map formats (`format=map`, `format=leaflet`,
`format=googlemaps`, `format=openlayers`) only support per-row icons
through `#cargo_compound_query`, by giving each sub-query block its own
`icon=` parameter. That value is meant to line up with each row's
position in the merged result set — but tested against the live
Saintapedia install (Cargo 3.9.1), that alignment doesn't hold: swapping
the order of two compound-query blocks did *not* change which row got the
custom icon. The same row kept winning regardless of which block (and
which `icon=` value) supplied it. Every other row silently falls back to
the default marker, with no error. See the class doc comment in
`includes/Pins/CargoPinsFormat.php` for the full trace.

This was found while trying to give Saintapedia's `Footprints` tour-stop
pins a different icon per `SiteType` (Church, School, Shrine, Home,
Cemetery, Outdoor, etc.) on a single combined map — see the `tour/`
directory's `docs/PLACE_MAPS.md` and `Template:Footprints`.

## Usage

```wikitext
{{#cargo_query:
tables=Footprints
|fields=LocationTitle,Coordinates,MapIcon
|where=Saint="{{BASEPAGENAME}}" AND Walked!="No"
|format=pins
|iconfield=MapIcon
|height=350
|width=100%
}}
```

`MapIcon` is any `String` field on the table holding a `File:` page name
(e.g. `Footprints-icon-church.svg`) — typically computed once at store
time from a controlled-vocabulary field via a `{{#switch:}}` in the
storing template, the way `Template:Footprints` already computes other
derived fields.

A single plain `#cargo_query` is all that's needed — no compound query,
no per-block bookkeeping. The icon is read straight out of the row being
rendered, so it's correct by construction.

If `iconfield` is omitted, `format=pins` falls back to Cargo core's own
`icon=` handling (one icon for the whole map, or the buggy compound-query
form), so it's a safe drop-in replacement for `format=leaflet` even for
queries that don't need per-row icons. Every other parameter (`height`,
`width`, `zoom`, `center`, `cluster`, `image`) works exactly as it does
for `format=leaflet`.

## Requirements

- MediaWiki >= 1.39
- [Cargo](https://www.mediawiki.org/wiki/Extension:Cargo) >= 3.0
- [Maps](https://www.mediawiki.org/wiki/Extension:Maps) (for the Leaflet
  JS/CSS assets `format=pins` reuses via `CargoLeafletFormat`)

## Status

Written and reviewed against Cargo 3.9.1's actual source, but **not yet
installed or tested against a live wiki**. See `WIRING.md` before
deploying.
