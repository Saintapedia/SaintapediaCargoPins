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

### Generic icons for any table: `iconmap`

For a table *without* its own stored icon field, add `iconmap=<bucket>`
alongside `iconfield=<VocabField>`: the field's raw value (e.g. a
`SiteType` of `Church`) is resolved through the named bucket in
`MediaWiki:CargoPins-config` (JSON) instead of being read as a literal
filename. Adding a new table's vocabulary means editing that one wiki
page — no `#cargo_declare`/`#cargo_store` change, no `Recreate data`,
no backfill. See `config/CargoPins-config.sample.json` for the config
shape, and `config/CargoPins-config.saintapedia.json` for what's
actually deployed.

A `#cargo_compound_query` spanning multiple tables can give each
table's rows their own icon the same way: alias each sub-block's own
vocab field (or a `CONCAT('SomeTable')=IconKey`-style literal tag) to
one shared column, then reference it once via a shared top-level
`iconfield=`/`iconmap=`. Two things to get right: a literal per-block
tag must be wrapped in a SQL function (`CONCAT('X')=Alias` — a bare
`'X'=Alias` is rejected by Cargo's field parser), and the
`Coordinates`-typed field itself must **not** be aliased (aliasing it
silently drops every row from that sub-block — a pre-existing Cargo-core
limitation, not specific to this extension; leave each table's native
`Coordinates`-type field name as-is instead).

## Requirements

- MediaWiki >= 1.42
- [Cargo](https://www.mediawiki.org/wiki/Extension:Cargo) >= 3.9.1

`format=pins` extends `CargoLeafletFormat`, which loads its own Leaflet
JS/CSS directly from unpkg (`CargoLeafletFormat::getScripts()`/
`getStyles()`) — Cargo 3.9.x does not consult Extension:Maps for this,
so this extension has no dependency on it.

## Status

`iconfield` (a literal `File:` page name per row) and `iconmap` (a
render-time vocabulary lookup against `MediaWiki:CargoPins-config`, so
*any* Cargo table with a `Coordinates` field can get per-row icons with
no schema change) have both been installed and verified end-to-end
against a live wiki (Canasta `dev`, `mwdev`), including through a
`#cargo_compound_query` spanning two different Cargo tables. See
`docs/superpowers/plans/2026-09-16-generic-map-icons-plan.md` for the
verification steps actually run. `WIRING.md`'s stored-`MapIcon`-field
walkthrough (`Footprints`'s original approach, predating `iconmap`) is
one still-supported but no longer necessary path — prefer `iconmap` for
any table that doesn't already have a computed icon field.
