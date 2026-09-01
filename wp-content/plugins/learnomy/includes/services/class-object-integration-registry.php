<?php
/**
 * Object Integration Registry — one owner-facing control for "does this have a
 * room?", on every Learnomy object that can have one.
 *
 * A course, a cohort and a Learnomy Space can each be given a community or a
 * discussion by another product. Before this, each product rendered its own
 * control on whichever screen it happened to reach: Jetonomy put a discussion
 * toggle on the course editor, BuddyNext put a community panel on the course
 * DETAIL page and on cohorts and Spaces. Same idea, four screens, different
 * owners, and no Jetonomy control on cohorts or Spaces at all. An owner had to
 * learn each product's habits rather than one workflow.
 *
 * Learnomy is the only plugin always present when the question arises - the
 * objects are its own - so the control belongs here, and the products that act
 * on it register as providers. This file knows nothing about any of them.
 *
 * Filter contract:
 *
 *     add_filter( 'learnomy_object_integrations',
 *         function ( array $providers, string $type, int $id ): array {
 *             $providers[] = [
 *                 'id'         => 'my-plugin',
 *                 'priority'   => 20,
 *                 'is_enabled' => fn () => (bool) my_plugin_has_room( $type, $id ),
 *                 'on_enable'  => fn () => my_plugin_create_room( $type, $id ),
 *                 'on_disable' => fn () => my_plugin_hide_room( $type, $id ),
 *             ];
 *             return $providers;
 *         },
 *         10,
 *         3
 *     );
 *
 * PROVIDERS SUPPLY NO WORDING. The owner reads the same control on every
 * screen and on every site, whichever product is installed, so the strings
 * live in the partial and a provider cannot reintroduce its own vocabulary.
 * Products keep their own names on their own screens; this is the
 * Learnomy-side decision and it is deliberately product-neutral. It also
 * avoids "space", which is the word three products already disagree about
 * and the reason this control exists.
 *
 * ONE provider acts: sorted by priority, first wins. Same negotiation already
 * shipped for the course link and the dashboard widget - one room, one link,
 * one toggle. Two independent toggles could express a state nothing can
 * honour, because a community's discussion IS the discussion space.
 *
 * `on_disable` should hide, not destroy. Removing a room full of conversation
 * because an owner unticked a box is not a thing to do quietly.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

class Object_Integration_Registry {

	/**
	 * Object types that can carry an integration.
	 *
	 * Spelled out so a typo in a caller cannot silently render an empty section
	 * against a type nothing supports.
	 *
	 * @var string[]
	 */
	public const TYPES = array( 'course', 'cohort', 'space' );

	/**
	 * Collect, validate and sort the providers for one object.
	 *
	 * Deduped by id, first registration wins - the same rule the dashboard
	 * widget registry uses, so a product can be loaded twice without doubling
	 * its own control.
	 *
	 * @param string $type Object type: course | cohort | space.
	 * @param int    $id   Object id.
	 * @return array<int,array<string,mixed>> Valid providers, sorted.
	 */
	public static function for_object( string $type, int $id ): array {
		if ( ! in_array( $type, self::TYPES, true ) || $id <= 0 ) {
			return array();
		}

		/**
		 * Register a product that can give this object a community or discussion.
		 *
		 * @param array  $providers Providers so far.
		 * @param string $type      Object type.
		 * @param int    $id        Object id.
		 */
		$raw = apply_filters( 'learnomy_object_integrations', array(), $type, $id );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$seen  = array();
		$valid = array();

		foreach ( $raw as $provider ) {
			if ( ! self::is_well_formed( $provider ) ) {
				continue;
			}

			$pid = (string) $provider['id'];
			if ( isset( $seen[ $pid ] ) ) {
				continue;
			}

			$seen[ $pid ] = true;
			$valid[]      = $provider;
		}

		usort(
			$valid,
			static fn( $a, $b ) => ( (int) ( $a['priority'] ?? 10 ) ) <=> ( (int) ( $b['priority'] ?? 10 ) )
		);

		return $valid;
	}

	/**
	 * A provider is usable only if it can be shown AND acted on.
	 *
	 * Every callable is required. A provider that renders a checkbox it cannot
	 * honour is worse than one that does not appear: the owner ticks it, nothing
	 * happens, and nothing says so.
	 *
	 * @param mixed $provider Candidate.
	 * @return bool
	 */
	private static function is_well_formed( $provider ): bool {
		if ( ! is_array( $provider ) ) {
			return false;
		}

		if ( empty( $provider['id'] ) || ! is_string( $provider['id'] ) ) {
			return false;
		}

		foreach ( array( 'is_enabled', 'on_enable', 'on_disable' ) as $fn ) {
			if ( ! isset( $provider[ $fn ] ) || ! is_callable( $provider[ $fn ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The URL of the first ENABLED provider that offers one, or '' when no
	 * provider is enabled or none exposes a link.
	 *
	 * Two views were walking `for_object()` themselves with the same
	 * enabled-and-callable-and-non-empty chain, differing only in the object
	 * type they passed. Both were correct, which is the problem: the chain has
	 * three ways to be subtly wrong (calling `url` without checking
	 * `is_enabled`, trusting a callable that returns '', not breaking on the
	 * first hit) and each copy has to get all three right forever. The registry
	 * owns the provider contract, so it should answer the question.
	 *
	 * Callers still decide WHETHER to show a community link — this only
	 * answers where it points.
	 *
	 * @param string $type Object type ('space', 'cohort', ...).
	 * @param int    $id   Object id.
	 */
	public static function first_enabled_url( string $type, int $id ): string {
		foreach ( self::for_object( $type, $id ) as $provider ) {
			if ( ! is_callable( $provider['is_enabled'] ?? null ) || ! call_user_func( $provider['is_enabled'] ) ) {
				continue;
			}

			if ( ! is_callable( $provider['url'] ?? null ) ) {
				continue;
			}

			$url = (string) call_user_func( $provider['url'] );
			if ( '' !== $url ) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * Render the section, or nothing at all when no product offers anything.
	 *
	 * Drawing an empty "Community & Discussion" heading on a Learnomy-only site
	 * would advertise a feature the owner does not have and cannot get from this
	 * screen. Silence is the correct output.
	 *
	 * @param string $type Object type.
	 * @param int    $id   Object id.
	 * @return void
	 */
	public static function render( string $type, int $id ): void {
		$providers = self::for_object( $type, $id );
		if ( ! $providers ) {
			return;
		}

		// The winner, and only the winner. Sorted by priority in for_object(),
		// so the richer surface wins where both are installed and the other
		// stands down - exactly as they already do for the course link and the
		// dashboard widget.
		$lrn_provider = $providers[0];
		$lrn_type     = $type;
		$lrn_id       = $id;

		require \Learnomy\template_path( 'partials/object-integrations.php' );
	}

	/**
	 * Apply a change for one provider.
	 *
	 * Returns the state the provider reports AFTER the call rather than the
	 * requested state, so a provider that refuses (a draft course with nothing
	 * to attach to, say) reports the truth back to the UI instead of the UI
	 * showing a tick the product did not honour.
	 *
	 * @param string $type     Object type.
	 * @param int    $id       Object id.
	 * @param string $provider Provider id.
	 * @param bool   $enabled  Requested state.
	 * @return bool|null Resulting state, or null when the provider is unknown.
	 */
	public static function apply( string $type, int $id, string $provider, bool $enabled ): ?bool {
		$providers = self::for_object( $type, $id );
		$target    = null;

		foreach ( $providers as $candidate ) {
			if ( (string) $candidate['id'] === $provider ) {
				$target = $candidate;
				break;
			}
		}

		if ( null === $target ) {
			return null;
		}

		/*
		 * EVERY provider acts, in both directions. The owner sees one switch,
		 * so one switch is what it has to be.
		 *
		 * Standing down only the winner left the other product still listening:
		 * on a full stack, switching Community off unlinked the BuddyNext
		 * community while Jetonomy went on admitting every new enrolment to the
		 * same room. One control, half a result, and nothing on screen saying
		 * so.
		 *
		 * Enabling only the winner had the mirror problem - it left the other
		 * provider's recorded opt-out in place, so a switch that read ON could
		 * still be suppressing links, and removing the winning plugin later
		 * would surface that stale "off" out of nowhere.
		 *
		 * Calling every provider is only safe because provisioning converges:
		 * whichever runs first creates the room and claims the object's access
		 * rule, and the others adopt it rather than making a second. Without
		 * that seam this would be how you end up with two rooms.
		 */
		$callback = $enabled ? 'on_enable' : 'on_disable';

		foreach ( $providers as $candidate ) {
			call_user_func( $candidate[ $callback ] );
		}

		return (bool) call_user_func( $target['is_enabled'] );
	}
}
