<?php
/**
 * Standalone test suite for wm-dsgvo-privacy-eraser — no WordPress, no MySQL.
 *
 * The mock $wpdb evaluates the SQL the plugin sends: SET lists and WHERE conditions are read from the
 * statement, UNIQUE keys refuse duplicates, a missing table refuses inserts, and any statement it does not
 * understand throws. Until 2026-09-15 the mock answered 1 to every query(), so an UPDATE that MySQL refuses
 * (the WooCommerce billing columns that do not exist in wc_orders) looked like a success, and none of the
 * reader pass defects D1–D22 of that day could turn a test red. Warnings fail the run.
 *
 * Groups 1–10 predate the reader pass (ported); groups 11–16 pin D1–D22.
 * Run: php tests/test-suite.php (exit 0 = green, results line required).
 *
 * @package WMCyber\DSGVO\Eraser
 */

declare(strict_types=1);

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

set_error_handler( static function ( int $no, string $str, string $file, int $line ): bool {
	if ( ! ( error_reporting() & $no ) ) {
		return false;
	}
	throw new ErrorException( $str, 0, $no, $file, $line );
} );

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

// ---------------------------------------------------------------------------------------------------
// Mock state
// ---------------------------------------------------------------------------------------------------
$GLOBALS['mock_options']                = [];
$GLOBALS['mock_option_autoloads']       = [];
$GLOBALS['mock_blogs_options']          = [];
$GLOBALS['mock_blogs_autoloads']        = [];
$GLOBALS['mock_current_blog_id']        = 1;
$GLOBALS['mock_blog_stack']             = [];
$GLOBALS['mock_sites']                  = [ 1 ];
$GLOBALS['mock_network_active_plugins'] = [];
$GLOBALS['mock_dbdelta_queries']        = [];
$GLOBALS['mock_dbdelta_throw']          = false;
$GLOBALS['mock_activation_hooks']       = [];
$GLOBALS['mock_deactivation_hooks']     = [];
$GLOBALS['mock_transients']             = [];
$GLOBALS['mock_actions']             = [];
$GLOBALS['mock_filters']             = [];
$GLOBALS['mock_fired_actions']       = [];
$GLOBALS['mock_can_manage_options']  = true;
$GLOBALS['mock_registered_settings'] = [];
$GLOBALS['mock_registered_scripts']  = [];
$GLOBALS['mock_script_translations'] = [];
$GLOBALS['mock_registered_blocks']   = [];
$GLOBALS['mock_block_bindings']      = [];
$GLOBALS['mock_gb_tags']             = [];
$GLOBALS['mock_rest_routes']         = [];
$GLOBALS['mock_cleared_cron']        = [];
$GLOBALS['mock_dropped_tables']      = [];
$GLOBALS['mock_users']               = [];
$GLOBALS['mock_mail']                = [];
$GLOBALS['mock_lock_result']         = '1';
$GLOBALS['mock_query_fail']          = [];
$GLOBALS['mock_insert_fail']         = false;
$GLOBALS['mock_multisite']           = false;
$GLOBALS['mock_now']                 = null;
$GLOBALS['mock_delete_fails']        = false;
$GLOBALS['mock_queries']             = [];
$GLOBALS['mock_db_tables']           = [
	'wp_wm_dsgvo_erasure_certificates' => [],
	'wp_wm_dsgvo_requests'             => [],
	'wp_comments'                      => [],
];

final class Mock_WPDB_DSGVO {
	public string $prefix   = 'wp_';
	public string $comments = 'wp_comments';
	public string $options  = 'wp_options';
	public int $insert_id   = 0;
	private array $auto     = [];

	private const UNIQUE = [
		'wp_wm_dsgvo_erasure_certificates' => [ 'certificate_id' ],
		'wp_wm_dsgvo_requests'             => [ 'request_uuid' ],
	];

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function get_charset_collate(): string {
		return '';
	}

	/** %i inserts the bare name, %d an integer, %s a quoted and slashed string. */
	public function prepare( string $query, ...$args ): string {
		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		return (string) preg_replace_callback( '/%[sdi]/', static function ( array $m ) use ( &$args ): string {
			$arg = array_shift( $args );
			if ( '%i' === $m[0] ) {
				return (string) $arg;
			}
			if ( '%d' === $m[0] ) {
				return (string) (int) $arg;
			}
			return "'" . addslashes( (string) $arg ) . "'";
		}, $query );
	}

	private static function norm( string $q ): string {
		$q = (string) preg_replace( '/\s+/', ' ', trim( $q ) );
		return (string) preg_replace_callback( '/DATE_SUB\( UTC_TIMESTAMP\(\), INTERVAL (\d+) HOUR \)/', static fn( $m ) => "'" . gmdate( 'Y-m-d H:i:s', time() - (int) $m[1] * 3600 ) . "'", $q );
	}

	/** Splits on a separator outside single quotes. */
	private static function split_outside_quotes( string $s, string $sep ): array {
		$parts  = [];
		$buf    = '';
		$quoted = false;
		$len    = strlen( $s );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $s[ $i ];
			if ( '\\' === $c && $quoted ) {
				$buf .= $c . ( $s[ $i + 1 ] ?? '' );
				++$i;
				continue;
			}
			if ( "'" === $c ) {
				$quoted = ! $quoted;
			}
			if ( ! $quoted && substr( $s, $i, strlen( $sep ) ) === $sep ) {
				$parts[] = $buf;
				$buf     = '';
				$i      += strlen( $sep ) - 1;
				continue;
			}
			$buf .= $c;
		}
		$parts[] = $buf;
		return array_map( 'trim', $parts );
	}

	private static function value( string $raw ) {
		if ( 'NULL' === $raw ) {
			return null;
		}
		if ( preg_match( "/^'(.*)'$/s", $raw, $m ) ) {
			return stripslashes( $m[1] );
		}
		if ( preg_match( '/^-?\d+$/', $raw ) ) {
			return (int) $raw;
		}
		throw new RuntimeException( 'mock wpdb: unsupported value ' . $raw );
	}

	private static function conds( string $where ): array {
		$out = [];
		foreach ( self::split_outside_quotes( $where, ' AND ' ) as $c ) {
			if ( preg_match( '/^(\w+) IS (NOT )?NULL$/', $c, $m ) ) {
				$out[] = [ $m[1], '' === $m[2] ? 'isnull' : 'notnull', null ];
			} elseif ( preg_match( "/^(\\w+) (=|<>|<|>|<=|>=) ('(?:[^'\\\\]|\\\\.)*'|-?\\d+)$/", $c, $m ) ) {
				$out[] = [ $m[1], $m[2], self::value( $m[3] ) ];
			} else {
				throw new RuntimeException( 'mock wpdb: unsupported condition ' . $c );
			}
		}
		return $out;
	}

	private static function matches( object $row, array $conds ): bool {
		foreach ( $conds as [ $col, $op, $val ] ) {
			$have = property_exists( $row, $col ) ? $row->$col : null;
			$ok   = match ( $op ) {
				'isnull'  => null === $have,
				'notnull' => null !== $have,
				'='       => null !== $have && (string) $have === (string) $val,
				'<>'      => null !== $have && (string) $have !== (string) $val,
				'<'       => null !== $have && strcmp( (string) $have, (string) $val ) < 0,
				'>'       => null !== $have && strcmp( (string) $have, (string) $val ) > 0,
				'<='      => null !== $have && strcmp( (string) $have, (string) $val ) <= 0,
				'>='      => null !== $have && strcmp( (string) $have, (string) $val ) >= 0,
			};
			if ( ! $ok ) {
				return false;
			}
		}
		return true;
	}

	public function insert( string $table, array $data, $format = null ) {
		global $mock_db_tables, $mock_insert_fail;
		$this->insert_id = 0;
		if ( $mock_insert_fail || ! array_key_exists( $table, $mock_db_tables ) ) {
			return false;
		}
		foreach ( self::UNIQUE[ $table ] ?? [] as $col ) {
			foreach ( $mock_db_tables[ $table ] as $row ) {
				if ( isset( $data[ $col ] ) && (string) ( $row->$col ?? '' ) === (string) $data[ $col ] ) {
					return false;
				}
			}
		}
		$this->auto[ $table ] = max( $this->auto[ $table ] ?? 0, (int) ( array_key_last( $mock_db_tables[ $table ] ) ?? 0 ) ) + 1;
		$id                   = $this->auto[ $table ];
		$mock_db_tables[ $table ][ $id ] = (object) array_merge( [ 'id' => $id ], $data );
		$this->insert_id      = $id;
		return 1;
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ) {
		global $mock_db_tables;
		if ( ! array_key_exists( $table, $mock_db_tables ) ) {
			return false;
		}
		$n = 0;
		foreach ( $mock_db_tables[ $table ] as $row ) {
			$hit = true;
			foreach ( $where as $k => $v ) {
				if ( (string) ( $row->$k ?? '' ) !== (string) $v ) {
					$hit = false;
					break;
				}
			}
			if ( $hit ) {
				foreach ( $data as $k => $v ) {
					$row->$k = $v;
				}
				++$n;
			}
		}
		return $n;
	}

	public function query( string $query ) {
		global $mock_db_tables, $mock_query_fail, $mock_queries;
		$q              = self::norm( $query );
		$mock_queries[] = $q;
		foreach ( $mock_query_fail as $re ) {
			if ( preg_match( $re, $q ) ) {
				return false;
			}
		}
		if ( preg_match( '/^UPDATE (\S+) SET (.+?) WHERE (.+)$/', $q, $m ) ) {
			if ( ! array_key_exists( $m[1], $mock_db_tables ) ) {
				return false;
			}
			$sets = [];
			foreach ( self::split_outside_quotes( $m[2], ', ' ) as $pair ) {
				if ( ! preg_match( '/^(\w+) = (.+)$/s', $pair, $s ) ) {
					throw new RuntimeException( 'mock wpdb: unsupported SET ' . $pair );
				}
				$sets[ $s[1] ] = self::value( $s[2] );
			}
			$conds = self::conds( $m[3] );
			$n     = 0;
			foreach ( $mock_db_tables[ $m[1] ] as $row ) {
				if ( self::matches( $row, $conds ) ) {
					foreach ( $sets as $k => $v ) {
						$row->$k = $v;
					}
					++$n;
				}
			}
			return $n;
		}
		if ( preg_match( '/^DROP TABLE IF EXISTS (\S+)$/', $q, $m ) ) {
			$GLOBALS['mock_dropped_tables'][] = $m[1];
			return 1;
		}
		if ( preg_match( '/^DELETE FROM (\S+) WHERE /', $q ) ) {
			return 0;
		}
		throw new RuntimeException( 'mock wpdb: unexpected query ' . $q );
	}

	public function get_var( string $query ) {
		global $mock_db_tables, $mock_lock_result, $mock_queries;
		$q              = self::norm( $query );
		$mock_queries[] = $q;
		if ( preg_match( "/^SELECT GET_LOCK\( '[^']+', \d+ \)$/", $q ) ) {
			return $mock_lock_result;
		}
		if ( preg_match( "/^SELECT RELEASE_LOCK\( '[^']+' \)$/", $q ) ) {
			return '1';
		}
		if ( preg_match( "/^SHOW TABLES LIKE '(.+)'$/", $q, $m ) ) {
			$name = str_replace( [ '\\_', '\\%' ], [ '_', '%' ], stripslashes( $m[1] ) );
			return array_key_exists( $name, $mock_db_tables ) ? $name : null;
		}
		if ( preg_match( "/^SELECT sha256_audit_seal FROM (\S+) WHERE prev_hash <> '' ORDER BY id DESC LIMIT 1$/", $q, $m ) ) {
			$rows = array_filter( $mock_db_tables[ $m[1] ] ?? [], static fn( $r ) => '' !== (string) $r->prev_hash );
			return [] === $rows ? null : end( $rows )->sha256_audit_seal;
		}
		if ( preg_match( '/^SELECT (COUNT\(\*\)|SUM\((\w+)\)) FROM (\S+)(?: WHERE (.+))?$/', $q, $m ) ) {
			if ( ! array_key_exists( $m[3], $mock_db_tables ) ) {
				return null;
			}
			$conds = isset( $m[4] ) ? self::conds( $m[4] ) : [];
			$rows  = array_filter( $mock_db_tables[ $m[3] ], static fn( $r ) => self::matches( $r, $conds ) );
			return '' === ( $m[2] ?? '' ) ? count( $rows ) : array_sum( array_map( static fn( $r ) => (int) ( $r->{$m[2]} ?? 0 ), $rows ) );
		}
		throw new RuntimeException( 'mock wpdb: unexpected get_var ' . $q );
	}

	public function get_row( string $query, $output = 'OBJECT' ) {
		global $mock_db_tables;
		$q = self::norm( $query );
		// A row another request read before this one changed it (the confirmation race).
		if ( isset( $GLOBALS['mock_stale_row'] ) ) {
			$stale = $GLOBALS['mock_stale_row'];
			unset( $GLOBALS['mock_stale_row'] );
			return ARRAY_A === $output ? (array) $stale : clone $stale;
		}
		if ( preg_match( '/^SELECT \* FROM (\S+) WHERE (.+?)(?: LIMIT 1)?$/', $q, $m ) ) {
			$conds = self::conds( $m[2] );
			foreach ( $mock_db_tables[ $m[1] ] ?? [] as $row ) {
				if ( self::matches( $row, $conds ) ) {
					return ARRAY_A === $output ? (array) $row : clone $row;
				}
			}
			return null;
		}
		throw new RuntimeException( 'mock wpdb: unexpected get_row ' . $q );
	}

	public function get_results( string $query, $output = 'OBJECT' ): array {
		global $mock_db_tables;
		$q = self::norm( $query );
		if ( preg_match( '/^SELECT \* FROM (\S+) ORDER BY id (ASC|DESC)(?: LIMIT (\d+))?$/', $q, $m ) ) {
			$rows = array_values( $mock_db_tables[ $m[1] ] ?? [] );
			if ( 'DESC' === $m[2] ) {
				$rows = array_reverse( $rows );
			}
			if ( isset( $m[3] ) ) {
				$rows = array_slice( $rows, 0, (int) $m[3] );
			}
			return array_map( static fn( $r ) => ARRAY_A === $output ? (array) $r : clone $r, $rows );
		}
		throw new RuntimeException( 'mock wpdb: unexpected get_results ' . $q );
	}

	public function get_col( string $query ): array {
		global $mock_db_tables;
		$q = self::norm( $query );
		if ( preg_match( '/^SELECT comment_ID FROM (\S+) WHERE (.+)$/', $q, $m ) ) {
			$conds = self::conds( $m[2] );
			return array_values( array_map( static fn( $r ) => $r->comment_ID, array_filter( $mock_db_tables[ $m[1] ] ?? [], static fn( $r ) => self::matches( $r, $conds ) ) ) );
		}
		throw new RuntimeException( 'mock wpdb: unexpected get_col ' . $q );
	}
}
$GLOBALS['wpdb'] = new Mock_WPDB_DSGVO();

// ---------------------------------------------------------------------------------------------------
// WordPress function mocks
// ---------------------------------------------------------------------------------------------------
function add_action( string $tag, $cb, int $prio = 10, int $args = 1 ): void {
	$GLOBALS['mock_actions'][ $tag ][] = $cb;
}
function add_filter( string $tag, $cb, int $prio = 10, int $args = 1 ): void {
	$GLOBALS['mock_filters'][ $tag ][] = $cb;
	add_action( $tag, $cb, $prio, $args );
}
function do_action( string $tag, ...$args ): void {
	$GLOBALS['mock_fired_actions'][ $tag ][] = $args;
	foreach ( $GLOBALS['mock_actions'][ $tag ] ?? [] as $cb ) {
		$cb( ...$args );
	}
}
function apply_filters( string $tag, $value, ...$args ) {
	foreach ( $GLOBALS['mock_filters'][ $tag ] ?? [] as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}
function get_option( string $name, $default = false ) {
	return $GLOBALS['mock_options'][ $name ] ?? $default;
}
function update_option( string $name, $value, $autoload = null ): bool {
	$GLOBALS['mock_options'][ $name ]          = $value;
	$GLOBALS['mock_option_autoloads'][ $name ] = $autoload;
	return true;
}
function delete_option( string $name ): bool {
	unset( $GLOBALS['mock_options'][ $name ], $GLOBALS['mock_option_autoloads'][ $name ] );
	return true;
}
function delete_site_option( string $name ): bool {
	unset( $GLOBALS['mock_options'][ $name ], $GLOBALS['mock_option_autoloads'][ $name ] );
	return true;
}
function get_transient( string $transient ) {
	return $GLOBALS['mock_transients'][ $transient ] ?? false;
}
function set_transient( string $transient, $value, int $expiration = 0 ): bool {
	$GLOBALS['mock_transients'][ $transient ] = $value;
	return true;
}
function delete_transient( string $transient ): bool {
	unset( $GLOBALS['mock_transients'][ $transient ] );
	return true;
}
function sanitize_email( $email ): string {
	if ( ! is_scalar( $email ) && null !== $email ) {
		throw new TypeError( 'strlen(): Argument #1 ($string) must be of type string' );
	}
	return filter_var( (string) $email, FILTER_SANITIZE_EMAIL ) ?: '';
}
function sanitize_text_field( $str ): string {
	return is_scalar( $str ) ? trim( strip_tags( (string) $str ) ) : '';
}
function sanitize_key( $key ): string {
	return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function is_email( $email ) {
	return false !== filter_var( (string) $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
}
function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}
function wp_salt( string $scheme = 'auth' ): string {
	return 'mock-salt-' . $scheme;
}
function current_time( string $type, $gmt = 0 ) {
	$ts = $GLOBALS['mock_now'] ?? time();
	return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s', $ts ) : $ts;
}
function admin_url( string $path = '' ): string {
	return 'https://demo.example/wp-admin/' . ltrim( $path, '/' );
}
function rest_url( string $path = '' ): string {
	return 'https://demo.example/wp-json/' . ltrim( $path, '/' );
}
function home_url( string $path = '' ): string {
	return 'https://demo.example' . $path;
}
/** Like core: values are appended as given, not encoded. */
function add_query_arg( array $args, string $url ): string {
	$pairs = [];
	foreach ( $args as $k => $v ) {
		$pairs[] = $k . '=' . $v;
	}
	return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . implode( '&', $pairs );
}
function get_bloginfo( string $show = 'name' ): string {
	return 'Demo Site';
}
function wp_specialchars_decode( string $text, $quote_style = ENT_NOQUOTES ): string {
	return htmlspecialchars_decode( $text, (int) $quote_style );
}
function wp_mail( $to, $subject, $message, $headers = '', $attachments = [] ): bool {
	$GLOBALS['mock_mail'][] = compact( 'to', 'subject', 'message' );
	return true;
}
function esc_url_raw( $url ): string {
	return (string) $url;
}
function esc_url( $url ): string {
	return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
}
function esc_html( $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $text ): string {
	return esc_html( $text );
}
function __( string $text, string $domain = 'default' ): string {
	return $text;
}
function esc_html__( string $text, string $domain = 'default' ): string {
	return esc_html( $text );
}
function esc_attr__( string $text, string $domain = 'default' ): string {
	return esc_html( $text );
}
function esc_html_e( string $text, string $domain = 'default' ): void {
	echo esc_html( $text );
}
function esc_attr_e( string $text, string $domain = 'default' ): void {
	echo esc_html( $text );
}
function dbDelta( $queries = '' ): array {
	if ( ! empty( $GLOBALS['mock_dbdelta_throw'] ) ) {
		throw new RuntimeException( 'dbDelta failed' );
	}
	$GLOBALS['mock_dbdelta_queries'][] = (string) $queries;
	return [];
}
function get_user_by( string $field, $value ) {
	if ( ! empty( $GLOBALS['mock_user_throw'] ) ) {
		throw new RuntimeException( 'user lookup failed' );
	}
	return $GLOBALS['mock_users'][ $value ] ?? false;
}
function wp_delete_user( int $id, $reassign = null ): bool {
	if ( $GLOBALS['mock_delete_fails'] ) {
		return false;
	}
	foreach ( $GLOBALS['mock_users'] as $k => $u ) {
		if ( $u->ID === $id ) {
			unset( $GLOBALS['mock_users'][ $k ] );
			return true;
		}
	}
	return false;
}
function is_multisite(): bool {
	return (bool) $GLOBALS['mock_multisite'];
}
function get_sites( array $args = [] ): array {
	return $GLOBALS['mock_sites'];
}
function switch_to_blog( int $new_blog_id ): bool {
	$prev                                     = (int) $GLOBALS['mock_current_blog_id'];
	$GLOBALS['mock_blogs_options'][ $prev ]   = $GLOBALS['mock_options'];
	$GLOBALS['mock_blogs_autoloads'][ $prev ] = $GLOBALS['mock_option_autoloads'];
	$GLOBALS['mock_blog_stack'][]             = $prev;
	$GLOBALS['mock_current_blog_id']          = $new_blog_id;
	$GLOBALS['mock_options']                  = $GLOBALS['mock_blogs_options'][ $new_blog_id ] ?? [];
	$GLOBALS['mock_option_autoloads']         = $GLOBALS['mock_blogs_autoloads'][ $new_blog_id ] ?? [];
	$prefix                                   = 1 === $new_blog_id ? 'wp_' : "wp_{$new_blog_id}_";
	$GLOBALS['wpdb']->prefix                  = $prefix;
	$GLOBALS['wpdb']->comments                = $prefix . 'comments';
	$GLOBALS['wpdb']->options                 = $prefix . 'options';
	return true;
}
function restore_current_blog(): bool {
	if ( [] === $GLOBALS['mock_blog_stack'] ) {
		return false;
	}
	$curr                                     = (int) $GLOBALS['mock_current_blog_id'];
	$GLOBALS['mock_blogs_options'][ $curr ]   = $GLOBALS['mock_options'];
	$GLOBALS['mock_blogs_autoloads'][ $curr ] = $GLOBALS['mock_option_autoloads'];
	$prev                                     = (int) array_pop( $GLOBALS['mock_blog_stack'] );
	$GLOBALS['mock_current_blog_id']          = $prev;
	$GLOBALS['mock_options']                  = $GLOBALS['mock_blogs_options'][ $prev ] ?? [];
	$GLOBALS['mock_option_autoloads']         = $GLOBALS['mock_blogs_autoloads'][ $prev ] ?? [];
	$prefix                                   = 1 === $prev ? 'wp_' : "wp_{$prev}_";
	$GLOBALS['wpdb']->prefix                  = $prefix;
	$GLOBALS['wpdb']->comments                = $prefix . 'comments';
	$GLOBALS['wpdb']->options                 = $prefix . 'options';
	return true;
}
function is_plugin_active_for_network( string $plugin ): bool {
	return in_array( $plugin, $GLOBALS['mock_network_active_plugins'], true );
}
function clean_comment_cache( $ids ): void {
	$GLOBALS['mock_cleaned_comments'] = $ids;
}
function number_format_i18n( $n ): string {
	return number_format( (float) $n );
}
function wp_json_encode( $data ) {
	return json_encode( $data );
}
function wp_create_nonce( $action = -1 ): string {
	return 'nonce';
}
function add_shortcode( string $tag, $cb ): void {}
function register_rest_route( ...$args ): bool {
	$ns    = (string) ( $args[0] ?? '' );
	$route = (string) ( $args[1] ?? '' );
	$GLOBALS['mock_rest_routes'][ $ns . $route ] = $args[2] ?? [];
	return true;
}
function current_user_can( string $cap ): bool {
	return 'manage_options' === $cap ? (bool) ( $GLOBALS['mock_can_manage_options'] ?? true ) : true;
}
function register_setting( string $group, string $name, array $args = [] ): void {
	$GLOBALS['mock_registered_settings'][ $name ] = [ 'group' => $group, 'args' => $args ];
}
function settings_fields( string $group ): void {
	echo '<input type="hidden" name="option_page" value="' . esc_attr( $group ) . '" />';
}
function wp_register_script( string $handle, string $src, array $deps = [], $ver = false, $in_footer = false ): bool {
	$GLOBALS['mock_registered_scripts'][ $handle ] = compact( 'src', 'deps', 'ver', 'in_footer' );
	return true;
}
function wp_set_script_translations( string $handle, string $domain = 'default', string $path = '' ): bool {
	$GLOBALS['mock_script_translations'][ $handle ] = compact( 'domain', 'path' );
	return true;
}
function register_block_type( $block_type, array $args = [] ) {
	$GLOBALS['mock_registered_blocks'][] = [ 'block' => $block_type, 'args' => $args ];
	return true;
}
function register_block_bindings_source( string $name, array $props ): bool {
	$GLOBALS['mock_block_bindings'][ $name ] = $props;
	return true;
}
function wp_clear_scheduled_hook( string $hook, array $args = [] ): int {
	$GLOBALS['mock_cleared_cron'][] = $hook;
	return 1;
}
function wp_next_scheduled( string $hook, array $args = [] ) {
	return false;
}
function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = [] ): bool {
	return true;
}
function load_plugin_textdomain( string $domain, $deprecated = false, string $plugin_rel_path = '' ): bool {
	return true;
}
function plugin_basename( string $file ): string {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}
function plugin_dir_path( string $file ): string {
	return dirname( $file ) . '/';
}
function plugin_dir_url( string $file ): string {
	return 'https://demo.example/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
}
function register_activation_hook( string $file, $callback ): void {
	$GLOBALS['mock_activation_hooks'][ $file ] = $callback;
}
function register_deactivation_hook( string $file, $callback ): void {
	$GLOBALS['mock_deactivation_hooks'][ $file ] = $callback;
}
function is_admin(): bool {
	return true;
}

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public $data = '' ) {}
	public function get_error_code(): string {
		return $this->code;
	}
	public function get_error_message(): string {
		return $this->message;
	}
	public function get_error_data() {
		return $this->data;
	}
}

class GenerateBlocks_Register_Dynamic_Tag {
	public function __construct( array $args ) {
		$GLOBALS['mock_gb_tags'][ (string) ( $args['tag'] ?? '' ) ] = $args;
	}
}

final class WM_CLI_Exit extends RuntimeException {}
final class WP_CLI {
	public static array $lines = [];
	public static function line( string $m ): void {
		self::$lines[] = $m;
	}
	public static function success( string $m ): void {
		self::$lines[] = 'Success: ' . $m;
	}
	public static function warning( string $m ): void {
		self::$lines[] = 'Warning: ' . $m;
	}
	public static function error( string $m ): void {
		self::$lines[] = 'Error: ' . $m;
		throw new WM_CLI_Exit( $m );
	}
	public static function colorize( string $m ): string {
		return $m;
	}
}
class WP_REST_Server {
	public const READABLE  = 'GET';
	public const CREATABLE = 'POST';
}
class WP_REST_Request {
	private array $params = [];
	public function __construct( private string $method = 'GET', private string $route = '' ) {}
	public function set_param( string $k, $v ): void {
		$this->params[ $k ] = $v;
	}
	public function get_param( string $k ) {
		return $this->params[ $k ] ?? null;
	}
}
class WP_REST_Response {
	public array $headers = [];
	public function __construct( public $data = null, public int $status = 200 ) {}
	public function get_status(): int {
		return $this->status;
	}
	public function get_data() {
		return $this->data;
	}
	public function header( string $k, string $v ): void {
		$this->headers[ $k ] = $v;
	}
}

require_once __DIR__ . '/stubs/hub-interface.php';
require_once ABSPATH . 'wm-dsgvo-privacy-eraser.php';

foreach ( [
	'Database/Schema', 'Crypto/RequestCipher', 'Crypto/ErasureCertificateGenerator', 'Request/ErasureRequestManager',
	'Eraser/TransversalDataEraser', 'Request/ErasureConfirmation', 'Adapters/Privacy_Eraser_Spoke_Adapter',
	'Admin/AdminDashboard', 'Frontend/ErasureRequestForm', 'Frontend/ErasureConfirmationPage',
	'Frontend/EcosystemIntegration', 'API/RestController', 'CLI/PrivacyEraserCommand', 'Core/Plugin',
] as $wm_file ) {
	require_once ABSPATH . 'includes/' . $wm_file . '.php';
}

use WMCyber\DSGVO\Eraser\Adapters\Privacy_Eraser_Spoke_Adapter;
use WMCyber\DSGVO\Eraser\Admin\AdminDashboard;
use WMCyber\DSGVO\Eraser\API\RestController;
use WMCyber\DSGVO\Eraser\CLI\PrivacyEraserCommand;
use WMCyber\DSGVO\Eraser\Core\Plugin;
use WMCyber\DSGVO\Eraser\Crypto\ErasureCertificateGenerator;
use WMCyber\DSGVO\Eraser\Crypto\RequestCipher;
use WMCyber\DSGVO\Eraser\Eraser\TransversalDataEraser;
use WMCyber\DSGVO\Eraser\Frontend\EcosystemIntegration;
use WMCyber\DSGVO\Eraser\Frontend\ErasureConfirmationPage;
use WMCyber\DSGVO\Eraser\Frontend\ErasureRequestForm;
use WMCyber\DSGVO\Eraser\Request\ErasureConfirmation;
use WMCyber\DSGVO\Eraser\Request\ErasureRequestManager;

// ---------------------------------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------------------------------
$GLOBALS['passed_count'] = 0;
$GLOBALS['failed_count'] = 0;

function print_results(): void {
	echo "\n==============================================================================\n";
	echo "  TEST SUITE RESULTS: {$GLOBALS['passed_count']} PASSED | {$GLOBALS['failed_count']} FAILED\n";
	echo "==============================================================================\n\n";
}

set_exception_handler( static function ( Throwable $e ): void {
	++$GLOBALS['failed_count'];
	echo '  [FAIL] uncaught ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() . "\n";
	print_results();
	exit( 1 );
} );

function assert_test( string $name, bool $condition, string $details = '' ): void {
	if ( $condition ) {
		echo "  [PASS] {$name}\n";
		++$GLOBALS['passed_count'];
	} else {
		echo "  [FAIL] {$name}" . ( '' !== $details ? ": {$details}" : '' ) . "\n";
		++$GLOBALS['failed_count'];
	}
}

function reset_db(): void {
	$GLOBALS['wpdb'] = new Mock_WPDB_DSGVO(); // Fresh auto-increment counters, so ids start at 1 again.
	$GLOBALS['mock_db_tables'] = [
		'wp_wm_dsgvo_erasure_certificates' => [],
		'wp_wm_dsgvo_requests'             => [],
		'wp_comments'                      => [],
	];
	$GLOBALS['mock_options']                = [];
	$GLOBALS['mock_option_autoloads']       = [];
	$GLOBALS['mock_blogs_options']          = [];
	$GLOBALS['mock_blogs_autoloads']        = [];
	$GLOBALS['mock_current_blog_id']        = 1;
	$GLOBALS['mock_blog_stack']             = [];
	$GLOBALS['mock_sites']                  = [ 1 ];
	$GLOBALS['mock_network_active_plugins'] = [];
	$GLOBALS['mock_dbdelta_queries']        = [];
	$GLOBALS['mock_dbdelta_throw']          = false;
	$GLOBALS['mock_users']                  = [];
	$GLOBALS['mock_mail']         = [];
	$GLOBALS['mock_lock_result']  = '1';
	$GLOBALS['mock_query_fail']   = [];
	$GLOBALS['mock_insert_fail']  = false;
	$GLOBALS['mock_multisite']    = false;
	$GLOBALS['mock_now']          = null;
	$GLOBALS['mock_delete_fails'] = false;
}

function certs(): array {
	return $GLOBALS['mock_db_tables']['wp_wm_dsgvo_erasure_certificates'];
}

function cert( int $id ): object {
	return $GLOBALS['mock_db_tables']['wp_wm_dsgvo_erasure_certificates'][ $id ];
}

function add_comment( int $id, string $email ): void {
	$GLOBALS['mock_db_tables']['wp_comments'][ $id ] = (object) [
		'id'                   => $id,
		'comment_ID'           => $id,
		'comment_author'       => 'Max Mustermann',
		'comment_author_email' => $email,
		'comment_author_url'   => 'https://example.test',
		'comment_author_IP'    => '198.51.100.7',
		'comment_agent'        => 'Mozilla/5.0 (Test)',
		'user_id'              => 42,
		'comment_content'      => 'Hallo',
	];
}

function add_user( string $email, int $id, string $role = 'subscriber' ): void {
	$GLOBALS['mock_users'][ $email ] = (object) [ 'ID' => $id, 'user_email' => $email, 'roles' => [ $role ] ];
}

/** A pending request as create() stores it, returning [ uuid, raw token ]. */
function pending_request( string $email ): array {
	$GLOBALS['mock_mail'] = [];
	ErasureRequestManager::create( $email );
	$row = end( $GLOBALS['mock_db_tables']['wp_wm_dsgvo_requests'] );
	preg_match( '/wm_erasure_token=([a-f0-9]{64})/', (string) ( end( $GLOBALS['mock_mail'] )['message'] ?? '' ), $m );
	return [ (string) $row->request_uuid, (string) ( $m[1] ?? '' ) ];
}

$emoji = '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}]/u';

echo "==============================================================================\n";
echo "  WM DSGVO PRIVACY ERASER — STANDALONE TEST SUITE\n";
echo "==============================================================================\n\n";

// ---------------------------------------------------------------------------------------------------
echo "[TEST GROUP 1] Address envelope for pending requests\n";
$plain     = 'max.mustermann@privacy-test.de';
$encrypted = RequestCipher::encrypt( $plain );
assert_test( 'encrypts with the wmv1: prefix and decrypts back', str_starts_with( $encrypted, 'wmv1:' ) && RequestCipher::decrypt( $encrypted ) === $plain && ! str_contains( $encrypted, 'mustermann' ) );
assert_test( 'a pre-1.2.0 clear-text row stays readable', 'legacy@old-schema.de' === RequestCipher::decrypt( 'legacy@old-schema.de' ) );
assert_test( 'a corrupt envelope returns empty, not garbage', '' === RequestCipher::decrypt( 'wmv1:' . base64_encode( 'not an envelope' ) ) );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 2] Request and mailed link\n";
reset_db();
$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
assert_test( 'blind() is a 64-hex keyed hash', 64 === strlen( ErasureRequestManager::blind( $plain ) ) && ctype_xdigit( ErasureRequestManager::blind( $plain ) ) );
[ $uuid, $token ] = pending_request( $plain );
$req_row          = end( $GLOBALS['mock_db_tables']['wp_wm_dsgvo_requests'] );
assert_test( 'a request is stored pending with the token as a hash and the address encrypted', 'pending' === $req_row->status && hash( 'sha256', $token ) === $req_row->token_hash && str_starts_with( (string) $req_row->email_enc, 'wmv1:' ) );
$mail = (string) ( $GLOBALS['mock_mail'][0]['message'] ?? '' );
assert_test( 'the mail links the confirmation page, not the REST route that used to erase on GET', str_contains( $mail, 'https://demo.example/?wm_dsgvo_erasure=confirm&wm_erasure_request=' . $uuid . '&wm_erasure_token=' . $token ) && ! str_contains( $mail, 'wp-json' ) );
for ( $i = 0; $i < 3; $i++ ) {
	ErasureRequestManager::create( $plain );
}
assert_test( 'the address limit stops the fourth request within 24 hours', 3 === count( $GLOBALS['mock_db_tables']['wp_wm_dsgvo_requests'] ) );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 3] Certificate chain\n";
reset_db();
$gen = new ErasureCertificateGenerator();
$c1  = $gen->issue_certificate( 'user1@test.de', 2, [ 'wp_comments' => 2 ] );
$c2  = $gen->issue_certificate( 'user2@test.de', 5, [ 'wp_comments' => 4, 'wp_users' => 1 ] );
$c3  = $gen->issue_certificate( 'user3@test.de', 1, [ 'wp_comments' => 1 ] );
assert_test( 'the first record chains onto the genesis constant, the next ones onto their predecessor', ErasureCertificateGenerator::GENESIS_HASH === $c1['prev_hash'] && $c1['sha256_audit_seal'] === $c2['prev_hash'] && $c2['sha256_audit_seal'] === $c3['prev_hash'] );
$intact = $gen->verify_chain_integrity();
assert_test( 'an untouched chain verifies INTACT with three checked', 'INTACT' === $intact['status'] && 3 === $intact['checked'], json_encode( $intact ) );
$row2                       = cert( 2 );
$row2->purged_records_count = 999;
$edited                     = $gen->verify_chain_integrity();
assert_test( 'an edited count is BROKEN at that row', 'BROKEN' === $edited['status'] && 2 === $edited['broken_node'], json_encode( $edited ) );
$row2->purged_records_count = 5;
assert_test( 'restoring the value verifies INTACT again', 'INTACT' === $gen->verify_chain_integrity()['status'] );
$original_id                = $row2->certificate_id;
$row2->purged_records_count = 999;
$row2->certificate_id       = 'ERASE-' . substr( ErasureCertificateGenerator::record_hash( $row2->blinded_email_hmac, 999, $row2->purged_tables_summary, $row2->erased_at ), 0, 16 );
$rederived                  = $gen->verify_chain_integrity();
$row2->purged_records_count = 5;
$row2->certificate_id       = $original_id;
assert_test( 'an edited count with a re-derived certificate id is still BROKEN: the seal needs the key', 'BROKEN' === $rederived['status'] && 2 === $rederived['broken_node'], json_encode( $rederived ) );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 4] Erasure of comments and account\n";
reset_db();
add_comment( 101, 'max@purge-target.de' );
add_user( 'max@purge-target.de', 42 );
$eraser = new TransversalDataEraser();
$purge  = $eraser->execute_erasure( 'max@purge-target.de' );
assert_test( 'comments anonymised, subscriber account deleted, two entries recorded', true === $purge['success'] && 2 === $purge['total_purged'] && 1 === $purge['tables']['wp_comments'] && 1 === $purge['tables']['wp_users'] && ! get_user_by( 'email', 'max@purge-target.de' ), json_encode( $purge['tables'] ) );
assert_test( 'the comment carries the replacement values', 'Anonymisiert' === $GLOBALS['mock_db_tables']['wp_comments'][101]->comment_author && TransversalDataEraser::ANON_EMAIL === $GLOBALS['mock_db_tables']['wp_comments'][101]->comment_author_email && '0.0.0.0' === $GLOBALS['mock_db_tables']['wp_comments'][101]->comment_author_IP );
assert_test( 'with the switch off WooCommerce orders are recorded as skipped_switch_off', 'skipped_switch_off' === $purge['tables']['woocommerce_orders'], json_encode( $purge['tables'] ) );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 5] Hub adapter\n";
$spoke = new Privacy_Eraser_Spoke_Adapter();
assert_test( 'slug', 'wm-dsgvo-privacy-eraser' === $spoke->get_module_slug() );
assert_test( 'HEALTHY with tables, an intact chain and libsodium', 'HEALTHY' === $spoke->get_health_status(), $spoke->get_health_status() );
$sbom = $spoke->get_sbom_data();
assert_test( 'SBOM component with the main file hash', 'pkg:wordpress/wm-dsgvo-privacy-eraser@' . WM_DSGVO_ERASER_VERSION === $sbom['bom-ref'] && ! empty( $sbom['hashes'][0]['content'] ) );
$verify_action = $spoke->execute_action( 'verify_chain' );
assert_test( 'verify_chain returns a structured result', true === $verify_action['success'] && str_contains( $verify_action['message'], 'Nachweiskette geprüft' ), $verify_action['message'] );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 6] Adapter health from tables, chain and libsodium\n";
$telemetry = $spoke->get_telemetry_metrics();
assert_test( 'telemetry reports tables, chain error and address encryption', true === $telemetry['tables_present'] && array_key_exists( 'chain_error', $telemetry ) && 'libsodium' === $telemetry['address_encryption'] );
$saved_requests = $GLOBALS['mock_db_tables']['wp_wm_dsgvo_requests'];
unset( $GLOBALS['mock_db_tables']['wp_wm_dsgvo_requests'] );
assert_test( 'a missing requests table is ERROR', 'ERROR' === $spoke->get_health_status() );
$GLOBALS['mock_db_tables']['wp_wm_dsgvo_requests'] = $saved_requests;
$last_row                       = cert( (int) array_key_last( certs() ) );
$last_count                     = $last_row->purged_records_count;
$last_row->purged_records_count = 77;
assert_test( 'a broken chain is ERROR', 'ERROR' === $spoke->get_health_status() );
$last_row->purged_records_count = $last_count;
assert_test( 'SBOM properties name features, not compliance', ! str_contains( (string) json_encode( $sbom['properties'] ), 'compliance' ) );
assert_test( 'without libsodium health is DEGRADED', (bool) preg_match( "/RequestCipher::is_available\(\)\s*\?\s*'HEALTHY'\s*:\s*'DEGRADED'/", (string) file_get_contents( ABSPATH . 'includes/Adapters/Privacy_Eraser_Spoke_Adapter.php' ) ) );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 7] Administrator accounts and record wording\n";
add_user( 'admin@purge-target.de', 1, 'administrator' );
$admin_result = $eraser->execute_erasure( 'admin@purge-target.de' );
assert_test( 'an administrator account is kept and recorded as skipped_administrator', 'skipped_administrator' === ( $admin_result['tables']['wp_users'] ?? null ) && false !== get_user_by( 'email', 'admin@purge-target.de' ) );
assert_test( 'the payload carries record_status, not a legal_status verdict', ! isset( $admin_result['certificate']['legal_status'] ) && 'ERASURE_EXECUTED_AND_CHAINED' === ( $admin_result['certificate']['record_status'] ?? null ) );
assert_test( 'the skipped account is part of the sealed summary', str_contains( (string) end( $GLOBALS['mock_db_tables']['wp_wm_dsgvo_erasure_certificates'] )->purged_tables_summary, 'wp_users: skipped_administrator' ) );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 8] Admin screen\n";
$dashboard = AdminDashboard::instance();
$pages     = [];
foreach ( [ 'overview', 'request', 'certificates', 'limits', 'settings' ] as $tab ) {
	$_GET = [ 'tab' => $tab ];
	ob_start();
	$dashboard->render_dashboard();
	$pages[ $tab ] = (string) ob_get_clean();
}
ob_start();
$dashboard->render_dashboard_widget();
$widget = (string) ob_get_clean();
$_GET   = [];
$all    = implode( '', $pages ) . $widget;
$newest = end( $GLOBALS['mock_db_tables']['wp_wm_dsgvo_erasure_certificates'] );
assert_test( 'the records tab shows the hash and erased_at of the real rows', str_contains( $pages['certificates'], substr( $newest->blinded_email_hmac, 0, 16 ) ) && str_contains( $pages['certificates'], (string) $newest->erased_at ) );
assert_test( 'the overview shows the measured chain', str_contains( $pages['overview'], 'Kette geprüft:' ) );
$first_row                       = cert( 1 );
$first_count                     = $first_row->purged_records_count;
$first_row->purged_records_count = 12345;
$_GET                            = [ 'tab' => 'overview' ];
ob_start();
$dashboard->render_dashboard();
$broken_page = (string) ob_get_clean();
$_GET        = [];
$first_row->purged_records_count = $first_count;
assert_test( 'a broken chain is shown as broken', str_contains( $broken_page, 'Kette gebrochen' ) && str_contains( $broken_page, 'wm-status-error' ) );
assert_test( 'no emoji, no fixed verdicts on screen and widget', 0 === preg_match( $emoji, $all ) && ! preg_match( '/INMUTABLES|INTACTA|Oficial|Borrado|PDF|Garant/', $all ) );
assert_test( 'the request tab posts to /request and says nothing is erased yet', ( str_contains( $pages['request'], 'wm-dsgvo-eraser/v1/request' ) || str_contains( $pages['request'], 'wm-dsgvo-eraser\/v1\/request' ) ) && str_contains( $pages['request'], trim( (string) json_encode( 'Noch ist nichts gelöscht.' ), '"' ) ) && ! str_contains( $pages['request'], 'innerHTML' ) );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 9] Public form\n";
$GLOBALS['mock_actions']['wp_footer'] = [];
$form = ( new ErasureRequestForm() )->render_shortcode();
ob_start();
foreach ( $GLOBALS['mock_actions']['wp_footer'] as $cb ) {
	$cb();
}
$footer = (string) ob_get_clean();
assert_test( 'the address field has a label for', (bool) preg_match( '/<label for="wm-erasure-email"/', $form ) && 0 === preg_match( $emoji, $form ) );
assert_test( 'the script writes messages with textContent, never innerHTML', str_contains( $footer, 'textContent' ) && ! str_contains( $footer, 'innerHTML' ) );
$texts = (string) file_get_contents( ABSPATH . 'includes/API/RestController.php' ) . (string) file_get_contents( ABSPATH . 'includes/Request/ErasureRequestManager.php' );
assert_test( 'REST and mail texts use real umlauts', 0 === preg_match( '/Bestaetig|Loesch|gueltig|Fuer |ausgefuehrt|geloescht/', $texts ) );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 10] Uninstall\n";
$uninstall = '';
foreach ( token_get_all( (string) file_get_contents( ABSPATH . 'uninstall.php' ) ) as $tok ) {
	if ( is_array( $tok ) && in_array( $tok[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
		continue;
	}
	$uninstall .= is_array( $tok ) ? $tok[1] : $tok;
}
assert_test( 'uninstall clears the scheduled sweep', str_contains( $uninstall, "'" . \WMCyber\DSGVO\Eraser\Core\Plugin::CRON_HOOK . "'" ) && ! str_contains( $uninstall, 'wm_dsgvo_daily_sweep' ) );
$written = [ ErasureCertificateGenerator::TIP_OPTION ];
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( ABSPATH . 'includes', FilesystemIterator::SKIP_DOTS ) ) as $file ) {
	preg_match_all( "/(?:update_option|get_option)\(\s*'([^']+)'/", (string) file_get_contents( (string) $file ), $m );
	$written = array_merge( $written, $m[1] );
}
$missing = array_values( array_filter( array_unique( $written ), static fn( string $o ): bool => ! str_contains( $uninstall, "'{$o}'" ) ) );
assert_test( 'every option the code reads or writes is deleted on uninstall', [] === $missing, implode( ', ', $missing ) );
assert_test( 'uninstall drops the tables through %i', 2 === substr_count( $uninstall, "prepare( 'DROP TABLE IF EXISTS %i'" ) );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 11] Reader pass: the chain (D6, D7, D8, D9, D10, D17)\n";
reset_db();
foreach ( [ 'a', 'b', 'c' ] as $i => $letter ) {
	$gen->issue_certificate( "{$letter}@test.de", $i + 1, [ 'wp_comments' => $i + 1 ] );
}
$GLOBALS['mock_db_tables']['wp_wm_dsgvo_erasure_certificates'] = [];
$emptied = $gen->verify_chain_integrity();
assert_test( 'D6: every row deleted while a tip is recorded is BROKEN (EMPTY_CHAIN, shown green, until 2026-09-15)', 'BROKEN' === $emptied['status'], json_encode( $emptied ) );
reset_db();
unset( $GLOBALS['mock_db_tables']['wp_wm_dsgvo_erasure_certificates'] );
assert_test( 'D6: a missing certificate table is BROKEN, also without a recorded tip', 'BROKEN' === $gen->verify_chain_integrity()['status'] );
reset_db();
$gen->issue_certificate( 'notip@test.de', 1, [ 'wp_comments' => 1 ] );
delete_option( ErasureCertificateGenerator::TIP_OPTION );
assert_test( 'sealed rows without the recorded tip are BROKEN (the option was removed)', 'BROKEN' === $gen->verify_chain_integrity()['status'] );
reset_db();
assert_test( 'D6: a fresh install without rows or tip is EMPTY_CHAIN', 'EMPTY_CHAIN' === $gen->verify_chain_integrity()['status'] );

foreach ( [ 'a', 'b', 'c' ] as $i => $letter ) {
	$gen->issue_certificate( "{$letter}@test.de", $i + 1, [ 'wp_comments' => $i + 1 ] );
}
unset( $GLOBALS['mock_db_tables']['wp_wm_dsgvo_erasure_certificates'][3] );
$truncated = $gen->verify_chain_integrity();
$gen->issue_certificate( 'd@test.de', 4, [ 'wp_comments' => 4 ] );
$after_next = $gen->verify_chain_integrity();
assert_test( 'D7: removing the newest row stays BROKEN after the next erasure (INTACT again until 2026-09-15)', 'BROKEN' === $truncated['status'] && 'BROKEN' === $after_next['status'] && 4 === $after_next['broken_node'], json_encode( [ $truncated, $after_next ] ) );

reset_db();
$gen->issue_certificate( 'e@test.de', 1, [ 'wp_comments' => 1 ] );
$forged_row                 = cert( 1 );
$forged_row->certificate_id = 'ERASE-forged00000000';
$forged = $gen->verify_chain_integrity();
assert_test( 'D8: an edited certificate_id is BROKEN (INTACT until 2026-09-15)', 'BROKEN' === $forged['status'] && 1 === $forged['broken_node'], json_encode( $forged ) );

reset_db();
$gen->issue_certificate( 'f@test.de', 1, [ 'wp_comments' => 1 ] );
$tip_before                  = get_option( ErasureCertificateGenerator::TIP_OPTION );
$GLOBALS['mock_lock_result'] = '0';
$no_lock                     = $gen->issue_certificate( 'g@test.de', 1, [ 'wp_comments' => 1 ] );
$GLOBALS['mock_lock_result'] = '1';
assert_test( 'D9: without the chain lock nothing is appended and the tip stays (appended anyway until 2026-09-15)', isset( $no_lock['error'] ) && 1 === count( certs() ) && $tip_before === get_option( ErasureCertificateGenerator::TIP_OPTION ), json_encode( $no_lock ) );

$GLOBALS['mock_now'] = 1789000000;
$first               = $gen->issue_certificate( 'same@test.de', 0, [ 'wp_comments' => 0 ] );
$tip_after_first     = get_option( ErasureCertificateGenerator::TIP_OPTION );
$duplicate           = $gen->issue_certificate( 'same@test.de', 0, [ 'wp_comments' => 0 ] );
$GLOBALS['mock_now'] = null;
assert_test( 'D10: a refused insert (same id in the same second) returns an error and leaves the tip (moved until 2026-09-15)', ! isset( $first['error'] ) && isset( $duplicate['error'] ) && $tip_after_first === get_option( ErasureCertificateGenerator::TIP_OPTION ) && 'INTACT' === $gen->verify_chain_integrity()['status'], json_encode( $duplicate ) );

require_once __DIR__ . '/stubs/consent-vault-engine.php';
$GLOBALS['mock_vault_anchors'] = [];
$anchored                      = $gen->issue_certificate( 'h@test.de', 1, [ 'wp_comments' => 1 ] );
$GLOBALS['mock_vault_throw']   = true;
try {
	$vault_failed = $gen->issue_certificate( 'i@test.de', 1, [ 'wp_comments' => 1 ] );
	$vault_ok     = isset( $vault_failed['certificate_id'] );
} catch ( Throwable $e ) {
	$vault_ok = false;
}
$GLOBALS['mock_vault_throw'] = false;
assert_test( 'D17: the seal is anchored in the Consent Vault, and a TypeError there does not escape (it did until 2026-09-15)', [ $anchored['certificate_id'], $anchored['sha256_audit_seal'] ] === ( $GLOBALS['mock_vault_anchors'][0] ?? null ) && $vault_ok );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 12] Reader pass: erasure results (D1, D2, D3, D14, D15)\n";
reset_db();
add_comment( 7, 'shop@test.de' );
update_option( 'wm_dsgvo_anonymize_wc_orders', 1 );
$no_woo = $eraser->execute_erasure( 'shop@test.de' );
assert_test( 'D14: switch on without WooCommerce is recorded as skipped_woocommerce_inactive (no entry until 2026-09-15)', 'skipped_woocommerce_inactive' === ( $no_woo['tables']['woocommerce_orders'] ?? null ) );
assert_test( 'D14: no statute is written into a record as a reason (skipped_retention_sec_147_AO until 2026-09-15)', ! preg_match( '/147|AO|HGB/', implode( ' ', array_map( static fn( $r ) => (string) $r->purged_tables_summary, certs() ) ) ) );
assert_test( 'D15: comment user agent and user id are cleared, like core\'s own eraser', '' === $GLOBALS['mock_db_tables']['wp_comments'][7]->comment_agent && 0 === $GLOBALS['mock_db_tables']['wp_comments'][7]->user_id && [ 7 ] === ( $GLOBALS['mock_cleaned_comments'] ?? null ) );

require_once __DIR__ . '/stubs/woocommerce-orders.php';
$GLOBALS['mock_wc_orders'] = [ new Mock_WC_Order( 501, 'shop@test.de' ), new Mock_WC_Order( 502, 'shop@test.de' ), new Mock_WC_Order( 503, 'other@test.de' ) ];
$woo                       = $eraser->execute_erasure( 'shop@test.de' );
$o501                      = $GLOBALS['mock_wc_orders'][0];
assert_test( 'D1: both orders of the address get the anonymised billing fields and are saved (UPDATE on columns wc_orders lacks until 2026-09-15)', 2 === $woo['tables']['woocommerce_orders'] && 'Anonym' === $o501->billing_first_name && 'Kunde' === $o501->billing_last_name && 'Gelöscht' === $o501->billing_address_1 && '' === $o501->billing_phone && TransversalDataEraser::ANON_EMAIL === $o501->billing_email && 1 === $o501->saves && 'other@test.de' === $GLOBALS['mock_wc_orders'][2]->billing_email && -1 === ( $GLOBALS['mock_wc_queries'][0]['limit'] ?? null ), json_encode( $woo['tables'] ) );
$GLOBALS['mock_wc_orders'] = [ new Mock_WC_Order( 601, 'fail@test.de', 0 ) ];
$woo_failed                = $eraser->execute_erasure( 'fail@test.de' );
assert_test( 'D1: an order that does not save is recorded as failed and the erasure is not a success', false === $woo_failed['success'] && str_starts_with( (string) $woo_failed['tables']['woocommerce_orders'], 'failed 1 of 1' ) && in_array( 'woocommerce_orders', $woo_failed['failed_areas'], true ) && 'ERASURE_INCOMPLETE_AND_CHAINED' === ( $woo_failed['certificate']['record_status'] ?? '' ) );
delete_option( 'wm_dsgvo_anonymize_wc_orders' );

reset_db();
add_comment( 8, 'refused@test.de' );
$GLOBALS['mock_query_fail'] = [ '/^UPDATE wp_comments /' ];
$refused                    = $eraser->execute_erasure( 'refused@test.de' );
$GLOBALS['mock_query_fail'] = [];
assert_test( 'D2: a refused comment UPDATE is recorded as failed, not as the SELECT count (counted until 2026-09-15)', false === $refused['success'] && 'failed' === $refused['tables']['wp_comments'] && 0 === $refused['total_purged'] && 'Max Mustermann' === $GLOBALS['mock_db_tables']['wp_comments'][8]->comment_author, json_encode( $refused ) );
add_user( 'stays@test.de', 77 );
$GLOBALS['mock_delete_fails'] = true;
$not_deleted                  = $eraser->execute_erasure( 'stays@test.de' );
$GLOBALS['mock_delete_fails'] = false;
assert_test( 'D2: wp_delete_user() returning false is recorded as failed (wp_users: 1 until 2026-09-15)', false === $not_deleted['success'] && 'failed' === $not_deleted['tables']['wp_users'] );
add_user( 'network@test.de', 78 );
$GLOBALS['mock_multisite'] = true;
$network                   = $eraser->execute_erasure( 'network@test.de' );
$GLOBALS['mock_multisite'] = false;
assert_test( 'D3: on multisite the record says removed_from_this_site, not a deletion (wp_users: 1 until 2026-09-15)', true === $network['success'] && 'removed_from_this_site' === $network['tables']['wp_users'] );
$GLOBALS['mock_lock_result'] = '0';
$unrecorded                  = $eraser->execute_erasure( 'nobody@test.de' );
$GLOBALS['mock_lock_result'] = '1';
assert_test( 'D9: an erasure whose record was not written is not a success', false === $unrecorded['success'] && null === $unrecorded['certificate'] && str_contains( (string) $unrecorded['error'], 'record was not written' ) );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 13] Reader pass: confirmation (D4, D5)\n";
reset_db();
add_user( 'editor@test.de', 90, 'editor' );
[ $uuid, $token ] = pending_request( 'editor@test.de' );
$rest             = new RestController();
$get              = new WP_REST_Request( 'GET', '/wm-dsgvo-eraser/v1/confirm' );
$get->set_param( 'request', $uuid );
$get->set_param( 'token', $token );
$redirect = $rest->handle_confirm( $get );
$req_row  = end( $GLOBALS['mock_db_tables']['wp_wm_dsgvo_requests'] );
assert_test( 'D4: a GET of the REST link redirects to the confirmation page and erases nothing (erased on GET until 2026-09-15)', 302 === $redirect->get_status() && str_contains( (string) ( $redirect->headers['Location'] ?? '' ), '?wm_dsgvo_erasure=confirm&wm_erasure_request=' . $uuid ) && false !== get_user_by( 'email', 'editor@test.de' ) && 'pending' === $req_row->status && [] === certs() );
assert_test( 'D4: opening the page resolves the request without erasing', true === ErasureConfirmation::pending( $uuid, $token ) && false !== get_user_by( 'email', 'editor@test.de' ) && 'pending' === end( $GLOBALS['mock_db_tables']['wp_wm_dsgvo_requests'] )->status );
$ask = ErasureConfirmationPage::markup( 'ask', $uuid, $token, null );
assert_test( 'D4: the page offers a POST button carrying the token', str_contains( $ask, '<form method="post"' ) && str_contains( $ask, 'name="wm_erasure_token" value="' . $token . '"' ) && str_contains( $ask, 'hat noch nichts gelöscht' ) );
$done = ErasureConfirmation::run( $uuid, $token );
$req_row = end( $GLOBALS['mock_db_tables']['wp_wm_dsgvo_requests'] );
assert_test( 'D4: the POST erases and closes the request', 'done' === $done['state'] && false === get_user_by( 'email', 'editor@test.de' ) && 'processed' === $req_row->status && null === $req_row->email_enc && null === $req_row->token_hash, json_encode( $done['state'] ) );
assert_test( 'D4: the result page names the record', str_contains( ErasureConfirmationPage::markup( 'done', $uuid, $token, $done['result'] ), (string) $done['result']['certificate']['certificate_id'] ) );
assert_test( 'D5: the same link cannot erase twice', 'invalid' === ErasureConfirmation::run( $uuid, $token )['state'] && 1 === count( certs() ) );

[ $uuid_w, $token_w ] = pending_request( 'wrong@test.de' );
assert_test( 'a wrong token is invalid and leaves the request pending', 'invalid' === ErasureConfirmation::run( $uuid_w, str_repeat( 'a', 64 ) )['state'] && true === ErasureConfirmation::pending( $uuid_w, $token_w ) );
foreach ( $GLOBALS['mock_db_tables']['wp_wm_dsgvo_requests'] as $r ) {
	if ( $r->request_uuid === $uuid_w ) {
		$r->expires_at = gmdate( 'Y-m-d H:i:s', time() - 60 );
		$expired_row   = $r;
	}
}
assert_test( 'an expired link is invalid and marks the request expired', false === ErasureConfirmation::pending( $uuid_w, $token_w ) && 'expired' === $expired_row->status && null === $expired_row->email_enc );

add_user( 'race-victim@test.de', 91, 'editor' );
[ $uuid_r, $token_r ] = pending_request( 'race-victim@test.de' );
$stale_read           = null;
foreach ( $GLOBALS['mock_db_tables']['wp_wm_dsgvo_requests'] as $r ) {
	if ( $r->request_uuid === $uuid_r ) {
		$stale_read = clone $r;
	}
}
ErasureRequestManager::claim( $uuid_r );
$GLOBALS['mock_stale_row'] = $stale_read;
$late                      = ErasureConfirmation::run( $uuid_r, $token_r );
assert_test( 'D5: a confirmation that read the row before another one claimed it erases nothing', 'invalid' === $late['state'] && false !== get_user_by( 'email', 'race-victim@test.de' ), json_encode( $late['state'] ) );

[ $uuid2, $token2 ] = pending_request( 'race@test.de' );
$first_resolve      = ErasureRequestManager::resolve( $uuid2, $token2 );
$second_resolve     = ErasureRequestManager::resolve( $uuid2, $token2 );
assert_test( 'D5: of two confirmations that both resolved, only the first claims the request (both erased until 2026-09-15)', null !== $first_resolve && null !== $second_resolve && true === ErasureRequestManager::claim( $uuid2 ) && false === ErasureRequestManager::claim( $uuid2 ) );

[ $uuid3, $token3 ] = pending_request( 'broken-envelope@test.de' );
foreach ( $GLOBALS['mock_db_tables']['wp_wm_dsgvo_requests'] as $r ) {
	if ( $r->request_uuid === $uuid3 ) {
		$r->email_enc = 'wmv1:' . base64_encode( str_repeat( 'x', 40 ) );
	}
}
$undecryptable = ErasureConfirmation::run( $uuid3, $token3 );
assert_test( 'an envelope that does not open is marked failed, nothing erased', 'undecryptable' === $undecryptable['state'] && 500 === $undecryptable['http'] );

[ $uuid4, $token4 ]         = pending_request( 'thrown@test.de' );
$GLOBALS['mock_user_throw'] = true;
$explode                    = ErasureConfirmation::run( $uuid4, $token4 );
$GLOBALS['mock_user_throw'] = false;
$thrown_row                 = null;
foreach ( $GLOBALS['mock_db_tables']['wp_wm_dsgvo_requests'] as $r ) {
	if ( $r->request_uuid === $uuid4 ) {
		$thrown_row = $r;
	}
}
assert_test( 'an erasure that throws marks the request failed and drops the address (stuck in processing before)', 'incomplete' === $explode['state'] && null !== $thrown_row && 'failed' === $thrown_row->status && null === $thrown_row->email_enc );

$stuck_row = null;

[ $uuid5, $token5 ] = pending_request( 'stuck@test.de' );
ErasureRequestManager::claim( $uuid5 );
foreach ( $GLOBALS['mock_db_tables']['wp_wm_dsgvo_requests'] as $r ) {
	if ( $r->request_uuid === $uuid5 ) {
		$r->expires_at = gmdate( 'Y-m-d H:i:s', time() - 60 );
		$stuck_row     = $r;
	}
}
ErasureRequestManager::purge_expired();
assert_test( 'a request left in processing past its window is marked failed and loses its address', null !== $stuck_row && 'failed' === $stuck_row->status && null === $stuck_row->email_enc );

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 14] Reader pass: CLI, adapter, SBOM (D11, D12, D18)\n";
reset_db();
WP_CLI::$lines = [];
( new PrivacyEraserCommand() )->erase( [ 'nobody@test.de' ], [] );
$cli = implode( "\n", WP_CLI::$lines );
assert_test( 'D12: erasing an address without data does not print "Data purged" and marks the genesis link', ! str_contains( $cli, 'purged' ) && str_contains( $cli, '0 entries erased or anonymised' ) && str_contains( $cli, '(genesis)' ), $cli );
$GLOBALS['mock_lock_result'] = '0';
WP_CLI::$lines               = [];
try {
	( new PrivacyEraserCommand() )->erase( [ 'nobody2@test.de' ], [] );
	$cli_failed = false;
} catch ( WM_CLI_Exit $e ) {
	$cli_failed = true;
}
$GLOBALS['mock_lock_result'] = '1';
assert_test( 'D12: a record that was not written ends the command with an error', $cli_failed );
$saved_tables = $GLOBALS['mock_db_tables'];
$GLOBALS['mock_db_tables'] = [ 'wp_comments' => [] ];
$self_test    = $spoke->execute_action( 'self_test' );
$GLOBALS['mock_db_tables'] = $saved_tables;
assert_test( 'D11: self_test fails when the tables are missing and says so (success until 2026-09-15)', false === $self_test['success'] && str_contains( $self_test['message'], 'Tabellen' ), $self_test['message'] );
$gen->issue_certificate( 'tip@test.de', 1, [ 'wp_comments' => 1 ] );
update_option( ErasureCertificateGenerator::TIP_OPTION, str_repeat( 'f', 64 ) );
$tip_msg = $spoke->execute_action( 'verify_chain' )['message'];
assert_test( 'D11: a tip mismatch is reported at the end of the chain, not as "Eintrag #0"', str_contains( $tip_msg, 'am Ende der Kette' ) && ! str_contains( $tip_msg, '#0' ), $tip_msg );
$licence = $sbom['licenses'][0]['license'] ?? [];
// CycloneDX 1.6 allows id XOR name, and an `id` must come from the SPDX list. This used to assert
// `name` and no `id`, which was the SHAPE of the 2026-09-15 answer to a different problem: the
// licence was then 'Proprietary', not an SPDX identifier, so it had to live in `name`. Since
// 2026-09-19 the licence is GPL-2.0-or-later, which IS one, and `id` is the machine-readable field.
// Asserting the rule instead of that one past answer keeps both correct and rejects both errors.
$licence_has_id   = isset( $licence['id'] );
$licence_has_name = isset( $licence['name'] );
assert_test(
	'D18: the SBOM licence sets id XOR name, and any id is a real SPDX identifier',
	( $licence_has_id xor $licence_has_name )
		&& ( ! $licence_has_id || in_array( $licence['id'], [ 'GPL-2.0-or-later', 'GPL-2.0-only' ], true ) ),
	(string) json_encode( $licence )
);

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 15] Reader pass: form, admin texts, sources (D9, D13, D19, D20, D21, D22)\n";
assert_test( 'D13: the public form sends no X-WP-Nonce (403 from a cached page until 2026-09-15)', ! preg_match( "/'X-WP-Nonce'\s*:/", $footer ) && ! str_contains( $footer, '"nonce"' ) );
assert_test( 'D22: the form and the request tab take the hours and limits from the code', ! str_contains( (string) file_get_contents( ABSPATH . 'includes/Frontend/ErasureRequestForm.php' ), '24 Stunden' ) && ! str_contains( (string) file_get_contents( ABSPATH . 'includes/Admin/AdminDashboard.php' ), '24 Stunden' ) && str_contains( $footer, '24 Stunden' ) && str_contains( $pages['request'], '24 Stunden gültig' ) );
assert_test( 'D9: the request tab does not promise that a link went out', str_contains( $pages['request'], trim( (string) json_encode( 'diese Seite erfährt das nicht' ), '"' ) ) && ! str_contains( $pages['request'], trim( (string) json_encode( 'ist der Bestätigungslink unterwegs' ), '"' ) ) );
$unguarded = [];
$interpolated = []; // CREATE TABLE for dbDelta() stays literal: dbDelta parses the statement and takes no placeholders.
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( ABSPATH . 'includes', FilesystemIterator::SKIP_DOTS ) ) as $file ) {
	if ( 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	$src = (string) file_get_contents( (string) $file );
	if ( ! preg_match( "/defined\( 'ABSPATH' \) \|\| exit;|if \( ! defined\( 'ABSPATH' \) \) \{/", $src ) ) {
		$unguarded[] = basename( (string) $file );
	}
	if ( preg_match( '/(FROM|UPDATE|INTO|LIKE|DROP TABLE(?: IF EXISTS)?)\s+[\'"]?\{?\$/', $src ) ) {
		$interpolated[] = basename( (string) $file );
	}
}
assert_test( 'D20: every file under includes/ has the ABSPATH guard (Schema and the eraser had none)', [] === $unguarded, implode( ', ', $unguarded ) );
assert_test( 'D21: no table name is interpolated into SQL under includes/', [] === $interpolated, implode( ', ', $interpolated ) );
foreach ( [ 'test-certificate-chain.php', 'test-erasure-authorization.php', 'test-request-address-at-rest.php' ] as $wp_test ) {
	$wp_src = (string) file_get_contents( ABSPATH . 'tests/' . $wp_test );
	$guard  = strpos( $wp_src, "getenv( 'WM_ERASER_INTEGRATION_DISPOSABLE' )" );
	$write  = strpos( $wp_src, 'Schema::' );
	assert_test( "D19: {$wp_test} refuses to run without the disposable-install flag and exits 1 on a failure", false !== $guard && false !== $write && $guard < $write && str_contains( $wp_src, 'exit( 2 )' ) && (bool) preg_match( '/exit\( \$GLOBALS\[\'wm_[cer]_failed\'\] > 0 \? 1 : 0 \);/', $wp_src ) );
}

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 16] v1.3.0: Gutenberg block, REST rate-limit, opt-in uninstall & ecosystem bridges\n";
$block_json_raw = (string) file_get_contents( ABSPATH . 'blocks/dsgvo-erasure-portal/block.json' );
$block_meta     = json_decode( $block_json_raw, true );
$asset_meta     = require ABSPATH . 'build/index.asset.php';
$GLOBALS['mock_registered_scripts']  = [];
$GLOBALS['mock_script_translations'] = [];
$GLOBALS['mock_registered_blocks']   = [];
( new ErasureRequestForm() )->register_block();
assert_test(
	'block.json and build assets define editorScript wm-dsgvo-eraser-blocks-editor',
	is_array( $block_meta )
		&& 'wm/dsgvo-erasure-portal' === ( $block_meta['name'] ?? '' )
		&& 'wm-dsgvo-eraser-blocks-editor' === ( $block_meta['editorScript'] ?? '' )
		&& is_file( ABSPATH . 'build/index.js' )
		&& in_array( 'wp-server-side-render', (array) ( $asset_meta['dependencies'] ?? [] ), true )
		&& isset( $GLOBALS['mock_registered_scripts']['wm-dsgvo-eraser-blocks-editor'] )
		&& 'wm-dsgvo-privacy-eraser' === ( $GLOBALS['mock_script_translations']['wm-dsgvo-eraser-blocks-editor']['domain'] ?? '' )
		&& 'wm-dsgvo-eraser-blocks-editor' === ( $GLOBALS['mock_registered_blocks'][0]['args']['editor_script'] ?? '' )
);
$compact_form = ( new ErasureRequestForm() )->render_shortcode( [ 'showDescription' => false ] );
assert_test(
	'block attribute showDescription=false hides the intro paragraph while keeping the form',
	! str_contains( $compact_form, 'Recht auf Vergessenwerden' ) && str_contains( $compact_form, 'id="wm-erasure-email"' )
);

// SEC-02: REST permission callback rate limit
$GLOBALS['mock_rest_routes'] = [];
$rest_guard_ctrl             = new RestController();
$rest_guard_ctrl->register_routes();
$req_perm  = $GLOBALS['mock_rest_routes']['wm-dsgvo-eraser/v1/request']['permission_callback'] ?? null;
$conf_perm = $GLOBALS['mock_rest_routes']['wm-dsgvo-eraser/v1/confirm']['permission_callback'] ?? null;
assert_test(
	'SEC-02: /request and /confirm register check_public_erasure_access instead of __return_true',
	[ $rest_guard_ctrl, 'check_public_erasure_access' ] === $req_perm
		&& [ $rest_guard_ctrl, 'check_public_erasure_access' ] === $conf_perm
);
$GLOBALS['mock_transients']         = [];
$GLOBALS['mock_can_manage_options'] = false;
$GLOBALS['mock_filters']['wm_eraser_rest_rate_limit'] = [ static fn(): int => 2 ];
$_SERVER['REMOTE_ADDR'] = '198.51.100.88';
$rl_req  = new WP_REST_Request( 'POST', '/wm-dsgvo-eraser/v1/request' );
$rl_1    = $rest_guard_ctrl->check_public_erasure_access( $rl_req );
$rl_2    = $rest_guard_ctrl->check_public_erasure_access( $rl_req );
$rl_3    = $rest_guard_ctrl->check_public_erasure_access( $rl_req );
$rl_keys = implode( ',', array_keys( $GLOBALS['mock_transients'] ) );
assert_test(
	'SEC-02: visitor requests are rate-limited with 429 using HMAC-blinded IP transients',
	true === $rl_1
		&& true === $rl_2
		&& $rl_3 instanceof WP_Error
		&& 'wm_eraser_rate_limited' === $rl_3->get_error_code()
		&& 429 === ( $rl_3->get_error_data()['status'] ?? 0 )
		&& str_starts_with( $rl_keys, 'wm_dsgvo_rl_' )
		&& ! str_contains( $rl_keys, '198.51.100.88' )
);
$GLOBALS['mock_can_manage_options'] = true;
assert_test( 'SEC-02: manage_options bypasses the transient rate limit', true === $rest_guard_ctrl->check_public_erasure_access( $rl_req ) );
unset( $GLOBALS['mock_filters']['wm_eraser_rest_rate_limit'] );

// D-UNINSTALL: settings registration + default preservation vs opt-in deletion
$GLOBALS['mock_registered_settings'] = [];
$dashboard->register_settings();
assert_test(
	'D-UNINSTALL: settings screen registers and renders wm_dsgvo_eraser_delete_data_on_uninstall',
	isset( $GLOBALS['mock_registered_settings']['wm_dsgvo_eraser_delete_data_on_uninstall'] )
		&& isset( $GLOBALS['mock_registered_settings']['wm_dsgvo_anonymize_wc_orders'] )
		&& str_contains( $pages['settings'], 'name="wm_dsgvo_eraser_delete_data_on_uninstall"' )
);
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', true );
}
reset_db();
update_option( 'wm_dsgvo_cert_chain_tip', 'keep-tip-hash' );
update_option( 'wm_dsgvo_schema_version', '3' );
$GLOBALS['mock_dropped_tables'] = [];
$GLOBALS['mock_cleared_cron']   = [];
include ABSPATH . 'uninstall.php';
assert_test(
	'D-UNINSTALL: default uninstall clears cron but preserves options and tables',
	in_array( 'wm_dsgvo_expire_requests', $GLOBALS['mock_cleared_cron'], true )
		&& 'keep-tip-hash' === get_option( 'wm_dsgvo_cert_chain_tip' )
		&& '3' === get_option( 'wm_dsgvo_schema_version' )
		&& [] === $GLOBALS['mock_dropped_tables']
);
update_option( 'wm_dsgvo_eraser_delete_data_on_uninstall', '1' );
$GLOBALS['mock_dropped_tables'] = [];
include ABSPATH . 'uninstall.php';
assert_test(
	'D-UNINSTALL: opted-in uninstall deletes all options and drops both tables',
	false === get_option( 'wm_dsgvo_cert_chain_tip' )
		&& false === get_option( 'wm_dsgvo_schema_version' )
		&& false === get_option( 'wm_dsgvo_eraser_delete_data_on_uninstall' )
		&& [ 'wp_wm_dsgvo_erasure_certificates', 'wp_wm_dsgvo_requests' ] === $GLOBALS['mock_dropped_tables']
);

// Ecosystem hooks & bridges
reset_db();
$GLOBALS['mock_fired_actions']  = [];
$GLOBALS['mock_block_bindings'] = [];
$GLOBALS['mock_gb_tags']        = [];
$GLOBALS['mock_filters']['generateblocks_dynamic_tag_replacement'] = [];
$GLOBALS['mock_filters']['generateblocks_dynamic_tag_output']      = [];
Plugin::instance()->boot();
$eco_integration = new EcosystemIntegration();
$eco_integration->boot();
$eco_integration->register_integrations();
$eco_status = EcosystemIntegration::get_block_binding_value();
$gb_output  = $eco_integration->filter_gb_dynamic_tag_output( 'Status: {{wm_eraser_status}}' );
$gb_rep_ok  = '' !== $eco_integration->filter_gb_dynamic_tag_output( '', [ 'tag' => 'wm_eraser_status', 'options' => [] ] );
$gb_null_ok = null === $eco_integration->filter_gb_dynamic_tag_output( null, [ 'source' => 'post' ], 'Post Title' );
assert_test(
	'Ecosystem: Plugin::boot fires wm_eraser_ready and registers Block Bindings + GenerateBlocks Dynamic Tag',
	! empty( $GLOBALS['mock_fired_actions']['wm_eraser_ready'] )
		&& isset( $GLOBALS['mock_block_bindings']['wm-dsgvo-eraser/status'] )
		&& isset( $GLOBALS['mock_gb_tags']['wm_eraser_status'] )
		&& [] === ( $GLOBALS['mock_filters']['generateblocks_dynamic_tag_output'] ?? [] )
		&& ! empty( $GLOBALS['mock_filters']['generateblocks_dynamic_tag_replacement'] )
		&& str_contains( $eco_status, 'Art. 17 DSGVO Löschportal aktiv' )
		&& str_contains( $gb_output, 'Art. 17 DSGVO Löschportal aktiv' )
		&& $gb_rep_ok
		&& $gb_null_ok
);
$GLOBALS['mock_filters']['wm_eraser_anonymize_personal_data'] = [
	static fn( array $extra, string $addr ): array => 'hooked@test.de' === $addr ? [ 'custom_newsletter' => 3 ] : $extra,
];
$GLOBALS['mock_fired_actions']['wm_eraser_completed'] = [];
$hooked_erasure = ( new TransversalDataEraser() )->execute_erasure( 'hooked@test.de' );
unset( $GLOBALS['mock_filters']['wm_eraser_anonymize_personal_data'] );
assert_test(
	'Ecosystem: wm_eraser_anonymize_personal_data folds custom areas into the sealed record and fires wm_eraser_completed',
	true === $hooked_erasure['success']
		&& 3 === ( $hooked_erasure['tables']['custom_newsletter'] ?? 0 )
		&& 3 === $hooked_erasure['total_purged']
		&& str_contains( (string) end( $GLOBALS['mock_db_tables']['wp_wm_dsgvo_erasure_certificates'] )->purged_tables_summary, 'custom_newsletter: 3' )
		&& ! empty( $GLOBALS['mock_fired_actions']['wm_eraser_completed'] )
);

// ---------------------------------------------------------------------------------------------------
echo "\n[TEST GROUP 17] R2-B8: multisite site initialization, network activation & autoload=false\n";
reset_db();
Plugin::instance()->maybe_upgrade_schema();
assert_test(
	'R2-B8: Plugin::maybe_upgrade_schema() writes wm_dsgvo_schema_version with autoload=false',
	Plugin::SCHEMA_VERSION === get_option( 'wm_dsgvo_schema_version' )
		&& false === ( $GLOBALS['mock_option_autoloads']['wm_dsgvo_schema_version'] ?? null )
		&& 2 === count( $GLOBALS['mock_dbdelta_queries'] )
);

reset_db();
$activation_cb = $GLOBALS['mock_activation_hooks'][ WM_DSGVO_ERASER_FILE ] ?? null;
assert_test( 'R2-B8: activation hook is registered on the main plugin file', is_callable( $activation_cb ) );
if ( is_callable( $activation_cb ) ) {
	$activation_cb( false );
}
$single_sql = implode( "\n", $GLOBALS['mock_dbdelta_queries'] );
assert_test(
	'R2-B8: single-site activation creates prefixed tables and sets wm_dsgvo_schema_version with autoload=false',
	str_contains( $single_sql, 'CREATE TABLE wp_wm_dsgvo_erasure_certificates ' )
		&& str_contains( $single_sql, 'CREATE TABLE wp_wm_dsgvo_requests ' )
		&& Plugin::SCHEMA_VERSION === get_option( 'wm_dsgvo_schema_version' )
		&& false === ( $GLOBALS['mock_option_autoloads']['wm_dsgvo_schema_version'] ?? null )
);

reset_db();
$GLOBALS['mock_multisite'] = true;
$GLOBALS['mock_sites']     = [ 1, 2, 3 ];
if ( is_callable( $activation_cb ) ) {
	$activation_cb( true );
}
$network_sql = implode( "\n", $GLOBALS['mock_dbdelta_queries'] );
assert_test(
	'R2-B8: network-wide activation initializes all sites, sets autoload=false, and restores current blog',
	6 === count( $GLOBALS['mock_dbdelta_queries'] )
		&& str_contains( $network_sql, 'CREATE TABLE wp_wm_dsgvo_requests ' )
		&& str_contains( $network_sql, 'CREATE TABLE wp_2_wm_dsgvo_requests ' )
		&& str_contains( $network_sql, 'CREATE TABLE wp_3_wm_dsgvo_requests ' )
		&& Plugin::SCHEMA_VERSION === ( $GLOBALS['mock_blogs_options'][2]['wm_dsgvo_schema_version'] ?? null )
		&& false === ( $GLOBALS['mock_blogs_autoloads'][2]['wm_dsgvo_schema_version'] ?? null )
		&& Plugin::SCHEMA_VERSION === ( $GLOBALS['mock_blogs_options'][3]['wm_dsgvo_schema_version'] ?? null )
		&& false === ( $GLOBALS['mock_blogs_autoloads'][3]['wm_dsgvo_schema_version'] ?? null )
		&& 1 === $GLOBALS['mock_current_blog_id']
		&& [] === $GLOBALS['mock_blog_stack']
		&& 'wp_' === $GLOBALS['wpdb']->prefix
);

reset_db();
$GLOBALS['mock_multisite'] = false;
do_action( 'wp_initialize_site', (object) [ 'blog_id' => 4 ] );
assert_test(
	'R2-B8: wp_initialize_site is a no-op outside multisite',
	[] === $GLOBALS['mock_dbdelta_queries'] && ! isset( $GLOBALS['mock_blogs_options'][4] )
);

reset_db();
$GLOBALS['mock_multisite']              = true;
$GLOBALS['mock_network_active_plugins'] = [];
do_action( 'wp_initialize_site', (object) [ 'blog_id' => 4 ] );
assert_test(
	'R2-B8: wp_initialize_site does nothing when plugin is not network-activated',
	[] === $GLOBALS['mock_dbdelta_queries'] && ! isset( $GLOBALS['mock_blogs_options'][4] )
);

reset_db();
$GLOBALS['mock_multisite']              = true;
$GLOBALS['mock_network_active_plugins'] = [ plugin_basename( WM_DSGVO_ERASER_FILE ) ];
do_action( 'wp_initialize_site', (object) [ 'blog_id' => 4 ] );
$site4_sql = implode( "\n", $GLOBALS['mock_dbdelta_queries'] );
assert_test(
	'R2-B8: wp_initialize_site initializes tables and non-autoloaded schema version on new network site',
	2 === count( $GLOBALS['mock_dbdelta_queries'] )
		&& str_contains( $site4_sql, 'CREATE TABLE wp_4_wm_dsgvo_erasure_certificates ' )
		&& str_contains( $site4_sql, 'CREATE TABLE wp_4_wm_dsgvo_requests ' )
		&& Plugin::SCHEMA_VERSION === ( $GLOBALS['mock_blogs_options'][4]['wm_dsgvo_schema_version'] ?? null )
		&& false === ( $GLOBALS['mock_blogs_autoloads'][4]['wm_dsgvo_schema_version'] ?? null )
		&& 1 === $GLOBALS['mock_current_blog_id']
		&& [] === $GLOBALS['mock_blog_stack']
		&& 'wp_' === $GLOBALS['wpdb']->prefix
);

reset_db();
$GLOBALS['mock_multisite']              = true;
$GLOBALS['mock_network_active_plugins'] = [ plugin_basename( WM_DSGVO_ERASER_FILE ) ];
$GLOBALS['mock_dbdelta_throw']          = true;
try {
	do_action( 'wp_initialize_site', (object) [ 'blog_id' => 5 ] );
} catch ( RuntimeException $e ) {
	// Expected simulated dbDelta failure to exercise finally restore_current_blog().
}
$GLOBALS['mock_dbdelta_throw'] = false;
assert_test(
	'R2-B8: wp_initialize_site restores current blog in finally even when schema creation throws',
	1 === $GLOBALS['mock_current_blog_id']
		&& [] === $GLOBALS['mock_blog_stack']
		&& 'wp_' === $GLOBALS['wpdb']->prefix
);

// ---------------------------------------------------------------------------------------------------
print_results();
exit( 0 === $GLOBALS['failed_count'] ? 0 : 1 );
