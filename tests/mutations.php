<?php
/**
 * Mutations for tests/run-mutations.sh: each entry undoes one correction with literal [file, search, replace]
 * replacements; the suite must report at least one failure for every entry. D-entries undo the reader pass
 * defects of 2026-09-15, the others the fixes before it.
 *
 * @package WMCyber\DSGVO\Eraser
 */

declare(strict_types=1);

return [
	[
		'seal comparison removed',
		[
			[ 'includes/Crypto/ErasureCertificateGenerator.php', 'if ( ! hash_equals( $expected_seal, (string) $row[\'sha256_audit_seal\'] ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'prev_hash comparison removed',
		[
			[ 'includes/Crypto/ErasureCertificateGenerator.php', 'if ( ! hash_equals( $expected_prev, (string) $row[\'prev_hash\'] ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'recorded tip not compared',
		[
			[ 'includes/Crypto/ErasureCertificateGenerator.php', 'if ( \'\' !== $stored_tip && ! hash_equals( $stored_tip, $expected_prev ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'D6: emptied table with a recorded tip reads as EMPTY_CHAIN',
		[
			[ 'includes/Crypto/ErasureCertificateGenerator.php', 'if ( \'\' !== $stored_tip ) {', 'if ( false ) {' ],
		],
	],
	[
		'D6: missing certificate table not detected',
		[
			[ 'includes/Crypto/ErasureCertificateGenerator.php', 'if ( ! self::table_present() ) {', 'if ( false ) {' ],
		],
	],
	[
		'sealed rows without a recorded tip accepted',
		[
			[ 'includes/Crypto/ErasureCertificateGenerator.php', 'if ( $result[\'checked\'] > 0 && \'\' === $stored_tip ) {', 'if ( false ) {' ],
		],
	],
	[
		'D7: next record chains onto the newest remaining row',
		[
			[ 'includes/Crypto/ErasureCertificateGenerator.php', 'if ( \'\' !== $recorded ) {', 'if ( false ) {' ],
		],
	],
	[
		'D8: certificate_id not verified',
		[
			[ 'includes/Crypto/ErasureCertificateGenerator.php', 'if ( ! hash_equals( \'ERASE-\' . substr( $record_hash, 0, 16 ), (string) $row[\'certificate_id\'] ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'D9: append without the chain lock',
		[
			[ 'includes/Crypto/ErasureCertificateGenerator.php', 'if ( \'1\' !== (string) $locked ) {', 'if ( false ) {' ],
		],
	],
	[
		'D10: refused certificate insert moves the tip',
		[
			[ 'includes/Crypto/ErasureCertificateGenerator.php', 'if ( false === $inserted ) {', 'if ( false ) {' ],
		],
	],
	[
		'D17: only Exception caught around the vault anchor',
		[
			[ 'includes/Crypto/ErasureCertificateGenerator.php', 'catch ( \\Throwable $e ) {', 'catch ( \\Exception $e ) {' ],
		],
	],
	[
		'D1: first name not anonymised on orders',
		[
			[ 'includes/Eraser/TransversalDataEraser.php', '$order->set_billing_first_name( \'Anonym\' );', '' ],
		],
	],
	[
		'D1: order save result ignored',
		[
			[ 'includes/Eraser/TransversalDataEraser.php', 'if ( (int) $order->save() > 0 ) {', 'if ( true ) {' ],
		],
	],
	[
		'D2: refused comment UPDATE counted',
		[
			[ 'includes/Eraser/TransversalDataEraser.php', 'if ( false === $changed ) {', 'if ( false ) {' ],
		],
	],
	[
		'D2: wp_delete_user() result ignored',
		[
			[ 'includes/Eraser/TransversalDataEraser.php', 'if ( ! $deleted ) {', 'if ( false ) {' ],
		],
	],
	[
		'D3: multisite removal recorded as a deletion',
		[
			[ 'includes/Eraser/TransversalDataEraser.php', '} elseif ( is_multisite() ) {', '} elseif ( false ) {' ],
		],
	],
	[
		'D14: statute written into the record as the switch-off reason',
		[
			[ 'includes/Eraser/TransversalDataEraser.php', '$areas[\'woocommerce_orders\'] = \'skipped_switch_off\';', '$areas[\'woocommerce_orders\'] = \'skipped_retention_sec_147_AO\';' ],
		],
	],
	[
		'D14: no entry when WooCommerce is inactive',
		[
			[ 'includes/Eraser/TransversalDataEraser.php', '$areas[\'woocommerce_orders\'] = \'skipped_woocommerce_inactive\';', '' ],
		],
	],
	[
		'D15: comment agent and user id kept',
		[
			[ 'includes/Eraser/TransversalDataEraser.php', 'comment_agent = \'\', user_id = 0, ', '' ],
		],
	],
	[
		'D15: comment cache not cleared',
		[
			[ 'includes/Eraser/TransversalDataEraser.php', 'clean_comment_cache( array_map( \'intval\', $comment_ids ) );', '' ],
		],
	],
	[
		'D2: success regardless of failed areas and missing record',
		[
			[ 'includes/Eraser/TransversalDataEraser.php', '\'success\'      => null === $error,', '\'success\'      => true,' ],
		],
	],
	[
		'administrator account deleted as a count',
		[
			[ 'includes/Eraser/TransversalDataEraser.php', '$areas[\'wp_users\'] = \'skipped_administrator\';', '$areas[\'wp_users\'] = 1;' ],
		],
	],
	[
		'D4: REST GET of the link erases again',
		[
			[ 'includes/API/RestController.php', '$response = new WP_REST_Response( null, 302 );', '$response = new WP_REST_Response( \\WMCyber\\DSGVO\\Eraser\\Request\\ErasureConfirmation::run( (string) $request->get_param( \'request\' ), (string) $request->get_param( \'token\' ) ), 200 );' ],
		],
	],
	[
		'D4: mail links the REST route again',
		[
			[ 'includes/Request/ErasureRequestManager.php', '$link = self::confirmation_url( $uuid, $raw_token );', '$link = add_query_arg( array( \'request\' => $uuid, \'token\' => $raw_token ), rest_url( \'wm-dsgvo-eraser/v1/confirm\' ) );' ],
		],
	],
	[
		'D5: claim not conditional on pending',
		[
			[ 'includes/Request/ErasureRequestManager.php', 'WHERE request_uuid = %s AND status = \'pending\'",', 'WHERE request_uuid = %s",' ],
		],
	],
	[
		'D5: confirmation erases without winning the claim',
		[
			[ 'includes/Request/ErasureConfirmation.php', 'if ( ! ErasureRequestManager::claim( (string) $row->request_uuid ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'an erasure that throws leaves the request processing',
		[
			[ 'includes/Request/ErasureConfirmation.php', "} catch ( \\Throwable \$e ) {\n\t\t\t// A fatal half-way", "} catch ( \\LogicException \$e ) {\n\t\t\t// A fatal half-way" ],
		],
	],
	[
		'requests stuck in processing never swept',
		[
			[ 'includes/Request/ErasureRequestManager.php', 'WHERE status = \'processing\' AND expires_at IS NOT NULL', 'WHERE status = \'never\' AND expires_at IS NOT NULL' ],
		],
	],
	[
		'address limit not applied',
		[
			[ 'includes/Request/ErasureRequestManager.php', 'return $per_email >= self::MAX_PER_EMAIL || $per_ip >= self::MAX_PER_IP_HOUR;', 'return false;' ],
		],
	],
	[
		'expired link accepted',
		[
			[ 'includes/Request/ErasureRequestManager.php', 'if ( empty( $row->expires_at ) || strtotime( $row->expires_at . \' UTC\' ) < time() ) {', 'if ( false ) {' ],
		],
	],
	[
		'token not compared',
		[
			[ 'includes/Request/ErasureRequestManager.php', 'if ( ! hash_equals( (string) $row->token_hash, hash( \'sha256\', $raw_token ) ) ) {', 'if ( false ) {' ],
		],
	],
	[
		'address stored in clear text',
		[
			[ 'includes/Crypto/RequestCipher.php', 'return self::PREFIX . base64_encode( $nonce . $cipher );', 'return $plain;' ],
		],
	],
	[
		'D11: self_test passes without tables',
		[
			[ 'includes/Adapters/Privacy_Eraser_Spoke_Adapter.php', 'if ( \'self_test\' === $action && ! self::tables_present() ) {', 'if ( false ) {' ],
		],
	],
	[
		'D11: tip mismatch reported as entry #0',
		[
			[ 'includes/Adapters/Privacy_Eraser_Spoke_Adapter.php', '? \'am Ende der Kette\'', '? \'bei Eintrag #0\'' ],
		],
	],
	[
		'D18: invalid SPDX id back in the SBOM licence',
		[
			[ 'includes/Adapters/Privacy_Eraser_Spoke_Adapter.php', '\'id\' => \'GPL-2.0-or-later\',', '\'id\' => \'Proprietary\',' ],
		],
	],
	[
		'D18: the SBOM licence sets id and name at once, which CycloneDX does not allow',
		[
			[ 'includes/Adapters/Privacy_Eraser_Spoke_Adapter.php', '\'id\' => \'GPL-2.0-or-later\',', '\'id\' => \'GPL-2.0-or-later\', \'name\' => \'WM Suite Commercial License\',' ],
		],
	],
	[
		'broken chain not ERROR in the hub',
		[
			[ 'includes/Adapters/Privacy_Eraser_Spoke_Adapter.php', "if ( 'BROKEN' === \$audit['status'] ) {\n\t\t\treturn 'ERROR';", "if ( false ) {\n\t\t\treturn 'ERROR';" ],
		],
	],
	[
		'D12: genesis link not marked',
		[
			[ 'includes/CLI/PrivacyEraserCommand.php', '( ErasureCertificateGenerator::GENESIS_HASH === $prev ? $prev . \' (genesis)\' : $prev )', '$prev' ],
		],
	],
	[
		'D12: failed erasure ends the command with success',
		[
			[ 'includes/CLI/PrivacyEraserCommand.php', "if ( empty( \$result['success'] ) ) {\n\t\t\tWP_CLI::error(", "if ( false ) {\n\t\t\tWP_CLI::error(" ],
		],
	],
	[
		'D13: public form sends the REST nonce again',
		[
			[ 'includes/Frontend/ErasureRequestForm.php', "'Content-Type': 'application/json'\n\t\t\t\t\t},", "'Content-Type': 'application/json',\n\t\t\t\t\t\t'X-WP-Nonce': 'nonce'\n\t\t\t\t\t}," ],
		],
	],
	[
		'D22: form types the hours instead of using the constant',
		[
			[ 'includes/Frontend/ErasureRequestForm.php', 'Der Link ist %d Stunden gültig und nur einmal verwendbar.', 'Der Link ist 24 Stunden gültig und nur einmal verwendbar.' ],
		],
	],
	[
		'form writes server text as markup',
		[
			[ 'includes/Frontend/ErasureRequestForm.php', 'statusBox.appendChild(document.createTextNode(text));', 'statusBox.innerHTML += text;' ],
		],
	],
	[
		'D9: request tab promises a link went out',
		[
			[ 'includes/Admin/AdminDashboard.php', 'diese Seite erfährt das nicht. Noch ist nichts gelöscht.', 'ist der Bestätigungslink unterwegs. Noch ist nichts gelöscht.' ],
		],
	],
	[
		'broken chain shown healthy on the overview',
		[
			[ 'includes/Admin/AdminDashboard.php', '\'BROKEN\' === $chain[\'status\'] ? \'wm-status-error\' : \'wm-status-healthy\'', '\'wm-status-healthy\'' ],
		],
	],
	[
		'D20: Schema without the direct-access guard',
		[
			[ 'includes/Database/Schema.php', "defined( 'ABSPATH' ) || exit;\n\nfinal class Schema {", "final class Schema {" ],
		],
	],
	[
		'D21: table name interpolated into the resolve query',
		[
			[ 'includes/Request/ErasureRequestManager.php', '\'SELECT * FROM %i WHERE request_uuid = %s LIMIT 1\', Schema::get_requests_table(), $uuid', '"SELECT * FROM {$wpdb->prefix}wm_dsgvo_requests WHERE request_uuid = %s LIMIT 1", $uuid' ],
		],
	],
	[
		'D19: chain test runs without the disposable-install flag',
		[
			[ 'tests/test-certificate-chain.php', "\nif ( '1' !== getenv( 'WM_ERASER_INTEGRATION_DISPOSABLE' ) ) {\n\tfwrite( STDERR, \"Refused: this test writes to the WordPress install it runs in. Set WM_ERASER_INTEGRATION_DISPOSABLE=1 on a disposable install.\\n\" );\n\texit( 2 );\n}\n", "\n" ],
		],
	],
	[
		'D19: chain test exits 0 on failures',
		[
			[ 'tests/test-certificate-chain.php', 'exit( $GLOBALS[\'wm_c_failed\'] > 0 ? 1 : 0 );', '' ],
		],
	],
	[
		'D19: authorization test exits 0 on failures',
		[
			[ 'tests/test-erasure-authorization.php', 'exit( $GLOBALS[\'wm_e_failed\'] > 0 ? 1 : 0 );', '' ],
		],
	],
	[
		'uninstall interpolates the table name',
		[
			[ 'uninstall.php', '$wpdb->query( $wpdb->prepare( \'DROP TABLE IF EXISTS %i\', $wm_dsgvo_eraser_certs ) );', '$wpdb->query( "DROP TABLE IF EXISTS {$wm_dsgvo_eraser_certs}" );' ],
		],
	],
	[
		'uninstall clears an invented cron hook',
		[
			[ 'uninstall.php', 'wp_clear_scheduled_hook( \'wm_dsgvo_expire_requests\' );', 'wp_clear_scheduled_hook( \'wm_dsgvo_daily_sweep\' );' ],
		],
	],
	[
		'D-UNINSTALL: uninstall drops tables and options without explicit opt-in',
		[
			[ 'uninstall.php', "if ( ! filter_var( get_option( 'wm_dsgvo_eraser_delete_data_on_uninstall', false ), FILTER_VALIDATE_BOOLEAN ) ) {\n\treturn;\n}", '' ],
		],
	],
	[
		'SEC-02: /request route reverts to __return_true',
		[
			[ 'includes/API/RestController.php', "'callback'            => [ \$this, 'handle_erasure_request' ],\n\t\t\t'permission_callback' => [ \$this, 'check_public_erasure_access' ],", "'callback'            => [ \$this, 'handle_erasure_request' ],\n\t\t\t'permission_callback' => '__return_true'," ],
		],
	],
	[
		'SEC-02: blinded-IP rate limit threshold not enforced',
		[
			[ 'includes/API/RestController.php', 'if ( $count >= $limit ) {', 'if ( false ) {' ],
		],
	],
	[
		'block.json omits editorScript',
		[
			[ 'blocks/dsgvo-erasure-portal/block.json', '"editorScript": "wm-dsgvo-eraser-blocks-editor",', '' ],
		],
	],
	[
		'Plugin::boot omits wm_eraser_ready action',
		[
			[ 'includes/Core/Plugin.php', "do_action( 'wm_eraser_ready', \$this );", '' ],
		],
	],
	[
		'Block Bindings source wm-dsgvo-eraser/status not registered',
		[
			[ 'includes/Frontend/EcosystemIntegration.php', "register_block_bindings_source(\n\t\t\t\t'wm-dsgvo-eraser/status',", "register_block_bindings_source(\n\t\t\t\t'wm-dsgvo-eraser/disabled'," ],
		],
	],
	[
		'wm_eraser_anonymize_personal_data filter bypassed',
		[
			[ 'includes/Eraser/TransversalDataEraser.php', "\$extra_areas = apply_filters( 'wm_eraser_anonymize_personal_data', [], \$clean_email );", '$extra_areas = [];' ],
		],
	],
	[
		'R2-B8: wp_initialize_site hook omitted on multisite',
		[
			[ 'wm-dsgvo-privacy-eraser.php', "add_action( 'wp_initialize_site',", "add_action( 'wm_noop_initialize_site'," ],
		],
	],
	[
		'R2-B8: wp_initialize_site skips network-activation check',
		[
			[ 'wm-dsgvo-privacy-eraser.php', "if ( ! function_exists( 'is_plugin_active_for_network' ) || ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {", 'if ( false ) {' ],
		],
	],
	[
		'R2-B8: multisite site switch omits restore_current_blog() in finally',
		[
			[ 'wm-dsgvo-privacy-eraser.php', 'restore_current_blog();', '// restore_current_blog();' ],
		],
	],
	[
		'R2-B8: register_activation_hook ignores $network_wide',
		[
			[ 'wm-dsgvo-privacy-eraser.php', "if ( is_multisite() && \$network_wide && function_exists( 'get_sites' ) ) {", 'if ( false ) {' ],
		],
	],
	[
		'R2-B8: Plugin::maybe_upgrade_schema autoloads wm_dsgvo_schema_version',
		[
			[ 'includes/Core/Plugin.php', "update_option( 'wm_dsgvo_schema_version', self::SCHEMA_VERSION, false );", "update_option( 'wm_dsgvo_schema_version', self::SCHEMA_VERSION );" ],
		],
	],
	[
		'R2-B8: site activation autoloads wm_dsgvo_schema_version',
		[
			[ 'wm-dsgvo-privacy-eraser.php', "update_option( 'wm_dsgvo_schema_version', Core\\Plugin::SCHEMA_VERSION, false );", "update_option( 'wm_dsgvo_schema_version', Core\\Plugin::SCHEMA_VERSION );" ],
		],
	],
];
