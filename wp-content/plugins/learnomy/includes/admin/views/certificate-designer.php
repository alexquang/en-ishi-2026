<?php
/**
 * Certificate Designer — Canva-like visual template builder.
 *
 * @package Learnomy
 * @var \Learnomy\Models\CertificateTemplate|null $template Template being edited, or null for new.
 * @var bool                                       $is_edit  Whether editing an existing template.
 */

defined( 'ABSPATH' ) || exit;

$back_url = admin_url( 'admin.php?page=learnomy-certificates' );
?>
<div class="wrap learnomy-admin">

	<!-- Global page header — title + breadcrumb. Bespoke designer toolbar renders below. -->
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="award" class="lrn-page-header__icon" aria-hidden="true"></i>
				<?php $is_edit ? esc_html_e( 'Edit Certificate Template', 'learnomy' ) : esc_html_e( 'Design Certificate Template', 'learnomy' ); ?>
			</h1>
			<nav class="lrn-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'learnomy' ); ?>">
				<a class="lrn-breadcrumb__item" href="<?php echo esc_url( $back_url ); ?>">
					<?php esc_html_e( 'Certificates', 'learnomy' ); ?>
				</a>
				<span class="lrn-breadcrumb__sep" aria-hidden="true">/</span>
				<span class="lrn-breadcrumb__item" aria-current="page">
					<?php $is_edit ? esc_html_e( 'Edit Template', 'learnomy' ) : esc_html_e( 'New Template', 'learnomy' ); ?>
				</span>
			</nav>
		</div>
		<div class="lrn-page-header__actions">
			<a href="<?php echo esc_url( $back_url ); ?>" class="lrn-admin-btn">
				<i data-lucide="arrow-left" aria-hidden="true"></i> <?php esc_html_e( 'Back to Certificates', 'learnomy' ); ?>
			</a>
		</div>
	</div>

	<?php
	// `wp-header-end` marker — admin notices render below this line.
	// Without it, third-party plugin notices stack at the top of the
	// page and overlap the page header (round 3 audit-rule-8).
	?>
	<hr class="wp-header-end">

<div class="lrn-designer" id="lrn-cert-designer">

	<!-- Designer toolbar — bespoke controls for the canvas below. -->
	<div class="lrn-designer__header">
		<div class="lrn-designer__header-left">
			<h2 class="lrn-designer__title">
				<?php esc_html_e( 'Canvas', 'learnomy' ); ?>
			</h2>
		</div>
		<div class="lrn-designer__header-actions">
			<button type="button" class="lrn-admin-btn" id="lrn-designer-preview" disabled>
				<?php esc_html_e( 'Preview PDF', 'learnomy' ); ?>
			</button>
			<button type="button" class="lrn-admin-btn lrn-admin-btn--primary" id="lrn-designer-save">
				<?php esc_html_e( 'Save Template', 'learnomy' ); ?>
			</button>
			<span id="lrn-autosave-status" class="lrn-editor-autosave"></span>
		</div>
	</div>

	<!-- Main layout: canvas + sidebar -->
	<div class="lrn-designer__body">

		<!-- Canvas area -->
		<div class="lrn-designer__canvas-wrap">
			<div class="lrn-designer__canvas" id="lrn-designer-canvas">
				<!-- Elements are rendered here by JS as absolutely positioned DOM nodes -->
			</div>
		</div>

		<!-- Sidebar -->
		<div class="lrn-designer__sidebar" id="lrn-designer-sidebar">

			<!-- Template settings -->
			<div class="lrn-designer__panel" data-panel="template">
				<h3 class="lrn-designer__panel-title">
					<?php esc_html_e( 'Template', 'learnomy' ); ?>
				</h3>
				<div class="lrn-designer__panel-body">
					<label class="lrn-designer__label" for="lrn-tpl-name">
						<?php esc_html_e( 'Name', 'learnomy' ); ?>
					</label>
					<input type="text" id="lrn-tpl-name" class="lrn-designer__input"
							placeholder="<?php esc_attr_e( 'My Certificate', 'learnomy' ); ?>"
							value="<?php echo $is_edit ? esc_attr( $template->title ) : ''; ?>">

					<label class="lrn-designer__label">
						<?php esc_html_e( 'Orientation', 'learnomy' ); ?>
					</label>
					<div class="lrn-designer__radio-group">
						<label class="lrn-designer__radio">
							<input type="radio" name="lrn-orientation" value="landscape"
								<?php checked( ! $is_edit || 'landscape' === ( $template->orientation ?? 'landscape' ) ); ?>>
							<?php esc_html_e( 'Landscape', 'learnomy' ); ?>
						</label>
						<label class="lrn-designer__radio">
							<input type="radio" name="lrn-orientation" value="portrait"
								<?php checked( $is_edit && 'portrait' === ( $template->orientation ?? '' ) ); ?>>
							<?php esc_html_e( 'Portrait', 'learnomy' ); ?>
						</label>
					</div>

					<label class="lrn-designer__label" for="lrn-bg-color">
						<?php esc_html_e( 'Background Color', 'learnomy' ); ?>
					</label>
					<input type="color" id="lrn-bg-color" class="lrn-designer__color-input" value="#ffffff">

					<label class="lrn-designer__label" for="lrn-bg-image">
						<?php esc_html_e( 'Background Image', 'learnomy' ); ?>
					</label>
					<div class="lrn-designer__image-picker">
						<input type="text" id="lrn-bg-image" class="lrn-designer__input" readonly
								placeholder="<?php esc_attr_e( 'No image selected', 'learnomy' ); ?>">
						<button type="button" class="lrn-admin-btn lrn-admin-btn--sm" id="lrn-bg-image-btn">
							<?php esc_html_e( 'Select', 'learnomy' ); ?>
						</button>
						<button type="button" class="lrn-admin-btn lrn-admin-btn--sm" id="lrn-bg-image-clear" hidden>
							<?php esc_html_e( 'Clear', 'learnomy' ); ?>
						</button>
					</div>
				</div>
			</div>

			<!-- Availability & type — curated-library governance -->
			<div class="lrn-designer__panel" data-panel="availability">
				<h3 class="lrn-designer__panel-title">
					<?php esc_html_e( 'Availability &amp; Type', 'learnomy' ); ?>
				</h3>
				<div class="lrn-designer__panel-body">
					<label class="lrn-designer__label" for="lrn-tpl-kind">
						<?php esc_html_e( 'Certificate type', 'learnomy' ); ?>
					</label>
					<select id="lrn-tpl-kind" class="lrn-designer__input">
						<option value="course_completion" <?php selected( $availability_meta['kind'], 'course_completion' ); ?>>
							<?php esc_html_e( 'Course Completion', 'learnomy' ); ?>
						</option>
						<option value="quiz_completion" <?php selected( $availability_meta['kind'], 'quiz_completion' ); ?>>
							<?php esc_html_e( 'Quiz Completion', 'learnomy' ); ?>
						</option>
					</select>
					<p class="lrn-designer__hint">
						<?php esc_html_e( 'A label so instructors know what the certificate is for. It does not change when the certificate is issued.', 'learnomy' ); ?>
					</p>

					<label class="lrn-designer__label" for="lrn-tpl-availability">
						<?php esc_html_e( 'Who can use this template', 'learnomy' ); ?>
					</label>
					<select id="lrn-tpl-availability" class="lrn-designer__input">
						<option value="admins" <?php selected( $availability_meta['availability'], 'admins' ); ?>>
							<?php esc_html_e( 'Admins only', 'learnomy' ); ?>
						</option>
						<option value="all" <?php selected( $availability_meta['availability'], 'all' ); ?>>
							<?php esc_html_e( 'All instructors', 'learnomy' ); ?>
						</option>
						<option value="specific" <?php selected( $availability_meta['availability'], 'specific' ); ?>>
							<?php esc_html_e( 'Specific instructors', 'learnomy' ); ?>
						</option>
					</select>

					<div id="lrn-tpl-specific-wrap" class="lrn-designer__specific"<?php echo 'specific' === $availability_meta['availability'] ? '' : ' hidden'; ?>>
						<input type="search" id="lrn-tpl-instructor-search" class="lrn-designer__input"
								placeholder="<?php esc_attr_e( 'Search instructors', 'learnomy' ); ?>">
						<select id="lrn-tpl-instructors" class="lrn-designer__input" multiple size="6">
							<?php
							$lrn_selected_ids = array_map( 'intval', (array) $availability_meta['instructors'] );
							foreach ( $instructor_choices as $lrn_uid => $lrn_name ) :
								?>
								<option value="<?php echo esc_attr( (string) $lrn_uid ); ?>" <?php echo in_array( (int) $lrn_uid, $lrn_selected_ids, true ) ? 'selected' : ''; ?>>
									<?php echo esc_html( $lrn_name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="lrn-designer__hint">
							<?php esc_html_e( 'Only the selected instructors will see this template.', 'learnomy' ); ?>
						</p>
					</div>
				</div>
			</div>

			<!-- Pre-built templates -->
			<div class="lrn-designer__panel" data-panel="prebuilt">
				<h3 class="lrn-designer__panel-title">
					<?php esc_html_e( 'Pre-built Templates', 'learnomy' ); ?>
				</h3>
				<div class="lrn-designer__panel-body">
					<div class="lrn-designer__prebuilt-grid">
						<button type="button" class="lrn-designer__prebuilt-card" data-template="classic">
							<span class="lrn-designer__prebuilt-label"><?php esc_html_e( 'Classic', 'learnomy' ); ?></span>
							<span class="lrn-designer__prebuilt-desc"><?php esc_html_e( 'Centered serif, gold border', 'learnomy' ); ?></span>
						</button>
						<button type="button" class="lrn-designer__prebuilt-card" data-template="modern">
							<span class="lrn-designer__prebuilt-label"><?php esc_html_e( 'Modern', 'learnomy' ); ?></span>
							<span class="lrn-designer__prebuilt-desc"><?php esc_html_e( 'Left-aligned, accent bar', 'learnomy' ); ?></span>
						</button>
						<button type="button" class="lrn-designer__prebuilt-card" data-template="minimal">
							<span class="lrn-designer__prebuilt-label"><?php esc_html_e( 'Minimal', 'learnomy' ); ?></span>
							<span class="lrn-designer__prebuilt-desc"><?php esc_html_e( 'Clean, lots of whitespace', 'learnomy' ); ?></span>
						</button>
					</div>
				</div>
			</div>

			<!-- Add elements -->
			<div class="lrn-designer__panel" data-panel="elements">
				<h3 class="lrn-designer__panel-title">
					<?php esc_html_e( 'Add Elements', 'learnomy' ); ?>
				</h3>
				<div class="lrn-designer__panel-body">
					<div class="lrn-designer__element-palette">
						<button type="button" class="lrn-designer__palette-btn" data-add="text">
							<?php esc_html_e( '+ Text', 'learnomy' ); ?>
						</button>
						<button type="button" class="lrn-designer__palette-btn" data-add="merge_tag">
							<?php esc_html_e( '+ Merge Tag', 'learnomy' ); ?>
						</button>
						<button type="button" class="lrn-designer__palette-btn" data-add="image">
							<?php esc_html_e( '+ Image / Logo', 'learnomy' ); ?>
						</button>
						<button type="button" class="lrn-designer__palette-btn" data-add="qr_code">
							<?php esc_html_e( '+ QR Code', 'learnomy' ); ?>
						</button>
						<button type="button" class="lrn-designer__palette-btn" data-add="line">
							<?php esc_html_e( '+ Line / Border', 'learnomy' ); ?>
						</button>
						<button type="button" class="lrn-designer__palette-btn" data-add="signature">
							<?php esc_html_e( '+ Signature', 'learnomy' ); ?>
						</button>
					</div>
				</div>
			</div>

			<!-- Selected element properties -->
			<div class="lrn-designer__panel lrn-designer__panel--props" data-panel="properties" id="lrn-designer-props" hidden>
				<h3 class="lrn-designer__panel-title">
					<?php esc_html_e( 'Selected Element', 'learnomy' ); ?>
				</h3>
				<div class="lrn-designer__panel-body" id="lrn-props-body">
					<!-- Dynamically populated by JS when an element is selected -->
				</div>
				<div class="lrn-designer__panel-footer">
					<button type="button" class="lrn-admin-btn lrn-admin-btn--sm" id="lrn-duplicate-el">
						<?php esc_html_e( 'Duplicate', 'learnomy' ); ?>
					</button>
					<button type="button" class="lrn-admin-btn lrn-admin-btn--sm lrn-admin-btn--danger" id="lrn-delete-el">
						<?php esc_html_e( 'Delete', 'learnomy' ); ?>
					</button>
				</div>
			</div>

		</div><!-- /.lrn-designer__sidebar -->

	</div><!-- /.lrn-designer__body -->

	<!-- Toast notification -->
	<div class="lrn-designer__toast" id="lrn-designer-toast" aria-live="polite"></div>

</div><!-- /.lrn-designer -->
</div><!-- /.learnomy-admin -->
