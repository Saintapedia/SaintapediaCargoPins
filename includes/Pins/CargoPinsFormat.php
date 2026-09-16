<?php

namespace MediaWiki\Extension\SaintapediaCargoPins\Pins;

use CargoLeafletFormat;
use CargoUtils;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MWException;

/**
 * A Cargo map display format ("pins") that resolves each marker's icon
 * directly from a field value on that same row, instead of Cargo core's
 * position-indexed `icon=` display parameter.
 *
 * ## Why this exists
 *
 * Cargo core (tested against the live Saintapedia install, Cargo 3.9.1)
 * only supports per-row icons through #cargo_compound_query, by giving
 * each sub-query block its own `icon=` parameter. Internally, that value
 * gets threaded through as $displayParams['icon'][$rowNum], where $rowNum
 * is meant to line up with each row's position in the merged result set
 * (see CargoCompoundQuery::getOrDisplayQueryResultsFromStrings()).
 *
 * In practice that alignment does not hold. Inside
 * CargoMapsFormat::display(), $rowNum is actually the array key Cargo
 * assigns to each row internally (`foreach ($formattedValuesTable as $i =>
 * ...)`), not a simple 0,1,2,... merge-order counter. Confirmed by testing
 * on saintapedia.org: swapping the order of two compound-query blocks did
 * NOT change which row got its custom icon -- the same row kept winning
 * regardless of which block (and which `icon=` value) supplied it,
 * pointing to a row-identity/position mismatch rather than anything
 * dependent on query structure. Every other row silently falls back to
 * Leaflet's default marker, with no error.
 *
 * ## What this format does instead
 *
 * It sidesteps the indexing question entirely: instead of matching
 * markers to icons by position, it reads the icon filename straight out
 * of the row's own data, via a field you name with `iconfield=`. That
 * works with a single plain #cargo_query -- no compound query, no
 * per-block bookkeeping -- and is correct by construction, since the
 * value always comes from the exact row being rendered.
 *
 * ## Usage
 *
 *   {{#cargo_query:
 *   tables=Footprints
 *   |fields=LocationTitle,Coordinates,MapIcon
 *   |where=Saint="{{BASEPAGENAME}}" AND Walked!="No"
 *   |format=pins
 *   |iconfield=MapIcon
 *   |height=350
 *   |width=100%
 *   }}
 *
 * `MapIcon` here is any String field on the table holding a File: page
 * name (e.g. "Footprints-icon-church.svg"), typically computed once at
 * store time from a controlled-vocabulary field like SiteType via a
 * {{#switch:}} in the storing template -- see Template:Footprints in the
 * Saintapedia tour/ repo for the existing SiteType vocabulary this was
 * built for.
 *
 * When `iconfield` is omitted, this format falls back to Cargo core's own
 * `icon=` handling (single icon for every marker, or the buggy
 * compound-query array form), so `format=pins` is a safe drop-in
 * replacement for `format=leaflet` even for existing single-icon queries.
 *
 * All other display parameters (height, width, zoom, center, cluster,
 * image) behave exactly as they do for `format=leaflet`, since this class
 * only overrides icon resolution -- everything else is inherited from
 * CargoLeafletFormat / CargoMapsFormat unchanged.
 */
class CargoPinsFormat extends CargoLeafletFormat {

	private ?CargoPinsConfigService $configService = null;

	private function getConfigService(): CargoPinsConfigService {
		return $this->configService ??= new CargoPinsConfigService();
	}

	public static function allowedParameters() {
		$params = parent::allowedParameters();
		$params['iconfield'] = [ 'type' => 'string' ];
		$params['iconmap'] = [ 'type' => 'string' ];
		return $params;
	}

	/**
	 * Copied from CargoMapsFormat::display() and modified only in how
	 * each marker's icon is resolved (see getPinValues() below). Keep
	 * this in sync with Cargo core's own version when Cargo is upgraded --
	 * ideally by diffing against
	 * includes/formats/CargoMapsFormat.php in the installed Cargo
	 * version, since this duplicates rather than wraps that method (PHP's
	 * `self::` inside CargoMapsFormat::display() is early-bound to
	 * CargoMapsFormat itself, so a plain subclass override of just the
	 * icon-lookup piece isn't possible without copying the caller too).
	 *
	 * @param array $valuesTable
	 * @param array $formattedValuesTable
	 * @param array $fieldDescriptions
	 * @param array $displayParams
	 * @return string HTML
	 * @throws MWException
	 */
	public function display( $valuesTable, $formattedValuesTable, $fieldDescriptions, $displayParams ) {
		$coordinatesFields = [];
		$latField = null;
		$lonField = null;
		$urlField = null;
		foreach ( $fieldDescriptions as $field => $description ) {
			if ( $description->mType == 'Coordinates' ) {
				$coordinatesFields[] = $field;
			}
			if ( $field == 'lat' ) {
				$latField = $field;
			} elseif ( $field == 'lon' ) {
				$lonField = $field;
			} elseif ( $field == 'URL' ) {
				$urlField = $field;
			}
		}

		if ( count( $coordinatesFields ) == 0 && ( $latField == null || $lonField == null ) ) {
			throw new MWException( "Error: no fields of type \"Coordinates\" were specified in this "
			. "query; cannot display in a map." );
		}

		if ( count( $formattedValuesTable ) == 0 ) {
			throw new MWException( "No results found for this query; not displaying a map." );
		}

		$scripts = $this->getScripts();
		$scriptsHTML = '';
		foreach ( $scripts as $script ) {
			$scriptsHTML .= Html::linkedScript( $script );
		}
		$styles = $this->getStyles();
		$stylesHTML = '';
		foreach ( $styles as $style ) {
			$stylesHTML .= Html::linkedStyle( $style );
		}
		$this->mOutput->addHeadItem( $scriptsHTML, $scriptsHTML );
		$this->mOutput->addHeadItem( $stylesHTML, $stylesHTML );
		$this->mOutput->addModules( [ 'ext.cargo.maps' ] );

		$iconFieldName = ( $displayParams['iconfield'] ?? '' ) !== '' ? $displayParams['iconfield'] : null;

		// Construct the table of data we will display.
		$valuesForMap = [];
		foreach ( $formattedValuesTable as $i => $valuesRow ) {
			$displayedValuesForRow = [];
			foreach ( $valuesRow as $fieldName => $fieldValue ) {
				if ( !array_key_exists( $fieldName, $fieldDescriptions ) ) {
					continue;
				}
				$fieldType = $fieldDescriptions[$fieldName]->mType;
				// Don't display any values that are going to be included already,
				// or that are only there to supply the marker icon.
				if ( $fieldType == 'Coordinates' || $fieldType == 'Coordinates part'
					|| $fieldName == $latField || $fieldName == $lonField || $fieldName == $urlField
					|| ( $iconFieldName !== null && $fieldName == $iconFieldName ) ) {
					continue;
				}
				if ( $fieldValue == '' ) {
					continue;
				}
				$displayedValuesForRow[$fieldName] = $fieldValue;
			}

			// There could potentially be more than one
			// coordinate for this "row".
			// @TODO - handle lists of coordinates as well.
			foreach ( $coordinatesFields as $coordinatesField ) {
				$coordinatesFieldKey = str_replace( ' ', '_', $coordinatesField );
				$latValue = $valuesRow[$coordinatesFieldKey . '  lat'] ?? null;
				$lonValue = $valuesRow[$coordinatesFieldKey . '  lon'] ?? null;
				if ( $latValue != '' && $lonValue != '' ) {
					$nameValue = array_shift( $valuesTable[$i] );
					// @TODO - enforce the existence of a field
					// besides the coordinates field(s).
					$firstValue = array_shift( $displayedValuesForRow );
					$valuesForMap[] = $this->getPinValues(
						$nameValue, $firstValue, $latValue, $lonValue,
						$displayedValuesForRow, $displayParams, $i, $valuesRow, $iconFieldName
					);
				}
			}

			if ( $latField !== null && $lonField !== null ) {
				$latValue = $valuesRow[$latField] ?? null;
				$lonValue = $valuesRow[$lonField] ?? null;
				$urlValue = $valuesRow[$urlField] ?? null;
				if ( $latValue != '' && $lonValue != '' ) {
					$nameValue = array_shift( $valuesTable[$i] );
					$titleValue = array_shift( $displayedValuesForRow );
					$urlUtils = MediaWikiServices::getInstance()->getUrlUtils();
					$hrefRegExp = '/^(' . $urlUtils->validProtocols() . ')[^\s]+$/';
					if ( $urlValue !== null && preg_match( $hrefRegExp, $urlValue ) ) {
						$titleValue = Html::element( 'a', [ 'href' => $urlValue ], $titleValue );
					}
					$valuesForMap[] = $this->getPinValues(
						$nameValue, $titleValue, $latValue, $lonValue,
						$displayedValuesForRow, $displayParams, $i, $valuesRow, $iconFieldName
					);
				}
			}
		}

		$service = self::$mappingService;
		$jsonData = json_encode( $valuesForMap, JSON_NUMERIC_CHECK | JSON_HEX_TAG );
		$divID = "mapCanvas" . self::$mapNumber++;

		if ( $service == 'Leaflet' && array_key_exists( 'image', $displayParams ) ) {
			$fileName = $displayParams['image'];
			$imageData = $this->getImageData( $fileName );
			if ( $imageData == null ) {
				$fileName = null;
			} else {
				[ $imageWidth, $imageHeight, $imageURL ] = $imageData;
			}
		} else {
			$fileName = null;
		}

		$height = CargoUtils::getCSSSize( $displayParams, 'height', null );
		$width = CargoUtils::getCSSSize( $displayParams, 'width', null );

		if ( $fileName !== null ) {
			// Do some scaling of the image, if necessary.
			if ( $height !== null && $width !== null ) {
				// Reduce image if it doesn't fit into the
				// assigned rectangle.
				$heightRatio = (int)$height / $imageHeight;
				$widthRatio = (int)$width / $imageWidth;
				$smallerRatio = min( $heightRatio, $widthRatio );
				if ( $smallerRatio < 1 ) {
					$imageHeight *= $smallerRatio;
					$imageWidth *= $smallerRatio;
				}
			} else {
				// Reduce image if it's too big.
				$maxDimension = max( $imageHeight, $imageWidth );
				$maxAllowedSize = 1000;
				if ( $maxDimension > $maxAllowedSize ) {
					$imageHeight *= $maxAllowedSize / $maxDimension;
					$imageWidth *= $maxAllowedSize / $maxDimension;
				}
				$height = $imageHeight . 'px';
				$width = $imageWidth . 'px';
			}
		} else {
			if ( $height == null ) {
				$height = "400px";
			}
			if ( $width == null ) {
				$width = "700px";
			}
		}

		// The 'map data' element does double duty: it holds the full
		// set of map data, as well as, in the tag attributes,
		// settings related to the display, including the mapping
		// service to use.
		$mapDataAttrs = [
			'class' => 'cargoMapData',
			'style' => 'display: none',
			'data-mapping-service' => $service,
			'data-mw-cargo-map-data' => $jsonData,
		];
		if ( array_key_exists( 'zoom', $displayParams ) && $displayParams['zoom'] != '' ) {
			$mapDataAttrs['data-zoom'] = $displayParams['zoom'];
		}
		if ( array_key_exists( 'center', $displayParams ) && $displayParams['center'] != '' ) {
			$mapDataAttrs['data-center'] = $displayParams['center'];
		}
		if ( array_key_exists( 'cluster', $displayParams ) ) {
			$mapDataAttrs['data-cluster'] = strtolower( $displayParams['cluster'] );
		}
		if ( $fileName !== null ) {
			$mapDataAttrs['data-image-path'] = $imageURL;
			$mapDataAttrs['data-height'] = $imageHeight;
			$mapDataAttrs['data-width'] = $imageWidth;
		}

		$mapData = Html::element( 'span', $mapDataAttrs );

		$mapCanvasAttrs = [
			'class' => 'mapCanvas',
			'style' => "height: $height; width: $width;",
			'id' => $divID,
		];
		return Html::rawElement( 'div', $mapCanvasAttrs, $mapData );
	}

	/**
	 * Like CargoMapsFormat::getMapPointValues(), but when $iconFieldName
	 * is given, resolves the icon from $valuesRow[$iconFieldName] --
	 * i.e. directly from this same row's own data -- instead of from
	 * $displayParams['icon']'s row-position indexing. Falls back to the
	 * parent format's own icon logic when no iconfield is set, so
	 * `format=pins` behaves exactly like `format=leaflet` for existing
	 * single-icon queries.
	 *
	 * @param mixed $nameValue
	 * @param mixed $titleValue
	 * @param mixed $latValue
	 * @param mixed $lonValue
	 * @param array $displayedValuesForRow
	 * @param array $displayParams
	 * @param int|string $rowNum
	 * @param array $valuesRow The formatted row this marker came from --
	 *   used only to look up $iconFieldName, so field formatting (link
	 *   wrapping etc.) doesn't matter for a plain String icon field.
	 * @param string|null $iconFieldName
	 * @return array
	 */
	private function getPinValues(
		$nameValue, $titleValue, $latValue, $lonValue, $displayedValuesForRow,
		$displayParams, $rowNum, array $valuesRow, ?string $iconFieldName
	) {
		$valuesForMapPoint = [
			// 'name' has no formatting (like a link), while 'title' might.
			'name' => $nameValue,
			'title' => $titleValue,
			'lat' => $latValue,
			'lon' => $lonValue,
			'otherValues' => $displayedValuesForRow,
		];

		$iconFileName = null;

		if ( $iconFieldName !== null ) {
			$iconFileName = $valuesRow[$iconFieldName]
				?? $valuesRow[str_replace( ' ', '_', $iconFieldName )]
				?? null;
			if ( $iconFileName === '' ) {
				$iconFileName = null;
			}
		} elseif ( array_key_exists( 'icon', $displayParams ) ) {
			// No iconfield given -- fall back to Cargo core's own
			// (position-indexed, compound-query-only) behavior, so this
			// format is a safe drop-in for `format=leaflet`.
			if ( is_array( $displayParams['icon'] ) ) {
				if ( array_key_exists( $rowNum, $displayParams['icon'] ) ) {
					$iconFileName = $displayParams['icon'][$rowNum];
				}
			} else {
				$iconFileName = $displayParams['icon'];
			}
		}

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

}
