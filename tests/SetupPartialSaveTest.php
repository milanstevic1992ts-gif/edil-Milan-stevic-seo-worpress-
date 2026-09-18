<?php

use PHPUnit\Framework\TestCase;

final class SetupPartialSaveTest extends TestCase {
	private EMS_Local_SEO_Settings $settings;

	protected function setUp(): void {
		$GLOBALS['ems_test_options'] = array();
		$this->settings = new EMS_Local_SEO_Settings( new EMS_Local_SEO_Compatibility() );
	}

	private function storedSettings(): array {
		return array_merge(
			EMS_Local_SEO_Settings::defaults(),
			array(
				'business_name'       => 'EDIL MILAN STEVIC',
				'legal_name'          => 'Milan Stevic',
				'phone'               => '+39 347 852 5176',
				'email'               => 'edilmilanstevic@gmail.com',
				'logo_url'            => 'https://triesteincostruzione.com/logo.webp',
				'google_business_url' => 'https://share.google/example',
				'facebook_url'        => 'https://facebook.com/ems',
				'services'            => "Ristrutturazione bagni\nPosa piastrelle",
				'service_areas'       => "Trieste",
				'enable_schema'       => 1,
				'enable_breadcrumbs'  => 1,
				'enable_indexnow'     => 0,
				'gsc_auto_refresh'    => 0,
				'enable_contacts'     => 0,
			)
		);
	}

	public function test_saving_zone_preserves_activity_profiles_services_and_integrations(): void {
		$GLOBALS['ems_test_options'][ EMS_Local_SEO_Settings::OPTION_KEY ] = $this->storedSettings();

		$result = $this->settings->sanitize(
			array(
				'_ems_setup_step' => 'zona',
				'street_address'  => '',
				'locality'        => 'Trieste',
				'region'          => 'Friuli-Venezia Giulia',
				'postal_code'     => '',
				'service_areas'   => "Trieste\nMuggia\nOpicina",
			)
		);

		$this->assertSame( '+39 347 852 5176', $result['phone'] );
		$this->assertSame( 'edilmilanstevic@gmail.com', $result['email'] );
		$this->assertSame( 'https://share.google/example', $result['google_business_url'] );
		$this->assertSame( "Ristrutturazione bagni\nPosa piastrelle", $result['services'] );
		$this->assertSame( 1, $result['enable_schema'] );
		$this->assertSame( 1, $result['enable_breadcrumbs'] );
		$this->assertSame( "Trieste\nMuggia\nOpicina", $result['service_areas'] );
	}

	public function test_integrations_step_can_uncheck_its_flags_without_touching_other_tabs(): void {
		$stored = $this->storedSettings();
		$stored['enable_indexnow'] = 1;
		$stored['gsc_auto_refresh'] = 1;
		$stored['enable_contacts'] = 1;
		$GLOBALS['ems_test_options'][ EMS_Local_SEO_Settings::OPTION_KEY ] = $stored;

		$result = $this->settings->sanitize(
			array(
				'_ems_setup_step' => 'integrazioni',
				'enable_schema'   => '1',
			)
		);

		$this->assertSame( 1, $result['enable_schema'] );
		$this->assertSame( 0, $result['enable_breadcrumbs'] );
		$this->assertSame( 0, $result['enable_indexnow'] );
		$this->assertSame( 0, $result['gsc_auto_refresh'] );
		$this->assertSame( 0, $result['enable_contacts'] );
		$this->assertSame( '+39 347 852 5176', $result['phone'] );
		$this->assertSame( 'https://share.google/example', $result['google_business_url'] );
		$this->assertSame( "Ristrutturazione bagni\nPosa piastrelle", $result['services'] );
	}

	public function test_profiles_step_can_intentionally_clear_one_profile_without_erasing_activity(): void {
		$GLOBALS['ems_test_options'][ EMS_Local_SEO_Settings::OPTION_KEY ] = $this->storedSettings();

		$result = $this->settings->sanitize(
			array(
				'_ems_setup_step'     => 'profili',
				'google_business_url' => '',
				'facebook_url'        => 'https://facebook.com/nuovo',
				'instagram_url'       => '',
				'tiktok_url'          => '',
			)
		);

		$this->assertSame( '', $result['google_business_url'] );
		$this->assertSame( 'https://facebook.com/nuovo', $result['facebook_url'] );
		$this->assertSame( '+39 347 852 5176', $result['phone'] );
		$this->assertSame( 'Milan Stevic', $result['legal_name'] );
	}

	public function test_full_settings_form_still_resets_unchecked_flags(): void {
		$GLOBALS['ems_test_options'][ EMS_Local_SEO_Settings::OPTION_KEY ] = $this->storedSettings();

		$result = $this->settings->sanitize(
			array(
				'business_name' => 'EDIL MILAN STEVIC',
				'enable_schema' => '1',
			)
		);

		$this->assertSame( 1, $result['enable_schema'] );
		$this->assertSame( 0, $result['enable_breadcrumbs'] );
		$this->assertSame( 0, $result['enable_indexnow'] );
	}
}
