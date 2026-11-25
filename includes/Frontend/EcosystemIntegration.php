<?php
/**
 * WordPress 6.5+ Block Bindings and GenerateBlocks 2.x Dynamic Tag bridges.
 *
 * @package WMCyber\DSGVO\Eraser
 */

declare(strict_types=1);

namespace WMCyber\DSGVO\Eraser\Frontend;

defined( 'ABSPATH' ) || exit;

use WMCyber\DSGVO\Eraser\Crypto\ErasureCertificateGenerator;

/**
 * Exposes read-only Art. 17 portal and chain status to block themes and GenerateBlocks 2.x.
 */
final class EcosystemIntegration {
	public const BINDING_SOURCE = 'wm-dsgvo-eraser/status';
	public const GB_TAG         = 'wm_eraser_status';

	public function boot(): void {
		add_action( 'init', [ $this, 'register_integrations' ] );
		add_filter( 'generateblocks_dynamic_tag_replacement', [ $this, 'filter_gb_dynamic_tag_output' ], 10, 2 );
	}

	public function register_integrations(): void {
		if ( function_exists( 'register_block_bindings_source' ) ) {
			register_block_bindings_source(
				'wm-dsgvo-eraser/status',
				[
					'label'              => __( 'WM Privacy Eraser Status', 'wm-dsgvo-privacy-eraser' ),
					'get_value_callback' => [ self::class, 'get_block_binding_value' ],
					'uses_context'       => [],
				]
			);
		}

		if ( class_exists( 'GenerateBlocks_Register_Dynamic_Tag' ) ) {
			new \GenerateBlocks_Register_Dynamic_Tag(
				[
					'title'       => __( 'WM Privacy Eraser Status', 'wm-dsgvo-privacy-eraser' ),
					'tag'         => self::GB_TAG,
					'type'        => 'wm-dsgvo-eraser',
					'supports'    => [],
					'description' => __( 'Aktueller Status des Art.-17-DSGVO-Löschportals.', 'wm-dsgvo-privacy-eraser' ),
					'return'      => [ self::class, 'render_gb_dynamic_tag' ],
				]
			);
		}
	}

	/**
	 * Callback for WP 6.5+ Block Bindings (`wm-dsgvo-eraser/status`).
	 *
	 * @param array<string, mixed> $source_args    Binding source arguments.
	 * @param mixed                $block_instance Block instance.
	 * @param string               $attribute_name Bound attribute name.
	 */
	public static function get_block_binding_value( array $source_args = [], $block_instance = null, string $attribute_name = 'content' ): string {
		$key = isset( $source_args['key'] ) ? sanitize_key( (string) $source_args['key'] ) : 'status';
		return self::status_summary( $key );
	}

	/**
	 * Callback for GenerateBlocks 2.x Dynamic Tag (`{{wm_eraser_status}}`).
	 *
	 * @param array<string, mixed> $options Tag options.
	 * @param array<string, mixed> $block   Block array.
	 * @param mixed                $instance Block instance.
	 */
	public static function render_gb_dynamic_tag( array $options = [], array $block = [], $instance = null ): string {
		$key = isset( $options['key'] ) ? sanitize_key( (string) $options['key'] ) : 'status';
		return esc_html( self::status_summary( $key ) );
	}

	/**
	 * Fallback filter for GenerateBlocks dynamic tag replacement when `{{wm_eraser_status}}` is evaluated.
	 *
	 * @param mixed $output   Current replacement/output.
	 * @param mixed $tag_data Tag metadata or name.
	 * @param mixed $context  Render context or raw scalar output.
	 */
	public function filter_gb_dynamic_tag_output( mixed $output, mixed $tag_data = [], mixed $context = [] ): mixed {
		$tag     = is_array( $tag_data ) ? (string) ( $tag_data['tag'] ?? '' ) : ( is_string( $tag_data ) ? $tag_data : '' );
		$options = is_array( $tag_data ) && isset( $tag_data['options'] ) && is_array( $tag_data['options'] ) ? $tag_data['options'] : [];

		if ( self::GB_TAG === $tag ) {
			return self::render_gb_dynamic_tag( $options );
		}

		if ( is_string( $output ) && str_contains( $output, '{{wm_eraser_status}}' ) ) {
			return str_replace( '{{wm_eraser_status}}', esc_html( self::status_summary( 'status' ) ), $output );
		}

		return $output;
	}

	/**
	 * Read-only public summary of the Art. 17 portal and certificate chain state.
	 */
	public static function status_summary( string $key = 'status' ): string {
		$chain = ( new ErasureCertificateGenerator() )->verify_chain_integrity();

		if ( 'checked' === $key ) {
			$summary = (string) (int) ( $chain['checked'] ?? 0 );
		} else {
			$summary = match ( $chain['status'] ) {
				'INTACT'      => sprintf(
					/* translators: %d: number of verified certificates */
					__( 'Art. 17 DSGVO Löschportal aktiv (%d versiegelte Nachweise)', 'wm-dsgvo-privacy-eraser' ),
					(int) $chain['checked']
				),
				'EMPTY_CHAIN' => __( 'Art. 17 DSGVO Löschportal aktiv', 'wm-dsgvo-privacy-eraser' ),
				default       => __( 'Art. 17 DSGVO Löschportal: Kette prüfen', 'wm-dsgvo-privacy-eraser' ),
			};
		}

		return (string) apply_filters( 'wm_eraser_status_text', $summary, $chain, $key );
	}
}
