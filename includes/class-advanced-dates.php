<?php
/** Native JetEngine Advanced Date writes. Ordinary date fields are unaffected. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Calico_JMCB_Advanced_Dates {

	public static function provider() {
		if ( ! class_exists( 'Jet_Engine_Advanced_Date_Field' ) ) { return null; }
		$instance = Jet_Engine_Advanced_Date_Field::instance();
		$data = isset( $instance->data ) ? $instance->data : null;
		return is_object( $data ) && method_exists( $data, 'update_field_with_value' ) && method_exists( $data, 'get_date_pairs_cache_key' ) ? $data : null;
	}

	public static function decode( $value ) {
		$value = is_string( $value ) ? json_decode( $value, true ) : $value;
		return is_array( $value ) && ( isset( $value['date'] ) || isset( $value['dates'] ) )
			&& ( isset( $value['time'] ) || isset( $value['dates'] ) || isset( $value['is_end_date'] ) || isset( $value['is_recurring'] ) ) ? $value : null;
	}

	private static function error( $message ) {
		return new WP_Error( 'calico_advanced_date_invalid', $message );
	}

	/** JetEngine uses strtotime under WordPress's UTC PHP timezone: wall-clock encoding, not a UTC instant conversion. */
	private static function timestamp( $date, $time ) {
		if ( ! is_string( $date ) || ! is_string( $time ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $date ) || ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $time ) ) {
			return self::error( 'Advanced Date schedules require YYYY-MM-DD dates and 24-hour HH:MM times.' );
		}
		$format = strlen( $time ) === 5 ? 'Y-m-d H:i' : 'Y-m-d H:i:s';
		$value = DateTimeImmutable::createFromFormat( '!' . $format, $date . ' ' . $time, new DateTimeZone( 'UTC' ) );
		return $value && $value->format( $format ) === $date . ' ' . $time ? (string) $value->getTimestamp() : self::error( 'Invalid Advanced Date calendar date.' );
	}

	public static function expected( $config ) {
		$items = ! empty( $config['dates'] ) ? $config['dates'] : array( $config );
		if ( ! is_array( $items ) || count( $items ) > 1000 || ! $items ) { return self::error( 'Use 1–1000 schedule entries.' ); }
		$pairs = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) { return self::error( 'Each schedule entry must be an object.' ); }
			$start = self::timestamp( $item['date'] ?? '', $item['time'] ?? '00:00' );
			if ( is_wp_error( $start ) ) { return $start; }
			$end = null;
			if ( ! empty( $item['is_end_date'] ) ) {
				$end = self::timestamp( $item['end_date'] ?? '', $item['end_time'] ?? '00:00' );
				if ( is_wp_error( $end ) ) { return $end; }
				if ( (int) $end < (int) $start ) { return self::error( 'The end must not precede the start. Supply the next date for an overnight event.' ); }
			}
			$pairs[] = array( 'start' => $start, 'end' => $end );
		}
		if ( ! empty( $config['is_recurring'] ) && empty( $config['dates'] ) ) {
			if ( ! in_array( $config['recurring'] ?? '', array( 'daily', 'weekly', 'monthly', 'yearly' ), true ) ) { return self::error( 'Unsupported recurrence rule.' ); }
			$period = $config['recurring_period'] ?? 1;
			if ( ! is_scalar( $period ) || ! ctype_digit( (string) $period ) || (int) $period < 1 || (int) $period > 1000 ) { return self::error( 'Recurrence interval must be an integer from 1 to 1000.' ); }
			if ( 'weekly' === $config['recurring'] ) {
				$days = $config['week_days'] ?? array();
				if ( ! is_array( $days ) || ! $days || count( $days ) > 7 ) { return self::error( 'Weekly recurrence requires selected weekdays.' ); }
				$last = 0;
				foreach ( $days as $day ) { if ( ! is_scalar( $day ) || ! ctype_digit( (string) $day ) || (int) $day <= $last || (int) $day > 7 ) { return self::error( 'Weekdays must be unique, ascending numbers from 1 to 7.' ); } $last = (int) $day; }
			}
			$ending = $config['end'] ?? 'after';
			if ( 'after' === $ending ) {
				$count = $config['end_after'] ?? 1;
				if ( ! is_scalar( $count ) || ! ctype_digit( (string) $count ) || (int) $count < 1 || (int) $count > 1000 ) { return self::error( 'Recurrence must end after 1–1000 occurrences.' ); }
			} elseif ( 'on_date' === $ending ) {
				$until = self::timestamp( $config['end_after_date'] ?? '', '23:59' );
				if ( is_wp_error( $until ) || (int) $until < (int) $pairs[0]['start'] || (int) $until - (int) $pairs[0]['start'] > 1000 * 86400 ) { return self::error( 'Recurrence end date must be within 1000 days of its start.' ); }
			} else { return self::error( 'Recurrence requires a finite end condition.' ); }
			foreach ( array( 'month_day' => 31, 'month' => 12 ) as $key => $max ) {
				if ( isset( $config[ $key ] ) && ( ! is_scalar( $config[ $key ] ) || ! ctype_digit( (string) $config[ $key ] ) || (int) $config[ $key ] < 1 || (int) $config[ $key ] > $max ) ) { return self::error( 'Invalid recurrence ' . $key . '.' ); }
			}
			if ( isset( $config['month_day_type'] ) && ! in_array( $config['month_day_type'], array( 'first', 'second', 'third', 'fourth', 'last' ), true ) ) { return self::error( 'Invalid month day ordinal.' ); }
			if ( isset( $config['month_day_type_value'] ) && ! in_array( (string) $config['month_day_type_value'], array( 'day', '1', '2', '3', '4', '5', '6', '7' ), true ) ) { return self::error( 'Invalid monthly weekday.' ); }

		}
		return $pairs;
	}

	/** Validate all plans before any external write; derived keys must be approved too. */
	public static function prepare( $fields, $post_id, $allowed ) {
		$plans = array();
		foreach ( $allowed as $key ) {
			if ( substr( $key, -8 ) !== '__config' ) { continue; }
			$base = substr( $key, 0, -8 );
			$old = $post_id ? self::decode( get_post_meta( $post_id, $key, true ) ) : null;
			$sent = array_key_exists( $key, $fields );
			$config = $sent ? self::decode( $fields[ $key ] ) : $old;
			$touched = array_key_exists( $base, $fields ) || array_key_exists( $base . '__end_date', $fields );
			$paired = in_array( $base, $allowed, true ) && in_array( $base . '__end_date', $allowed, true );
			if ( $paired && ( $sent || $touched ) && ! $config ) { return self::error( 'A complete, valid ' . $key . ' schedule is required.' ); }
			if ( $old && $sent && ! $config ) { return self::error( 'Supply the complete Advanced Date configuration; deleting it independently is unsafe.' ); }
			if ( $old && $touched && ! $sent ) { return self::error( 'Send ' . $key . ' as one complete schedule instead of editing its timestamps independently.' ); }
			if ( ! $sent || ! $config ) { continue; }
			foreach ( array( $base, $base . '__end_date', $key ) as $derived ) {
				if ( ! in_array( $derived, $allowed, true ) || ( $post_id && ! current_user_can( 'edit_post_meta', $post_id, $derived ) ) ) { return self::error( 'Approve and grant edit access to all Advanced Date fields: ' . $base . ', ' . $base . '__end_date and ' . $key . '.' ); }
			}
			$expected = self::expected( $config );
			if ( is_wp_error( $expected ) ) { return $expected; }
			foreach ( array( $base => 'start', $base . '__end_date' => 'end' ) as $field => $part ) {
				if ( ! array_key_exists( $field, $fields ) ) { continue; }
				$value = $fields[ $field ];
				$want = $expected[0][ $part ];
				// Accept legacy date-only companions only when their day agrees. Config supplies the time.
				$matches = is_scalar( $value ) && null !== $want && ( (string) $value === $want || (string) $value === gmdate( 'Y-m-d', (int) $want ) );
				if ( ! $matches ) { return self::error( 'Conflicting value for ' . $field . '; send only the complete schedule configuration.' ); }
			}
			$plans[ $base ] = array( 'config' => $config, 'expected' => $expected );
		}
		if ( $plans && ! self::provider() ) { return self::error( 'JetEngine Advanced Date is unavailable. No schedule was saved.' ); }
		return $plans;
	}

	public static function strip_derived( $fields, $plans ) {
		foreach ( $plans as $base => $plan ) { unset( $fields[ $base ], $fields[ $base . '__end_date' ], $fields[ $base . '__config' ] ); }
		return $fields;
	}

	public static function save( $post_id, $plans ) {
		foreach ( $plans as $base => $plan ) {
			try { self::provider()->update_field_with_value( $plan['config'], $post_id, $base ); }
			catch ( Throwable $e ) { return self::error( 'JetEngine could not save the schedule: ' . $e->getMessage() ); }
			$result = self::verify( $post_id, $base, $plan['config'] );
			if ( is_wp_error( $result ) ) { return $result; }
		}
		return true;
	}

	public static function verify( $post_id, $base, $config ) {
		$expected = self::expected( $config );
		if ( is_wp_error( $expected ) ) { return $expected; }
		$starts = array_map( 'strval', get_post_meta( $post_id, $base, false ) );
		$ends = array_map( 'strval', get_post_meta( $post_id, $base . '__end_date', false ) );
		$recurring = ! empty( $config['is_recurring'] ) && empty( $config['dates'] );
		if ( ! $starts || count( $starts ) > 1000 || ( ! $recurring && count( $starts ) !== count( $expected ) ) ) { return self::error( 'Saved Advanced Date occurrence count does not match its schedule.' ); }
		$end_index = 0;
		foreach ( $starts as $i => $start ) {
			$pair = $recurring ? $expected[0] : $expected[ $i ];
			if ( ! ctype_digit( $start ) || ( ! $recurring && $start !== $pair['start'] ) || ( $recurring && (int) $start < (int) $pair['start'] ) ) { return self::error( 'Saved start timestamp does not match the schedule. Publication stopped.' ); }
			if ( $recurring && gmdate( 'H:i:s', (int) $start ) !== gmdate( 'H:i:s', (int) $pair['start'] ) ) { return self::error( 'Recurring occurrence time does not match its schedule.' ); }
			if ( null !== $pair['end'] ) {
				$want = $recurring ? (string) ( (int) $start + (int) $pair['end'] - (int) $pair['start'] ) : $pair['end'];
				if ( ! isset( $ends[ $end_index ] ) || $ends[ $end_index++ ] !== $want ) { return self::error( 'Saved end timestamp does not match the schedule. Publication stopped.' ); }
			}
		}
		if ( count( $ends ) !== $end_index ) { return self::error( 'Unexpected saved end dates.' ); }
		$saved = self::decode( get_post_meta( $post_id, $base . '__config', true ) );
		if ( $saved != $config ) { return self::error( 'Saved schedule configuration did not match the request.' ); }
		return true;
	}

	public static function verify_all( $post_id, $allowed ) {
		foreach ( $allowed as $key ) {
			if ( substr( $key, -8 ) !== '__config' ) { continue; }
			$base = substr( $key, 0, -8 );
			if ( ! in_array( $base, $allowed, true ) || ! in_array( $base . '__end_date', $allowed, true ) ) { continue; }
			$config = self::decode( get_post_meta( $post_id, $key, true ) );
			if ( ! $config ) { continue; }
			$check = self::verify( $post_id, $base, $config );
			if ( is_wp_error( $check ) ) { return $check; }
		}
		return true;
	}

	/** Snapshot/restore only fields in this operation, preserving repeated schedule row pairing. */
	public static function snapshot( $post_id, $fields, $plans ) {
		$keys = array_keys( $fields );
		foreach ( $plans as $base => $plan ) { $keys = array_merge( $keys, array( $base, $base . '__end_date', $base . '__config' ) ); }
		$snapshot = array();
		foreach ( array_unique( $keys ) as $key ) { $snapshot[ $key ] = get_post_meta( $post_id, $key, false ); }
		global $wpdb;
		$snapshot['__calico_schedule_rows'] = array();
		foreach ( $plans as $base => $plan ) {
			$snapshot['__calico_schedule_rows'][ $base ] = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN (%s, %s) ORDER BY meta_id ASC", $post_id, $base, $base . '__end_date' ), ARRAY_A );
		}
		return $snapshot;
	}

	public static function restore( $post_id, $snapshot, $plans ) {
		$rows = $snapshot['__calico_schedule_rows'] ?? array();
		unset( $snapshot['__calico_schedule_rows'] );
		foreach ( $snapshot as $key => $values ) { delete_post_meta( $post_id, $key ); }
		foreach ( $plans as $base => $plan ) {
			foreach ( $rows[ $base ] ?? array() as $row ) { add_post_meta( $post_id, $row['meta_key'], wp_slash( maybe_unserialize( $row['meta_value'] ) ) ); }
			unset( $snapshot[ $base ], $snapshot[ $base . '__end_date' ] );
			if ( self::provider() ) { wp_cache_delete( self::provider()->get_date_pairs_cache_key( $post_id, $base ), 'jet-engine' ); }
		}
		foreach ( $snapshot as $key => $values ) { foreach ( $values as $value ) { add_post_meta( $post_id, $key, wp_slash( $value ) ); } }
	}
}
