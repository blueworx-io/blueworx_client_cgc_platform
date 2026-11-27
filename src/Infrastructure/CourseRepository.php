<?php
/**
 * Courses, tees and holes: save, read, change, delete.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Infrastructure;

use CGC\Championship\Clock;
use CGC\Championship\DemoMode;
use CGC\Championship\Domain\Course\CourseValidator;
use CGC\Championship\Domain\Course\Tee;
use CGC\Championship\Domain\Course\TeeHole;
use WP_Error;

/**
 * Data layer for cgc_courses, cgc_tees and cgc_tee_holes. Every query is scoped by tenant and
 * prepared. Nothing is saved until CourseValidator accepts it.
 *
 * This class does not check who is asking. The REST controller that calls it must.
 */
final class CourseRepository {

	/**
	 * Adds a course.
	 *
	 * @param array<string, mixed> $data      Fields: name, club, lat, lng.
	 * @param int                  $tenant_id Tenant.
	 * @return int|WP_Error New course id.
	 */
	public static function create_course( array $data, int $tenant_id = 1 ): int|WP_Error {
		global $wpdb;

		$data   = self::clean_names( $data );
		$errors = CourseValidator::validate_course( $data );
		if ( array() !== $errors ) {
			return self::invalid( $errors );
		}

		$ok = $wpdb->insert( Schema::table( 'courses' ), self::course_row( $data ) + self::base_row( $tenant_id ) );
		return false === $ok ? self::db_error() : (int) $wpdb->insert_id;
	}

	/**
	 * Changes a course.
	 *
	 * @param int                  $id        Course id.
	 * @param array<string, mixed> $data      Fields: name, club, lat, lng.
	 * @param int                  $tenant_id Tenant.
	 * @return bool|WP_Error
	 */
	public static function update_course( int $id, array $data, int $tenant_id = 1 ): bool|WP_Error {
		global $wpdb;

		if ( null === self::get_course( $id, $tenant_id ) ) {
			return new WP_Error( 'cgc_not_found', __( 'Course not found.', 'cgc' ) );
		}
		$data   = self::clean_names( $data );
		$errors = CourseValidator::validate_course( $data );
		if ( array() !== $errors ) {
			return self::invalid( $errors );
		}

		$ok = $wpdb->update(
			Schema::table( 'courses' ),
			self::course_row( $data ) + array( 'updated_at' => Clock::mysql() ),
			array(
				'id'        => $id,
				'tenant_id' => $tenant_id,
			)
		);
		return false === $ok ? self::db_error() : true;
	}

	/**
	 * Deletes a course with its tees and holes. Refused while a venue or match uses it.
	 *
	 * @param int $id        Course id.
	 * @param int $tenant_id Tenant.
	 * @return bool|WP_Error
	 */
	public static function delete_course( int $id, int $tenant_id = 1 ): bool|WP_Error {
		global $wpdb;

		if ( null === self::get_course( $id, $tenant_id ) ) {
			return new WP_Error( 'cgc_not_found', __( 'Course not found.', 'cgc' ) );
		}
		foreach ( array( 'venues', 'matches' ) as $short ) {
			$table = Schema::table( $short );
			$used  = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE course_id = %d AND tenant_id = %d", $id, $tenant_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema.
			);
			if ( $used > 0 ) {
				return new WP_Error( 'cgc_in_use', __( 'This course is used by a venue or a match and cannot be deleted.', 'cgc' ) );
			}
		}

		if ( ! Db::begin() ) {
			return self::db_error();
		}
		foreach ( self::tees_for_course( $id, $tenant_id ) as $tee ) {
			if ( ! self::delete_tee_rows( $tee->id, $tenant_id ) ) {
				Db::rollback();
				return self::db_error();
			}
		}
		$ok = $wpdb->delete(
			Schema::table( 'courses' ),
			array(
				'id'        => $id,
				'tenant_id' => $tenant_id,
			)
		);
		if ( false === $ok ) {
			Db::rollback();
			return self::db_error();
		}
		return Db::commit() ? true : self::db_error();
	}

	/**
	 * One course.
	 *
	 * @param int $id        Course id.
	 * @param int $tenant_id Tenant.
	 * @return array<string, mixed>|null
	 */
	public static function get_course( int $id, int $tenant_id = 1 ): ?array {
		global $wpdb;

		$table = Schema::table( 'courses' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND tenant_id = %d", $id, $tenant_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema.
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * A page of courses, by name.
	 *
	 * @param int $tenant_id Tenant.
	 * @param int $page      Page, from 1.
	 * @param int $per_page  Courses per page, 1 to 200.
	 * @return list<array<string, mixed>>
	 */
	public static function list_courses( int $tenant_id = 1, int $page = 1, int $per_page = 50 ): array {
		global $wpdb;

		$per_page = max( 1, min( 200, $per_page ) );
		$offset   = max( 0, $page - 1 ) * $per_page;
		$table    = Schema::table( 'courses' );
		$rows     = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE tenant_id = %d ORDER BY name ASC, id ASC LIMIT %d OFFSET %d", $tenant_id, $per_page, $offset ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema.
			ARRAY_A
		);
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * Adds a tee with its 18 holes. Nothing is saved when anything is wrong.
	 *
	 * @param int                              $course_id Course id.
	 * @param array<string, mixed>             $tee       Fields: name, gender, par, course_rating, slope.
	 * @param array<int, array<string, mixed>> $holes     Rows: hole, par, si, yards.
	 * @param int                              $tenant_id Tenant.
	 * @return int|WP_Error New tee id.
	 */
	public static function create_tee( int $course_id, array $tee, array $holes, int $tenant_id = 1 ): int|WP_Error {
		global $wpdb;

		if ( null === self::get_course( $course_id, $tenant_id ) ) {
			return new WP_Error( 'cgc_not_found', __( 'Course not found.', 'cgc' ) );
		}
		$tee    = self::clean_names( $tee );
		$errors = CourseValidator::validate_tee( $tee, $holes );
		if ( array() !== $errors ) {
			return self::invalid( $errors );
		}

		if ( ! Db::begin() ) {
			return self::db_error();
		}
		$ok = $wpdb->insert( Schema::table( 'tees' ), self::tee_row( $tee ) + array( 'course_id' => $course_id ) + self::base_row( $tenant_id ) );
		if ( false === $ok ) {
			Db::rollback();
			return self::db_error();
		}
		$tee_id = (int) $wpdb->insert_id;
		if ( ! self::insert_holes( $tee_id, $holes, $tenant_id ) ) {
			Db::rollback();
			return self::db_error();
		}
		return Db::commit() ? $tee_id : self::db_error();
	}

	/**
	 * Changes a tee and replaces its holes.
	 *
	 * @param int                              $id        Tee id.
	 * @param array<string, mixed>             $tee       Fields: name, gender, par, course_rating, slope.
	 * @param array<int, array<string, mixed>> $holes     Rows: hole, par, si, yards.
	 * @param int                              $tenant_id Tenant.
	 * @return bool|WP_Error
	 */
	public static function update_tee( int $id, array $tee, array $holes, int $tenant_id = 1 ): bool|WP_Error {
		global $wpdb;

		if ( null === self::get_tee( $id, $tenant_id ) ) {
			return new WP_Error( 'cgc_not_found', __( 'Tee not found.', 'cgc' ) );
		}
		$tee    = self::clean_names( $tee );
		$errors = CourseValidator::validate_tee( $tee, $holes );
		if ( array() !== $errors ) {
			return self::invalid( $errors );
		}

		if ( ! Db::begin() ) {
			return self::db_error();
		}
		$where = array(
			'id'        => $id,
			'tenant_id' => $tenant_id,
		);
		$ok    = $wpdb->update( Schema::table( 'tees' ), self::tee_row( $tee ) + array( 'updated_at' => Clock::mysql() ), $where );
		if ( false === $ok ) {
			Db::rollback();
			return self::db_error();
		}
		$deleted = $wpdb->delete(
			Schema::table( 'tee_holes' ),
			array(
				'tee_id'    => $id,
				'tenant_id' => $tenant_id,
			)
		);
		if ( false === $deleted || ! self::insert_holes( $id, $holes, $tenant_id ) ) {
			Db::rollback();
			return self::db_error();
		}
		return Db::commit() ? true : self::db_error();
	}

	/**
	 * Deletes a tee and its holes.
	 *
	 * @param int $id        Tee id.
	 * @param int $tenant_id Tenant.
	 * @return bool|WP_Error
	 */
	public static function delete_tee( int $id, int $tenant_id = 1 ): bool|WP_Error {
		global $wpdb;

		if ( null === self::get_tee( $id, $tenant_id ) ) {
			return new WP_Error( 'cgc_not_found', __( 'Tee not found.', 'cgc' ) );
		}
		if ( ! Db::begin() ) {
			return self::db_error();
		}
		if ( ! self::delete_tee_rows( $id, $tenant_id ) ) {
			Db::rollback();
			return self::db_error();
		}
		return Db::commit() ? true : self::db_error();
	}

	/**
	 * Deletes a tee row and its hole rows. The caller owns the transaction.
	 *
	 * @param int $id        Tee id.
	 * @param int $tenant_id Tenant.
	 * @return bool Whether both deletes worked.
	 */
	private static function delete_tee_rows( int $id, int $tenant_id ): bool {
		global $wpdb;

		$holes = $wpdb->delete(
			Schema::table( 'tee_holes' ),
			array(
				'tee_id'    => $id,
				'tenant_id' => $tenant_id,
			)
		);
		if ( false === $holes ) {
			return false;
		}
		$tee = $wpdb->delete(
			Schema::table( 'tees' ),
			array(
				'id'        => $id,
				'tenant_id' => $tenant_id,
			)
		);
		return false !== $tee;
	}

	/**
	 * Cleans the name the way it will be saved, so validation sees what is stored.
	 * A name made only of HTML tags becomes empty and is refused.
	 *
	 * @param array<array-key, mixed> $data Course or tee fields.
	 * @return array<array-key, mixed>
	 */
	private static function clean_names( array $data ): array {
		if ( isset( $data['name'] ) && is_scalar( $data['name'] ) ) {
			$data['name'] = sanitize_text_field( (string) $data['name'] );
		}
		return $data;
	}

	/**
	 * One tee with its holes in hole order.
	 *
	 * @param int $id        Tee id.
	 * @param int $tenant_id Tenant.
	 */
	public static function get_tee( int $id, int $tenant_id = 1 ): ?Tee {
		global $wpdb;

		$table = Schema::table( 'tees' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND tenant_id = %d", $id, $tenant_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema.
			ARRAY_A
		);
		return is_array( $row ) ? self::tee_from_row( $row, $tenant_id ) : null;
	}

	/**
	 * Every tee of a course.
	 *
	 * @param int $course_id Course id.
	 * @param int $tenant_id Tenant.
	 * @return list<Tee>
	 */
	public static function tees_for_course( int $course_id, int $tenant_id = 1 ): array {
		global $wpdb;

		$table = Schema::table( 'tees' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE course_id = %d AND tenant_id = %d ORDER BY id ASC", $course_id, $tenant_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema.
			ARRAY_A
		);
		$tees  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$tees[] = self::tee_from_row( $row, $tenant_id );
		}
		return $tees;
	}

	/**
	 * Builds a Tee from a database row and loads its holes.
	 *
	 * @param array<string, mixed> $row       Row from cgc_tees.
	 * @param int                  $tenant_id Tenant.
	 */
	private static function tee_from_row( array $row, int $tenant_id ): Tee {
		global $wpdb;

		$tee_id = (int) $row['id'];
		$table  = Schema::table( 'tee_holes' );
		$rows   = $wpdb->get_results(
			$wpdb->prepare( "SELECT hole, par, si, yards FROM {$table} WHERE tee_id = %d AND tenant_id = %d ORDER BY hole ASC", $tee_id, $tenant_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema.
			ARRAY_A
		);
		$holes  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $hole ) {
			$holes[] = new TeeHole( (int) $hole['hole'], (int) $hole['par'], (int) $hole['si'], (int) $hole['yards'] );
		}
		return new Tee(
			$tee_id,
			(string) $row['name'],
			(string) $row['gender'],
			(int) $row['par'],
			(float) $row['course_rating'],
			(int) $row['slope'],
			$holes
		);
	}

	/**
	 * Inserts the holes of a tee.
	 *
	 * @param int                              $tee_id    Tee id.
	 * @param array<int, array<string, mixed>> $holes     Hole rows (already validated).
	 * @param int                              $tenant_id Tenant.
	 */
	private static function insert_holes( int $tee_id, array $holes, int $tenant_id ): bool {
		global $wpdb;

		foreach ( $holes as $hole ) {
			$ok = $wpdb->insert(
				Schema::table( 'tee_holes' ),
				array(
					'tee_id' => $tee_id,
					'hole'   => (int) $hole['hole'],
					'par'    => (int) $hole['par'],
					'si'     => (int) $hole['si'],
					'yards'  => (int) ( $hole['yards'] ?? 0 ),
				) + self::base_row( $tenant_id )
			);
			if ( false === $ok ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Cleaned course columns.
	 *
	 * @param array<string, mixed> $data Course fields.
	 * @return array<string, mixed>
	 */
	private static function course_row( array $data ): array {
		return array(
			'name' => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'club' => sanitize_text_field( (string) ( $data['club'] ?? '' ) ),
			'lat'  => isset( $data['lat'] ) ? (float) $data['lat'] : null,
			'lng'  => isset( $data['lng'] ) ? (float) $data['lng'] : null,
		);
	}

	/**
	 * Cleaned tee columns.
	 *
	 * @param array<string, mixed> $tee Tee fields.
	 * @return array<string, mixed>
	 */
	private static function tee_row( array $tee ): array {
		return array(
			'name'          => sanitize_text_field( (string) $tee['name'] ),
			'gender'        => (string) $tee['gender'],
			'par'           => (int) $tee['par'],
			'course_rating' => number_format( (float) $tee['course_rating'], 1, '.', '' ),
			'slope'         => (int) $tee['slope'],
		);
	}

	/**
	 * Columns every row carries.
	 *
	 * @param int $tenant_id Tenant.
	 * @return array<string, mixed>
	 */
	private static function base_row( int $tenant_id ): array {
		$now = Clock::mysql();
		return array(
			'tenant_id'  => $tenant_id,
			'created_at' => $now,
			'updated_at' => $now,
			'is_demo'    => DemoMode::enabled() ? 1 : 0,
		);
	}

	/**
	 * A validation error carrying the message for each field.
	 *
	 * @param array<string, string> $errors Message by field.
	 */
	private static function invalid( array $errors ): WP_Error {
		return new WP_Error( 'cgc_invalid', __( 'The course data is not valid.', 'cgc' ), array( 'fields' => $errors ) );
	}

	/**
	 * A database failure.
	 */
	private static function db_error(): WP_Error {
		return new WP_Error( 'cgc_db_error', __( 'The database could not save that.', 'cgc' ) );
	}
}
