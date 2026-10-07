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

	const TAGS = array( 'cluvo_form', 'cluvo_events', 'cluvo_news' );

	/** @var Cluvo_Client */
	private $client;

	public function __construct( Cluvo_Client $client ) {
		$this->client = $client;

		add_shortcode( 'cluvo_form', array( $this, 'form' ) );
		add_shortcode( 'cluvo_events', array( $this, 'events' ) );
		add_shortcode( 'cluvo_news', array( $this, 'news' ) );
	}

	/**
	 * Notice for visitors; administrators also see why something failed.
	 */
	private function notice( $message, $error = null ) {
		$details = '';
		if ( $error instanceof WP_Error && current_user_can( 'manage_options' ) ) {
			$details = '<br /><small class="cluvo-notice__details">'
				/* translators: %s: technical error message */
				. esc_html( sprintf( __( 'Hinweis für Administratoren: %s', 'cluvo' ), $error->get_error_message() ) )
				. '</small>';
		}
		return '<div class="cluvo cluvo-notice">' . esc_html( $message ) . $details . '</div>';
	}

	private function account( $atts ) {
		return $this->client->resolve_account( absint( $atts['account'] ) );
	}

	public function form( $atts ) {
		$atts = shortcode_atts(
			array(
				'slug'    => '',
				'account' => '',
			),
			$atts,
			'cluvo_form'
		);

		wp_enqueue_style( 'cluvo' );

		$account = $this->account( $atts );
		if ( is_wp_error( $account ) ) {
			return $this->notice( $account->get_error_message() );
		}

		$slug = sanitize_title( $atts['slug'] );
		if ( '' === $slug ) {
			return $this->notice( __( 'Bitte gib einen Formular-Slug an: [cluvo_form slug="…"].', 'cluvo' ) );
		}

		$form = $this->client->get_form( $slug, $account['id'] );

		if ( is_wp_error( $form ) ) {
			return $this->notice( __( 'Das Formular konnte nicht geladen werden.', 'cluvo' ), $form );
		}
		if ( empty( $form['data'] ) || ! is_array( $form['data'] ) ) {
			return $this->notice( __( 'Das Formular konnte nicht geladen werden.', 'cluvo' ) );
		}

		wp_enqueue_script( 'cluvo' );

		return Cluvo_Form_Renderer::render( $form['data'], $account['id'] );
	}

	public function events( $atts ) {
		$atts = shortcode_atts(
			array(
				'limit'    => 10,
				'per_page' => '',
				'category' => '',
				'from'     => '',
				'to'       => '',
				'layout'   => 'list',
				'account'  => '',
			),
			$atts,
			'cluvo_events'
		);

		wp_enqueue_style( 'cluvo' );

		$account = $this->account( $atts );
		if ( is_wp_error( $account ) ) {
			return $this->notice( $account->get_error_message() );
		}

		// Detail view when ?cluvo_event=<id> is present – only for the account the link was made for.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$single      = isset( $_GET['cluvo_event'] ) ? absint( $_GET['cluvo_event'] ) : 0;
		$for_account = isset( $_GET['cluvo_account'] ) ? absint( $_GET['cluvo_account'] ) : 0;
		$date        = isset( $_GET['cluvo_date'] ) ? self::iso_date( sanitize_text_field( wp_unslash( $_GET['cluvo_date'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( $single && ( ! $for_account || (int) $account['id'] === $for_account ) ) {
			return $this->event_single( $single, $date, $account['id'] );
		}

		$limit  = '' !== $atts['per_page'] ? $atts['per_page'] : $atts['limit'];
		$params = array( 'limit' => min( 500, max( 1, absint( $limit ) ) ) );
		if ( absint( $atts['category'] ) ) {
			$params['category'] = absint( $atts['category'] );
		}
		foreach ( array( 'from', 'to' ) as $key ) {
			$from_to = self::iso_date( $atts[ $key ] );
			if ( $from_to ) {
				$params[ $key ] = $from_to;
			}
		}

		$response = $this->client->get_events( $params, $account['id'] );

		if ( is_wp_error( $response ) ) {
			return $this->notice( __( 'Veranstaltungen konnten nicht geladen werden.', 'cluvo' ), $response );
		}

		$events = isset( $response['data'] ) && is_array( $response['data'] ) ? array_filter( $response['data'], 'is_array' ) : array();
		if ( empty( $events ) ) {
			return '<div class="cluvo cluvo-empty">' . esc_html__( 'Aktuell sind keine Veranstaltungen geplant.', 'cluvo' ) . '</div>';
		}

		$base  = remove_query_arg( array( 'cluvo_event', 'cluvo_date', 'cluvo_account' ) );
		$cards = 'cards' === strtolower( trim( (string) $atts['layout'] ) );

		ob_start();
		echo $cards ? '<div class="cluvo cluvo-events cluvo-events--cards">' : '<ul class="cluvo cluvo-events">';
		foreach ( $events as $event ) {
			$start    = isset( $event['start_date'] ) ? self::iso_date( $event['start_date'] ) : '';
			$category = isset( $event['category'] ) && is_array( $event['category'] ) ? $event['category'] : array();
			$title    = isset( $event['title'] ) ? $event['title'] : '';
			$link     = self::event_link( $event, $base, $account['id'] );
			$image    = self::image_url( $event );

			if ( $cards ) {
				?>
				<article class="cluvo-event-card"<?php echo self::color_style( $category ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>>
					<a class="cluvo-event-card__image" href="<?php echo esc_url( $link ); ?>" tabindex="-1" aria-hidden="true">
						<?php if ( $image ) : ?>
							<img src="<?php echo esc_url( $image ); ?>" alt="" loading="lazy" decoding="async" />
						<?php endif; ?>
						<?php if ( $start ) : ?>
							<span class="cluvo-event__badge">
								<span class="cluvo-event__day"><?php echo esc_html( self::format_date( $start, 'j' ) ); ?></span>
								<span class="cluvo-event__month"><?php echo esc_html( self::format_date( $start, 'M' ) ); ?></span>
							</span>
						<?php endif; ?>
					</a>
					<div class="cluvo-event-card__content">
						<?php if ( ! empty( $category['name'] ) ) : ?>
							<span class="cluvo-badge"><?php echo esc_html( $category['name'] ); ?></span>
						<?php endif; ?>
						<h3 class="cluvo-event__title"><a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $title ); ?></a></h3>
						<?php if ( $start ) : ?>
							<p class="cluvo-event__when"><?php echo self::event_when( $event ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $event['location'] ) ) : ?>
							<p class="cluvo-event__meta"><span class="cluvo-event__location"><?php echo esc_html( $event['location'] ); ?></span></p>
						<?php endif; ?>
						<?php if ( ! empty( $event['description'] ) ) : ?>
							<p class="cluvo-event__desc"><?php echo esc_html( wp_trim_words( $event['description'], 20 ) ); ?></p>
						<?php endif; ?>
					</div>
				</article>
				<?php
				continue;
			}
			?>
			<li class="cluvo-event"<?php echo self::color_style( $category ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>>
				<?php if ( $start ) : ?>
					<div class="cluvo-event__badge" aria-hidden="true">
						<span class="cluvo-event__day"><?php echo esc_html( self::format_date( $start, 'j' ) ); ?></span>
						<span class="cluvo-event__month"><?php echo esc_html( self::format_date( $start, 'M' ) ); ?></span>
					</div>
				<?php endif; ?>
				<div class="cluvo-event__body">
					<h3 class="cluvo-event__title"><a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $title ); ?></a></h3>
					<?php if ( $start ) : ?>
						<p class="cluvo-event__when"><?php echo self::event_when( $event ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></p>
					<?php endif; ?>
					<?php if ( ! empty( $event['location'] ) || ! empty( $category['name'] ) ) : ?>
						<p class="cluvo-event__meta">
							<?php if ( ! empty( $event['location'] ) ) : ?>
								<span class="cluvo-event__location"><?php echo esc_html( $event['location'] ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $category['name'] ) ) : ?>
								<span class="cluvo-badge"><?php echo esc_html( $category['name'] ); ?></span>
							<?php endif; ?>
						</p>
					<?php endif; ?>
					<?php if ( ! empty( $event['description'] ) ) : ?>
						<p class="cluvo-event__desc"><?php echo esc_html( wp_trim_words( $event['description'], 30 ) ); ?></p>
					<?php endif; ?>
				</div>
				<?php if ( $image ) : ?>
					<a class="cluvo-event__thumb" href="<?php echo esc_url( $link ); ?>" tabindex="-1" aria-hidden="true">
						<img src="<?php echo esc_url( $image ); ?>" alt="" loading="lazy" decoding="async" />
					</a>
				<?php endif; ?>
			</li>
			<?php
		}
		echo $cards ? '</div>' : '</ul>';
		return ob_get_clean();
	}

	/**
	 * One event on the same WordPress page: title image, date, place, content from the Cluvo editor and – for a series –
	 * the next dates. Older events without editor content show their plain description.
	 */
	private function event_single( $id, $date, $account_id ) {
		$back     = remove_query_arg( array( 'cluvo_event', 'cluvo_date', 'cluvo_account' ) );
		$response = $this->client->get_event( $id, $date, $account_id );

		if ( is_wp_error( $response ) || empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
			$error = is_wp_error( $response ) && 404 !== Cluvo_Client::error_status( $response ) ? $response : null;
			return $this->notice( __( 'Veranstaltung nicht gefunden.', 'cluvo' ), $error )
				. '<p class="cluvo cluvo-article__back"><a href="' . esc_url( $back ) . '"><span aria-hidden="true">← </span>' . esc_html__( 'Zurück zur Übersicht', 'cluvo' ) . '</a></p>';
		}

		$event    = $response['data'];
		$image    = self::image_url( $event );
		$category = isset( $event['category'] ) && is_array( $event['category'] ) ? $event['category'] : array();
		$upcoming = isset( $event['upcoming'] ) && is_array( $event['upcoming'] ) ? array_filter( array_map( array( __CLASS__, 'iso_date' ), $event['upcoming'] ) ) : array();

		ob_start();
		?>
		<article class="cluvo cluvo-article cluvo-event-detail"<?php echo self::color_style( $category ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>>
			<p class="cluvo-article__back"><a href="<?php echo esc_url( $back ); ?>"><span aria-hidden="true">← </span><?php esc_html_e( 'Zurück zur Übersicht', 'cluvo' ); ?></a></p>
			<?php if ( ! empty( $category['name'] ) ) : ?>
				<p class="cluvo-article__meta"><span class="cluvo-badge"><?php echo esc_html( $category['name'] ); ?></span></p>
			<?php endif; ?>
			<h2 class="cluvo-article__title"><?php echo esc_html( isset( $event['title'] ) ? $event['title'] : '' ); ?></h2>
			<div class="cluvo-event-detail__facts">
				<?php if ( ! empty( $event['start_date'] ) ) : ?>
					<p class="cluvo-event__when"><?php echo self::event_when( $event ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $event['location'] ) ) : ?>
					<p class="cluvo-event__location"><?php echo esc_html( $event['location'] ); ?></p>
				<?php endif; ?>
			</div>
			<?php if ( $image ) : ?>
				<img class="cluvo-article__image" src="<?php echo esc_url( $image ); ?>" alt="" decoding="async" />
			<?php endif; ?>
			<?php if ( ! empty( $event['content'] ) ) : ?>
				<div class="cluvo-article__content"><?php echo wp_kses_post( $event['content'] ); ?></div>
			<?php elseif ( ! empty( $event['description'] ) ) : ?>
				<div class="cluvo-article__content"><?php echo wp_kses_post( wpautop( esc_html( $event['description'] ) ) ); ?></div>
			<?php endif; ?>
			<?php if ( $upcoming ) : ?>
				<div class="cluvo-event-detail__dates">
					<h3><?php esc_html_e( 'Weitere Daten', 'cluvo' ); ?></h3>
					<ul>
						<?php foreach ( $upcoming as $day ) : ?>
							<li><a href="<?php echo esc_url( add_query_arg( 'cluvo_date', $day ) ); ?>"><?php echo esc_html( self::format_date( $day, get_option( 'date_format' ) ) ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		</article>
		<?php
		return ob_get_clean();
	}

	/** Link to the detail view on this page – with date (series) and account (several clubs on one page). */
	private static function event_link( $event, $base, $account_id ) {
		$args = array(
			'cluvo_event'   => isset( $event['id'] ) ? absint( $event['id'] ) : 0,
			'cluvo_account' => absint( $account_id ),
		);
		$date = isset( $event['start_date'] ) ? self::iso_date( $event['start_date'] ) : '';
		if ( $date ) {
			$args['cluvo_date'] = $date;
		}

		return add_query_arg( $args, $base );
	}

	public function news( $atts ) {
		$atts = shortcode_atts(
			array(
				'limit'      => 6,
				'category'   => '',
				'pagination' => '',
				'account'    => '',
			),
			$atts,
			'cluvo_news'
		);

		wp_enqueue_style( 'cluvo' );

		$account = $this->account( $atts );
		if ( is_wp_error( $account ) ) {
			return $this->notice( $account->get_error_message() );
		}

		// Single-article view when ?cluvo_article=slug is present.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$single = isset( $_GET['cluvo_article'] ) ? sanitize_title( wp_unslash( $_GET['cluvo_article'] ) ) : '';
		if ( $single ) {
			return $this->news_single( $single, $account['id'] );
		}

		$paginate = in_array( strtolower( (string) $atts['pagination'] ), array( '1', 'true', 'yes', 'ja' ), true );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = $paginate && isset( $_GET['cluvo_page'] ) ? max( 1, absint( $_GET['cluvo_page'] ) ) : 1;

		$params = array( 'per_page' => min( 50, max( 1, absint( $atts['limit'] ) ) ) );
		if ( absint( $atts['category'] ) ) {
			$params['category'] = absint( $atts['category'] );
		}
		if ( $page > 1 ) {
			$params['page'] = $page;
		}

		$response = $this->client->get_news( $params, $account['id'] );

		if ( is_wp_error( $response ) ) {
			return $this->notice( __( 'News konnten nicht geladen werden.', 'cluvo' ), $response );
		}

		$articles = isset( $response['data'] ) && is_array( $response['data'] ) ? array_filter( $response['data'], 'is_array' ) : array();
		if ( empty( $articles ) ) {
			return '<div class="cluvo cluvo-empty">' . esc_html__( 'Noch keine News vorhanden.', 'cluvo' ) . '</div>';
		}

		$base = remove_query_arg( array( 'cluvo_article', 'cluvo_page' ) );

		ob_start();
		echo '<div class="cluvo cluvo-news"><div class="cluvo-news__grid">';
		foreach ( $articles as $article ) {
			if ( empty( $article['slug'] ) ) {
				continue;
			}
			$title = isset( $article['title'] ) ? $article['title'] : '';
			$link  = add_query_arg( 'cluvo_article', rawurlencode( $article['slug'] ), $base );
			$image = self::image_url( $article );
			?>
			<article class="cluvo-news__item">
				<?php if ( $image ) : ?>
					<a class="cluvo-news__image" href="<?php echo esc_url( $link ); ?>" tabindex="-1" aria-hidden="true">
						<img src="<?php echo esc_url( $image ); ?>" alt="" loading="lazy" decoding="async" />
					</a>
				<?php endif; ?>
				<div class="cluvo-news__content">
					<?php echo $this->news_meta( $article, 'cluvo-news__meta' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
					<h3 class="cluvo-news__title">
						<a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $title ); ?></a>
					</h3>
					<?php if ( ! empty( $article['excerpt'] ) ) : ?>
						<p class="cluvo-news__excerpt"><?php echo esc_html( $article['excerpt'] ); ?></p>
					<?php endif; ?>
					<a class="cluvo-news__more" href="<?php echo esc_url( $link ); ?>">
						<?php esc_html_e( 'Weiterlesen', 'cluvo' ); ?><span class="cluvo-sr-only">: <?php echo esc_html( $title ); ?></span>
						<span aria-hidden="true">→</span>
					</a>
				</div>
			</article>
			<?php
		}
		echo '</div>';

		$last = isset( $response['meta']['last_page'] ) ? (int) $response['meta']['last_page'] : 1;
		if ( $paginate && $last > 1 ) {
			echo $this->pagination( $page, $last, $base ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}

		echo '</div>';
		return ob_get_clean();
	}

	private function news_single( $slug, $account_id ) {
		$back     = remove_query_arg( 'cluvo_article' );
		$response = $this->client->get_article( $slug, $account_id );

		if ( is_wp_error( $response ) || empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
			$error = is_wp_error( $response ) && 404 !== Cluvo_Client::error_status( $response ) ? $response : null;
			return $this->notice( __( 'Artikel nicht gefunden.', 'cluvo' ), $error )
				. '<p class="cluvo cluvo-article__back"><a href="' . esc_url( $back ) . '"><span aria-hidden="true">← </span>' . esc_html__( 'Zurück zur Übersicht', 'cluvo' ) . '</a></p>';
		}

		$article = $response['data'];
		$image   = self::image_url( $article );

		ob_start();
		?>
		<article class="cluvo cluvo-article">
			<p class="cluvo-article__back"><a href="<?php echo esc_url( $back ); ?>"><span aria-hidden="true">← </span><?php esc_html_e( 'Zurück zur Übersicht', 'cluvo' ); ?></a></p>
			<h2 class="cluvo-article__title"><?php echo esc_html( isset( $article['title'] ) ? $article['title'] : '' ); ?></h2>
			<?php echo $this->news_meta( $article, 'cluvo-article__meta' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
			<?php if ( $image ) : ?>
				<img class="cluvo-article__image" src="<?php echo esc_url( $image ); ?>" alt="" decoding="async" />
			<?php endif; ?>
			<div class="cluvo-article__content"><?php echo wp_kses_post( isset( $article['content'] ) ? $article['content'] : '' ); ?></div>
		</article>
		<?php
		return ob_get_clean();
	}

	private function news_meta( $article, $css_class ) {
		$parts = array();

		if ( ! empty( $article['published_at'] ) && strtotime( $article['published_at'] ) ) {
			$timestamp = strtotime( $article['published_at'] );
			$parts[]   = '<time datetime="' . esc_attr( gmdate( 'c', $timestamp ) ) . '">' . esc_html( wp_date( get_option( 'date_format' ), $timestamp ) ) . '</time>';
		}
		if ( ! empty( $article['category']['name'] ) && is_array( $article['category'] ) ) {
			$parts[] = '<span class="cluvo-badge"' . self::color_style( $article['category'] ) . '>' . esc_html( $article['category']['name'] ) . '</span>';
		}

		return $parts ? '<p class="' . esc_attr( $css_class ) . '">' . implode( ' ', $parts ) . '</p>' : '';
	}

	private function pagination( $page, $last, $base ) {
		$html = '<nav class="cluvo-pagination" aria-label="' . esc_attr__( 'Weitere News', 'cluvo' ) . '">';

		if ( $page > 1 ) {
			$html .= '<a class="cluvo-pagination__link" rel="prev" href="' . esc_url( 2 === $page ? $base : add_query_arg( 'cluvo_page', $page - 1, $base ) ) . '"><span aria-hidden="true">← </span>' . esc_html__( 'Neuere', 'cluvo' ) . '</a>';
		}

		/* translators: 1: current page, 2: number of pages */
		$html .= '<span class="cluvo-pagination__status">' . esc_html( sprintf( __( 'Seite %1$d von %2$d', 'cluvo' ), $page, $last ) ) . '</span>';

		if ( $page < $last ) {
			$html .= '<a class="cluvo-pagination__link" rel="next" href="' . esc_url( add_query_arg( 'cluvo_page', $page + 1, $base ) ) . '">' . esc_html__( 'Ältere', 'cluvo' ) . '<span aria-hidden="true"> →</span></a>';
		}

		return $html . '</nav>';
	}

	/**
	 * «Samstag, 20. September 2026, 19:30–22:00» in the site's date and time
	 * format, with ranges for multi-day events.
	 */
	private static function event_when( $event ) {
		$start_date = self::iso_date( $event['start_date'] );
		$end_date   = isset( $event['end_date'] ) ? self::iso_date( $event['end_date'] ) : '';
		$end_date   = $end_date && $end_date > $start_date ? $end_date : $start_date;
		$start_time = isset( $event['start_time'] ) ? self::clock_time( $event['start_time'] ) : '';
		$end_time   = isset( $event['end_time'] ) ? self::clock_time( $event['end_time'] ) : '';
		$all_day    = ! empty( $event['is_all_day'] ) || '' === $start_time;

		$date_format = get_option( 'date_format' );
		$time_format = get_option( 'time_format' );
		$start       = self::format_date( $start_date, $date_format );
		$end         = self::format_date( $end_date, $date_format );

		/* translators: 1: start, 2: end (dates or times) */
		$range = __( '%1$s – %2$s', 'cluvo' );
		/* translators: 1: date, 2: time */
		$at = __( '%1$s, %2$s', 'cluvo' );

		if ( $all_day ) {
			$text  = $start_date === $end_date ? $start : sprintf( $range, $start, $end );
			$text .= ' · ' . __( 'Ganztägig', 'cluvo' );
		} elseif ( $start_date === $end_date ) {
			$times = self::format_time( $start_time, $time_format );
			if ( $end_time && $end_time > $start_time ) {
				$times = sprintf( $range, $times, self::format_time( $end_time, $time_format ) );
			}
			$text = sprintf( $at, $start, $times );
		} else {
			$text = sprintf(
				$range,
				sprintf( $at, $start, self::format_time( $start_time, $time_format ) ),
				$end_time ? sprintf( $at, $end, self::format_time( $end_time, $time_format ) ) : $end
			);
		}

		$datetime = $start_date . ( $all_day ? '' : 'T' . $start_time );

		return '<time datetime="' . esc_attr( $datetime ) . '">' . esc_html( $text ) . '</time>';
	}

	/** Y-m-d or '' – also accepts values with a time part. */
	private static function iso_date( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		return preg_match( '/^(\d{4}-\d{2}-\d{2})/', $value, $m ) && checkdate( (int) substr( $m[1], 5, 2 ), (int) substr( $m[1], 8, 2 ), (int) substr( $m[1], 0, 4 ) ) ? $m[1] : '';
	}

	/** H:i or ''. */
	private static function clock_time( $value ) {
		return is_string( $value ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d/', $value, $m ) ? $m[0] : '';
	}

	/** Calendar dates carry no timezone – format them as they are. */
	private static function format_date( $date, $format ) {
		return wp_date( $format, strtotime( $date . ' 00:00:00 UTC' ), new DateTimeZone( 'UTC' ) );
	}

	private static function format_time( $time, $format ) {
		return wp_date( $format, strtotime( '1970-01-01 ' . $time . ':00 UTC' ), new DateTimeZone( 'UTC' ) );
	}

	/** Category colour as CSS variable, only valid hex colours. */
	private static function color_style( $category ) {
		$color = is_array( $category ) && isset( $category['color'] ) ? sanitize_hex_color( $category['color'] ) : '';
		return $color ? ' style="--cluvo-category-color:' . esc_attr( $color ) . '"' : '';
	}

	private static function image_url( $article ) {
		$url = isset( $article['image_url'] ) && is_string( $article['image_url'] ) ? $article['image_url'] : '';
		return in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ? $url : '';
	}
}
