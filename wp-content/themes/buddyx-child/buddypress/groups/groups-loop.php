<?php

/**
 * BuddyPress - Groups Loop (BuddyPress / BuddyMeet Data + FC2 Live Card Style)
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

	$user_id      = get_current_user_id();
	$user_fav_ids = $user_id ? (array) get_user_meta($user_id, 'pt_favorite_groups', true) : [];
	$user_fav_ids = array_map('intval', array_filter($user_fav_ids));

	$live_table_name = $wpdb->prefix . 'group_live_status';
	$has_live_table  = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $live_table_name)) === $live_table_name);
	$active_streams  = get_option('pt_active_livestreams', []);
	if (! is_array($active_streams)) {
		$active_streams = [];
	}

	/**
	 * Lấy dữ liệu thực tế từ BuddyPress & BuddyMeet cho từng Group:
	 * - Trạng thái phòng Meet (`buddymeet_is_enabled`, `buddymeet_room_members_*`, `group_live_status`, `pt_active_livestreams`)
	 * - Số người đang tham gia / thành viên của Group (`total_member_count` từ BuddyPress `groups_get_groupmeta($gid, 'total_member_count')`)
	 * - Hoạt động gần đây của Group (`last_activity` từ BuddyPress)
	 */
	$group_buddy_data = [];
	if (! empty($groups_template->groups)) {
		foreach ($groups_template->groups as $g_obj) {
			$gid = (int) $g_obj->id;

			// 1. Lấy tổng số thành viên thực tế của Group từ BuddyPress
			$bp_member_count = 0;
			if (isset($g_obj->total_member_count) && (int) $g_obj->total_member_count > 0) {
				$bp_member_count = (int) $g_obj->total_member_count;
			} elseif (function_exists('groups_get_total_member_count')) {
				$bp_member_count = (int) groups_get_total_member_count($gid);
			} elseif (function_exists('groups_get_groupmeta')) {
				$bp_member_count = (int) groups_get_groupmeta($gid, 'total_member_count', true);
			}

			// 2. Lấy số người đang trong phòng BuddyMeet của Group (nếu có)
			$buddymeet_members_count = 0;
			$buddymeet_enabled       = false;
			if (function_exists('buddymeet_is_enabled')) {
				$buddymeet_enabled = (bool) buddymeet_is_enabled($gid);
			} elseif (function_exists('groups_get_groupmeta')) {
				$bm_meta           = groups_get_groupmeta($gid, 'buddymeet_enabled', true);
				$buddymeet_enabled = ($bm_meta === '1' || $bm_meta === 1 || $bm_meta === true);
			}

			if (function_exists('groups_get_groupmeta')) {
				$bm_room = groups_get_groupmeta($gid, 'buddymeet_room', true);
				if (! empty($bm_room)) {
					$bm_room_members = groups_get_groupmeta($gid, 'buddymeet_room_members_' . $bm_room, true);
					if (is_array($bm_room_members)) {
						$buddymeet_members_count = count(array_filter($bm_room_members));
					}
				}

				// Kiểm tra toàn bộ các room buddymeet_room_members_* của group trong bảng bp_groups_groupmeta
				$bp_groupmeta_table = $wpdb->prefix . 'bp_groups_groupmeta';
				$room_rows          = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT meta_value FROM {$bp_groupmeta_table} WHERE group_id = %d AND meta_key LIKE %s",
						$gid,
						$wpdb->esc_like('buddymeet_room_members_') . '%'
					)
				);
				if (! empty($room_rows)) {
					foreach ($room_rows as $serialized_members) {
						$arr = maybe_unserialize($serialized_members);
						if (is_array($arr)) {
							$buddymeet_members_count = max($buddymeet_members_count, count(array_filter($arr)));
						}
					}
				}
			}

			// 3. Lấy số người đang live trong bảng group_live_status
			$live_table_count = 0;
			if ($has_live_table) {
				$live_table_count = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$live_table_name} WHERE group_id = %d AND status = 1",
						$gid
					)
				);
			}

			// 4. Kiểm tra trong pt_active_livestreams
			$stream_viewers = 0;
			$room_key_id    = 'group-' . $gid;
			$room_key_slug  = isset($g_obj->slug) ? (string) $g_obj->slug : '';
			if (isset($active_streams[$room_key_id]) && ! empty($active_streams[$room_key_id]['is_live'])) {
				$stream_viewers = (int) ($active_streams[$room_key_id]['viewers'] ?? 1);
			} elseif ($room_key_slug !== '' && isset($active_streams[$room_key_slug]) && ! empty($active_streams[$room_key_slug]['is_live'])) {
				$stream_viewers = (int) ($active_streams[$room_key_slug]['viewers'] ?? 1);
			}

			// 5. Tổng hợp trạng thái Live & Số người xem từ dữ liệu BuddyPress / BuddyMeet
			$active_live_viewers = max($live_table_count, $buddymeet_members_count, $stream_viewers);
			$viewer_count        = $active_live_viewers > 0 ? $active_live_viewers : $bp_member_count;
			$is_live_active      = ($active_live_viewers > 0 || $buddymeet_enabled || $bp_member_count > 0);

			$group_buddy_data[$gid] = [
				'is_live'          => $is_live_active,
				'active_live'      => $active_live_viewers,
				'viewer_count'     => $viewer_count,
				'bp_member_count'  => $bp_member_count,
				'buddymeet_active' => $buddymeet_enabled,
			];
		}

		// Sắp xếp: Group đang có phiên Live/Meet trực tiếp lên trước, tiếp theo theo số lượng thành viên/người xem BuddyPress giảm dần, và ưu tiên Group user đã tham gia
		usort($groups_template->groups, function ($a, $b) use ($user_id, $group_buddy_data) {
			$a_data = $group_buddy_data[(int) $a->id] ?? ['active_live' => 0, 'viewer_count' => 0];
			$b_data = $group_buddy_data[(int) $b->id] ?? ['active_live' => 0, 'viewer_count' => 0];

			if ($a_data['active_live'] !== $b_data['active_live']) {
				return $b_data['active_live'] <=> $a_data['active_live'];
			}

			if ($user_id) {
				$a_member = groups_is_user_member($user_id, $a->id);
				$b_member = groups_is_user_member($user_id, $b->id);
				if ($a_member !== $b_member) {
					return $a_member ? -1 : 1;
				}
			}

			if ($a_data['viewer_count'] !== $b_data['viewer_count']) {
				return $b_data['viewer_count'] <=> $a_data['viewer_count'];
			}

			return 0;
		});

		// Reset lại vòng lặp BuddyPress
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

			$g_data         = $group_buddy_data[$group_id] ?? [
				'is_live'          => false,
				'active_live'      => 0,
				'viewer_count'     => 0,
				'bp_member_count'  => 0,
				'buddymeet_active' => false,
			];
			$viewer_count   = (int) $g_data['viewer_count'];
			$is_live_active = (bool) $g_data['is_live'] && $viewer_count > 0;
			$is_fav         = in_array($group_id, $user_fav_ids, true);

			// Quốc kỳ của group (lấy từ BuddyPress groupmeta 'country_code' / 'country' hoặc ngôn ngữ cài đặt trong BuddyMeet)
			$country_code = '';
			if (function_exists('groups_get_groupmeta')) {
				$country_code = strtolower(trim((string) groups_get_groupmeta($group_id, 'country_code', true)));
				if (empty($country_code)) {
					$bm_lang = strtolower(trim((string) groups_get_groupmeta($group_id, 'buddymeet_default_language', true)));
					$lang_to_flag = [
						'ja' => 'jp',
						'jp' => 'jp',
						'vi' => 'vn',
						'vn' => 'vn',
						'en' => 'us',
						'ru' => 'ru',
						'ko' => 'kr',
						'zh' => 'cn',
					];
					if (isset($lang_to_flag[$bm_lang])) {
						$country_code = $lang_to_flag[$bm_lang];
					}
				}
			}
			if (empty($country_code) || ! preg_match('/^[a-z]{2}$/', $country_code)) {
				$country_code = 'jp';
			}
			$flag_url = 'https://flagcdn.com/w40/' . $country_code . '.png';

			// Mô tả ngắn của group lấy trực tiếp từ BuddyPress
			$raw_desc = '';
			if (! empty($groups_template->group->description)) {
				$raw_desc = wp_strip_all_tags($groups_template->group->description);
			} else {
				$raw_desc = wp_strip_all_tags(bp_get_group_description_excerpt());
			}
			$desc_text = trim($raw_desc) !== '' ? wp_trim_words($raw_desc, 14, '...') : 'Not specified';

			// Đường dẫn phòng Meet của Group trong BuddyMeet / BuddyPress
			$meet_slug = function_exists('buddymeet_get_slug') ? buddymeet_get_slug() : 'buddymeet';
			$group_meet_url = ! empty($g_data['buddymeet_active'])
				? trailingslashit($group_url) . $meet_slug . '/'
				: $group_url;
			?>

			<li <?php bp_group_class(array('item-entry', 'pt-fc2-card-item')); ?>
				data-bp-item-id="<?php echo esc_attr($group_id); ?>"
				data-bp-item-component="groups"
				data-live-state="<?php echo $is_live_active ? 'live' : 'standby'; ?>"
				data-fav="<?php echo $is_fav ? '1' : '0'; ?>"
				data-viewers="<?php echo esc_attr($viewer_count); ?>">

				<div class="list-wrap pt-fc2-card">

					<!-- THUMBNAIL (BuddyPress Group Avatar / Cover) + COUNTRY FLAG -->
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
							<a href="<?php echo esc_url($group_meet_url); ?>" class="pt-fc2-badge-live" title="<?php esc_attr_e('Enter Group Live / Meet', 'buddyx-child'); ?>">
								Live
							</a>

							<?php if ($group_status === 'private') : ?>
								<span class="pt-fc2-badge-extra is-lock" title="<?php esc_attr_e('Private Group', 'buddyx-child'); ?>">
									<i class="fa-solid fa-lock"></i>
								</span>
							<?php elseif ($group_status === 'hidden') : ?>
								<span class="pt-fc2-badge-extra is-p" title="<?php esc_attr_e('Hidden / Premium Group', 'buddyx-child'); ?>">
									P
								</span>
							<?php endif; ?>
						</div>

						<h2 class="pt-fc2-title list-title groups-title">
							<?php bp_group_link(); ?>
						</h2>

						<div class="pt-fc2-desc" title="<?php echo esc_attr($raw_desc ?: 'Not specified'); ?>">
							<?php echo esc_html($desc_text); ?>
						</div>

						<?php if (bp_get_group_join_button()) : ?>
							<div class="pt-fc2-join-wrap">
								<?php bp_nouveau_groups_loop_buttons(); ?>
							</div>
						<?php endif; ?>

						<?php bp_nouveau_groups_loop_item(); ?>
					</div>

					<!-- FOOTER: BUDDYPRESS MEMBER/VIEWER COUNT OR STANDBY + FAVORITE STAR -->
					<div class="pt-fc2-footer">
						<div class="pt-fc2-viewers <?php echo $is_live_active ? 'is-live' : 'is-standby'; ?>"
							title="<?php echo esc_attr(sprintf(__('%d members / viewers in group', 'buddyx-child'), $viewer_count)); ?>">
							<i class="fa-solid fa-user"></i>
							<span class="pt-fc2-viewer-text">
								<?php echo $is_live_active ? esc_html(number_format_i18n($viewer_count)) : esc_html__('Standby', 'buddyx-child'); ?>
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
