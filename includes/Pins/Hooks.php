<?php

namespace MediaWiki\Extension\SaintapediaCargoPins\Pins;

class Hooks {

	/**
	 * Register the 'pins' Cargo query display format alongside Cargo's
	 * own 'map' / 'leaflet' / 'googlemaps' / 'openlayers' formats.
	 *
	 * @param array &$formatClasses Format name => class name.
	 */
	public static function onCargoSetFormatClasses( array &$formatClasses ): void {
		$formatClasses['pins'] = CargoPinsFormat::class;
	}

}
