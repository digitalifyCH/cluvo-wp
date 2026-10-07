<?php
/**
 * Admin settings page: cache duration plus a list of Cluvo accounts (one per
 * Verein), each with a stable numeric id used in shortcodes.
 *
 * Stored API keys are never printed into the page again; leaving the key
 * field empty keeps the saved key.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cluvo_Settings {

	const PAGE = 'cluvo';

	/** @var Cluvo_Client */
	private $client;

	/** @var Cluvo_Updater */
	private $updater;

	/** @var array Settings errors already added in this request (sanitize may run twice). */
	private $errors = array();

	public function __construct( Cluvo_Client $client, Cluvo_Updater $updater ) {
		$this->client  = $client;
		$this->updater = $updater;

		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_cluvo_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'admin_post_cluvo_flush_cache', array( $this, 'handle_flush_cache' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CLUVO_PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	public function add_menu() {
		add_options_page(
			__( 'Cluvo', 'cluvo' ),
			__( 'Cluvo', 'cluvo' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Einstellungen', 'cluvo' ) . '</a>' );
		return $links;
	}

	public function register_settings() {
		register_setting(
			'cluvo_settings_group',
			Cluvo_Client::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);
	}

	public function enqueue_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}

		wp_enqueue_style( 'cluvo-admin', CLUVO_PLUGIN_URL . 'assets/cluvo-admin.css', array(), CLUVO_VERSION );
		wp_enqueue_script( 'cluvo-admin', CLUVO_PLUGIN_URL . 'assets/cluvo-admin.js', array(), CLUVO_VERSION, true );
		wp_localize_script(
			'cluvo-admin',
			'CluvoAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'cluvo_test' ),
				'i18n'    => array(
					'testing'   => __( 'Verbindung wird geprüft …', 'cluvo' ),
					'error'     => __( 'Die Prüfung ist fehlgeschlagen. Bitte versuche es erneut.', 'cluvo' ),
					'noKey'     => __( 'Bitte zuerst einen API-Key eintragen.', 'cluvo' ),
					'saveFirst' => __( 'Speichere die Einstellungen, damit der Verein eine ID bekommt.', 'cluvo' ),
				),
			)
		);
	}

	/**
	 * Sanitize input, assign stable ids to new accounts and flush the cache.
	 */
	public function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$existing = $this->client->get_settings();
		$next_id  = max( 1, (int) $existing['next_id'] );

		$saved_keys = array();
		foreach ( $this->client->get_accounts() as $account ) {
			$saved_keys[ (int) $account['id'] ] = $account['api_key'];
		}

		$accounts = array();
		$used     = array();
		$rows     = isset( $input['accounts'] ) && is_array( $input['accounts'] ) ? $input['accounts'] : array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$name = isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '';
			$key  = isset( $row['api_key'] ) ? preg_replace( '/\s+/', '', sanitize_text_field( $row['api_key'] ) ) : '';
			$id   = isset( $row['id'] ) ? absint( $row['id'] ) : 0;

			if ( '' === $key && $id && ! empty( $saved_keys[ $id ] ) ) {
				$key = $saved_keys[ $id ];
			}

			// Drop fully empty rows.
			if ( '' === $name && '' === $key ) {
				continue;
			}

			if ( $id <= 0 || isset( $used[ $id ] ) ) {
				$id = $next_id;
			}
			$next_id     = max( $next_id, $id + 1 );
			$used[ $id ] = true;

			if ( '' === $key ) {
				/* translators: %s: name of the Verein */
				$this->add_error( sprintf( __( 'Für «%s» fehlt der API-Key.', 'cluvo' ), $name ) );
			}

			$accounts[] = array(
				'id'      => $id,
				'name'    => $name,
				'api_key' => $key,
			);
		}

		Cluvo_Client::flush_cache();

		return array(
			'cache_ttl' => isset( $input['cache_ttl'] ) ? max( 30, absint( $input['cache_ttl'] ) ) : 600,
			'accounts'  => $accounts,
			'next_id'   => $next_id,
		);
	}

	private function add_error( $message ) {
		if ( in_array( $message, $this->errors, true ) ) {
			return;
		}
		$this->errors[] = $message;
		add_settings_error( Cluvo_Client::OPTION_KEY, 'cluvo_' . count( $this->errors ), $message, 'warning' );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->client->get_settings();
		$opt      = Cluvo_Client::OPTION_KEY;
		$accounts = $this->client->get_accounts();
		$example  = count( $accounts ) > 1 ? ' account="' . (int) $accounts[0]['id'] . '"' : '';
		?>
		<div class="wrap cluvo-admin">
			<h1><?php esc_html_e( 'Cluvo', 'cluvo' ); ?></h1>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( isset( $_GET['cluvo_flushed'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Der Cache wurde geleert.', 'cluvo' ); ?></p></div>
			<?php endif; ?>
			<?php Cluvo_Settings::update_check_notice(); ?>

			<p><?php esc_html_e( 'Verbinde diese Webseite mit einem oder mehreren Cluvo-Vereinen. Einen API-Key erstellst du in Cluvo unter Einstellungen → Integration.', 'cluvo' ); ?></p>

			<form action="options.php" method="post">
				<?php settings_fields( 'cluvo_settings_group' ); ?>

				<h2><?php esc_html_e( 'Vereine', 'cluvo' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Jeder Verein hat einen eigenen API-Key. Die ID nutzt du im Shortcode, z.B. account="1". Gespeicherte Keys werden nicht mehr angezeigt – lässt du das Feld leer, bleibt der Key erhalten.', 'cluvo' ); ?></p>

				<table class="widefat striped cluvo-accounts" id="cluvo-accounts">
					<thead>
						<tr>
							<th scope="col" class="cluvo-accounts__id"><?php esc_html_e( 'ID', 'cluvo' ); ?></th>
							<th scope="col" class="cluvo-accounts__name"><?php esc_html_e( 'Name', 'cluvo' ); ?></th>
							<th scope="col"><?php esc_html_e( 'API-Key', 'cluvo' ); ?></th>
							<th scope="col" class="cluvo-accounts__actions"><span class="screen-reader-text"><?php esc_html_e( 'Aktionen', 'cluvo' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
						<?php
						if ( empty( $accounts ) ) {
							echo $this->account_row( $opt, array(), 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
						}
						foreach ( $accounts as $i => $account ) {
							echo $this->account_row( $opt, $account, $i ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
						}
						?>
					</tbody>
				</table>

				<p>
					<button type="button" class="button" id="cluvo-add-account"><?php esc_html_e( '+ Verein hinzufügen', 'cluvo' ); ?></button>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cluvo_cache_ttl"><?php esc_html_e( 'Cache-Dauer (Sekunden)', 'cluvo' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( $opt ); ?>[cache_ttl]" id="cluvo_cache_ttl" type="number" min="30" step="30" class="small-text" value="<?php echo esc_attr( $settings['cache_ttl'] ); ?>" aria-describedby="cluvo_cache_ttl_help" />
							<p class="description" id="cluvo_cache_ttl_help"><?php esc_html_e( 'So lange werden Formulare, Veranstaltungen und News zwischengespeichert. Ist Cluvo nicht erreichbar, zeigt das Plugin bis zu einem Tag lang den letzten Stand.', 'cluvo' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="cluvo_flush_cache" />
				<?php wp_nonce_field( 'cluvo_flush_cache' ); ?>
				<p>
					<button type="submit" class="button"><?php esc_html_e( 'Cache leeren', 'cluvo' ); ?></button>
					<span class="description"><?php esc_html_e( 'Änderungen aus Cluvo sofort anzeigen.', 'cluvo' ); ?></span>
				</p>
			</form>

			<?php $this->render_updates(); ?>

			<hr />
			<h2><?php esc_html_e( 'Shortcodes', 'cluvo' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Ist nur ein Verein hinterlegt, kann account="…" weggelassen werden. «Testen» zeigt die Shortcodes der aktiven Formulare eines Vereins.', 'cluvo' ); ?></p>

			<table class="widefat striped cluvo-shortcodes">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Shortcode', 'cluvo' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Attribute', 'cluvo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><code>[cluvo_form<?php echo esc_html( $example ); ?> slug="mitglied-werden"]</code></td>
						<td><?php echo wp_kses( __( '<code>slug</code> – Slug des Formulars in Cluvo (Pflicht)', 'cluvo' ), array( 'code' => array() ) ); ?></td>
					</tr>
					<tr>
						<td><code>[cluvo_events<?php echo esc_html( $example ); ?> limit="10"]</code></td>
						<td><?php echo wp_kses( __( '<code>limit</code> – Anzahl Termine (Serien einzeln gezählt, Standard 10) · <code>category</code> – ID der Kategorie · <code>from</code>, <code>to</code> – Zeitraum als JJJJ-MM-TT (Standard: ab heute für 12 Monate) · <code>layout="cards"</code> – Karten mit Titelbild statt Liste', 'cluvo' ), array( 'code' => array() ) ); ?></td>
					</tr>
					<tr>
						<td><code>[cluvo_news<?php echo esc_html( $example ); ?> limit="6"]</code></td>
						<td><?php echo wp_kses( __( '<code>limit</code> – Anzahl pro Seite (Standard 6, höchstens 50) · <code>category</code> – ID der Kategorie · <code>pagination="1"</code> – Links zu älteren News', 'cluvo' ), array( 'code' => array() ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<template id="cluvo-account-template">
				<?php echo $this->account_row( $opt, array(), '__INDEX__' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
			</template>
		</div>
		<?php
	}

	/**
	 * Installed and published version, auto-update state and a button that asks
	 * the update server right away.
	 */
	private function render_updates() {
		$status    = $this->updater->get_status();
		$latest    = isset( $status['info']['version'] ) ? $status['info']['version'] : '';
		$available = '' !== $latest && version_compare( CLUVO_VERSION, $latest, '<' );
		$basename  = plugin_basename( CLUVO_PLUGIN_FILE );
		$can       = current_user_can( 'update_plugins' );
		$auto      = function_exists( 'wp_is_auto_update_enabled_for_type' ) && wp_is_auto_update_enabled_for_type( 'plugin' )
			&& in_array( $basename, (array) get_site_option( 'auto_update_plugins', array() ), true );
		?>
		<hr />
		<h2><?php esc_html_e( 'Updates', 'cluvo' ); ?></h2>
		<table class="form-table cluvo-updates" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Installierte Version', 'cluvo' ); ?></th>
				<td><code><?php echo esc_html( CLUVO_VERSION ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Neueste Version', 'cluvo' ); ?></th>
				<td>
					<?php if ( '' === $latest ) : ?>
						<span>–</span>
					<?php else : ?>
						<code><?php echo esc_html( $latest ); ?></code>
						<?php if ( $available ) : ?>
							<span class="cluvo-badge cluvo-badge--update"><?php esc_html_e( 'Update verfügbar', 'cluvo' ); ?></span>
						<?php else : ?>
							<span class="cluvo-badge cluvo-badge--ok"><?php esc_html_e( 'Aktuell', 'cluvo' ); ?></span>
						<?php endif; ?>
					<?php endif; ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: date and time of the last check */
							esc_html__( 'Zuletzt geprüft: %s', 'cluvo' ),
							esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $status['checked'] ) )
						);
						?>
					</p>
					<?php if ( '' !== $status['error'] ) : ?>
						<p class="description cluvo-updates__error">
							<?php
							/* translators: %s: technical error message */
							printf( esc_html__( 'Der Update-Server war nicht erreichbar (%s). WordPress versucht es später erneut.', 'cluvo' ), esc_html( $status['error'] ) );
							?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Automatische Updates', 'cluvo' ); ?></th>
				<td>
					<?php echo $auto ? esc_html__( 'Eingeschaltet', 'cluvo' ) : esc_html__( 'Ausgeschaltet', 'cluvo' ); ?>
					<p class="description"><?php esc_html_e( 'Ein- und ausschalten unter Plugins in der Zeile von Cluvo. Neue Versionen kommen von wp.cluvo.ch.', 'cluvo' ); ?></p>
				</td>
			</tr>
		</table>
		<?php if ( $can ) : ?>
			<p>
				<?php if ( $available ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $basename ) ), 'upgrade-plugin_' . $basename ) ); ?>">
						<?php
						/* translators: %s: version number */
						printf( esc_html__( 'Auf %s aktualisieren', 'cluvo' ), esc_html( $latest ) );
						?>
					</a>
				<?php endif; ?>
				<a class="button" href="<?php echo esc_url( Cluvo_Updater::check_url( 'options-general.php?page=' . self::PAGE ) ); ?>"><?php esc_html_e( 'Jetzt nach Updates suchen', 'cluvo' ); ?></a>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Result of "Check for updates" – on this page and in the plugin list.
	 */
	public static function update_check_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$result = isset( $_GET['cluvo_update_check'] ) ? sanitize_key( wp_unslash( $_GET['cluvo_update_check'] ) ) : '';

		$messages = array(
			'available' => array( 'warning', __( 'Eine neue Version von Cluvo ist verfügbar.', 'cluvo' ) ),
			'current'   => array( 'success', __( 'Cluvo ist auf dem neuesten Stand.', 'cluvo' ) ),
			'error'     => array( 'error', __( 'Der Update-Server von Cluvo ist gerade nicht erreichbar. Bitte versuche es später erneut.', 'cluvo' ) ),
		);

		if ( ! isset( $messages[ $result ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $result ][0] ),
			esc_html( $messages[ $result ][1] )
		);
	}

	/**
	 * Render one account row. $i is only used to group the row's field names.
	 */
	private function account_row( $opt, $account, $i ) {
		$id      = isset( $account['id'] ) ? (int) $account['id'] : 0;
		$name    = isset( $account['name'] ) ? $account['name'] : '';
		$has_key = ! empty( $account['api_key'] );
		$base    = sprintf( '%s[accounts][%s]', $opt, $i );
		$dom     = 'cluvo-account-' . $i;

		$placeholder = $has_key
			/* translators: %s: last four characters of the stored API key */
			? sprintf( __( 'Gespeichert (…%s)', 'cluvo' ), substr( $account['api_key'], -4 ) )
			: __( 'API-Key einfügen', 'cluvo' );

		ob_start();
		?>
		<tr>
			<td>
				<input type="hidden" name="<?php echo esc_attr( $base ); ?>[id]" value="<?php echo esc_attr( $id ); ?>" />
				<strong><?php echo $id ? esc_html( $id ) : '<span class="cluvo-muted">' . esc_html__( 'neu', 'cluvo' ) . '</span>'; ?></strong>
			</td>
			<td>
				<label class="screen-reader-text" for="<?php echo esc_attr( $dom ); ?>-name"><?php esc_html_e( 'Name', 'cluvo' ); ?></label>
				<input type="text" class="regular-text" id="<?php echo esc_attr( $dom ); ?>-name" name="<?php echo esc_attr( $base ); ?>[name]" value="<?php echo esc_attr( $name ); ?>" placeholder="<?php esc_attr_e( 'Turnverein Musterdorf', 'cluvo' ); ?>" />
			</td>
			<td>
				<label class="screen-reader-text" for="<?php echo esc_attr( $dom ); ?>-key"><?php esc_html_e( 'API-Key', 'cluvo' ); ?></label>
				<input type="password" class="regular-text code cluvo-key-input" id="<?php echo esc_attr( $dom ); ?>-key" name="<?php echo esc_attr( $base ); ?>[api_key]" value="" placeholder="<?php echo esc_attr( $placeholder ); ?>" autocomplete="new-password" spellcheck="false" data-has-key="<?php echo $has_key ? '1' : '0'; ?>" />
			</td>
			<td>
				<button type="button" class="button button-small cluvo-test-account" data-account="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Testen', 'cluvo' ); ?></button>
				<button type="button" class="button button-small button-link-delete cluvo-remove-account"><?php esc_html_e( 'Entfernen', 'cluvo' ); ?></button>
				<div class="cluvo-test-result" role="status" aria-live="polite"></div>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	public function ajax_test_connection() {
		check_ajax_referer( 'cluvo_test' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'cluvo' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$api_key    = isset( $_POST['api_key'] ) ? preg_replace( '/\s+/', '', sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';
		$account_id = isset( $_POST['account_id'] ) ? absint( $_POST['account_id'] ) : 0;

		if ( '' === $api_key && $account_id ) {
			$account = $this->client->get_account( $account_id );
			$api_key = $account ? $account['api_key'] : '';
		}

		if ( '' === $api_key ) {
			wp_send_json_error( array( 'message' => __( 'Bitte zuerst einen API-Key eintragen.', 'cluvo' ) ) );
		}

		$result = $this->client->ping( $api_key );
		$error  = Cluvo_Client::response_error( $result );

		if ( $error ) {
			wp_send_json_error( array( 'message' => $error->get_error_message() ) );
		}

		$forms = array();
		foreach ( isset( $result['body']['data'] ) && is_array( $result['body']['data'] ) ? $result['body']['data'] : array() as $form ) {
			if ( isset( $form['slug'] ) ) {
				$forms[] = array(
					'name' => isset( $form['name'] ) ? (string) $form['name'] : '',
					'slug' => (string) $form['slug'],
				);
			}
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of active forms */
					_n( 'Verbunden – %d aktives Formular.', 'Verbunden – %d aktive Formulare.', count( $forms ), 'cluvo' ),
					count( $forms )
				),
				'forms'   => $forms,
			)
		);
	}

	public function handle_flush_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'cluvo' ), 403 );
		}
		check_admin_referer( 'cluvo_flush_cache' );

		Cluvo_Client::flush_cache();

		wp_safe_redirect( add_query_arg( 'cluvo_flushed', '1', admin_url( 'options-general.php?page=' . self::PAGE ) ) );
		exit;
	}
}
