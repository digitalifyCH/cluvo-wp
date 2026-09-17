<?php
/**
 * Renders a Cluvo form schema as native WordPress HTML.
 *
 * The markup posts to the plugin's REST proxy (see Cluvo_Rest), which forwards
 * the submission to Cluvo with the stored API key. Server-side validation in
 * Cluvo remains authoritative; this renderer mirrors the public form on the
 * Cluvo website: name block, fixed memberships, free contribution, custom fields.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cluvo_Form_Renderer {

	/** Keys of the name block. The API sends first_name/last_name/company_name, older versions `name`. */
	const NAME_KEYS = array( 'name', 'first_name', 'last_name', 'company_name' );

	const WIDTHS = array( 'full', 'half', 'third', 'two-thirds' );

	const INTERVALS = array( 'monthly', 'yearly' );

	/** @var int Numbers the forms on a page, so DOM ids stay unique. */
	private static $count = 0;

	/** @var array */
	private $form;

	/** @var int */
	private $account_id;

	/** @var string */
	private $prefix;

	/** @var array<int,array> */
	private $custom_fields = array();

	/** @var array<int,array> */
	private $membership_types = array();

	/** @var bool */
	private $allow_companies;

	/** @var bool */
	private $has_required = false;

	/** @var bool */
	private $interval_rendered = false;

	/**
	 * @param array $form       Decoded `data` payload from GET /forms/{slug}.
	 * @param int   $account_id Cluvo account the form belongs to.
	 */
	public static function render( $form, $account_id ) {
		$renderer = new self( $form, $account_id );
		return $renderer->html();
	}

	private function __construct( $form, $account_id ) {
		++self::$count;

		$this->form       = is_array( $form ) ? $form : array();
		$this->account_id = (int) $account_id;
		$this->prefix     = 'cluvo-form-' . self::$count;

		foreach ( self::items( $this->form, 'custom_fields' ) as $cf ) {
			if ( isset( $cf['id'] ) ) {
				$this->custom_fields[ (int) $cf['id'] ] = $cf;
			}
		}

		foreach ( self::items( $this->form, 'membership_types' ) as $type ) {
			if ( isset( $type['id'], $type['name'] ) ) {
				$this->membership_types[] = $type;
			}
		}

		$org                   = isset( $this->form['organization'] ) && is_array( $this->form['organization'] ) ? $this->form['organization'] : array();
		$this->allow_companies = ! empty( $org['allow_companies'] );
	}

	private function html() {
		$slug     = isset( $this->form['slug'] ) ? (string) $this->form['slug'] : '';
		$endpoint = rest_url( sprintf( 'cluvo/v1/accounts/%d/forms/%s/submit', $this->account_id, rawurlencode( $slug ) ) );

		$fields = '';
		$named  = false;

		foreach ( self::items( $this->form, 'fields' ) as $field ) {
			$key = isset( $field['key'] ) ? (string) $field['key'] : '';

			if ( in_array( $key, self::NAME_KEYS, true ) ) {
				if ( ! $named ) {
					$fields .= $this->name_block();
					$named   = true;
				}
				continue;
			}

			$fields .= $this->field( $key, $field );
		}

		// Cluvo needs a name for every contact, even if the schema lacks it.
		if ( ! $named ) {
			$fields = $this->name_block() . $fields;
		}

		$org    = isset( $this->form['organization'] ) && is_array( $this->form['organization'] ) ? $this->form['organization'] : array();
		$accent = sanitize_hex_color( (string) apply_filters( 'cluvo_accent_color', isset( $org['primary_color'] ) ? (string) $org['primary_color'] : '', $this->form ) );

		ob_start();
		?>
		<form class="cluvo cluvo-form" method="post" action="<?php echo esc_url( $endpoint ); ?>" data-cluvo-form="<?php echo esc_attr( $slug ); ?>"<?php echo $accent ? ' style="--cluvo-org-accent:' . esc_attr( $accent ) . '"' : ''; ?>>
			<?php if ( ! empty( $this->form['description'] ) ) : ?>
				<p class="cluvo-form__description"><?php echo esc_html( $this->form['description'] ); ?></p>
			<?php endif; ?>

			<div class="cluvo-form__status" role="status" aria-live="polite" tabindex="-1" hidden></div>

			<div class="cluvo-form__body">
				<div class="cluvo-form__grid">
					<?php echo $fields; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while building. ?>
				</div>

				<div class="cluvo-hp" aria-hidden="true">
					<label for="<?php echo esc_attr( $this->prefix . '-hp' ); ?>"><?php esc_html_e( 'Dieses Feld bitte leer lassen', 'cluvo' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $this->prefix . '-hp' ); ?>" name="<?php echo esc_attr( Cluvo_Rest::HONEYPOT ); ?>" value="" tabindex="-1" autocomplete="off" />
				</div>

				<div class="cluvo-form__actions">
					<?php if ( $this->has_required ) : ?>
						<p class="cluvo-form__hint"><?php esc_html_e( '* Pflichtfeld', 'cluvo' ); ?></p>
					<?php endif; ?>
					<button type="submit" class="cluvo-button cluvo-form__submit"><?php esc_html_e( 'Absenden', 'cluvo' ); ?></button>
				</div>
			</div>
		</form>
		<?php
		return ob_get_clean();
	}

	private function field( $key, $field ) {
		$settings = isset( $field['settings'] ) && is_array( $field['settings'] ) ? $field['settings'] : array();
		$width    = isset( $field['width'] ) && in_array( $field['width'], self::WIDTHS, true ) ? $field['width'] : 'full';

		if ( 0 === strpos( $key, 'divider_' ) ) {
			return '<hr class="cluvo-col--full cluvo-divider" />';
		}

		// Zwischentitel: plain text in Cluvo's form builder.
		if ( 0 === strpos( $key, 'html_' ) ) {
			$content = isset( $field['content'] ) ? trim( wp_strip_all_tags( (string) $field['content'] ) ) : '';
			return '' === $content ? '' : '<p class="cluvo-col--' . esc_attr( $width ) . ' cluvo-form__heading">' . esc_html( $content ) . '</p>';
		}

		if ( 0 === strpos( $key, 'cf_' ) ) {
			$id = (int) substr( $key, 3 );
			return isset( $this->custom_fields[ $id ] ) ? $this->custom_field( $this->custom_fields[ $id ], $width ) : '';
		}

		switch ( $key ) {
			case 'membership':
				return $this->membership( $settings, $width );

			case 'membership_fee':
				return $this->membership_fee( $settings, $width );

			case 'membership_interval':
				return $this->interval_rendered ? '' : $this->interval_field( $settings, $width );
		}

		$contact_fields = array(
			'email'  => array( __( 'E-Mail', 'cluvo' ), 'email', 'email' ),
			'street' => array( __( 'Strasse', 'cluvo' ), 'text', 'address-line1' ),
			'number' => array( __( 'Nr.', 'cluvo' ), 'text', '' ),
			'zip'    => array( __( 'PLZ', 'cluvo' ), 'text', 'postal-code' ),
			'city'   => array( __( 'Ort', 'cluvo' ), 'text', 'address-level2' ),
		);

		if ( ! isset( $contact_fields[ $key ] ) ) {
			return '';
		}

		list( $label, $type, $autocomplete ) = $contact_fields[ $key ];

		return $this->text_field(
			$key,
			$label,
			array(
				'type'         => $type,
				'width'        => $width,
				'required'     => ! empty( $settings['required'] ),
				'placeholder'  => isset( $settings['placeholder'] ) ? $settings['placeholder'] : '',
				'help'         => isset( $settings['help_text'] ) ? $settings['help_text'] : '',
				'autocomplete' => $autocomplete,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Name block
	 * ------------------------------------------------------------------- */

	/**
	 * Switch Privatperson/Unternehmen (only if the Verein accepts companies),
	 * company name (only for companies) and first/last name, which become
	 * optional for companies. cluvo.js toggles the parts.
	 */
	private function name_block() {
		$this->has_required = true;

		$html = '<div class="cluvo-col--full cluvo-name" data-cluvo-name>';

		if ( $this->allow_companies ) {
			$html .= '<fieldset class="cluvo-field cluvo-name__type">';
			$html .= '<legend class="cluvo-sr-only">' . esc_html__( 'Anmeldung als', 'cluvo' ) . '</legend>';
			$html .= '<div class="cluvo-segmented">';
			foreach ( array(
				'person'  => __( 'Privatperson', 'cluvo' ),
				'company' => __( 'Unternehmen', 'cluvo' ),
			) as $value => $label ) {
				$html .= '<label class="cluvo-segmented__option">'
					. '<input type="radio" class="cluvo-segmented__input" name="contact_type" value="' . esc_attr( $value ) . '"' . checked( 'person', $value, false ) . ' />'
					. '<span class="cluvo-segmented__label">' . esc_html( $label ) . '</span>'
					. '</label>';
			}
			$html .= '</div></fieldset>';

			$html .= '<div class="cluvo-name__company" data-cluvo-company hidden>';
			$html .= $this->text_field(
				'company_name',
				__( 'Firmenname', 'cluvo' ),
				array(
					'required'     => true,
					'width'        => '',
					'autocomplete' => 'organization',
					'attrs'        => array( 'disabled' => true ),
				)
			);
			$html .= '</div>';
		}

		foreach ( array(
			'first_name' => array( __( 'Vorname', 'cluvo' ), 'given-name' ),
			'last_name'  => array( __( 'Nachname', 'cluvo' ), 'family-name' ),
		) as $key => $config ) {
			$html .= $this->text_field(
				$key,
				$config[0],
				array(
					'required'     => true,
					'width'        => '',
					'autocomplete' => $config[1],
					'attrs'        => array( 'data-cluvo-person-name' => true ),
				)
			);
		}

		return $html . '</div>';
	}

	/* ---------------------------------------------------------------------
	 * Fixed memberships
	 * ------------------------------------------------------------------- */

	/**
	 * Radio cards with name, description and price. Real radio inputs, so
	 * arrow keys and the browser's required check work as usual.
	 */
	private function membership( $settings, $width ) {
		$types = $this->membership_types;
		if ( empty( $types ) ) {
			return '';
		}

		$required = ! empty( $settings['required'] );
		$help     = $this->help( 'membership', isset( $settings['help_text'] ) ? $settings['help_text'] : '' );
		// A single required choice is already made.
		$preselect = $required && 1 === count( $types );

		$html  = '<fieldset class="cluvo-field cluvo-col--' . esc_attr( $width ) . '"' . $help['describedby'] . '>';
		$html .= '<legend class="cluvo-label">' . esc_html__( 'Mitgliedschaft', 'cluvo' ) . $this->star( $required ) . '</legend>';
		$html .= '<div class="cluvo-cards">';

		foreach ( $types as $type ) {
			$description = isset( $type['description'] ) ? trim( (string) $type['description'] ) : '';
			$interval    = isset( $type['interval'] ) ? (string) $type['interval'] : '';

			$html .= '<label class="cluvo-card">';
			$html .= '<input type="radio" class="cluvo-card__input" name="membership_type_id" value="' . esc_attr( (int) $type['id'] ) . '"'
				. ( $preselect ? ' checked' : '' ) . ( $required ? ' required' : '' ) . ' />';
			$html .= '<span class="cluvo-card__box">';
			$html .= '<span class="cluvo-card__radio" aria-hidden="true"></span>';
			$html .= '<span class="cluvo-card__text">';
			$html .= '<span class="cluvo-card__title">' . esc_html( $type['name'] ) . '</span>';
			if ( '' !== $description ) {
				$html .= '<span class="cluvo-card__description">' . esc_html( $description ) . '</span>';
			}
			$html .= '</span>';
			if ( isset( $type['amount'] ) && is_numeric( $type['amount'] ) ) {
				$html .= '<span class="cluvo-card__price">'
					. '<span class="cluvo-card__amount">' . esc_html( self::chf( $type['amount'] ) ) . '</span> '
					. '<span class="cluvo-card__interval">' . esc_html( self::per_interval( $interval ) ) . '</span>'
					. '</span>';
			}
			$html .= '</span></label>';
		}

		return $html . '</div>' . $help['html'] . '</fieldset>';
	}

	/* ---------------------------------------------------------------------
	 * Free contribution
	 * ------------------------------------------------------------------- */

	/**
	 * Mirrors Cluvo: optional interval choice first, then preset amounts for
	 * that interval (plus «Individueller Betrag») or a free amount input.
	 */
	private function membership_fee( $settings, $width ) {
		$required         = ! empty( $settings['required'] );
		$enable_intervals = ! empty( $settings['enable_intervals'] );
		$help             = $this->help( 'membership_fee', isset( $settings['help_text'] ) ? $settings['help_text'] : '' );

		$html = '<div class="cluvo-field cluvo-col--' . esc_attr( $width ) . ' cluvo-fee" data-cluvo-fee>';

		if ( ! $enable_intervals ) {
			$html .= $this->fee_group( '', self::amounts( $settings, 'preset_amounts' ), $settings, true, $help['id'] );
			return $html . $help['html'] . '</div>';
		}

		$intervals = self::allowed_intervals( $settings );
		$selected  = isset( $settings['default_interval'] ) && in_array( $settings['default_interval'], $intervals, true )
			? $settings['default_interval']
			: ( 1 === count( $intervals ) ? $intervals[0] : '' );

		$html                   .= $this->interval_choice( $intervals, $selected, $required );
		$this->interval_rendered = true;

		foreach ( $intervals as $interval ) {
			$html .= $this->fee_group( $interval, self::amounts( $settings, 'preset_amounts_' . $interval ), $settings, $interval === $selected, $help['id'] );
		}

		return $html . $help['html'] . '</div>';
	}

	/**
	 * Amount choice for one interval (or the only one, $interval = '').
	 * Inactive groups are hidden and disabled, so they are neither validated
	 * nor submitted.
	 */
	private function fee_group( $interval, $presets, $settings, $active, $help_id ) {
		$required     = ! empty( $settings['required'] );
		$allow_custom = ! isset( $settings['allow_custom_amount'] ) || ! empty( $settings['allow_custom_amount'] );
		$default      = isset( $settings['default_amount'] ) && is_numeric( $settings['default_amount'] ) ? (float) $settings['default_amount'] : null;
		$id           = $this->prefix . '-fee' . ( $interval ? '-' . $interval : '' );
		$disabled     = $active ? '' : ' disabled';
		$label        = __( 'Beitrag', 'cluvo' );

		$number_attrs = array(
			'type'             => 'number',
			'class'            => 'cluvo-input',
			'id'               => $id,
			'inputmode'        => 'decimal',
			'step'             => '0.01',
			'min'              => isset( $settings['min_amount'] ) && is_numeric( $settings['min_amount'] ) ? (string) $settings['min_amount'] : '0',
			'max'              => isset( $settings['max_amount'] ) && is_numeric( $settings['max_amount'] ) ? (string) $settings['max_amount'] : '',
			'placeholder'      => isset( $settings['placeholder'] ) && '' !== $settings['placeholder'] ? $settings['placeholder'] : '0.00',
			'aria-describedby' => $help_id,
			'disabled'         => ! $active,
		);

		$html = '<div class="cluvo-fee__group" data-cluvo-fee-group="' . esc_attr( $interval ) . '"' . ( $active ? '' : ' hidden' ) . '>';

		if ( empty( $presets ) ) {
			$number_attrs['name']     = 'membership_fee';
			$number_attrs['required'] = $required;
			$number_attrs['value']    = null !== $default ? (string) $default : '';

			return $html
				. '<label class="cluvo-label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . $this->star( $required ) . '</label>'
				. self::affix( '<input' . self::attributes( $number_attrs ) . ' />' )
				. '</div>';
		}

		$matches_preset = false;
		$cards          = '';
		foreach ( $presets as $amount ) {
			$checked        = null !== $default && abs( (float) $amount - $default ) < 0.001;
			$matches_preset = $matches_preset || $checked;
			$cards         .= $this->choice_card( 'membership_fee', (string) $amount, self::chf( $amount ), $checked, $required, $disabled );
		}

		// A default amount that is no preset opens the custom amount.
		$custom = $allow_custom && null !== $default && ! $matches_preset;

		if ( $allow_custom ) {
			$cards .= $this->choice_card( 'membership_fee', '', __( 'Individueller Betrag', 'cluvo' ), $custom, $required, $disabled, ' data-cluvo-custom' );
		}

		$number_attrs['disabled']                = ! ( $active && $custom );
		$number_attrs['required']                = $required && $custom;
		$number_attrs['value']                   = $custom ? (string) $default : '';
		$number_attrs['data-cluvo-custom-input'] = true;

		$html .= '<fieldset class="cluvo-fee__part"' . ( $help_id ? ' aria-describedby="' . esc_attr( $help_id ) . '"' : '' ) . '>';
		$html .= '<legend class="cluvo-label">' . esc_html( $label ) . $this->star( $required ) . '</legend>';
		$html .= '<div class="cluvo-cards cluvo-cards--compact">' . $cards . '</div>';
		if ( $allow_custom ) {
			$html .= '<div class="cluvo-fee__custom" data-cluvo-custom-wrap' . ( $custom ? '' : ' hidden' ) . '>'
				. '<label class="cluvo-sr-only" for="' . esc_attr( $id ) . '">' . esc_html__( 'Individueller Betrag in CHF', 'cluvo' ) . '</label>'
				. self::affix( '<input' . self::attributes( $number_attrs ) . ' />' )
				. '</div>';
		}
		$html .= '</fieldset>';

		return $html . '</div>';
	}

	/**
	 * Stand-alone interval field (older forms without enable_intervals).
	 */
	private function interval_field( $settings, $width ) {
		$required  = ! empty( $settings['required'] );
		$intervals = self::allowed_intervals( $settings );
		$help      = $this->help( 'membership_interval', isset( $settings['help_text'] ) ? $settings['help_text'] : '' );
		$selected  = 1 === count( $intervals ) ? $intervals[0] : '';

		$this->interval_rendered = true;

		return '<div class="cluvo-field cluvo-col--' . esc_attr( $width ) . '">'
			. $this->interval_choice( $intervals, $selected, $required )
			. $help['html']
			. '</div>';
	}

	private function interval_choice( $intervals, $selected, $required ) {
		$names = array(
			'monthly' => __( 'Monatlich', 'cluvo' ),
			'yearly'  => __( 'Jährlich', 'cluvo' ),
		);

		if ( $required ) {
			$this->has_required = true;
		}

		$html  = '<fieldset class="cluvo-fee__part">';
		$html .= '<legend class="cluvo-label">' . esc_html__( 'Intervall', 'cluvo' ) . $this->star( $required ) . '</legend>';
		$html .= '<div class="cluvo-cards cluvo-cards--compact">';
		foreach ( $intervals as $interval ) {
			$html .= $this->choice_card( 'membership_interval', $interval, $names[ $interval ], $interval === $selected, $required, '' );
		}
		return $html . '</div></fieldset>';
	}

	private function choice_card( $name, $value, $label, $checked, $required, $disabled, $extra = '' ) {
		return '<label class="cluvo-card cluvo-card--compact">'
			. '<input type="radio" class="cluvo-card__input" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"'
			. ( $checked ? ' checked' : '' ) . ( $required ? ' required' : '' ) . $disabled . $extra . ' />'
			. '<span class="cluvo-card__box">'
			. '<span class="cluvo-card__radio" aria-hidden="true"></span>'
			. '<span class="cluvo-card__title">' . esc_html( $label ) . '</span>'
			. '</span></label>';
	}

	/* ---------------------------------------------------------------------
	 * Custom fields (Zusatzfelder)
	 * ------------------------------------------------------------------- */

	private function custom_field( $cf, $width ) {
		$id       = (int) $cf['id'];
		$type     = isset( $cf['field_type'] ) ? (string) $cf['field_type'] : 'text';
		$label    = isset( $cf['label'] ) ? (string) $cf['label'] : '';
		$required = ! empty( $cf['is_required'] );
		$name     = 'custom_fields[' . $id . ']';
		$dom_id   = $this->prefix . '-cf-' . $id;

		if ( $required ) {
			$this->has_required = true;
		}

		switch ( $type ) {
			case 'repeater':
				return $this->repeater( $cf, $width );

			case 'checkbox':
				return '<div class="cluvo-field cluvo-col--' . esc_attr( $width ) . '">'
					. '<input type="hidden" name="' . esc_attr( $name ) . '" value="0" />'
					. '<label class="cluvo-check" for="' . esc_attr( $dom_id ) . '">'
					. '<input type="checkbox" class="cluvo-check__input" id="' . esc_attr( $dom_id ) . '" name="' . esc_attr( $name ) . '" value="1"' . ( $required ? ' required' : '' ) . ' />'
					. '<span class="cluvo-check__label">' . esc_html( $label ) . $this->star( $required ) . '</span>'
					. '</label></div>';

			case 'select':
				return '<div class="cluvo-field cluvo-col--' . esc_attr( $width ) . '">'
					. $this->label( $dom_id, $label, $required )
					. self::select( $dom_id, $name, isset( $cf['options'] ) ? $cf['options'] : array(), $required )
					. '</div>';

			case 'textarea':
				return '<div class="cluvo-field cluvo-col--' . esc_attr( $width ) . '">'
					. $this->label( $dom_id, $label, $required )
					. '<textarea class="cluvo-input cluvo-textarea" id="' . esc_attr( $dom_id ) . '" name="' . esc_attr( $name ) . '" rows="4"' . ( $required ? ' required' : '' ) . '></textarea>'
					. '</div>';
		}

		return $this->text_field(
			$name,
			$label,
			array_merge(
				self::input_type( $type ),
				array(
					'id'       => $dom_id,
					'width'    => $width,
					'required' => $required,
				)
			)
		);
	}

	/**
	 * Repeating group (e.g. several children). Needs `sub_fields` in the API
	 * payload; without them the field can't be rendered.
	 */
	private function repeater( $cf, $width ) {
		$id         = (int) $cf['id'];
		$label      = isset( $cf['label'] ) ? (string) $cf['label'] : '';
		$required   = ! empty( $cf['is_required'] );
		$sub_fields = array();

		foreach ( isset( $cf['sub_fields'] ) && is_array( $cf['sub_fields'] ) ? $cf['sub_fields'] : array() as $sub ) {
			if ( is_array( $sub ) && isset( $sub['key'] ) && preg_match( '/^[A-Za-z0-9_\-]+$/', (string) $sub['key'] ) ) {
				$sub_fields[] = $sub;
			}
		}

		if ( empty( $sub_fields ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return '';
			}
			return '<p class="cluvo-col--full cluvo-notice cluvo-notice--admin">'
				/* translators: %s: custom field label */
				. esc_html( sprintf( __( 'Das Wiederholungsfeld «%s» kann das Plugin nicht anzeigen, weil die Cluvo-API keine Unterfelder liefert. Dieser Hinweis ist nur für Administratoren sichtbar.', 'cluvo' ), $label ) )
				. '</p>';
		}

		$html  = '<fieldset class="cluvo-field cluvo-col--' . esc_attr( $width ) . ' cluvo-repeater" data-cluvo-repeater data-cluvo-next="' . ( $required ? 1 : 0 ) . '">';
		$html .= '<legend class="cluvo-label">' . esc_html( $label ) . $this->star( $required ) . '</legend>';
		$html .= '<div class="cluvo-repeater__rows" data-cluvo-rows>' . ( $required ? $this->repeater_row( $id, $sub_fields, '0', 1 ) : '' ) . '</div>';
		$html .= '<template data-cluvo-row-template>' . $this->repeater_row( $id, $sub_fields, '__ROW__', 0 ) . '</template>';
		$html .= '<button type="button" class="cluvo-button cluvo-button--secondary" data-cluvo-add-row>' . esc_html__( 'Eintrag hinzufügen', 'cluvo' ) . '</button>';

		return $html . '</fieldset>';
	}

	private function repeater_row( $id, $sub_fields, $index, $number ) {
		$html  = '<div class="cluvo-repeater__row" data-cluvo-row>';
		$html .= '<div class="cluvo-repeater__head">';
		/* translators: %s: row number */
		$html .= '<span class="cluvo-repeater__number" data-cluvo-row-number>' . esc_html( sprintf( __( 'Eintrag %s', 'cluvo' ), $number ? $number : '' ) ) . '</span>';
		$html .= '<button type="button" class="cluvo-repeater__remove" data-cluvo-remove-row aria-label="' . esc_attr__( 'Eintrag entfernen', 'cluvo' ) . '"><span aria-hidden="true">×</span></button>';
		$html .= '</div><div class="cluvo-repeater__grid">';

		foreach ( $sub_fields as $sub ) {
			$key    = (string) $sub['key'];
			$name   = 'custom_fields[' . $id . '][' . $index . '][' . $key . ']';
			$dom_id = $this->prefix . '-cf-' . $id . '-' . $index . '-' . $key;
			$label  = isset( $sub['label'] ) ? (string) $sub['label'] : $key;
			$type   = isset( $sub['type'] ) ? (string) $sub['type'] : 'text';

			if ( 'checkbox' === $type ) {
				$html .= '<div class="cluvo-field">'
					. '<input type="hidden" name="' . esc_attr( $name ) . '" value="0" />'
					. '<label class="cluvo-check" for="' . esc_attr( $dom_id ) . '">'
					. '<input type="checkbox" class="cluvo-check__input" id="' . esc_attr( $dom_id ) . '" name="' . esc_attr( $name ) . '" value="1" />'
					. '<span class="cluvo-check__label">' . esc_html( $label ) . '</span></label></div>';
			} elseif ( 'select' === $type ) {
				$html .= '<div class="cluvo-field">' . $this->label( $dom_id, $label, false )
					. self::select( $dom_id, $name, isset( $sub['options'] ) ? $sub['options'] : array(), false ) . '</div>';
			} else {
				$html .= $this->text_field(
					$name,
					$label,
					array_merge(
						self::input_type( $type ),
						array(
							'id'    => $dom_id,
							'width' => '',
						)
					)
				);
			}
		}

		return $html . '</div></div>';
	}

	/* ---------------------------------------------------------------------
	 * Building blocks
	 * ------------------------------------------------------------------- */

	/**
	 * @param array $args type, id, width, required, placeholder, help, autocomplete, attrs.
	 */
	private function text_field( $name, $label, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'type'         => 'text',
				'id'           => '',
				'width'        => 'full',
				'required'     => false,
				'placeholder'  => '',
				'help'         => '',
				'autocomplete' => '',
				'attrs'        => array(),
			)
		);

		$id   = '' !== $args['id'] ? $args['id'] : $this->prefix . '-' . $name;
		$help = $this->help( $name, $args['help'] );

		$attrs = array_merge(
			array(
				'type'             => $args['type'],
				'class'            => 'cluvo-input',
				'id'               => $id,
				'name'             => $name,
				'placeholder'      => (string) $args['placeholder'],
				'autocomplete'     => $args['autocomplete'],
				'required'         => (bool) $args['required'],
				'aria-describedby' => $help['id'],
			),
			$args['attrs']
		);

		$class = 'cluvo-field' . ( $args['width'] ? ' cluvo-col--' . $args['width'] : '' );

		return '<div class="' . esc_attr( $class ) . '">'
			. $this->label( $id, $label, $args['required'] )
			. '<input' . self::attributes( $attrs ) . ' />'
			. $help['html']
			. '</div>';
	}

	private function label( $id, $label, $required ) {
		return '<label class="cluvo-label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . $this->star( $required ) . '</label>';
	}

	private function star( $required ) {
		if ( ! $required ) {
			return '';
		}
		$this->has_required = true;
		return '<span class="cluvo-required" aria-hidden="true"> *</span>';
	}

	/**
	 * @return array{id:string, html:string, describedby:string}
	 */
	private function help( $name, $text ) {
		$text = is_string( $text ) ? trim( $text ) : '';
		if ( '' === $text ) {
			return array(
				'id'          => '',
				'html'        => '',
				'describedby' => '',
			);
		}

		$id = $this->prefix . '-' . sanitize_html_class( $name ) . '-help';

		return array(
			'id'          => $id,
			'html'        => '<p class="cluvo-field__help" id="' . esc_attr( $id ) . '">' . esc_html( $text ) . '</p>',
			'describedby' => ' aria-describedby="' . esc_attr( $id ) . '"',
		);
	}

	private static function select( $id, $name, $options, $required ) {
		$html  = '<select class="cluvo-input cluvo-select" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . ( $required ? ' required' : '' ) . '>';
		$html .= '<option value="">' . esc_html__( 'Bitte wählen', 'cluvo' ) . '</option>';
		foreach ( is_array( $options ) ? $options : array() as $option ) {
			if ( is_scalar( $option ) ) {
				$html .= '<option value="' . esc_attr( $option ) . '">' . esc_html( $option ) . '</option>';
			}
		}
		return $html . '</select>';
	}

	private static function input_type( $type ) {
		switch ( $type ) {
			case 'number':
				return array(
					'type'  => 'number',
					'attrs' => array( 'step' => 'any' ),
				);
			case 'date':
			case 'email':
				return array( 'type' => $type );
			case 'url':
				return array(
					'type'        => 'url',
					'placeholder' => 'https://',
				);
			case 'phone':
				return array(
					'type'         => 'tel',
					'autocomplete' => 'tel',
				);
		}
		return array( 'type' => 'text' );
	}

	private static function affix( $input ) {
		return '<div class="cluvo-affix"><span class="cluvo-affix__prefix" aria-hidden="true">CHF</span>' . $input . '</div>';
	}

	/**
	 * Attribute string: true renders a boolean attribute, false/''/null nothing.
	 */
	private static function attributes( $attrs ) {
		$html = '';
		foreach ( $attrs as $name => $value ) {
			if ( true === $value ) {
				$html .= ' ' . $name;
			} elseif ( false !== $value && null !== $value && '' !== $value ) {
				$html .= ' ' . $name . '="' . esc_attr( $value ) . '"';
			}
		}
		return $html;
	}

	private static function items( $data, $key ) {
		return isset( $data[ $key ] ) && is_array( $data[ $key ] ) ? array_filter( $data[ $key ], 'is_array' ) : array();
	}

	private static function allowed_intervals( $settings ) {
		$allowed   = isset( $settings['allowed_intervals'] ) && is_array( $settings['allowed_intervals'] ) ? $settings['allowed_intervals'] : self::INTERVALS;
		$intervals = array_values( array_intersect( self::INTERVALS, $allowed ) );
		return empty( $intervals ) ? self::INTERVALS : $intervals;
	}

	private static function amounts( $settings, $key ) {
		$amounts = isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ? $settings[ $key ] : array();
		return array_values( array_filter( $amounts, 'is_numeric' ) );
	}

	/** «CHF 120.00» in the site's number format. */
	public static function chf( $amount ) {
		/* translators: %s: formatted amount */
		return sprintf( __( 'CHF %s', 'cluvo' ), number_format_i18n( (float) $amount, 2 ) );
	}

	private static function per_interval( $interval ) {
		if ( 'monthly' === $interval ) {
			return __( 'pro Monat', 'cluvo' );
		}
		return 'yearly' === $interval ? __( 'pro Jahr', 'cluvo' ) : '';
	}
}
