<?php

/**
 * BuddyPress - Groups Loop
 *
 * @since 3.0.0
 * @version 12.0.0
 */
bp_nouveau_before_loop(); ?>

<?php if (bp_get_current_group_directory_type()) : ?>
	<p class="current-group-type"><?php bp_current_group_directory_type_message(); ?></p>
<?php endif; ?>

<?php if (bp_has_groups(bp_ajax_querystring('groups'))) : ?>
	<?php
	global $groups_template;

	if (is_user_logged_in() && ! empty($groups_template->groups)) {

		$user_id = get_current_user_id();

		usort($groups_template->groups, function ($a, $b) use ($user_id) {

			$a_member = groups_is_user_member($user_id, $a->id);
			$b_member = groups_is_user_member($user_id, $b->id);

			// Nếu cùng trạng thái member thì giữ nguyên
			if ($a_member === $b_member) {
				return 0;
			}

			// Group mình tham gia lên trước
			return $a_member ? -1 : 1;
		});

		// Reset lại vòng lặp
		$groups_template->current_group = -1;
		$groups_template->group = null;
	}
	?>
	<?php bp_nouveau_pagination('top'); ?>

	<ul id="groups-list" class="item-list groups-list bp-list grid four">

		<?php while (bp_groups()) : bp_the_group(); ?>

			<li <?php bp_group_class(array('item-entry')); ?> data-bp-item-id="<?php bp_group_id(); ?>" data-bp-item-component="groups">
				<div class="list-wrap">

					<?php if (! bp_disable_group_avatar_uploads()) : ?>
						<div class="item-avatar">
							<a href="<?php bp_group_url(); ?>"><?php bp_group_avatar(bp_nouveau_avatar_args()); ?></a>
						</div>
					<?php endif; ?>

					<div class="item">

						<div class="item-block">

							<h2 class="list-title groups-title"><?php bp_group_link(); ?></h2>

							<?php if (bp_nouveau_group_has_meta()) : ?>

								<p class="item-meta group-details"><?php bp_nouveau_the_group_meta(array('keys' => array('status', 'count'))); ?></p>

							<?php endif; ?>

							<p class="last-activity item-meta">
								<?php
								printf(
									/* translators: %s: last activity timestamp (e.g. "Active 1 hour ago") */
									esc_html__('Active %s', 'buddypress'),
									sprintf(
										'<span data-livestamp="%1$s">%2$s</span>',
										esc_attr(bp_core_get_iso8601_date(bp_get_group_last_active(0, array('relative' => false)))),
										esc_html(bp_get_group_last_active())
									)
								);
								?>
							</p>

							<?php
							global $wpdb;

							$live_count = $wpdb->get_var(
								$wpdb->prepare(
									"SELECT COUNT(*)
										FROM {$wpdb->prefix}group_live_status
										WHERE group_id = %d
										AND status = 1",
									bp_get_group_id()
								)
							);
							?>
							<div class="pt-group-actions">

								<?php if ($live_count > 0) : ?>

									<a href="<?php echo esc_url(home_url('/group-live/?group=' . bp_get_group_id())); ?>"
										class="button pt-live-btn">
										Join Meeting Now (<?php echo $live_count; ?>)
									</a>

								<?php endif; ?>

								<?php if (bp_get_group_join_button()) : ?>
									<div class="pt-join-btn">
										<?php bp_nouveau_groups_loop_buttons(); ?>
									</div>
								<?php endif; ?>

							</div>
						</div>

						<div class="group-desc">
							<p><?php bp_nouveau_group_description_excerpt(); ?></p>
						</div>

						<?php bp_nouveau_groups_loop_item(); ?>

					</div>

				</div>

			</li>

		<?php endwhile; ?>

	</ul>

	<?php bp_nouveau_pagination('bottom'); ?>

<?php else : ?>

	<?php bp_nouveau_user_feedback('groups-loop-none'); ?>

<?php endif; ?>

<?php
bp_nouveau_after_loop();
