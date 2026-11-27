<?php
/**
 * Validation of course, tee and hole data.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Domain\Course;

/**
 * Checks a scorecard before it is saved (BUILD_BRIEF section 3): slope 55 to 155, stroke
 * indexes 1 to 18 once each, and hole pars that add up to the tee par.
 */
final class CourseValidator {

	public const HOLE_COUNT = 18;
	public const MIN_SLOPE  = 55;
	public const MAX_SLOPE  = 155;

	/**
	 * Checks a course.
	 *
	 * @param array<string, mixed> $course Course fields: name, optional lat and lng.
	 * @return array<string, string> Error message by field. Empty when valid.
	 */
	public static function validate_course( array $course ): array {
		$errors = array();
		$name   = $course['name'] ?? '';
		if ( ! is_string( $name ) || '' === trim( $name ) ) {
			$errors['name'] = __( 'A course needs a name.', 'cgc' );
		}
		if ( isset( $course['lat'] ) && ( ! is_numeric( $course['lat'] ) || abs( (float) $course['lat'] ) > 90 ) ) {
			$errors['lat'] = __( 'Latitude must be between -90 and 90.', 'cgc' );
		}
		if ( isset( $course['lng'] ) && ( ! is_numeric( $course['lng'] ) || abs( (float) $course['lng'] ) > 180 ) ) {
			$errors['lng'] = __( 'Longitude must be between -180 and 180.', 'cgc' );
		}
		return $errors;
	}

	/**
	 * Checks a tee and its holes.
	 *
	 * @param array<string, mixed>             $tee   Tee fields: name, gender, par, course_rating, slope.
	 * @param array<int, array<string, mixed>> $holes Hole rows: hole, par, si, optional yards.
	 * @return array<string, string> Error message by field. Empty when valid.
	 */
	public static function validate_tee( array $tee, array $holes ): array {
		$errors = array();

		$name = $tee['name'] ?? '';
		if ( ! is_string( $name ) || '' === trim( $name ) ) {
			$errors['name'] = __( 'A tee needs a name.', 'cgc' );
		}
		if ( ! in_array( $tee['gender'] ?? null, array( 'male', 'female' ), true ) ) {
			$errors['gender'] = __( 'Tee gender must be male or female.', 'cgc' );
		}

		$slope = $tee['slope'] ?? null;
		if ( ! self::is_whole( $slope ) || (int) $slope < self::MIN_SLOPE || (int) $slope > self::MAX_SLOPE ) {
			$errors['slope'] = sprintf(
				/* translators: 1: lowest slope, 2: highest slope. */
				__( 'Slope must be a whole number from %1$d to %2$d.', 'cgc' ),
				self::MIN_SLOPE,
				self::MAX_SLOPE
			);
		}

		$rating = $tee['course_rating'] ?? null;
		if ( ! is_numeric( $rating ) || (float) $rating < 40 || (float) $rating > 90 ) {
			$errors['course_rating'] = __( 'Course rating must be a number from 40 to 90.', 'cgc' );
		}

		$par = $tee['par'] ?? null;
		if ( ! self::is_whole( $par ) || (int) $par < 54 || (int) $par > 80 ) {
			$errors['par'] = __( 'Total par must be a whole number from 54 to 80.', 'cgc' );
		}

		return $errors + self::validate_holes( $holes, self::is_whole( $par ) ? (int) $par : null );
	}

	/**
	 * Checks the 18 holes of a tee.
	 *
	 * @param array<int, array<string, mixed>> $holes     Hole rows: hole, par, si, optional yards.
	 * @param int|null                         $total_par The tee par, or null to skip the sum check.
	 * @return array<string, string> Error message by field. Empty when valid.
	 */
	public static function validate_holes( array $holes, ?int $total_par ): array {
		$errors = array();

		if ( count( $holes ) !== self::HOLE_COUNT ) {
			$errors['holes'] = sprintf(
				/* translators: %d: number of holes required. */
				__( 'A tee needs exactly %d holes.', 'cgc' ),
				self::HOLE_COUNT
			);
			return $errors;
		}

		$seen_holes = array();
		$seen_si    = array();
		$sum        = 0;
		$sum_ok     = true;
		foreach ( array_values( $holes ) as $i => $row ) {
			$label = (string) ( $i + 1 );
			$no    = $row['hole'] ?? null;
			if ( ! self::is_whole( $no ) || (int) $no < 1 || (int) $no > self::HOLE_COUNT || isset( $seen_holes[ (int) $no ] ) ) {
				$errors[ "holes.$label.hole" ] = __( 'Hole numbers must be 1 to 18, each once.', 'cgc' );
			} else {
				$seen_holes[ (int) $no ] = true;
			}

			$hole_par = $row['par'] ?? null;
			if ( ! self::is_whole( $hole_par ) || (int) $hole_par < 3 || (int) $hole_par > 6 ) {
				$errors[ "holes.$label.par" ] = __( 'Hole par must be a whole number from 3 to 6.', 'cgc' );
				$sum_ok                       = false;
			} else {
				$sum += (int) $hole_par;
			}

			$si = $row['si'] ?? null;
			if ( ! self::is_whole( $si ) || (int) $si < 1 || (int) $si > self::HOLE_COUNT || isset( $seen_si[ (int) $si ] ) ) {
				$errors[ "holes.$label.si" ] = __( 'Stroke index must be 1 to 18, each once.', 'cgc' );
			} else {
				$seen_si[ (int) $si ] = true;
			}

			$yards = $row['yards'] ?? 0;
			if ( ! self::is_whole( $yards ) || (int) $yards < 0 ) {
				$errors[ "holes.$label.yards" ] = __( 'Yards must be zero or more.', 'cgc' );
			}
		}

		if ( null !== $total_par && $sum_ok && $sum !== $total_par ) {
			$errors['par_total'] = sprintf(
				/* translators: 1: sum of the hole pars, 2: the tee par. */
				__( 'The hole pars add up to %1$d but the tee par is %2$d.', 'cgc' ),
				$sum,
				$total_par
			);
		}

		return $errors;
	}

	/**
	 * Whether a value is a whole number (an int, a digit string, or a float with no fraction).
	 *
	 * @param mixed $value Value to test.
	 */
	private static function is_whole( mixed $value ): bool {
		if ( is_int( $value ) ) {
			return true;
		}
		if ( is_float( $value ) ) {
			return floor( $value ) === $value;
		}
		return is_string( $value ) && 1 === preg_match( '/^-?\d+$/', $value );
	}
}
