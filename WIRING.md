# Wiring this into a real wiki

This was originally written by reading Cargo 3.9.1's actual installed
source (`CargoMapsFormat.php`, `CargoLeafletFormat.php`,
`CargoCompoundQuery.php`, fetched from the upstream GitHub mirror and
diffed against the live `saintapedia.org` extension version) and
duplicating the relevant method with a fix. It has since been installed
and verified end-to-end against Canasta `dev` (`mwdev`, Cargo 3.9.2, MW
1.43) — both `iconfield` (a stored filename field, `Footprints`'s
original approach) and `iconmap` (a render-time vocabulary lookup, no
stored field needed — see README.md) are confirmed working, including
through a `#cargo_compound_query` spanning two different Cargo tables.
See `docs/superpowers/plans/2026-09-16-generic-map-icons-plan.md` for
exactly what was verified and how. Steps below still apply for wiring
this extension into a *new* wiki instance.

## 1. Install it (dev first)

Canasta's `dev` wiki (`~/canasta-workspace/dev/`) mounts
`extensions/` from a host directory (per `docker inspect dev-web-1`) — put
this repo where that mount expects extensions to live, then:

1. Add `SaintapediaCargoPins` to the `extensions:` list in
   `~/canasta-workspace/dev/config/settings/global/settings.yaml`
   (alongside the existing `Cargo`, `Maps`, `SaintapediaDrilldown`, etc.
   entries).
2. Restart the wiki so Canasta regenerates `LocalSettings.php` and
   picks up the new extension: `canasta restart -i dev` (a plain source
   edit afterward does *not* need a restart — `dev`'s
   `opcache.validate_timestamps` picks that up within ~2 seconds; only a
   new extension or an `extension.json` change needs one).
3. Confirm it loaded: `Special:Version` should list
   "SaintapediaCargoPins", and
   `action=query&meta=siteinfo&siprop=extensions` should include it.

## 2. Smoke-test with `iconmap` (no schema change needed)

Prefer `iconmap` over the older stored-`MapIcon`-field path below for a
first smoke test — it needs no `#cargo_declare` change on any table.
Seed `MediaWiki:CargoPins-config` from
`config/CargoPins-config.saintapedia.json` (or your own vocabulary,
shaped like `config/CargoPins-config.sample.json`), then query any
existing table's own
vocab field directly:

```wikitext
{{#cargo_query:
tables=Footprints
|fields=LocationTitle,Coordinates,SiteType
|format=pins
|iconfield=SiteType
|iconmap=site-type
|height=400
|width=100%
}}
```

Confirm every row gets its own distinct icon — this is the thing that
was broken with `format=leaflet` + `#cargo_compound_query`. Check:
- A page with rows spanning every vocabulary value.
- That omitting `iconfield` still renders a normal single-icon (or
  default-marker) map, to confirm the fallback path.
- A vocab value with no entry in the bucket falls back to that bucket's
  `"default"`.
- A `#cargo_compound_query` spanning two different tables (see README.md's
  `iconmap` section for the `CONCAT('TableName')=IconKey` pattern) gives
  each table's rows their own icon.

## 3. The older path: a stored `MapIcon` field (optional)

`Footprints` predates `iconmap` and still uses this approach — keep it
if a table already has a computed icon field, but prefer `iconmap` for
anything new:

1. Add `MapIcon=String` to the table's `#cargo_declare` block and
   compute it in `#cargo_store` via `{{#switch:{{{SiteType|}}}| ... }}`
   — see `Template:Footprints` in the `tour/` repo for a worked example
   and `tour/assets/map_icons/` for the icon file set (CC0, Maki +
   openstreetmap-carto).
2. Cargo **recreate data** for that table (schema changed) —
   `Special:RecreateCargoData/<Table>`, admin only. This also backfills
   `MapIcon` on every existing row by re-running `#cargo_store` for each
   page that calls the template.
3. Update the relevant `format=map` call sites to `format=pins` +
   `iconfield=MapIcon`.
4. Only after `dev` looks right: install the extension on prod the same
   way, then repeat steps 2–3 there.

## Things to double-check, not assumed

- **Cargo version drift.** This duplicates `CargoMapsFormat::display()`
  rather than wrapping it (PHP's `self::` inside that method is
  early-bound to `CargoMapsFormat`, so a subclass can't just override the
  icon-lookup piece in isolation — see the class doc comment). If Cargo
  is upgraded, diff `includes/formats/CargoMapsFormat.php` in the new
  version against what's duplicated here and re-sync.
- **Field-name normalization.** The icon lookup tries `$iconFieldName` as
  given, with underscores turned into spaces, with spaces turned into
  underscores, and — for a `Table.Field` name with no explicit `=Alias`
  — the same swap applied to just the part after the first `.`
  (`CargoPinsFormat::iconFieldKeyCandidates()`), matching Cargo's own
  row-key aliasing (`CargoSQLQuery::setAliasedFieldNames()`) exactly,
  not just the coordinate-field convention this originally assumed.
  Field names with punctuation or other special characters still aren't
  handled.
- **`wfDebugLog('SaintapediaCargoPins', ...)` needs
  `$wgDebugLogGroups['SaintapediaCargoPins']` set to actually go
  anywhere.** Verified on `dev`: with no entry for this group (the
  stock Canasta default), these calls are silently discarded by
  MediaWiki's `LoggerFactory` — nothing reaches any file, including
  `$wgDebugLogFile`. Add a `SaintapediaCargoPins` entry to
  `$wgDebugLogGroups` (see `config/settings/global/*.php` for this
  wiki's existing per-extension config-snippet convention) to actually
  see the "iconfield/iconmap produced no icon" diagnostics.
- **No `Maps` dependency.** `CargoLeafletFormat::getScripts()`/
  `getStyles()` in Cargo 3.9.x load Leaflet directly from unpkg and do
  not consult Extension:Maps — confirmed by reading the installed
  Cargo 3.9.2 source on `dev`. `extension.json` does not declare it.
- **No automated tests.** Given the bug this works around was only
  confirmed by testing against the live site, the most valuable test
  here would be an integration test against a real Cargo table — not
  attempted yet (`tests/` directory intentionally omitted). Verification
  instead happens against a live wiki — see
  `docs/superpowers/plans/2026-09-16-generic-map-icons-plan.md` for the
  exact steps run against `dev`.
