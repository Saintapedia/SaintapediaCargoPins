# Generic per-row map icons for any Cargo table — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the `iconmap=` mechanism from the design doc so any Cargo table with a `Coordinates` field can get per-row `format=pins` icons by editing one wiki config page — no PHP change, no Cargo schema change, no `Recreate data`, no backfill.

**Architecture:** New `CargoPinsConfigService` reads `MediaWiki:CargoPins-config` (JSON), modeled directly on the already-proven `NearMeConfigService` (`~/nearme/includes/NearMeConfigService.php`) — same cache-by-revision, graceful-degradation, config-var-with-default conventions. `CargoPinsFormat` gains one new display parameter (`iconmap`) that, when present, routes the existing per-row `iconfield` value through that service instead of using it as a literal filename. `Footprints`'s shipped `MapIcon` field is untouched.

**Tech Stack:** MediaWiki (Canasta `dev`, localhost:8080, wiki id `mwdev`), Cargo, PHP (extension code), `MediaWiki:CargoPins-config` JSON page.

**Spec:** `docs/superpowers/specs/2026-09-16-generic-map-icons-design.md`

## Global Constraints

- No PHPUnit harness exists for this extension (same as the `Footprints` rollout) — every task verifies against the real `dev` wiki (localhost:8080, wiki id `mwdev`), the same way `format=pins` itself was verified: `action=parse` + inspecting the `data-mw-cargo-map-data` JSON, not visual-only checks.
- `dev`'s PHP has `opcache.validate_timestamps=On`, `revalidate_freq=2` — a plain source edit (after syncing into the `dev` checkout) takes effect within ~2 seconds, **no `canasta restart` needed**, except after an `extension.json` change (Task 2 adds a new config var — restart there to be safe, since `ExtensionRegistry` manifest processing caches more aggressively than raw opcache).
- Extension source lives at `/home/tom/extensions/pins/SaintapediaCargoPins` (origin of truth, pushed to `github.com/Saintapedia/SaintapediaCargoPins`) and is deployed to `dev` as a **separate clone** at `~/canasta-workspace/dev/extensions/SaintapediaCargoPins` — every task's sync step is: commit + push in the source repo, then `git pull` in the `dev` checkout.
- Use `docker exec -i dev-web-1 ...` (the `-i` flag is required for `maintenance/run.php eval.php` to read stdin — verified in this session; a plain `docker exec` without `-i` silently produces no output).
- Use Python's `urllib` (not `curl`) for read-only diagnostic HTTP checks in this environment — `curl` intermittently gets intercepted by a local tooling hook in this session; `python3 -c "import urllib.request; ..."` does not.
- `Footprints` keeps working unchanged: it never passes `iconmap=`, so every code path it exercises is untouched by this plan.
- Real Cargo tables already on `dev` to verify against: `Footprints` (has `SiteType`, `Coordinates`, `LocationTitle`) and `Parishes` (has `ParishLocation` [Coordinates], `ShortName`, `Type`, 1,180 rows — confirmed via `Special:CargoTables/Parishes`).

---

### Task 1: Seed and deploy `MediaWiki:CargoPins-config`

**Files:**
- Create: `config/CargoPins-config.sample.json`
- Create: `config/CargoPins-config.saintapedia.json`

**Interfaces:**
- Produces: a real, live `MediaWiki:CargoPins-config` page on `dev` containing a `site-type` bucket (the same vocabulary `Template:Footprints`'s `#switch` already encodes) and a `table-default` bucket (`Footprints`, `Parishes`, `default`). Tasks 2-4 verify against this real content.

- [ ] **Step 1: Create the generic sample config**

```json
{
  "vocabularies": {
    "example-type": {
      "TypeA": "Example-icon-a.svg",
      "TypeB": "Example-icon-b.svg",
      "default": "Example-icon-pin.svg"
    },
    "table-default": {
      "SomeTable": "Example-icon-a.svg",
      "default": "Example-icon-pin.svg"
    }
  }
}
```

Write this to `config/CargoPins-config.sample.json`.

- [ ] **Step 2: Create the real saintapedia config**

Write to `config/CargoPins-config.saintapedia.json`:

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

- [ ] **Step 3: Commit and push**

```bash
cd /home/tom/extensions/pins/SaintapediaCargoPins
git add config/CargoPins-config.sample.json config/CargoPins-config.saintapedia.json
git commit -m "Add CargoPins-config sample and saintapedia seed content"
git push
```

- [ ] **Step 4: Deploy the saintapedia config to the dev wiki**

```bash
docker exec -i dev-web-1 bash -lc \
  "cd /var/www/mediawiki/w && php maintenance/run.php edit.php --wiki=mwdev --user=Admin --summary='Seed CargoPins-config' 'MediaWiki:CargoPins-config'" \
  < /home/tom/extensions/pins/SaintapediaCargoPins/config/CargoPins-config.saintapedia.json
```

- [ ] **Step 5: Verify it round-trips as valid JSON with the expected buckets**

```bash
python3 -c "
import urllib.request, json
url = 'http://localhost:8080/w/api.php?action=query&titles=MediaWiki:CargoPins-config&prop=revisions&rvprop=content&format=json'
with urllib.request.urlopen(url) as r:
    data = json.load(r)
pages = data['query']['pages']
text = next(iter(pages.values()))['revisions'][0]['*']
parsed = json.loads(text)
print('buckets:', sorted(parsed['vocabularies'].keys()))
print('site-type Church ->', parsed['vocabularies']['site-type']['Church'])
print('table-default Parishes ->', parsed['vocabularies']['table-default']['Parishes'])
"
```

Expected: `buckets: ['site-type', 'table-default']`, `Church -> Footprints-icon-church.svg`, `Parishes -> Footprints-icon-parish.svg`.

---

### Task 2: `CargoPinsConfigService`

**Files:**
- Create: `includes/Pins/CargoPinsConfigService.php`
- Modify: `extension.json`

**Interfaces:**
- Consumes: `MediaWiki:CargoPins-config` (Task 1).
- Produces: `CargoPinsConfigService::resolveIcon( string $vocabulary, string $key ): ?string` — Task 3's `CargoPinsFormat` change calls this exact method.

- [ ] **Step 1: Write the service class**

Create `includes/Pins/CargoPinsConfigService.php`:

```php
<?php

namespace MediaWiki\Extension\SaintapediaCargoPins\Pins;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * Loads the shared vocabulary -> icon filename mapping from
 * MediaWiki:CargoPins-config (JSON), the same config-page convention
 * NearMeConfigService uses for MediaWiki:NearMe-config.
 */
class CargoPinsConfigService {

	private const CACHE_VERSION = 1;
	private const CACHE_TTL = 300;

	/** @var array<string,array<string,string>>|null */
	private ?array $resolvedVocabularies = null;

	/**
	 * @param string $vocabulary Bucket name (e.g. "site-type").
	 * @param string $key Row's vocab value (e.g. "Church"), or a literal
	 *   tag such as a table name for the "table-default" bucket.
	 * @return string|null File: page name, or null if unresolvable.
	 */
	public function resolveIcon( string $vocabulary, string $key ): ?string {
		$vocabularies = $this->getVocabularies();
		$bucket = $vocabularies[$vocabulary] ?? null;
		if ( $bucket === null ) {
			return null;
		}
		return $bucket[$key] ?? $bucket['default'] ?? null;
	}

	/**
	 * @return array<string,array<string,string>>
	 */
	private function getVocabularies(): array {
		if ( $this->resolvedVocabularies !== null ) {
			return $this->resolvedVocabularies;
		}
		$this->resolvedVocabularies = $this->loadFromWikiPage() ?? [];
		return $this->resolvedVocabularies;
	}

	/**
	 * @return array<string,array<string,string>>|null
	 */
	private function loadFromWikiPage(): ?array {
		$services = MediaWikiServices::getInstance();
		$pageName = (string)$services->getMainConfig()->get( 'CargoPinsConfigPage' );
		if ( $pageName === '' ) {
			return null;
		}

		$title = Title::makeTitleSafe( NS_MEDIAWIKI, $pageName );
		if ( $title === null || !$title->exists() ) {
			return null;
		}

		$cache = $services->getMainWANObjectCache();
		$key = $cache->makeKey(
			'cargopins-config',
			self::CACHE_VERSION,
			$title->getLatestRevID()
		);

		$vocabularies = $cache->getWithSetCallback(
			$key,
			self::CACHE_TTL,
			function () use ( $services, $title ) {
				$wikiPage = $services->getWikiPageFactory()->newFromTitle( $title );
				$content = $wikiPage->getContent();
				if ( $content === null ) {
					return null;
				}
				return $this->parseAndNormalize( $content->getText() );
			}
		);

		return is_array( $vocabularies ) ? $vocabularies : null;
	}

	/**
	 * @param string $text
	 * @return array<string,array<string,string>>|null
	 * @internal For unit tests
	 */
	public function parseAndNormalize( string $text ): ?array {
		$text = trim( $text );
		if ( $text === '' ) {
			return null;
		}

		// Allow wikitext wrappers (<pre>, nowiki) -- extract the JSON object.
		if ( preg_match( '/\{.*\}/s', $text, $matches ) ) {
			$text = $matches[0];
		}

		$decoded = json_decode( $text, true );
		if ( !is_array( $decoded ) ) {
			wfDebugLog( 'SaintapediaCargoPins', 'Failed to parse MediaWiki:CargoPins-config as JSON.' );
			return null;
		}

		$rawVocabularies = $decoded['vocabularies'] ?? null;
		if ( !is_array( $rawVocabularies ) ) {
			wfDebugLog( 'SaintapediaCargoPins', 'MediaWiki:CargoPins-config has no "vocabularies" object.' );
			return null;
		}

		$vocabularies = [];
		foreach ( $rawVocabularies as $vocabName => $bucket ) {
			if ( !is_string( $vocabName ) || $vocabName === '' || !is_array( $bucket ) ) {
				wfDebugLog( 'SaintapediaCargoPins', "Skipping invalid vocabulary entry: $vocabName" );
				continue;
			}
			$normalizedBucket = [];
			foreach ( $bucket as $vocabKey => $fileName ) {
				if ( !is_string( $vocabKey ) || $vocabKey === '' || !is_string( $fileName ) || $fileName === '' ) {
					wfDebugLog( 'SaintapediaCargoPins', "Skipping invalid entry in vocabulary \"$vocabName\": $vocabKey" );
					continue;
				}
				$normalizedBucket[$vocabKey] = $fileName;
			}
			if ( $normalizedBucket !== [] ) {
				$vocabularies[$vocabName] = $normalizedBucket;
			}
		}

		return $vocabularies;
	}
}
```

- [ ] **Step 2: Register the `CargoPinsConfigPage` config variable**

In `extension.json`, add a `"config"` block (there isn't one yet) right after `"MessagesDirs"`:

```json
	"MessagesDirs": {
		"SaintapediaCargoPins": [
			"i18n"
		]
	},
	"config": {
		"CargoPinsConfigPage": {
			"value": "CargoPins-config",
			"description": "MediaWiki page (JSON) mapping vocabulary buckets to File: page names for format=pins' iconmap parameter. See config/CargoPins-config.sample.json."
		}
	},
	"manifest_version": 2
```

- [ ] **Step 3: Commit, push, sync to dev, restart**

```bash
cd /home/tom/extensions/pins/SaintapediaCargoPins
git add includes/Pins/CargoPinsConfigService.php extension.json
git commit -m "Add CargoPinsConfigService for MediaWiki:CargoPins-config"
git push
cd ~/canasta-workspace/dev/extensions/SaintapediaCargoPins
git pull
canasta restart -i dev
```

- [ ] **Step 4: Verify resolution against the real seeded config**

```bash
docker exec -i dev-web-1 bash -lc "cd /var/www/mediawiki/w && php maintenance/run.php eval.php --wiki=mwdev" <<'EOF'
$svc = new \MediaWiki\Extension\SaintapediaCargoPins\Pins\CargoPinsConfigService();
echo "Church -> ", var_export( $svc->resolveIcon( 'site-type', 'Church' ), true ), "\n";
echo "UnknownValue -> ", var_export( $svc->resolveIcon( 'site-type', 'UnknownValue' ), true ), "\n";
echo "NonexistentBucket -> ", var_export( $svc->resolveIcon( 'no-such-bucket', 'Church' ), true ), "\n";
EOF
```

Expected: `Church -> 'Footprints-icon-church.svg'`, `UnknownValue -> 'Footprints-icon-pin.svg'` (falls to the bucket's `default`), `NonexistentBucket -> NULL`.

---

### Task 3: Wire `iconmap` into `CargoPinsFormat`

**Files:**
- Modify: `includes/Pins/CargoPinsFormat.php`
- Modify: `~/saintapedia/tour/wiki/Tour_Query_Smoke_Test.wiki`

**Interfaces:**
- Consumes: `CargoPinsConfigService::resolveIcon()` (Task 2).
- Produces: the `iconmap=` display parameter, live and end-to-end verifiable.

- [ ] **Step 1: Add the `iconmap` parameter**

In `includes/Pins/CargoPinsFormat.php`, change:

```php
	public static function allowedParameters() {
		$params = parent::allowedParameters();
		$params['iconfield'] = [ 'type' => 'string' ];
		return $params;
	}
```

to:

```php
	public static function allowedParameters() {
		$params = parent::allowedParameters();
		$params['iconfield'] = [ 'type' => 'string' ];
		$params['iconmap'] = [ 'type' => 'string' ];
		return $params;
	}
```

- [ ] **Step 2: Add a lazily-initialized config service**

Right after the class declaration line (`class CargoPinsFormat extends CargoLeafletFormat {`), add:

```php

	private ?CargoPinsConfigService $configService = null;

	private function getConfigService(): CargoPinsConfigService {
		return $this->configService ??= new CargoPinsConfigService();
	}
```

- [ ] **Step 3: Route through the config service when `iconmap` is set**

In `getPinValues()`, the existing tail of the method is:

```php
		if ( $iconFileName !== null ) {
			$iconURL = self::getImageURL( $iconFileName );
			if ( $iconURL !== null ) {
				$valuesForMapPoint['icon'] = $iconURL;
			}
		}

		return $valuesForMapPoint;
	}
```

Change it to:

```php
		if ( $iconFileName !== null && ( $displayParams['iconmap'] ?? '' ) !== '' ) {
			$iconFileName = $this->getConfigService()->resolveIcon( $displayParams['iconmap'], $iconFileName );
		}

		if ( $iconFileName !== null ) {
			$iconURL = self::getImageURL( $iconFileName );
			if ( $iconURL !== null ) {
				$valuesForMapPoint['icon'] = $iconURL;
			}
		}

		return $valuesForMapPoint;
	}
```

This sits between the existing `$iconFileName` resolution (literal-filename-from-row, unchanged) and the existing `getImageURL()` call — the vocab-key case now flows into the same file-resolution/fallback path the literal case already used, so nothing downstream needs to change.

- [ ] **Step 4: Sync to dev**

```bash
cd /home/tom/extensions/pins/SaintapediaCargoPins
git add includes/Pins/CargoPinsFormat.php
git commit -m "Add iconmap: resolve iconfield values through CargoPinsConfigService"
git push
cd ~/canasta-workspace/dev/extensions/SaintapediaCargoPins
git pull
```

(No restart needed — pure PHP logic change, picked up within ~2s per opcache settings.)

- [ ] **Step 5: Add a verification block to the smoke-test page**

In `~/saintapedia/tour/wiki/Tour_Query_Smoke_Test.wiki`, append (before `[[Category:Saint Footprints]]`):

```wikitext
== Pins format: iconmap (site-type vocabulary, no stored MapIcon field) ==
{{#cargo_query:
tables=Footprints
|fields=LocationTitle,Coordinates,SiteType
|where=Country="United States" AND VisitAccess="Public"
|format=pins
|iconfield=SiteType
|iconmap=site-type
|height=400
|width=100%
|limit=60
}}
```

- [ ] **Step 6: Push and verify**

```bash
docker exec -i dev-web-1 bash -lc \
  "cd /var/www/mediawiki/w && php maintenance/run.php edit.php --wiki=mwdev --user=Admin --summary='Verify iconmap against SiteType directly'  'Tour Query Smoke Test'" \
  < /home/tom/saintapedia/tour/wiki/Tour_Query_Smoke_Test.wiki
```

```bash
python3 -c "
import urllib.request, json, re, html
url = 'http://localhost:8080/w/api.php?action=parse&page=Tour%20Query%20Smoke%20Test&prop=text&format=json'
with urllib.request.urlopen(url) as r:
    d = json.load(r)
text = d['parse']['text']['*']
matches = re.findall(r'data-mw-cargo-map-data=\"([^\"]*)\"', text)
print('map blocks found:', len(matches))
# The new iconmap block is appended last -> highest index
data = json.loads(html.unescape(matches[-1]))
icons = sorted(set(pt.get('icon') for pt in data))
print('distinct icons via iconmap:', icons)
"
```

Expected: the new block's icons are real `Footprints-icon-*.svg` URLs, matching the same distinct set the `iconfield=MapIcon` block (Task 4 of the mapicon plan) already produces for the same `SiteType` values — confirming the render-time path (no stored field, no schema change) resolves identically to the store-time path.

- [ ] **Step 7: Verify a nonexistent `iconmap` bucket degrades gracefully at the format level, not just in the service**

```bash
python3 -c "
import urllib.request, json
url = 'http://localhost:8080/w/api.php'
data = urllib.parse.urlencode({
    'action': 'parse',
    'text': '{{#cargo_query:tables=Footprints|fields=LocationTitle,Coordinates,SiteType|where=Saint=\"Blessed Stanley Rother\"|format=pins|iconfield=SiteType|iconmap=no-such-bucket|limit=3}}',
    'contentmodel': 'wikitext',
    'title': 'Blessed Stanley Rother',
    'format': 'json',
}).encode()
import urllib.parse
with urllib.request.urlopen(url, data=data) as r:
    d = json.load(r)
print('error' in d, d.get('error'))
text = d['parse']['text']['*'] if 'parse' in d else ''
print('has map div:', 'cargoMapData' in text)
"
```

Expected: `False None` (no API error) and `has map div: True` — the map still renders with default markers, no PHP exception, confirming Task 2's service-level `NonexistentBucket -> NULL` result flows all the way through `getPinValues()` safely.

- [ ] **Step 8: Regression-check `Footprints`'s existing `iconfield=MapIcon` and iconfield-omitted blocks are unchanged**

```bash
python3 -c "
import urllib.request, json, re, html
url = 'http://localhost:8080/w/api.php?action=parse&page=Tour%20Query%20Smoke%20Test&prop=text&format=json'
with urllib.request.urlopen(url) as r:
    d = json.load(r)
text = d['parse']['text']['*']
matches = re.findall(r'data-mw-cargo-map-data=\"([^\"]*)\"', text)
print('total map blocks now:', len(matches))
mapicon_block = json.loads(html.unescape(matches[0]))   # 'Pins format: per-row icon by SiteType' (MapIcon), first block
omitted_block = json.loads(html.unescape(matches[1]))   # 'iconfield omitted' fallback, second block
print('MapIcon block sample icon:', mapicon_block[0].get('icon'))
print('omitted block: all icons None:', all(pt.get('icon') is None for pt in omitted_block))
"
```

Expected: identical results to when these two blocks were first verified in the `Footprints`/`MapIcon` rollout — a real `Footprints-icon-*.svg` URL for the first block, `True` for the second. Confirms the `iconmap`-less code path is byte-for-byte unaffected by this task's change.

- [ ] **Step 9: Commit the tour-repo change**

```bash
cd /home/tom/saintapedia/tour
git add wiki/Tour_Query_Smoke_Test.wiki
git commit -m "Smoke-test iconmap: per-row icons via SiteType with no stored MapIcon field"
```

---

### Task 4: Multi-table combined map

**Files:**
- Modify: `~/saintapedia/tour/wiki/Tour_Query_Smoke_Test.wiki`

**Interfaces:**
- Consumes: `iconmap` (Task 3), `table-default` bucket (Task 1).
- Produces: proof that a `#cargo_compound_query` spanning two different Cargo tables (`Footprints`, `Parishes`) renders each table's rows with its own distinct icon, using the field-aliasing pattern from the design doc.

- [ ] **Step 1: Add the combined-map block**

Append to `~/saintapedia/tour/wiki/Tour_Query_Smoke_Test.wiki` (after the block added in Task 3, before `[[Category:Saint Footprints]]`):

```wikitext
== Pins format: multi-table combined map (generic table-default bucket) ==
{{#cargo_compound_query:
tables=Footprints;where=Saint="Blessed Stanley Rother" AND Walked!="No";fields=LocationTitle,Coordinates,'Footprints'=IconKey
|tables=Parishes;fields=ShortName=LocationTitle,ParishLocation=Coordinates,'Parishes'=IconKey
|format=pins
|iconfield=IconKey
|iconmap=table-default
|height=350
|width=100%
|limit=5
}}
```

- [ ] **Step 2: Push**

```bash
docker exec -i dev-web-1 bash -lc \
  "cd /var/www/mediawiki/w && php maintenance/run.php edit.php --wiki=mwdev --user=Admin --summary='Verify iconmap across a multi-table compound query'  'Tour Query Smoke Test'" \
  < /home/tom/saintapedia/tour/wiki/Tour_Query_Smoke_Test.wiki
```

- [ ] **Step 3: Verify each table's rows get their own distinct icon**

```bash
python3 -c "
import urllib.request, json, re, html
url = 'http://localhost:8080/w/api.php?action=parse&page=Tour%20Query%20Smoke%20Test&prop=text&format=json'
with urllib.request.urlopen(url) as r:
    d = json.load(r)
text = d['parse']['text']['*']
matches = re.findall(r'data-mw-cargo-map-data=\"([^\"]*)\"', text)
data = json.loads(html.unescape(matches[-1]))
for pt in data:
    print(pt['name'], '->', pt.get('icon'))
"
```

Expected: the `Blessed Stanley Rother` walked-stop rows resolve to `.../Footprints-icon-pin.svg`, the `Parishes` rows resolve to `.../Footprints-icon-parish.svg` — two visibly distinct icons in one combined map, with neither table needing a stored icon field of its own.

- [ ] **Step 4: Commit**

```bash
cd /home/tom/saintapedia/tour
git add wiki/Tour_Query_Smoke_Test.wiki
git commit -m "Smoke-test iconmap across a multi-table compound query"
```

---

### Task 5: Documentation

**Files:**
- Modify: `README.md`

**Interfaces:**
- Consumes: nothing new — documents Tasks 1-4's shipped behavior.

- [ ] **Step 1: Document `iconmap` in the README**

In `README.md`, right after the existing "If `iconfield` is omitted..." paragraph, add:

```markdown
For any table *without* a stored icon field, add `iconmap=<bucket>`
alongside `iconfield=<VocabField>`: the field's raw value (e.g. a
`SiteType` of `Church`) is resolved through the named bucket in
`MediaWiki:CargoPins-config` (JSON) instead of being read as a literal
filename. See `config/CargoPins-config.sample.json` for the config
shape, and `config/CargoPins-config.saintapedia.json` for what's
actually deployed. A `#cargo_compound_query` spanning multiple tables
can give each table's rows their own icon the same way: alias each
sub-block's own field (or a literal table-name tag) to one shared
column, then reference it once via a shared top-level `iconfield=` /
`iconmap=`.
```

- [ ] **Step 2: Commit and push**

```bash
cd /home/tom/extensions/pins/SaintapediaCargoPins
git add README.md
git commit -m "Document iconmap for generic per-row icons on any Cargo table"
git push
```
