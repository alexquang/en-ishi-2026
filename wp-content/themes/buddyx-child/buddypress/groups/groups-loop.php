<?php

/**
 * BuddyPress - Groups Loop (FC2 / Live Channel Card Style)
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
	global $groups_template, $wpdb;

	$user_id       = get_current_user_id();
	$user_fav_ids  = $user_id ? (array) get_user_meta($user_id, 'pt_favorite_groups', true) : [];
	$user_fav_ids  = array_map('intval', array_filter($user_fav_ids));

	$live_table_name = $wpdb->prefix . 'group_live_status';
	$has_live_table  = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $live_table_name)) === $live_table_name);
	$active_streams  = get_option('pt_active_livestreams', []);
	if (! is_array($active_streams)) {
		$active_streams = [];
	}

	/**
	 * Tính toán trạng thái Live & Số người xem cho từng Group trước khi sort & render
	 */
	$group_live_map = [];
	if (! empty($groups_template->groups)) {
		foreach ($groups_template->groups as $g_obj) {
			$gid        = (int) $g_obj->id;
			$live_count = 0;

			if ($has_live_table) {
				$live_count = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$live_table_name} WHERE group_id = %d AND status = 1",
						$gid
					)
				);
			}

			// Kiểm tra trong pt_active_livestreams theo room group-{id} hoặc slug
			$room_key_id   = 'group-' . $gid;
			$room_key_slug = isset($g_obj->slug) ? (string) $g_obj->slug : '';
			if (isset($active_streams[$room_key_id]) && ! empty($active_streams[$room_key_id]['is_live'])) {
				$live_count = max($live_count, (int) ($active_streams[$room_key_id]['viewers'] ?? 1));
			} elseif ($room_key_slug !== '' && isset($active_streams[$room_key_slug]) && ! empty($active_streams[$room_key_slug]['is_live'])) {
				$live_count = max($live_count, (int) ($active_streams[$room_key_slug]['viewers'] ?? 1));
			}

			// Kiểm tra groupmeta nếu có cài đặt live_viewers / is_live
			if (function_exists('groups_get_groupmeta')) {
				$meta_viewers = groups_get_groupmeta($gid, 'pt_live_viewers', true);
				if ($meta_viewers !== '' && (int) $meta_viewers > 0) {
					$live_count = max($live_count, (int) $meta_viewers);
				}
			}

			$group_live_map[$gid] = $live_count;
		}

		// Sắp xếp: Group đang có người xem (Live > 0) lên trước theo số người xem giảm dần, sau đó tới Group đã tham gia
		usort($groups_template->groups, function ($a, $b) use ($user_id, $group_live_map) {
			$a_live = $group_live_map[(int) $a->id] ?? 0;
			$b_live = $group_live_map[(int) $b->id] ?? 0;

			if ($a_live !== $b_live) {
				return $b_live <=> $a_live;
			}

			if ($user_id) {
				$a_member = groups_is_user_member($user_id, $a->id);
				$b_member = groups_is_user_member($user_id, $b->id);
				if ($a_member !== $b_member) {
					return $a_member ? -1 : 1;
				}
			}

			return 0;
		});

		// Reset lại vòng lặp
		$groups_template->current_group = -1;
		$groups_template->group         = null;
	}
	?>

	<div class="pt-fc2-toolbar">
		<button type="button" id="pt-reset-group-filter" class="pt-fc2-reset-btn">
			<?php esc_html_e('Reset Filter', 'buddyx-child'); ?>
		</button>
		<div class="pt-fc2-quick-filters">
			<button type="button" class="pt-fc2-filter-chip is-active" data-filter="all">All</button>
			<button type="button" class="pt-fc2-filter-chip" data-filter="live">Live</button>
			<button type="button" class="pt-fc2-filter-chip" data-filter="standby">Standby</button>
			<button type="button" class="pt-fc2-filter-chip" data-filter="fav">★ Favorites</button>
		</div>
	</div>

	<?php bp_nouveau_pagination('top'); ?>

	<ul id="groups-list" class="item-list groups-list bp-list pt-fc2-groups-grid">

		<?php while (bp_groups()) : bp_the_group(); ?>
			<?php
			$group_id     = (int) bp_get_group_id();
			$group_name   = bp_get_group_name();
			$group_url    = bp_get_group_url();
			$group_status = bp_get_group_status(); // public, private, hidden
			$live_count   = isset($group_live_map[$group_id]) ? (int) $group_live_map[$group_id] : 0;

			// Nếu group có hoạt động hoặc member count, kiểm tra xem đang Live hay Standby
			$is_live_active = ($live_count > 0);
			$is_fav         = in_array($group_id, $user_fav_ids, true);

			// Quốc kỳ của group (lấy từ groupmeta 'country' hoặc mặc định JP như thiết kế)
			$country_code = '';
			if (function_exists('groups_get_groupmeta')) {
				$country_code = strtolower(trim((string) groups_get_groupmeta($group_id, 'country_code', true)));
			}
			if (empty($country_code) || ! preg_match('/^[a-z]{2}$/', $country_code)) {
				$country_code = 'jp';
			}
			$flag_url = 'https://flagcdn.com/w40/' . $country_code . '.png';

			// Mô tả ngắn của group (nếu trống hiển thị "Not specified")
			$raw_desc = '';
			if (! empty($groups_template->group->description)) {
				$raw_desc = wp_strip_all_tags($groups_template->group->description);
			}
			$desc_text = trim($raw_desc) !== '' ? wp_trim_words($raw_desc, 14, '...') : 'Not specified';

			// Link khi click vào xem Live hoặc vào Group
			$target_live_url = $is_live_active
				? home_url('/video/?room=group-' . $group_id)
				: $group_url;
			?>

			<li <?php bp_group_class(array('item-entry', 'pt-fc2-card-item')); ?>
				data-bp-item-id="<?php echo esc_attr($group_id); ?>"
				data-bp-item-component="groups"
				data-live-state="<?php echo $is_live_active ? 'live' : 'standby'; ?>"
				data-fav="<?php echo $is_fav ? '1' : '0'; ?>"
				data-viewers="<?php echo esc_attr($live_count); ?>">

				<div class="list-wrap pt-fc2-card">

					<!-- THUMBNAIL + COUNTRY FLAG -->
					<div class="pt-fc2-thumb">
						<a href="<?php echo esc_url($group_url); ?>" class="pt-fc2-thumb-link">
							<?php if (! bp_disable_group_avatar_uploads()) : ?>
								<?php bp_group_avatar(bp_nouveau_avatar_args()); ?>
							<?php endif; ?>
						</a>

						<span class="pt-fc2-flag" title="<?php echo esc_attr(strtoupper($country_code)); ?>">
							<img src="<?php echo esc_url($flag_url); ?>" alt="<?php echo esc_attr(strtoupper($country_code)); ?>" loading="lazy" />
						</span>
					</div>

					<!-- BODY: LIVE BADGE, GROUP TITLE, DESCRIPTION -->
					<div class="pt-fc2-body">
						<div class="pt-fc2-badges">
							<a href="<?php echo esc_url($target_live_url); ?>" class="pt-fc2-badge-live" title="Live Stream">
								Live
							</a>

							<?php if ($group_status === 'private') : ?>
								<span class="pt-fc2-badge-extra is-lock" title="<?php esc_attr_e('Private Group', 'buddyx-child'); ?>">
									<i class="fa-solid fa-lock"></i>
								</span>
							<?php elseif ($group_status === 'hidden') : ?>
								<span class="pt-fc2-badge-extra is-p" title="<?php esc_attr_e('Premium / Hidden Group', 'buddyx-child'); ?>">
									P
								</span>
							<?php endif; ?>
						</div>

						<h2 class="pt-fc2-title list-title groups-title">
							<a href="<?php echo esc_url($group_url); ?>" title="<?php echo esc_attr($group_name); ?>">
								<?php echo esc_html($group_name); ?>
							</a>
						</h2>

						<div class="pt-fc2-desc" title="<?php echo esc_attr($raw_desc ?: 'Not specified'); ?>">
							<?php echo esc_html($desc_text); ?>
						</div>

						<!-- Nút Join / Leave ẩn gọn hoặc hiển thị khi hover nếu cần -->
						<?php if (bp_get_group_join_button()) : ?>
							<div class="pt-fc2-join-wrap">
								<?php bp_nouveau_groups_loop_buttons(); ?>
							</div>
						<?php endif; ?>

						<?php bp_nouveau_groups_loop_item(); ?>
					</div>

					<!-- FOOTER: VIEWER COUNT / STANDBY + FAVORITE STAR -->
					<div class="pt-fc2-footer">
						<div class="pt-fc2-viewers <?php echo $is_live_active ? 'is-live' : 'is-standby'; ?>">
							<i class="fa-solid fa-user"></i>
							<span class="pt-fc2-viewer-text">
								<?php echo $is_live_active ? esc_html(number_format_i18n($live_count)) : esc_html__('Standby', 'buddyx-child'); ?>
							</span>
						</div>

						<button type="button"
							class="pt-fc2-fav-btn <?php echo $is_fav ? 'is-active' : ''; ?>"
							data-group-id="<?php echo esc_attr($group_id); ?>"
							aria-label="<?php esc_attr_e('Favorite', 'buddyx-child'); ?>"
							title="<?php esc_attr_e('Favorite', 'buddyx-child'); ?>">
							<i class="<?php echo $is_fav ? 'fa-solid' : 'fa-regular'; ?> fa-star"></i>
						</button>
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
