<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper statistici per leggere l'andamento Search Console.
 *
 * Le proiezioni sono scenari lineari diagnostici, non previsioni di ranking.
 */
final class EMS_Local_SEO_Forecast {
	private const CTR_CURVE = array(
		1 => 0.28,
		2 => 0.16,
		3 => 0.11,
		4 => 0.08,
		5 => 0.06,
		6 => 0.045,
		7 => 0.035,
		8 => 0.028,
		9 => 0.022,
		10 => 0.018,
	);

	public static function expected_ctr( float $position ): float {
		if ( $position <= 1.0 ) {
			return self::CTR_CURVE[1];
		}
		if ( $position >= 10.0 ) {
			return self::CTR_CURVE[10];
		}

		$low  = (int) floor( $position );
		$high = (int) ceil( $position );
		if ( $low === $high ) {
			return self::CTR_CURVE[ $low ] ?? 0.018;
		}

		$a = self::CTR_CURVE[ $low ] ?? 0.018;
		$b = self::CTR_CURVE[ $high ] ?? 0.018;
		$t = $position - $low;

		return $a + ( $b - $a ) * $t;
	}

	public static function sum_range( array $daily, string $from, string $to ): array {
		$clicks         = 0.0;
		$impressions    = 0.0;
		$position_total = 0.0;
		$rows           = 0;

		foreach ( $daily as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$date = (string) ( $row['date'] ?? '' );
			if ( '' === $date || $date < $from || $date > $to ) {
				continue;
			}

			$imp             = max( 0.0, (float) ( $row['impressions'] ?? 0 ) );
			$clicks        += max( 0.0, (float) ( $row['clicks'] ?? 0 ) );
			$impressions   += $imp;
			$position_total += max( 0.0, (float) ( $row['position'] ?? 0 ) ) * $imp;
			$rows++;
		}

		return array(
			'clicks'      => $clicks,
			'impressions' => $impressions,
			'ctr'         => $impressions > 0 ? $clicks / $impressions : 0.0,
			'position'    => $impressions > 0 ? $position_total / $impressions : 0.0,
			'rows'        => $rows,
		);
	}

	public static function last_date( array $daily ): ?string {
		$dates = array();
		foreach ( $daily as $row ) {
			if ( is_array( $row ) && ! empty( $row['date'] ) ) {
				$dates[] = (string) $row['date'];
			}
		}
		if ( empty( $dates ) ) {
			return null;
		}

		sort( $dates );
		return end( $dates ) ?: null;
	}

	public static function compare_periods( array $daily, int $days ): array {
		$days = max( 7, min( 90, $days ) );
		$last = self::last_date( $daily );
		if ( null === $last ) {
			return array();
		}

		$end          = new DateTimeImmutable( $last );
		$current_from = $end->modify( '-' . ( $days - 1 ) . ' days' );
		$previous_to  = $current_from->modify( '-1 day' );
		$previous_from= $previous_to->modify( '-' . ( $days - 1 ) . ' days' );

		return array(
			'from'          => $current_from->format( 'Y-m-d' ),
			'to'            => $end->format( 'Y-m-d' ),
			'previous_from' => $previous_from->format( 'Y-m-d' ),
			'previous_to'   => $previous_to->format( 'Y-m-d' ),
			'current'       => self::sum_range( $daily, $current_from->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) ),
			'previous'      => self::sum_range( $daily, $previous_from->format( 'Y-m-d' ), $previous_to->format( 'Y-m-d' ) ),
		);
	}

	public static function weekly( array $daily ): array {
		$groups = array();

		foreach ( $daily as $row ) {
			if ( ! is_array( $row ) || empty( $row['date'] ) ) {
				continue;
			}
			try {
				$date = new DateTimeImmutable( (string) $row['date'] );
			} catch ( Exception $e ) {
				continue;
			}

			$week = $date->modify( 'monday this week' )->format( 'Y-m-d' );
			if ( ! isset( $groups[ $week ] ) ) {
				$groups[ $week ] = array(
					'week'           => $week,
					'clicks'         => 0.0,
					'impressions'    => 0.0,
					'position_total' => 0.0,
				);
			}

			$imp = max( 0.0, (float) ( $row['impressions'] ?? 0 ) );
			$groups[ $week ]['clicks']         += max( 0.0, (float) ( $row['clicks'] ?? 0 ) );
			$groups[ $week ]['impressions']    += $imp;
			$groups[ $week ]['position_total'] += max( 0.0, (float) ( $row['position'] ?? 0 ) ) * $imp;
		}

		ksort( $groups );
		$out = array();

		foreach ( $groups as $group ) {
			$imp = (float) $group['impressions'];
			$out[] = array(
				'week'        => $group['week'],
				'clicks'      => (float) $group['clicks'],
				'impressions' => $imp,
				'ctr'         => $imp > 0 ? (float) $group['clicks'] / $imp : 0.0,
				'position'    => $imp > 0 ? (float) $group['position_total'] / $imp : 0.0,
			);
		}

		return $out;
	}

	public static function project( array $weeks, string $metric = 'clicks', int $horizon = 4, int $window = 12 ): array {
		$allowed = array( 'clicks', 'impressions', 'ctr', 'position' );
		if ( ! in_array( $metric, $allowed, true ) ) {
			$metric = 'clicks';
		}

		$series = array_slice( array_values( $weeks ), -max( 4, min( 26, $window ) ) );
		$n      = count( $series );
		if ( $n < 4 ) {
			return array(
				'ok'         => false,
				'confidence' => 'bassa',
				'points'     => array(),
				'note'       => 'Servono almeno quattro settimane osservate.',
			);
		}

		$sum_x = 0.0;
		$sum_y = 0.0;
		$sum_xy= 0.0;
		$sum_x2= 0.0;

		foreach ( $series as $i => $row ) {
			$x = (float) $i;
			$y = (float) ( $row[ $metric ] ?? 0 );
			$sum_x  += $x;
			$sum_y  += $y;
			$sum_xy += $x * $y;
			$sum_x2 += $x * $x;
		}

		$denom = $n * $sum_x2 - $sum_x * $sum_x;
		$slope = 0.0 !== $denom ? ( $n * $sum_xy - $sum_x * $sum_y ) / $denom : 0.0;
		$intercept = ( $sum_y - $slope * $sum_x ) / $n;

		$residuals = array();
		foreach ( $series as $i => $row ) {
			$pred = $intercept + $slope * $i;
			$residuals[] = (float) ( $row[ $metric ] ?? 0 ) - $pred;
		}
		$variance = 0.0;
		foreach ( $residuals as $residual ) {
			$variance += $residual * $residual;
		}
		$std = sqrt( $variance / max( 1, $n - 2 ) );

		$last_week = new DateTimeImmutable( (string) $series[ $n - 1 ]['week'] );
		$points    = array();
		$total     = 0.0;

		for ( $j = 1; $j <= max( 1, min( 8, $horizon ) ); $j++ ) {
			$x     = ( $n - 1 ) + $j;
			$value = $intercept + $slope * $x;
			$spread= $std * ( 1.25 + 0.20 * $j );

			if ( 'position' !== $metric ) {
				$value = max( 0.0, $value );
				$low   = max( 0.0, $value - $spread );
			} else {
				$value = max( 1.0, $value );
				$low   = max( 1.0, $value - $spread );
			}
			$high = max( $low, $value + $spread );

			$points[] = array(
				'week'  => $last_week->modify( '+' . $j . ' weeks' )->format( 'Y-m-d' ),
				'value' => $value,
				'low'   => $low,
				'high'  => $high,
			);
			$total += $value;
		}

		$mean = abs( $sum_y / max( 1, $n ) );
		$noise_ratio = $mean > 0 ? $std / $mean : 1.0;
		$confidence = $n >= 10 && $noise_ratio < 0.25 ? 'alta' : ( $n >= 6 && $noise_ratio < 0.55 ? 'media' : 'bassa' );

		return array(
			'ok'         => true,
			'metric'     => $metric,
			'weeks'      => $n,
			'slope'      => $slope,
			'confidence' => $confidence,
			'points'     => $points,
			'total'      => $total,
			'note'       => 'Scenario lineare basato sul trend osservato; non è una previsione di ranking.',
		);
	}

	public static function potential( array $rows, float $target = 3.0, int $limit = 10 ): array {
		$items = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$impressions = max( 0.0, (float) ( $row['impressions'] ?? 0 ) );
			$position    = max( 0.0, (float) ( $row['position'] ?? 0 ) );
			if ( $impressions < 20 || $position < 4.0 || $position > 20.0 ) {
				continue;
			}

			$current_ctr = max( 0.0, (float) ( $row['ctr'] ?? 0 ) );
			$target_ctr  = self::expected_ctr( max( 1.0, $target ) );
			$gain        = max( 0.0, $impressions * ( $target_ctr - $current_ctr ) );
			if ( $gain <= 0 ) {
				continue;
			}

			$items[] = array_merge(
				$row,
				array(
					'scenario_click_gain' => $gain,
					'target_position'     => $target,
				)
			);
		}

		usort(
			$items,
			static fn( array $a, array $b ): int => $b['scenario_click_gain'] <=> $a['scenario_click_gain']
		);
		$items = array_slice( $items, 0, max( 1, $limit ) );

		return array(
			'items' => $items,
			'total' => array_sum( array_column( $items, 'scenario_click_gain' ) ),
			'note'  => 'Scenario teorico se le query selezionate raggiungessero la posizione obiettivo; non è una previsione.',
		);
	}

	public static function group_by( array $rows, string $dimension ): array {
		$groups = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key = trim( (string) ( $row[ $dimension ] ?? '' ) );
			if ( '' === $key ) {
				continue;
			}

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					$dimension      => $key,
					'clicks'         => 0.0,
					'impressions'    => 0.0,
					'position_total' => 0.0,
				);
			}

			$imp = max( 0.0, (float) ( $row['impressions'] ?? 0 ) );
			$groups[ $key ]['clicks']         += max( 0.0, (float) ( $row['clicks'] ?? 0 ) );
			$groups[ $key ]['impressions']    += $imp;
			$groups[ $key ]['position_total'] += max( 0.0, (float) ( $row['position'] ?? 0 ) ) * $imp;
		}

		$out = array();
		foreach ( $groups as $group ) {
			$imp = (float) $group['impressions'];
			$group['ctr']      = $imp > 0 ? (float) $group['clicks'] / $imp : 0.0;
			$group['position'] = $imp > 0 ? (float) $group['position_total'] / $imp : 0.0;
			unset( $group['position_total'] );
			$out[] = $group;
		}

		usort( $out, static fn( array $a, array $b ): int => $b['impressions'] <=> $a['impressions'] );
		return $out;
	}

	public static function delta_pct( float $current, float $previous ): ?float {
		if ( $previous <= 0.0 ) {
			return $current > 0.0 ? null : 0.0;
		}

		return ( $current - $previous ) / $previous;
	}
}
