<?php
/**
 * Renders a Cluvo form schema as native WordPress HTML.
 *
 * The markup posts to the plugin's REST proxy (see Cluvo_Rest), which forwards
 * the submission to Cluvo with the stored API key. Server-side validation in
 * Cluvo remains authoritative; this renderer only mirrors the common fields.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cluvo_Form_Renderer {

	/** Labels for Cluvo's built-in contact fields. */
	private static function labels() {
		return array(
			'first_name'   => __( 'Vorname', 'cluvo' ),
			'last_name'    => __( 'Nachname', 'cluvo' ),
			'company_name' => __( 'Firmenname', 'cluvo' ),
			'email'        => __( 'E-Mail', 'cluvo' ),
			'street'       => __( 'Strasse', 'cluvo' ),
			'number'       => __( 'Nr.', 'cluvo' ),
			'zip'          => __( 'PLZ', 'cluvo' ),
			'city'         => __( 'Ort', 'cluvo' ),
			'membership_fee' => __( 'Mitgliederbeitrag', 'cluvo' ),
		);
	}

	/**
	 * @param array $form       Decoded `data` payload from GET /forms/{slug}.
	 * @param int   $account_id Cluvo account the form belongs to.
	 */
	public static function render( $form, $account_id ) {
		$fields        = isset( $form['fields'] ) ? $form['fields'] : array();
		$custom_fields = array();
		foreach ( isset( $form['custom_fields'] ) ? $form['custom_fields'] : array() as $cf ) {
			$custom_fields[ (int) $cf['id'] ] = $cf;
		}
		$org            = isset( $form['organization'] ) ? $form['organization'] : array();
		$allow_companies = ! empty( $org['allow_companies'] );
		$has_company    = self::has_field( $fields, 'company_name' );

		$labels = self::labels();

		ob_start();
		?>
		<form class="cluvo-form" data-cluvo-form="<?php echo esc_attr( $form['slug'] ); ?>" data-cluvo-account="<?php echo esc_attr( $account_id ); ?>">
			<?php if ( ! empty( $form['description'] ) ) : ?>
				<p class="cluvo-form__description"><?php echo esc_html( $form['description'] ); ?></p>
			<?php endif; ?>

			<div class="cluvo-form__status" hidden></div>

			<?php if ( $allow_companies && $has_company ) : ?>
				<div class="cluvo-field cluvo-field--full">
					<label for="cluvo-contact-type"><?php esc_html_e( 'Typ', 'cluvo' ); ?></label>
					<select id="cluvo-contact-type" name="contact_type">
						<option value="person"><?php esc_html_e( 'Privatperson', 'cluvo' ); ?></option>
						<option value="company"><?php esc_html_e( 'Firma', 'cluvo' ); ?></option>
					</select>
				</div>
			<?php endif; ?>

			<div class="cluvo-form__grid">
			<?php
			foreach ( $fields as $field ) {
				$key      = isset( $field['key'] ) ? $field['key'] : '';
				$width    = isset( $field['width'] ) && 'half' === $field['width'] ? 'half' : 'full';
				$settings = isset( $field['settings'] ) ? $field['settings'] : array();
				$required = ! empty( $settings['required'] ) || in_array( $key, array( 'first_name', 'last_name' ), true );

				if ( 0 === strpos( $key, 'divider_' ) ) {
					echo '<hr class="cluvo-field cluvo-field--full cluvo-divider" />';
					continue;
				}

				if ( 0 === strpos( $key, 'html_' ) ) {
					$content = isset( $field['content'] ) ? $field['content'] : '';
					echo '<div class="cluvo-field cluvo-field--full cluvo-html">' . wp_kses_post( $content ) . '</div>';
					continue;
				}

				if ( 'membership_fee' === $key ) {
					echo self::render_membership_fee( $settings, $labels['membership_fee'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					continue;
				}

				if ( 'membership_interval' === $key ) {
					continue; // Rendered together with membership_fee.
				}

				if ( 0 === strpos( $key, 'cf_' ) ) {
					$id = (int) substr( $key, 3 );
					if ( isset( $custom_fields[ $id ] ) ) {
						echo self::render_custom_field( $custom_fields[ $id ], $width ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
					continue;
				}

				if ( isset( $labels[ $key ] ) ) {
					$type = 'email' === $key ? 'email' : 'text';
					echo self::input_field( $key, $labels[ $key ], $type, $width, $required ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
			}
			?>
			</div>

			<div class="cluvo-form__actions">
				<button type="submit" class="cluvo-form__submit"><?php esc_html_e( 'Absenden', 'cluvo' ); ?></button>
			</div>
		</form>
		<?php
		return ob_get_clean();
	}

	private static function has_field( $fields, $key ) {
		foreach ( $fields as $f ) {
			if ( isset( $f['key'] ) && $f['key'] === $key ) {
				return true;
			}
		}
		return false;
	}

	private static function input_field( $name, $label, $type, $width, $required ) {
		$id  = 'cluvo-' . sanitize_html_class( $name );
		$req = $required ? ' required' : '';
		$star = $required ? ' <span class="cluvo-required">*</span>' : '';

		return sprintf(
			'<div class="cluvo-field cluvo-field--%1$s"><label for="%2$s">%3$s%4$s</label><input type="%5$s" id="%2$s" name="%6$s"%7$s /></div>',
			esc_attr( $width ),
			esc_attr( $id ),
			esc_html( $label ),
			$star,
			esc_attr( $type ),
			esc_attr( $name ),
			$req
		);
	}

	private static function render_membership_fee( $settings, $label ) {
		$enable_intervals = ! empty( $settings['enable_intervals'] );
		$required         = ! empty( $settings['required'] );
		$req              = $required ? ' required' : '';
		$star             = $required ? ' <span class="cluvo-required">*</span>' : '';

		$html = '<div class="cluvo-field cluvo-field--full cluvo-membership">';

		if ( $enable_intervals ) {
			$intervals = isset( $settings['allowed_intervals'] ) ? $settings['allowed_intervals'] : array( 'monthly', 'yearly' );
			$names     = array(
				'monthly' => __( 'monatlich', 'cluvo' ),
				'yearly'  => __( 'jährlich', 'cluvo' ),
			);
			$html .= '<label for="cluvo-membership-interval">' . esc_html__( 'Intervall', 'cluvo' ) . '</label>';
			$html .= '<select id="cluvo-membership-interval" name="membership_interval"' . $req . '>';
			foreach ( $intervals as $interval ) {
				$html .= '<option value="' . esc_attr( $interval ) . '">' . esc_html( isset( $names[ $interval ] ) ? $names[ $interval ] : $interval ) . '</option>';
			}
			$html .= '</select>';
		}

		$presets      = isset( $settings['preset_amounts'] ) ? $settings['preset_amounts'] : array();
		$allow_custom = ! isset( $settings['allow_custom_amount'] ) || $settings['allow_custom_amount'];

		$html .= '<label for="cluvo-membership-fee">' . esc_html( $label ) . $star . '</label>';

		if ( ! empty( $presets ) && ! $allow_custom && ! $enable_intervals ) {
			$html .= '<select id="cluvo-membership-fee" name="membership_fee"' . $req . '>';
			foreach ( $presets as $amount ) {
				$html .= '<option value="' . esc_attr( $amount ) . '">' . esc_html( number_format_i18n( (float) $amount, 2 ) ) . '</option>';
			}
			$html .= '</select>';
		} else {
			$min = isset( $settings['min_amount'] ) ? ' min="' . esc_attr( $settings['min_amount'] ) . '"' : ' min="0"';
			$max = isset( $settings['max_amount'] ) ? ' max="' . esc_attr( $settings['max_amount'] ) . '"' : '';
			$html .= '<input type="number" step="0.01" id="cluvo-membership-fee" name="membership_fee"' . $min . $max . $req . ' />';
		}

		$html .= '</div>';
		return $html;
	}

	private static function render_custom_field( $cf, $width ) {
		$id       = (int) $cf['id'];
		$name     = 'custom_fields[' . $id . ']';
		$dom_id   = 'cluvo-cf-' . $id;
		$label    = isset( $cf['label'] ) ? $cf['label'] : '';
		$type     = isset( $cf['field_type'] ) ? $cf['field_type'] : 'text';
		$required = ! empty( $cf['is_required'] );
		$req      = $required ? ' required' : '';
		$star     = $required ? ' <span class="cluvo-required">*</span>' : '';

		$control = '';

		switch ( $type ) {
			case 'select':
				$options = isset( $cf['options'] ) && is_array( $cf['options'] ) ? $cf['options'] : array();
				$control  = '<select id="' . esc_attr( $dom_id ) . '" name="' . esc_attr( $name ) . '"' . $req . '>';
				$control .= '<option value="">—</option>';
				foreach ( $options as $opt ) {
					$control .= '<option value="' . esc_attr( $opt ) . '">' . esc_html( $opt ) . '</option>';
				}
				$control .= '</select>';
				break;

			case 'checkbox':
				$control = '<input type="hidden" name="' . esc_attr( $name ) . '" value="0" />'
					. '<input type="checkbox" id="' . esc_attr( $dom_id ) . '" name="' . esc_attr( $name ) . '" value="1"' . $req . ' />';
				break;

			case 'textarea':
				$control = '<textarea id="' . esc_attr( $dom_id ) . '" name="' . esc_attr( $name ) . '" rows="4"' . $req . '></textarea>';
				break;

			case 'number':
			case 'date':
			case 'email':
			case 'url':
				$input_type = 'url' === $type ? 'url' : $type;
				$control = '<input type="' . esc_attr( $input_type ) . '" id="' . esc_attr( $dom_id ) . '" name="' . esc_attr( $name ) . '"' . $req . ' />';
				break;

			default:
				$control = '<input type="text" id="' . esc_attr( $dom_id ) . '" name="' . esc_attr( $name ) . '"' . $req . ' />';
		}

		return '<div class="cluvo-field cluvo-field--' . esc_attr( $width ) . '"><label for="' . esc_attr( $dom_id ) . '">' . esc_html( $label ) . $star . '</label>' . $control . '</div>';
	}
}
