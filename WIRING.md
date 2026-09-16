# Wiring this into a real wiki

This was written by reading Cargo 3.9.1's actual installed source
(`CargoMapsFormat.php`, `CargoLeafletFormat.php`, `CargoCompoundQuery.php`,
fetched from the upstream GitHub mirror and diffed against the live
`saintapedia.org` extension version) and duplicating the relevant method
with a fix — it has not been installed or exercised against a running
wiki yet. Treat it as a solid starting point to review and test, not
copy-paste-deploy.

## 1. Install it (dev first)

Canasta's `dev` wiki (`~/canasta-workspace/dev/`) mounts
`extensions/` from a host directory (per `docker inspect dev-web-1`) — put
this repo where that mount expects extensions to live, then:

1. Add `SaintapediaCargoPins` to the `extensions:` list in
   `~/canasta-workspace/dev/config/settings/global/settings.yaml`
   (alongside the existing `Cargo`, `Maps`, `SaintapediaDrilldown`, etc.
   entries).
2. Restart `dev-web-1` so Canasta regenerates `LocalSettings.php` and
   picks up the new extension (`docker restart dev-web-1`, same as the
   `SubpagesInMainNamespace.php` fix from the icon spike).
3. Confirm it loaded: `Special:Version` should list
   "SaintapediaCargoPins", and
   `action=query&meta=siteinfo&siprop=extensions` should include it.

## 2. Smoke-test before touching Footprints

Reuse the `FootprintsIconTest` table/template from the icon spike (or
recreate it on dev now that subpages work there) and swap its map query
to:

```wikitext
{{#cargo_query:
tables=FootprintsIconTest
|fields=LocationTitle,Coordinates,MapIcon
|format=pins
|iconfield=MapIcon
|height=400
|width=100%
}}
```

Confirm every row gets its own distinct icon — this is the thing that
was broken with `format=leaflet` + `#cargo_compound_query`. Check both:
- A page with rows spanning every `SiteType` in the icon switch.
- That omitting `iconfield` still renders a normal single-icon (or
  default-marker) map, to confirm the fallback path.

## 3. Roll into Footprints for real

Once confirmed on dev:

1. Add `MapIcon=String` to `Template:Footprints`'s `#cargo_declare` block
   and compute it in `#cargo_store` via `{{#switch:{{{SiteType|}}}| ... }}`
   — see the chat transcript / `tour/assets/map_icons/` for the icon
   file set already sourced (CC0, Maki + openstreetmap-carto) and the
   switch mapping already drafted.
2. Cargo **recreate data** for `Footprints` (schema changed) —
   `Special:RecreateCargoData/Footprints`, admin only.
3. Update `Template:Place maps/walked map` (and the other `format=map`
   call sites — `Footprints_Landing.wiki`, `Tour_Regional_Trails.wiki`,
   `Template:Place maps/map`) to `format=pins` + `iconfield=MapIcon`.
4. Re-save/purge existing `/Footprints` pages so `MapIcon` backfills.
5. Only after dev looks right: install the extension on prod the same
   way, then repeat steps 2–4 there.

## Things to double-check, not assumed

- **Cargo version drift.** This duplicates `CargoMapsFormat::display()`
  rather than wrapping it (PHP's `self::` inside that method is
  early-bound to `CargoMapsFormat`, so a subclass can't just override the
  icon-lookup piece in isolation — see the class doc comment). If Cargo
  is upgraded, diff `includes/formats/CargoMapsFormat.php` in the new
  version against what's duplicated here and re-sync.
- **Field-name normalization.** The icon lookup tries `$iconFieldName` as
  given and with spaces replaced by underscores, matching the pattern
  Cargo uses elsewhere in the same file for coordinate field keys. Field
  names with punctuation or other special characters aren't handled —
  keep `MapIcon` simple.
- **`Maps` extension dependency** is declared in `extension.json` because
  `CargoLeafletFormat::getScripts()`/`getStyles()` are being reused for
  the Leaflet JS/CSS asset URLs — confirm that's still true for whatever
  Cargo version ends up installed; if Cargo ever stops requiring `Maps`
  for `format=leaflet`, this dependency can be dropped too.
- **No automated tests.** Given the bug this works around was only
  confirmed by testing against the live site, the most valuable test
  here would be an integration test against a real Cargo table — not
  attempted yet (`tests/` directory intentionally omitted).
