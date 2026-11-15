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

	// Lọc các livestream còn hoạt động (heartbeat trong 45 giây gần nhất) và lập chỉ mục theo host_id / room
	$now                    = time();
	$active_streams_by_host = [];
	$active_streams_by_room = [];
	foreach ($active_streams as $stream_room => $stream_info) {
		if (! is_array($stream_info) || empty($stream_info['is_live'])) {
			continue;
		}
		$updated_at = isset($stream_info['updated_at']) ? (int) $stream_info['updated_at'] : 0;
		if ($updated_at > 0 && ($now - $updated_at) > 45) {
			continue;
		}
		$host_id = isset($stream_info['host_id']) ? (int) $stream_info['host_id'] : 0;
		if ($host_id > 0 && ! isset($active_streams_by_host[$host_id])) {
			$active_streams_by_host[$host_id] = $stream_info;
		}
		$active_streams_by_room[(string) $stream_room] = $stream_info;
	}

	$live_page_url = home_url('/video/');
	$live_pages    = get_pages([
		'meta_key'   => '_wp_page_template',
		'meta_value' => 'page-pt-live.php',
		'number'     => 1,
	]);
	if (! empty($live_pages[0])) {
		$live_page_url = get_permalink($live_pages[0]->ID);
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

			// 4. Kiểm tra chủ group (creator_id) hoặc phòng của group có đang livestream trong pt_active_livestreams
			$stream_viewers = 0;
			$owner_live_url = '';
			$creator_id     = isset($g_obj->creator_id) ? (int) $g_obj->creator_id : 0;
			$room_key_id    = 'group-' . $gid;
			$room_key_slug  = isset($g_obj->slug) ? (string) $g_obj->slug : '';
			$matched_stream = null;

			if ($creator_id > 0 && isset($active_streams_by_host[$creator_id])) {
				$matched_stream = $active_streams_by_host[$creator_id];
			} elseif (isset($active_streams_by_room[$room_key_id])) {
				$matched_stream = $active_streams_by_room[$room_key_id];
			} elseif ($room_key_slug !== '' && isset($active_streams_by_room[$room_key_slug])) {
				$matched_stream = $active_streams_by_room[$room_key_slug];
			}

			if (is_array($matched_stream)) {
				$stream_viewers = max(1, (int) ($matched_stream['viewers'] ?? 1));
				if (! empty($matched_stream['room'])) {
					$owner_live_url = add_query_arg('room', rawurlencode((string) $matched_stream['room']), $live_page_url);
				}
			}

			// 5. Chỉ đánh dấu Live khi chủ group / group thực sự đang phát livestream hoặc có phiên Meet đang hoạt động
			$active_live_viewers = max($live_table_count, $buddymeet_members_count, $stream_viewers);
			$is_live_active      = ($active_live_viewers > 0);
			$viewer_count        = $is_live_active ? $active_live_viewers : $bp_member_count;

			$group_buddy_data[$gid] = [
				'is_live'          => $is_live_active,
				'active_live'      => $active_live_viewers,
				'viewer_count'     => $viewer_count,
				'bp_member_count'  => $bp_member_count,
				'buddymeet_active' => ($buddymeet_members_count > 0),
				'live_url'         => $owner_live_url,
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
				'live_url'         => '',
			];
			$viewer_count    = (int) $g_data['viewer_count'];
			$bp_member_count = (int) $g_data['bp_member_count'];
			$is_live_active  = ! empty($g_data['is_live']);
			$is_fav          = in_array($group_id, $user_fav_ids, true);

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

			// Đường dẫn phòng Live/Meet của Group
			$meet_slug = function_exists('buddymeet_get_slug') ? buddymeet_get_slug() : 'buddymeet';
			$group_meet_url = ! empty($g_data['live_url'])
				? $g_data['live_url']
				: (! empty($g_data['buddymeet_active'])
					? trailingslashit($group_url) . $meet_slug . '/'
					: $group_url);
			$has_badges = ($is_live_active || $group_status === 'private' || $group_status === 'hidden');
			?>

			<li <?php bp_group_class(array('item-entry', 'pt-fc2-card-item')); ?>
				data-bp-item-id="<?php echo esc_attr($group_id); ?>"
				data-bp-item-component="groups"
				data-live-state="<?php echo $is_live_active ? 'live' : 'standby'; ?>"
				data-fav="<?php echo $is_fav ? '1' : '0'; ?>"
				data-viewers="<?php echo esc_attr($viewer_count); ?>">

				<div class="list-wrap pt-fc2-card <?php echo $is_live_active ? 'is-live-card' : ''; ?>">

					<!-- THUMBNAIL (BuddyPress Group Avatar / Cover) + COUNTRY FLAG -->
					<div class="pt-fc2-thumb">
						<a href="<?php echo esc_url($is_live_active ? $group_meet_url : $group_url); ?>" class="pt-fc2-thumb-link">
							<?php if (! bp_disable_group_avatar_uploads()) : ?>
								<?php bp_group_avatar(bp_nouveau_avatar_args()); ?>
							<?php endif; ?>
						</a>

						<?php if ($is_live_active) : ?>
							<a href="<?php echo esc_url($group_meet_url); ?>" class="pt-fc2-thumb-live" title="<?php esc_attr_e('Watch Group Livestream', 'buddyx-child'); ?>">
								<span class="pt-fc2-live-dot" aria-hidden="true"></span>
								<span><?php esc_html_e('LIVE', 'buddyx-child'); ?></span>
							</a>
						<?php endif; ?>

						<span class="pt-fc2-flag" title="<?php echo esc_attr(strtoupper($country_code)); ?>">
							<img src="<?php echo esc_url($flag_url); ?>" alt="<?php echo esc_attr(strtoupper($country_code)); ?>" loading="lazy" />
						</span>
					</div>

					<!-- BODY: BADGES (CHỈ HIỆN LIVE KHI CHỦ GROUP ĐANG LIVESTREAM), GROUP TITLE, DESCRIPTION -->
					<div class="pt-fc2-body">
						<?php if ($has_badges) : ?>
							<div class="pt-fc2-badges">
								<?php if ($is_live_active) : ?>
									<a href="<?php echo esc_url($group_meet_url); ?>" class="pt-fc2-badge-live" title="<?php esc_attr_e('Enter Group Live / Meet', 'buddyx-child'); ?>">
										<span class="pt-fc2-live-dot" aria-hidden="true"></span>
										<span>Live</span>
									</a>
								<?php endif; ?>

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
						<?php endif; ?>

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

					<!-- FOOTER: BUDDYPRESS MEMBER/VIEWER COUNT + FAVORITE STAR -->
					<div class="pt-fc2-footer">
						<div class="pt-fc2-viewers <?php echo $is_live_active ? 'is-live' : 'is-standby'; ?>"
							title="<?php echo esc_attr($is_live_active ? sprintf(__('%d viewers watching live', 'buddyx-child'), $viewer_count) : sprintf(__('%d members in group', 'buddyx-child'), $bp_member_count)); ?>">
							<i class="fa-solid <?php echo $is_live_active ? 'fa-eye' : 'fa-users'; ?>"></i>
							<span class="pt-fc2-viewer-text">
								<?php echo $is_live_active ? esc_html(number_format_i18n($viewer_count)) : esc_html(number_format_i18n($bp_member_count)); ?>
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
