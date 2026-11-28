<?php
/**
 * Stand-in for the SQLite drop-in's database class, so Db can tell the SQLite driver apart.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

use CGC\Championship\Tests\Support\FakeWpdb;

if ( class_exists( 'WP_SQLite_DB' ) ) {
	return;
}

/**
 * The SQLite driver's class name.
 */
class WP_SQLite_DB extends FakeWpdb {} // phpcs:ignore Generic.Classes.DuplicateClassName.Found, Generic.Files.OneObjectStructurePerFile.MultipleFound, PEAR.NamingConventions.ValidClassName.Invalid, Universal.Files.SeparateFunctionsFromOO.Mixed
