<?php
/**
 * Course validation tests.
 *
 * @package CGC\Championship
 */

declare(strict_types=1);

namespace CGC\Championship\Tests\Unit;

use CGC\Championship\Domain\Course\CourseValidator;
use CGC\Championship\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * Slope 55 to 155, SI 1 to 18 once each, pars add up.
 */
final class CourseValidatorTest extends TestCase {

	/**
	 * A valid tee.
	 *
	 * @param array<string, mixed> $changes Fields to change.
	 * @return array<string, mixed>
	 */
	private function tee( array $changes = array() ): array {
		return array_merge(
			array(
				'name'          => 'Yellow',
				'gender'        => 'male',
				'par'           => 72,
				'course_rating' => 71.2,
				'slope'         => 125,
			),
			$changes
		);
	}

	public function test_a_good_scorecard_passes(): void {
		$this->assertSame( array(), CourseValidator::validate_tee( $this->tee(), Fixtures::hole_rows() ) );
	}

	public function test_slope_must_be_55_to_155(): void {
		foreach ( array( 54, 156, 0, 'abc', 125.5 ) as $bad ) {
			$this->assertArrayHasKey( 'slope', CourseValidator::validate_tee( $this->tee( array( 'slope' => $bad ) ), Fixtures::hole_rows() ), (string) $bad );
		}
		foreach ( array( 55, 155, '113' ) as $good ) {
			$this->assertArrayNotHasKey( 'slope', CourseValidator::validate_tee( $this->tee( array( 'slope' => $good ) ), Fixtures::hole_rows() ), (string) $good );
		}
	}

	public function test_stroke_index_must_use_1_to_18_once_each(): void {
		$holes          = Fixtures::hole_rows();
		$holes[1]['si'] = $holes[0]['si']; // Duplicate.
		$errors         = CourseValidator::validate_tee( $this->tee(), $holes );
		$this->assertArrayHasKey( 'holes.2.si', $errors );

		$holes          = Fixtures::hole_rows();
		$holes[4]['si'] = 19;
		$this->assertArrayHasKey( 'holes.5.si', CourseValidator::validate_tee( $this->tee(), $holes ) );

		$holes          = Fixtures::hole_rows();
		$holes[4]['si'] = 0;
		$this->assertArrayHasKey( 'holes.5.si', CourseValidator::validate_tee( $this->tee(), $holes ) );
	}

	public function test_hole_pars_must_add_up_to_the_tee_par(): void {
		$errors = CourseValidator::validate_tee( $this->tee( array( 'par' => 71 ) ), Fixtures::hole_rows() );
		$this->assertArrayHasKey( 'par_total', $errors );

		$holes           = Fixtures::hole_rows();
		$holes[0]['par'] = 5;
		$this->assertArrayHasKey( 'par_total', CourseValidator::validate_tee( $this->tee(), $holes ) );
	}

	public function test_there_must_be_18_holes_numbered_once_each(): void {
		$holes = Fixtures::hole_rows();
		array_pop( $holes );
		$this->assertArrayHasKey( 'holes', CourseValidator::validate_tee( $this->tee(), $holes ) );

		$holes             = Fixtures::hole_rows();
		$holes[17]['hole'] = 1;
		$this->assertArrayHasKey( 'holes.18.hole', CourseValidator::validate_tee( $this->tee(), $holes ) );
	}

	public function test_other_tee_fields_are_checked(): void {
		$errors = CourseValidator::validate_tee(
			$this->tee(
				array(
					'name'          => ' ',
					'gender'        => 'x',
					'course_rating' => 12,
				)
			),
			Fixtures::hole_rows()
		);
		$this->assertArrayHasKey( 'name', $errors );
		$this->assertArrayHasKey( 'gender', $errors );
		$this->assertArrayHasKey( 'course_rating', $errors );
	}

	public function test_a_course_needs_a_name_and_sane_coordinates(): void {
		$this->assertSame(
			array(),
			CourseValidator::validate_course(
				array(
					'name' => 'Celtic Manor',
					'lat'  => 51.6,
					'lng'  => -2.9,
				)
			)
		);
		$errors = CourseValidator::validate_course(
			array(
				'name' => '',
				'lat'  => 95,
				'lng'  => 200,
			)
		);
		$this->assertSame( array( 'name', 'lat', 'lng' ), array_keys( $errors ) );
	}
}
