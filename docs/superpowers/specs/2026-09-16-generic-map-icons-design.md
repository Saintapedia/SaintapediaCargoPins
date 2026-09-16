# Generic per-row map icons for any Cargo table — Design

## Goal

`format=pins` currently gets its per-row icon from a `MapIcon` field that
has to be computed and stored on the table itself (as done for
`Footprints`, via a `#switch` in `Template:Footprints`'s `#cargo_store`).
That means every new table needing per-row icons requires a schema
change (`#cargo_declare` + `#cargo_store` edit), a Cargo `Recreate data`
run, and a backfill pass — real friction, and exactly the class of gap
flagged by the `code-review` skill against the `Footprints` rollout
(marker assets living outside version control, backfill being a manual
step nothing enforces).

This design makes per-row icons work for **any Cargo table that has a
`Coordinates` field**, named targets being `Saints`, `Dioceses`,
`Parishes`, lay organizations, `PopulationPlace`, `ReligiousHouse`,
`Seminaries`, and `Schools` — with zero Cargo schema changes and zero
per-table PHP changes. It also covers maps that combine rows from
*multiple* tables in one `#cargo_compound_query`, where different
source tables should render as visually distinct pins.

## Background: what already exists

- `CargoPinsFormat` (`includes/Pins/CargoPinsFormat.php`) already reads
  `iconfield=<Column>` from each row and resolves it to a `File:` page
  URL via `self::getImageURL()`. This already works correctly across
  `#cargo_compound_query` sub-blocks — proven directly, on real data, in
  this session (`Blessed Stanley Rother`'s `{{Place maps/map}}`
  correctly gives all 12 rows their own icon, the exact scenario
  `format=map` gets wrong via Cargo core's row-position-indexed `icon=`
  array).
- `Footprints` ships today with a stored `MapIcon` field, computed from
  `SiteType` via a `#switch` in `Template:Footprints`. This stays
  unchanged — see "Backward compatibility" below.
- This wiki already has a config-page convention for exactly this shape
  of problem: the `NearMe` extension
  (`~/nearme/includes/NearMeConfigService.php`) reads
  `MediaWiki:NearMe-config` as JSON, cached by the page's
  `getLatestRevID()` (auto-invalidates on edit, `WANObjectCache` with a
  TTL safety net), with a `LocalSettings.php` fallback and graceful,
  logged degradation on bad entries — never a hard failure. This design
  reuses that exact pattern rather than inventing a new one.

## Requirements (from discussion)

1. Works for any Cargo table with `Coordinates` — no PHP or schema
   change needed to add a new table.
2. Supports **per-row** icon variation within one table, driven by that
   table's own existing controlled-vocabulary field (the `SiteType`
   case) — no new stored field required.
3. Supports **per-table** icon variation when a map combines rows from
   multiple Cargo tables via `#cargo_compound_query` (e.g. a map mixing
   `Saints`, `Parishes`, and `Schools` pins, each table rendering as a
   visually distinct marker).
4. `Footprints`'s already-shipped `MapIcon` field keeps working
   unchanged — this is additive, not a forced migration.
5. Never a hard failure: a map with missing/misconfigured icon data
   still renders, with default Leaflet markers, exactly like today's
   `iconfield`-omitted fallback.

## Non-goals

- No migration of `Footprints` off its stored `MapIcon` field (possible
  later cleanup, not required here — see "Optional follow-up").
- No wiki-form/UI for editing the config page. Direct JSON editing of
  `MediaWiki:CargoPins-config`, same as `NearMe-config` today.
- No auto-detection of "which Cargo table did this row come from" out
  of Cargo/MediaWiki internals — the wikitext author names the right
  config bucket explicitly via `iconmap=`. Simpler and more robust than
  reflecting on query internals, and matches how compound-query authors
  already do per-block bookkeeping (`fields=...=Alias`).

## Design

### 1. New display parameter: `iconmap`

`CargoPinsFormat::allowedParameters()` gains one new parameter,
alongside the existing `iconfield`:

```php
public static function allowedParameters() {
    $params = parent::allowedParameters();
    $params['iconfield'] = [ 'type' => 'string' ];
    $params['iconmap'] = [ 'type' => 'string' ];
    return $params;
}
```

Semantics, layered onto the existing `getPinValues()` resolution:

- `iconfield` alone (as today): the column's value **is** the `File:`
  page name, used as-is. Unchanged code path — this is what `Footprints`
  uses via `MapIcon` today, and keeps working byte-for-byte identically.
- `iconfield` + `iconmap=<VocabularyName>`: the column's value is a
  **vocabulary key** (e.g. `Church`, `Parish`, or a literal table name
  tag), resolved through the named bucket in
  `MediaWiki:CargoPins-config` to a `File:` page name. Falls back to
  that bucket's `default` entry if the key isn't found, and to no icon
  at all (default Leaflet marker) if the bucket itself doesn't exist —
  never an `MWException`.
- `iconmap` without `iconfield`: not a valid combination; treated the
  same as neither being set (no custom icon, matches today's fallback
  path for compound-query `icon=` / no icon at all).

### 2. `MediaWiki:CargoPins-config`

New page name is itself configurable (`$wgCargoPinsConfigPage`,
default `"CargoPins-config"`), matching `$wgNearMeConfigPage`. Shape:

```json
{
  "vocabularies": {
    "site-type": {
      "Parish": "Footprints-icon-parish.svg",
      "Shrine": "Footprints-icon-shrine.svg",
      "Church": "Footprints-icon-church.svg",
      "Home": "Footprints-icon-home.svg",
      "Workplace": "Footprints-icon-workplace.svg",
      "School": "Footprints-icon-school.svg",
      "Cemetery": "Footprints-icon-cemetery.svg",
      "Convent": "Footprints-icon-convent.svg",
      "Outdoor": "Footprints-icon-outdoor.svg",
      "default": "Footprints-icon-pin.svg"
    },
    "table-default": {
      "Footprints": "Footprints-icon-pin.svg",
      "Parishes": "Footprints-icon-parish.svg",
      "default": "Footprints-icon-pin.svg"
    }
  }
}
```

`"default"` is a reserved key within each bucket: the icon used when the
row's vocab value doesn't otherwise match. A new table's vocabulary
(e.g. a future `Schools` "Level" field) is added by editing this one
page — no code deploy, no `Recreate data`.

### 3. `CargoPinsConfigService` (new file)

`includes/Pins/CargoPinsConfigService.php`, modeled directly on
`NearMeConfigService`:

```php
class CargoPinsConfigService {
    private const CACHE_VERSION = 1;
    private const CACHE_TTL = 300;

    /** @var array<string,array<string,string>>|null */
    private ?array $resolvedVocabularies = null;

    public function resolveIcon( string $vocabulary, string $key ): ?string {
        $vocabularies = $this->getVocabularies();
        $bucket = $vocabularies[$vocabulary] ?? null;
        if ( $bucket === null ) {
            return null;
        }
        return $bucket[$key] ?? $bucket['default'] ?? null;
    }

    /** @return array<string,array<string,string>> */
    private function getVocabularies(): array {
        if ( $this->resolvedVocabularies !== null ) {
            return $this->resolvedVocabularies;
        }
        $this->resolvedVocabularies = $this->loadFromWikiPage() ?? [];
        return $this->resolvedVocabularies;
    }

    private function loadFromWikiPage(): ?array {
        // Title::makeTitleSafe(NS_MEDIAWIKI, $wgCargoPinsConfigPage);
        // WANObjectCache keyed by (CACHE_VERSION, title->getLatestRevID()), TTL fallback.
        // JSON-decode page content (tolerant of <pre>/nowiki wrapping, same
        // regex-extraction NearMeConfigService uses); wfDebugLog + return null
        // on parse failure or missing page.
        // Normalize: drop non-string/non-array vocab entries, drop non-string
        // filename values, wfDebugLog each drop (mirrors
        // NearMeConfigService::normalizeSourceList's per-entry validation).
    }
}
```

No `ServiceWiring.php` entry — matches `NearMeConfigService`'s plain
`new NearMeConfigService()` instantiation pattern. `CargoPinsFormat`
holds it as a lazily-initialized private property (no constructor
change, since `CargoLeafletFormat`'s constructor isn't ours to alter):

```php
private ?CargoPinsConfigService $configService = null;

private function getConfigService(): CargoPinsConfigService {
    return $this->configService ??= new CargoPinsConfigService();
}
```

### 4. `getPinValues()` change

Current resolution (unchanged first branch):

```php
if ( $iconFieldName !== null ) {
    $iconFileName = $valuesRow[$iconFieldName]
        ?? $valuesRow[str_replace( ' ', '_', $iconFieldName )]
        ?? null;
    if ( $iconFileName === '' ) {
        $iconFileName = null;
    }
}
```

New: after resolving `$iconFileName` as today, if `iconmap` was given
and `$iconFileName` is non-null, resolve it through the config service
*before* the existing `getImageURL()` call — the vocab-key case now
flows into the exact same file-resolution and graceful-fallback path
the literal-filename case already uses:

```php
$iconMapName = ( $displayParams['iconmap'] ?? '' ) !== '' ? $displayParams['iconmap'] : null;
// ... existing $iconFileName resolution ...
if ( $iconFileName !== null && $iconMapName !== null ) {
    $iconFileName = $this->getConfigService()->resolveIcon( $iconMapName, $iconFileName );
}
// existing: if ( $iconFileName !== null ) { $iconURL = self::getImageURL( $iconFileName ); ... }
```

### 5. Combined multi-table maps

Each `#cargo_compound_query` sub-block aliases its own field to one
shared column name; a single top-level `iconfield`/`iconmap` (display
params are already shared across all sub-blocks, per how
`Template:Place maps/map` already uses them today) resolves every row
regardless of source table:

```wikitext
{{#cargo_compound_query:
tables=Saints;where=...;fields=Name,Coordinates,'Saints'=IconKey
|tables=Parishes;where=...;fields=ParishName=Name,ParishLocation=Coordinates,'Parishes'=IconKey
|format=pins
|iconfield=IconKey
|iconmap=table-default
}}
```

Per-row vocab variation *within* one of those tables (e.g. `Parishes`
has its own `ParishType`) composes the same way — alias
`ParishType=IconKey` instead of the literal table-name tag, and point
`iconmap` at that vocabulary's bucket instead of `table-default`.

### Backward compatibility

`Footprints` issues no `iconmap=` parameter anywhere in its existing
templates, so every code path it exercises is byte-for-byte unchanged.
No re-verification of the already-shipped `Footprints` behavior should
be needed beyond a smoke check that nothing regressed.

### Optional follow-up (not in scope here)

`Footprints` could eventually drop its stored `MapIcon` field and
`#switch`, switching to `iconfield=SiteType|iconmap=site-type` like any
other table — this would remove the one remaining case where
`Recreate data` + backfill is needed at all in this extension's usage.
Worth doing once the generic mechanism is proven, not required for it.

## Verification plan

No PHPUnit harness exists for this extension (same situation as the
`Footprints` rollout — see `README.md`/`WIRING.md`). Verification
follows the same approach used to confirm `format=pins` on `dev` this
session: `action=parse` on a page using the new parameters, extracting
`data-mw-cargo-map-data` JSON and checking resolved `icon` URLs
directly, rather than relying on visual inspection alone. At minimum:

- A single-table query with `iconfield=<vocab>|iconmap=<bucket>` on a
  brand-new demo/test table resolves distinct icons per row.
- The same query with a vocab value absent from the bucket falls back
  to that bucket's `default`.
- The same query pointed at a **nonexistent** `iconmap` bucket renders
  with no icon (default Leaflet marker), no PHP warning/exception.
- A `#cargo_compound_query` combining two tables with the
  `IconKey`-aliasing pattern above renders each table's rows with its
  own distinct icon.
- Re-run the existing `Footprints` smoke-test page (`Tour Query Smoke
  Test`) and confirm its two `format=pins` blocks are unchanged.
