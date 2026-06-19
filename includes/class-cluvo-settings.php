<?php
/**
 * Admin settings page: shared base URL/cache plus a list of Cluvo accounts
 * (one per Verein), each with a stable numeric id used in shortcodes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cluvo_Settings {

	/** @var Cluvo_Client */
	private $client;

	public function __construct( Cluvo_Client $client ) {
		$this->client = $client;

		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_cluvo_test_connection', array( $this, 'ajax_test_connection' ) );
	}

	public function add_menu() {
		add_options_page(
			__( 'Cluvo', 'cluvo' ),
			__( 'Cluvo', 'cluvo' ),
			'manage_options',
			'cluvo',
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'cluvo_settings_group',
			Cluvo_Client::OPTION_KEY,
			array( $this, 'sanitize' )
		);
	}

	/**
	 * Sanitize input, assign stable ids to new accounts and flush the cache.
	 */
	public function sanitize( $input ) {
		$existing = $this->client->get_settings();
		$next_id  = max( 1, (int) ( isset( $existing['next_id'] ) ? $existing['next_id'] : 1 ) );

		$accounts = array();
		$rows     = isset( $input['accounts'] ) && is_array( $input['accounts'] ) ? $input['accounts'] : array();

		foreach ( $rows as $row ) {
			$name = isset( $row['name'] ) ? sanitize_text_field( trim( $row['name'] ) ) : '';
			$key  = isset( $row['api_key'] ) ? sanitize_text_field( trim( $row['api_key'] ) ) : '';

			// Drop fully empty rows.
			if ( '' === $name && '' === $key ) {
				continue;
			}

			$id = isset( $row['id'] ) ? (int) $row['id'] : 0;
			if ( $id <= 0 ) {
				$id = $next_id;
				$next_id++;
			} else {
				$next_id = max( $next_id, $id + 1 );
			}

			$accounts[] = array(
				'id'      => $id,
				'name'    => $name,
				'api_key' => $key,
			);
		}

		$clean = array(
			'base_url'  => isset( $input['base_url'] ) ? esc_url_raw( trim( $input['base_url'] ) ) : '',
			'cache_ttl' => isset( $input['cache_ttl'] ) ? max( 30, absint( $input['cache_ttl'] ) ) : 600,
			'accounts'  => $accounts,
			'next_id'   => $next_id,
		);

		Cluvo_Client::flush_cache();

		return $clean;
	}

	public function render_page() {
		$settings = $this->client->get_settings();
		$opt      = Cluvo_Client::OPTION_KEY;
		$accounts = $settings['accounts'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Cluvo', 'cluvo' ); ?></h1>
			<p><?php esc_html_e( 'Verbinde diese Webseite mit einem oder mehreren Cluvo-Vereinen. Basis-URL und API-Keys findest du in Cluvo unter Einstellungen → Integration.', 'cluvo' ); ?></p>

			<form action="options.php" method="post">
				<?php settings_fields( 'cluvo_settings_group' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cluvo_base_url"><?php esc_html_e( 'API-Basis-URL', 'cluvo' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( $opt ); ?>[base_url]" id="cluvo_base_url" type="url" class="regular-text code" value="<?php echo esc_attr( $settings['base_url'] ); ?>" placeholder="https://cluvo.ch/api/v1" />
							<p class="description"><?php esc_html_e( 'Für alle Vereine gleich.', 'cluvo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cluvo_cache_ttl"><?php esc_html_e( 'Cache-Dauer (Sekunden)', 'cluvo' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( $opt ); ?>[cache_ttl]" id="cluvo_cache_ttl" type="number" min="30" step="30" value="<?php echo esc_attr( $settings['cache_ttl'] ); ?>" />
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Vereine', 'cluvo' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Jeder Verein hat einen eigenen API-Key. Die ID nutzt du im Shortcode, z.B. account="1".', 'cluvo' ); ?></p>

				<table class="widefat striped" id="cluvo-accounts" style="max-width:900px;margin-top:10px;">
					<thead>
						<tr>
							<th style="width:60px;"><?php esc_html_e( 'ID', 'cluvo' ); ?></th>
							<th style="width:220px;"><?php esc_html_e( 'Name', 'cluvo' ); ?></th>
							<th><?php esc_html_e( 'API-Key', 'cluvo' ); ?></th>
							<th style="width:160px;"></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $accounts ) ) : ?>
							<?php echo $this->account_row( $opt, array( 'id' => 0, 'name' => '', 'api_key' => '' ), 0 ); // phpcs:ignore ?>
						<?php else : ?>
							<?php foreach ( $accounts as $i => $account ) : ?>
								<?php echo $this->account_row( $opt, $account, $i ); // phpcs:ignore ?>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<p>
					<button type="button" class="button" id="cluvo-add-account"><?php esc_html_e( '+ Verein hinzufügen', 'cluvo' ); ?></button>
				</p>

				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Shortcodes', 'cluvo' ); ?></h2>
			<ul style="list-style:disc;padding-left:20px;">
				<li><code>[cluvo_form account="1" slug="mein-formular"]</code></li>
				<li><code>[cluvo_events account="1" limit="10"]</code></li>
				<li><code>[cluvo_news account="1" limit="6"]</code></li>
			</ul>
			<p class="description"><?php esc_html_e( 'Ist nur ein Verein hinterlegt, kann account="…" weggelassen werden.', 'cluvo' ); ?></p>

			<template id="cluvo-account-template">
				<?php echo $this->account_row( $opt, array( 'id' => 0, 'name' => '', 'api_key' => '' ), '__INDEX__' ); // phpcs:ignore ?>
			</template>

			<script>
			( function () {
				var nonce = '<?php echo esc_js( wp_create_nonce( 'cluvo_test' ) ); ?>';
				var baseInput = document.getElementById( 'cluvo_base_url' );
				var tbody = document.querySelector( '#cluvo-accounts tbody' );
				var addBtn = document.getElementById( 'cluvo-add-account' );
				var tpl = document.getElementById( 'cluvo-account-template' );
				var index = tbody.querySelectorAll( 'tr' ).length;

				addBtn.addEventListener( 'click', function () {
					var html = tpl.innerHTML.replace( /__INDEX__/g, index++ );
					var temp = document.createElement( 'tbody' );
					temp.innerHTML = html;
					tbody.appendChild( temp.firstElementChild );
				} );

				tbody.addEventListener( 'click', function ( e ) {
					var btn = e.target.closest( 'button' );
					if ( ! btn ) { return; }

					if ( btn.classList.contains( 'cluvo-remove-account' ) ) {
						btn.closest( 'tr' ).remove();
						return;
					}

					if ( btn.classList.contains( 'cluvo-test-account' ) ) {
						var row = btn.closest( 'tr' );
						var key = row.querySelector( '.cluvo-key-input' ).value;
						var out = row.querySelector( '.cluvo-test-result' );
						out.textContent = '…';
						var body = new FormData();
						body.append( 'action', 'cluvo_test_connection' );
						body.append( '_ajax_nonce', nonce );
						body.append( 'base_url', baseInput.value );
						body.append( 'api_key', key );
						fetch( ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' } )
							.then( function ( r ) { return r.json(); } )
							.then( function ( res ) {
								out.textContent = res.data && res.data.message ? res.data.message : '?';
								out.style.color = res.success ? '#1a7f37' : '#b32d2e';
							} )
							.catch( function () { out.textContent = 'Fehler'; out.style.color = '#b32d2e'; } );
					}
				} );
			} )();
			</script>
		</div>
		<?php
	}

	/**
	 * Render one account row. $i is only used to group the row's field names.
	 */
	private function account_row( $opt, $account, $i ) {
		$id      = (int) $account['id'];
		$name    = isset( $account['name'] ) ? $account['name'] : '';
		$api_key = isset( $account['api_key'] ) ? $account['api_key'] : '';
		$base    = sprintf( '%s[accounts][%s]', $opt, $i );

		ob_start();
		?>
		<tr>
			<td>
				<input type="hidden" class="cluvo-id-input" name="<?php echo esc_attr( $base ); ?>[id]" value="<?php echo esc_attr( $id ); ?>" />
				<strong><?php echo $id ? esc_html( $id ) : '<span style="color:#888;">' . esc_html__( 'neu', 'cluvo' ) . '</span>'; ?></strong>
			</td>
			<td>
				<input type="text" class="regular-text" name="<?php echo esc_attr( $base ); ?>[name]" value="<?php echo esc_attr( $name ); ?>" placeholder="<?php esc_attr_e( 'Turnverein Musterdorf', 'cluvo' ); ?>" />
			</td>
			<td>
				<input type="password" class="regular-text code cluvo-key-input" name="<?php echo esc_attr( $base ); ?>[api_key]" value="<?php echo esc_attr( $api_key ); ?>" autocomplete="off" />
			</td>
			<td>
				<button type="button" class="button button-small cluvo-test-account"><?php esc_html_e( 'Testen', 'cluvo' ); ?></button>
				<button type="button" class="button button-small cluvo-remove-account" aria-label="<?php esc_attr_e( 'Entfernen', 'cluvo' ); ?>">×</button>
				<span class="cluvo-test-result" style="display:block;font-size:11px;margin-top:4px;"></span>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	public function ajax_test_connection() {
		check_ajax_referer( 'cluvo_test' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'cluvo' ) ) );
		}

		$base_url = isset( $_POST['base_url'] ) ? esc_url_raw( wp_unslash( $_POST['base_url'] ) ) : '';
		$api_key  = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

		$result = $this->client->ping( $base_url, $api_key );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		if ( $result['status'] >= 400 ) {
			wp_send_json_error( array( 'message' => sprintf( __( 'Fehler (Status %d) – Key prüfen.', 'cluvo' ), $result['status'] ) ) );
		}

		$count = isset( $result['body']['data'] ) ? count( $result['body']['data'] ) : 0;
		wp_send_json_success(
			array( 'message' => sprintf( __( 'OK – %d Formular(e).', 'cluvo' ), $count ) )
		);
	}
}
