<?php
/**
 * Default renderer for registered catalog facets.
 *
 * Listens on the existing `learnomy_catalog_filter_facets` hook in
 * templates/catalog/sidebar.php and walks Facets::all() to emit standard
 * markup. Ad-hoc extensions can still attach their own listener at any
 * priority; this renderer doesn't block.
 *
 * @package Learnomy\Catalog
 */

namespace Learnomy\Catalog;

defined( 'ABSPATH' ) || exit;

class Facet_Renderer {

	public static function boot(): void {
		add_action( 'learnomy_catalog_filter_facets', array( __CLASS__, 'render' ), 10, 1 );
		add_action( 'learnomy_course_card_meta', array( __CLASS__, 'render_card_badges' ), 10, 1 );
	}

	/**
	 * Paint the per-card badge a facet asked for through `badge_label`.
	 *
	 * The Facets docblock has always listed card badges as one of the four
	 * things a registered facet contributes, and `badge_label` has always been
	 * an accepted config key — but nothing ever listened on
	 * `learnomy_course_card_meta`, so the key was silently ignored
	 * (Basecamp #10143794593). This is that listener.
	 *
	 * The framework itself runs no query per card: a string `badge_label` is
	 * printed as-is, and a callable one is the registrant's own code, so the
	 * cost of anything it looks up is theirs to batch.
	 *
	 * @param object $course Course row from lrn_courses.
	 */
	public static function render_card_badges( $course ): void {
		foreach ( Facets::all() as $facet ) {
			$badge = $facet['badge_label'] ?? null;
			if ( empty( $badge ) ) {
				continue;
			}

			$label = is_callable( $badge ) ? call_user_func( $badge, $course, $facet ) : $badge;
			$label = is_scalar( $label ) ? trim( (string) $label ) : '';
			if ( '' === $label ) {
				continue;
			}

			printf(
				'<span class="lrn-badge lrn-course-card__facet-badge">%s</span>',
				esc_html( $label )
			);
		}
	}

	public static function render( /* string $current_cat */ ): void {
		$facets = Facets::all();
		if ( empty( $facets ) ) {
			return;
		}

		$active = Facets::resolve_active_from_request();

		foreach ( $facets as $slug => $facet ) {
			$options = Facets::resolve_options( $facet );
			if ( empty( $options ) ) {
				continue;
			}
			$selected = $active[ $slug ] ?? array();
			self::render_group( $slug, $facet, $options, $selected );
		}
	}

	/**
	 * @param string                                                $slug Facet slug.
	 * @param array<string,mixed>                                   $facet Facet definition.
	 * @param array<int, array{slug:string,label:string,count:int}> $options
	 * @param string[]                                              $selected
	 */
	private static function render_group( string $slug, array $facet, array $options, array $selected ): void {
		$ui          = (string) ( $facet['ui'] ?? 'checkbox-list' );
		$param       = (string) ( $facet['param'] ?? $slug );
		$show_counts = ! empty( $facet['show_counts'] );
		$multi       = ! empty( $facet['multi'] );
		?>
		<fieldset class="lrn-catalog-filters__group lrn-catalog-facet" data-facet="<?php echo esc_attr( $slug ); ?>">
			<legend class="lrn-catalog-filters__heading"><?php echo esc_html( (string) $facet['label'] ); ?></legend>

			<?php if ( 'pills' === $ui ) : ?>
				<div class="lrn-catalog-facet__pills" role="group">
					<?php
					foreach ( $options as $opt ) :
						$is_on = in_array( $opt['slug'], $selected, true );
						?>
						<button type="button"
							class="lrn-catalog-facet__pill<?php echo $is_on ? ' is-on' : ''; ?>"
							data-wp-on--click="actions.toggleFacet"
							data-facet-param="<?php echo esc_attr( $param ); ?>"
							data-facet-value="<?php echo esc_attr( $opt['slug'] ); ?>"
							data-facet-multi="<?php echo $multi ? '1' : '0'; ?>"
							aria-pressed="<?php echo $is_on ? 'true' : 'false'; ?>">
							<span class="lrn-catalog-facet__pill-label"><?php echo esc_html( $opt['label'] ); ?></span>
							<?php if ( $show_counts ) : ?>
								<span class="lrn-catalog-facet__count"><?php echo (int) $opt['count']; ?></span>
							<?php endif; ?>
						</button>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<ul class="lrn-catalog-facet__list">
					<?php
					foreach ( $options as $opt ) :
						$is_on = in_array( $opt['slug'], $selected, true );
						$id    = 'lrn-facet-' . $slug . '-' . $opt['slug'];
						?>
						<li class="lrn-catalog-facet__item">
							<label for="<?php echo esc_attr( $id ); ?>" class="lrn-catalog-facet__row">
								<input
									type="<?php echo $multi ? 'checkbox' : 'radio'; ?>"
									id="<?php echo esc_attr( $id ); ?>"
									name="<?php echo esc_attr( $param ); ?><?php echo $multi ? '[]' : ''; ?>"
									value="<?php echo esc_attr( $opt['slug'] ); ?>"
									<?php checked( $is_on ); ?>
									data-wp-on--change="actions.toggleFacet"
									data-facet-param="<?php echo esc_attr( $param ); ?>"
									data-facet-value="<?php echo esc_attr( $opt['slug'] ); ?>"
									data-facet-multi="<?php echo $multi ? '1' : '0'; ?>" />
								<span class="lrn-catalog-facet__label"><?php echo esc_html( $opt['label'] ); ?></span>
								<?php if ( $show_counts ) : ?>
									<span class="lrn-catalog-facet__count"><?php echo (int) $opt['count']; ?></span>
								<?php endif; ?>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</fieldset>
		<?php
	}
}
