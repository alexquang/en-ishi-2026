<?php
/**
 * BP Nouveau Component's directory nav template.
 *
 * @since 3.0.0
 * @version 3.0.0
 */
?>
<style> 

.component-navigation{
    display:flex;
    align-items:center;
}

.component-navigation .create-group-nav{
    margin-left:10px;
}

.component-navigation .create-group-nav a{
    color:#1f3c88;
    font-weight:500;
    text-decoration:none;
    padding:0;
    background:none;
    border:none;
}

.component-navigation .create-group-nav a:hover{
    color:#ff7a00;
}

</style>
<nav class="<?php bp_nouveau_directory_type_navs_class(); ?>" role="navigation" aria-label="<?php esc_attr_e( 'Directory menu', 'buddypress' ); ?>">

	<?php if ( bp_nouveau_has_nav( array( 'object' => 'directory' ) ) ) : ?>

		<ul class="component-navigation <?php bp_nouveau_directory_list_class(); ?>">

			<?php
			while ( bp_nouveau_nav_items() ) :
				bp_nouveau_nav_item();
			?>

				<li id="<?php bp_nouveau_nav_id(); ?>" class="<?php bp_nouveau_nav_classes(); ?>" <?php bp_nouveau_nav_scope(); ?> data-bp-object="<?php bp_nouveau_directory_nav_object(); ?>">
					<a href="<?php bp_nouveau_nav_link(); ?>">
						<?php bp_nouveau_nav_link_text(); ?>

						<?php if ( bp_nouveau_nav_has_count() ) : ?>
							<span class="count"><?php bp_nouveau_nav_count(); ?></span>
						<?php endif; ?>
					</a>
				</li>

			<?php endwhile; ?>

<?php if ( bp_is_groups_directory() && ! is_user_logged_in() ) : ?>

    <li class="create-group-nav">
        <a href="<?php echo esc_url( wp_login_url( bp_get_groups_directory_permalink() . 'create/' ) ); ?>">
            <?php esc_html_e( 'Create Group', 'buddypress' ); ?>
        </a>
    </li>

<?php endif; ?>

		</ul><!-- .component-navigation -->

	<?php endif; ?>

</nav><!-- .bp-navs -->
