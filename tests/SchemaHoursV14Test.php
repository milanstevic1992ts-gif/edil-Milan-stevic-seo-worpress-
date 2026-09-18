<?php

use PHPUnit\Framework\TestCase;

final class SchemaHoursV14Test extends TestCase {
	public function test_parses_weekday_ranges_and_split_hours(): void {
		$hours = EMS_Local_SEO_Schema::parse_opening_hours(
			"lun-ven 08:00-12:00, 14:00-18:00\nsab 08:00-12:00"
		);

		$this->assertCount( 3, $hours );
		$this->assertSame( '08:00', $hours[0]['opens'] );
		$this->assertSame( '12:00', $hours[0]['closes'] );
		$this->assertCount( 5, $hours[0]['dayOfWeek'] );
		$this->assertSame( array( 'https://schema.org/Saturday' ), $hours[2]['dayOfWeek'] );
	}

	public function test_rejects_invalid_hours(): void {
		$hours = EMS_Local_SEO_Schema::parse_opening_hours(
			"lun-ven 25:00-30:00\ndom 18:00-09:00\nqualcosa"
		);

		$this->assertSame( array(), $hours );
	}
}
