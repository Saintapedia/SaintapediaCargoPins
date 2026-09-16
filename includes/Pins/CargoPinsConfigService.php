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
