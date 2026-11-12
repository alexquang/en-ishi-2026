<?php
/**
 * Template Name: Google Map Home
 *
 * @package BuddyX_Child
 */

get_header();
?>
<script>
const gmUserLoggedIn = <?php echo is_user_logged_in() ? 'true' : 'false'; ?>;
</script>

<div class="google-map-section">

    <div class="google-map-container">
        <div class="top-categories">

            <button type="button" class="cat-btn" data-type="restaurant">
                🍜 Restaurants
            </button>

            <button type="button" class="cat-btn" data-type="lodging">
                🏨 Hotels
            </button>

            <button type="button" class="cat-btn" data-type="cafe">
                ☕ Coffee
            </button>

            <button type="button" class="cat-btn" data-type="shopping_mall">
                🛍 Shopping
            </button>

        </div>

        <div class="search-wrapper" id="search-wrapper">
            <div class="map-search-box">
                <input
                    id="pac-input"
                    type="text"
                    placeholder="Search Google Maps"
                    autocomplete="off">
                <button type="button" class="find-btn" id="find-btn" aria-label="Search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </button>
                <button type="button" class="ai-search-btn" id="ai-search-btn" title="Search with Gemini" aria-label="Search with Gemini">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                        <path
                            d="M12 2C12 7.52 16.48 12 22 12
                            C16.48 12 12 16.48 12 22
                            C12 16.48 7.52 12 2 12
                            C7.52 12 12 7.52 12 2Z"
                            fill="currentColor" />
                    </svg>
                </button>
            </div>

            <div class="result-wrapper">

                <div id="gm-place-popup" class="gm-place-popup"></div>

                <div id="result-panel">

                    <div id="results" class="results-style"></div>

                </div>

                <button type="button" id="toggle-results" aria-label="Toggle Results Panel">
                    <i class="fa-solid fa-chevron-left"></i>
                </button>

            </div>

            <!-- GEMINI RESULT -->
            <div id="ai-result-panel" class="ai-result-panel">

                <div class="ai-result-header">

                    <div class="ai-result-title">
                        <i class="fa-solid fa-wand-magic-sparkles"></i>
                        <span>Gemini AI</span>
                    </div>

                    <button
                        id="close-ai-result"
                        class="ai-close-btn"
                        type="button"
                        aria-label="Close Gemini Result">
                        <i class="fa-solid fa-xmark"></i>
                    </button>

                </div>

                <div
                    id="ai-result-content"
                    class="ai-result-content">
                </div>

            </div>
            <!-- END GEMINI RESULT -->

        </div>
    </div>

    <div id="map"></div>

    <div class="map-layers-control">
        <button type="button" id="layers-toggle">
            <i class="fa-solid fa-layer-group"></i>
            Layers
        </button>

        <div id="layers-menu">
            <label>
                <input type="checkbox" id="traffic-layer">
                Traffic
            </label>

            <label>
                <input type="checkbox" id="transit-layer">
                Transit
            </label>

            <label>
                <input type="checkbox" id="bike-layer">
                Bicycle
            </label>

            <hr>

            <label>
                <input type="radio" name="maptype" value="roadmap" checked>
                Roadmap
            </label>

            <label>
                <input type="radio" name="maptype" value="satellite">
                Satellite
            </label>

            <label>
                <input type="radio" name="maptype" value="hybrid">
                Hybrid
            </label>

            <label>
                <input type="radio" name="maptype" value="terrain">
                Terrain
            </label>
        </div>
    </div>

</div>

<!-- Dialog Picture -->
<div id="gmPictureDialog" class="gm-picture-dialog">

    <div class="gm-picture-modal">

        <div class="gm-picture-header">

            <h3>
                <i class="fa-solid fa-images"></i>
                Place Pictures
            </h3>

            <button
                type="button"
                id="gmUploadPhotoBtn"
                class="gm-upload-btn">
                <i class="fa-solid fa-upload"></i>
                Upload
            </button>

            <input
                type="file"
                id="gmUploadPhotoFile"
                accept="image/*"
                hidden>

            <button type="button" id="gmPictureClose" aria-label="Close Picture Dialog">
                <i class="fa-solid fa-xmark"></i>
            </button>

        </div>

        <div class="gm-picture-body">

            <div id="gmPictureContent">

                <div class="gm-empty">
                    No pictures loaded
                </div>

            </div>

        </div>

    </div>

</div>

<!-- Dialog Video -->
<div id="gmVideoDialog" class="gm-video-dialog">

    <div class="gm-video-modal">

        <div class="gm-video-header">

            <h3>
                <i class="fa-solid fa-video"></i>
                Place Videos
            </h3>

            <button
                type="button"
                id="gmUploadVideoBtn"
                class="gm-upload-btn">
                <i class="fa-solid fa-upload"></i>
                Upload
            </button>

            <input
                type="file"
                id="gmUploadVideoFile"
                accept="video/*"
                hidden>

            <button type="button" id="gmVideoClose" aria-label="Close Video Dialog">
                <i class="fa-solid fa-xmark"></i>
            </button>

        </div>

        <div class="gm-video-body">

            <div id="gmVideoContent">

                <div class="gm-empty">
                    No Video loaded
                </div>

            </div>

        </div>

    </div>

</div>

<!-- Dialog Login Required -->
<div id="gmLoginRequiredDialog" class="gm-login-dialog">

    <div class="gm-login-dialog-content">

        <button
            type="button"
            id="gmLoginDialogClose"
            class="gm-login-dialog-close"
            aria-label="Close Login Dialog"
        >
            <i class="fa-solid fa-xmark"></i>
        </button>

        <div class="gm-login-dialog-icon">
            <i class="fa-solid fa-lock"></i>
        </div>

        <h3>Login Required</h3>

        <p>
            Please log in to upload a video or picture.
        </p>

        <div id="gmNextendGoogleLogin">

            <?php
            if ( shortcode_exists( 'nextend_social_login' ) ) {
                echo do_shortcode( '[nextend_social_login provider="google"]' );
            } else {
                echo '<a class="button gm-login-btn" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">Log In</a>';
            }
            ?>

        </div>

    </div>

</div>

<?php get_footer(); ?>