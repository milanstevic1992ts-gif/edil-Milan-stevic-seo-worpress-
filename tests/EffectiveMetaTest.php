<?php

use PHPUnit\Framework\TestCase;

final class EffectiveMetaTest extends TestCase {
	public function test_parser_reads_effective_metadata_and_counts_duplicates(): void {
		$html = <<<'HTML'
<!doctype html>
<html>
<head>
<title>Ristrutturazione bagno a Trieste</title>
<meta name="description" content="Prima descrizione">
<meta content="Seconda descrizione" name="description">
<link rel="canonical" href="https://triesteincostruzione.com/ristrutturazione-bagno/">
<meta name="robots" content="index,follow">
</head>
<body></body>
</html>
HTML;

		$reader = new EMS_Local_SEO_Effective_Meta();
		$method = new ReflectionMethod( EMS_Local_SEO_Effective_Meta::class, 'parse_html' );
		$method->setAccessible( true );

		$result = $method->invoke( $reader, $html );

		$this->assertSame( 'Ristrutturazione bagno a Trieste', $result['title'] );
		$this->assertSame( 1, $result['title_count'] );
		$this->assertSame( 2, $result['description_count'] );
		$this->assertSame( 1, $result['canonical_count'] );
		$this->assertFalse( $result['noindex'] );
	}

	public function test_parser_detects_noindex(): void {
		$html = '<html><head><title>Test</title><meta name="robots" content="noindex, follow"></head><body></body></html>';

		$reader = new EMS_Local_SEO_Effective_Meta();
		$method = new ReflectionMethod( EMS_Local_SEO_Effective_Meta::class, 'parse_html' );
		$method->setAccessible( true );

		$result = $method->invoke( $reader, $html );

		$this->assertTrue( $result['noindex'] );
		$this->assertFalse( $result['nofollow'] );
	}
}
