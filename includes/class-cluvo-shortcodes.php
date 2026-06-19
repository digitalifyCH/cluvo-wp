<?php
/**
 * Front-end shortcodes: [cluvo_form], [cluvo_events], [cluvo_news].
 *
 * Each shortcode targets one Cluvo account via account="<id>". When only a
 * single account is configured, the attribute may be omitted.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cluvo_Shortcodes {

	/** @var Cluvo_Client */
	private $client;

	public function __construct( Cluvo_Client $client ) {
		$this->client = $client;

		add_shortcode( 'cluvo_form', array( $this, 'form' ) );
		add_shortcode( 'cluvo_events', array( $this, 'events' ) );
		add_shortcode( 'cluvo_news', array( $this, 'news' ) );
	}

	private function notice( $message ) {
		return '<div class="cluvo-notice">' . esc_html( $message ) . '</div>';
	}

	public function form( $atts ) {
		$atts = shortcode_atts( array( 'slug' => '', 'account' => '' ), $atts, 'cluvo_form' );

		$account = $this->client->resolve_account( absint( $atts['account'] ) );
		if ( is_wp_error( $account ) ) {
			return $this->notice( $account->get_error_message() );
		}

		if ( empty( $atts['slug'] ) ) {
			return $this->notice( __( 'Bitte gib einen Formular-Slug an: [cluvo_form slug="…"].', 'cluvo' ) );
		}

		$form = $this->client->get_form( $atts['slug'], $account['id'] );

		if ( is_wp_error( $form ) || empty( $form['data'] ) ) {
			return $this->notice( __( 'Formular konnte nicht geladen werden.', 'cluvo' ) );
		}

		wp_enqueue_style( 'cluvo' );
		wp_enqueue_script( 'cluvo' );

		return Cluvo_Form_Renderer::render( $form['data'], $account['id'] );
	}

	public function events( $atts ) {
		$atts = shortcode_atts(
			array(
				'limit'    => 10,
				'category' => '',
				'from'     => '',
				'account'  => '',
			),
			$atts,
			'cluvo_events'
		);

		$account = $this->client->resolve_account( absint( $atts['account'] ) );
		if ( is_wp_error( $account ) ) {
			return $this->notice( $account->get_error_message() );
		}

		$params = array( 'per_page' => absint( $atts['limit'] ) );
		if ( $atts['category'] ) {
			$params['category'] = absint( $atts['category'] );
		}
		if ( $atts['from'] ) {
			$params['from'] = sanitize_text_field( $atts['from'] );
		}

		$response = $this->client->get_events( $params, $account['id'] );

		if ( is_wp_error( $response ) ) {
			return $this->notice( __( 'Veranstaltungen konnten nicht geladen werden.', 'cluvo' ) );
		}

		$events = isset( $response['data'] ) ? $response['data'] : array();
		if ( empty( $events ) ) {
			return '<div class="cluvo-empty">' . esc_html__( 'Aktuell sind keine Veranstaltungen geplant.', 'cluvo' ) . '</div>';
		}

		wp_enqueue_style( 'cluvo' );

		ob_start();
		echo '<ul class="cluvo-events">';
		foreach ( $events as $event ) {
			$date  = self::format_date( isset( $event['start_date'] ) ? $event['start_date'] : '' );
			$time  = ! empty( $event['start_time'] ) && empty( $event['is_all_day'] ) ? substr( $event['start_time'], 0, 5 ) : '';
			$color = isset( $event['category']['color'] ) ? $event['category']['color'] : '';
			?>
			<li class="cluvo-event">
				<div class="cluvo-event__date"<?php echo $color ? ' style="border-color:' . esc_attr( $color ) . '"' : ''; ?>>
					<?php echo esc_html( $date ); ?>
					<?php if ( $time ) : ?><span class="cluvo-event__time"><?php echo esc_html( $time ); ?></span><?php endif; ?>
				</div>
				<div class="cluvo-event__body">
					<h3 class="cluvo-event__title"><?php echo esc_html( $event['title'] ); ?></h3>
					<?php if ( ! empty( $event['location'] ) ) : ?>
						<p class="cluvo-event__location"><?php echo esc_html( $event['location'] ); ?></p>
					<?php endif; ?>
					<?php if ( ! empty( $event['description'] ) ) : ?>
						<p class="cluvo-event__desc"><?php echo esc_html( wp_trim_words( $event['description'], 30 ) ); ?></p>
					<?php endif; ?>
				</div>
			</li>
			<?php
		}
		echo '</ul>';
		return ob_get_clean();
	}

	public function news( $atts ) {
		$atts = shortcode_atts(
			array(
				'limit'    => 6,
				'category' => '',
				'account'  => '',
			),
			$atts,
			'cluvo_news'
		);

		$account = $this->client->resolve_account( absint( $atts['account'] ) );
		if ( is_wp_error( $account ) ) {
			return $this->notice( $account->get_error_message() );
		}

		// Single-article view when ?cluvo_article=slug is present.
		$single = isset( $_GET['cluvo_article'] ) ? sanitize_title( wp_unslash( $_GET['cluvo_article'] ) ) : '';
		if ( $single ) {
			return $this->news_single( $single, $account['id'] );
		}

		$params = array( 'per_page' => absint( $atts['limit'] ) );
		if ( $atts['category'] ) {
			$params['category'] = absint( $atts['category'] );
		}

		$response = $this->client->get_news( $params, $account['id'] );

		if ( is_wp_error( $response ) ) {
			return $this->notice( __( 'News konnten nicht geladen werden.', 'cluvo' ) );
		}

		$articles = isset( $response['data'] ) ? $response['data'] : array();
		if ( empty( $articles ) ) {
			return '<div class="cluvo-empty">' . esc_html__( 'Noch keine News vorhanden.', 'cluvo' ) . '</div>';
		}

		wp_enqueue_style( 'cluvo' );

		$base = remove_query_arg( 'cluvo_article' );

		ob_start();
		echo '<div class="cluvo-news">';
		foreach ( $articles as $article ) {
			$link = add_query_arg( 'cluvo_article', rawurlencode( $article['slug'] ), $base );
			?>
			<article class="cluvo-news__item">
				<h3 class="cluvo-news__title">
					<a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $article['title'] ); ?></a>
				</h3>
				<?php if ( ! empty( $article['published_at'] ) ) : ?>
					<p class="cluvo-news__date"><?php echo esc_html( self::format_date( $article['published_at'] ) ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $article['excerpt'] ) ) : ?>
					<p class="cluvo-news__excerpt"><?php echo esc_html( $article['excerpt'] ); ?></p>
				<?php endif; ?>
				<a class="cluvo-news__more" href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'Weiterlesen', 'cluvo' ); ?> →</a>
			</article>
			<?php
		}
		echo '</div>';
		return ob_get_clean();
	}

	private function news_single( $slug, $account_id ) {
		$response = $this->client->get_article( $slug, $account_id );

		if ( is_wp_error( $response ) || empty( $response['data'] ) ) {
			return $this->notice( __( 'Artikel nicht gefunden.', 'cluvo' ) );
		}

		$article = $response['data'];
		wp_enqueue_style( 'cluvo' );

		ob_start();
		?>
		<article class="cluvo-article">
			<p class="cluvo-article__back"><a href="<?php echo esc_url( remove_query_arg( 'cluvo_article' ) ); ?>">← <?php esc_html_e( 'Zurück', 'cluvo' ); ?></a></p>
			<h1 class="cluvo-article__title"><?php echo esc_html( $article['title'] ); ?></h1>
			<?php if ( ! empty( $article['published_at'] ) ) : ?>
				<p class="cluvo-article__date"><?php echo esc_html( self::format_date( $article['published_at'] ) ); ?></p>
			<?php endif; ?>
			<div class="cluvo-article__content"><?php echo wp_kses_post( isset( $article['content'] ) ? $article['content'] : '' ); ?></div>
		</article>
		<?php
		return ob_get_clean();
	}

	private static function format_date( $value ) {
		if ( empty( $value ) ) {
			return '';
		}
		$ts = strtotime( $value );
		return $ts ? date_i18n( get_option( 'date_format' ), $ts ) : '';
	}
}
