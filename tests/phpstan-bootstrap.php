<?php
/**
 * Constants PHPStan cannot see because the main plugin file defines them at runtime.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

define( 'CGC_VERSION', '0.1.0' );
define( 'CGC_DB_VERSION', '5' );

// The SQLite drop-in's database class exists only on the SQLite test site.
if ( ! class_exists( 'WP_SQLite_DB' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound, PEAR.NamingConventions.ValidClassName.Invalid, Universal.Files.SeparateFunctionsFromOO.Mixed
	/**
	 * Stand-in for static analysis only.
	 */
	class WP_SQLite_DB extends wpdb {}
}
