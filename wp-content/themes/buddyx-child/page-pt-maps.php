<?php
/*
Template Name: Google Map Home
*/

get_header();
?>
<div class="google-map-section">

    <div class="google-map-container">
        <div class="top-categories">

            <button class="cat-btn" data-type="restaurant">
                🍜 Restaurants
            </button>

            <button class="cat-btn" data-type="lodging">
                🏨 Hotels
            </button>

            <button class="cat-btn" data-type="cafe">
                ☕ Coffee
            </button>

            <button class="cat-btn" data-type="shopping_mall">
                🛍 Shopping
            </button>

            <button class="cat-btn" id="btnTraffic">
                🚗 Traffic
            </button>

            <button class="cat-btn" id="btnTransit">
                🚇 Transit
            </button>

            <button class="cat-btn" id="btnBike">
                🚲 Bicycle
            </button>

        </div>



        <div class="search-wrapper" id="search-wrapper">

        
            <div class="map-search-box">

                <input
                    id="pac-input"
                    type="text"
                    placeholder="Search Google Maps"
                    autocomplete="off"
                >
                <button class="find-btn" id="find-btn">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </button>
            </div>
            <div class="result-wrapper">

                <div id="result-panel">

                    <div id="results" class="results-style"></div>

                </div>

                <button id="toggle-results">
                    <i class="fa-solid fa-chevron-left"></i>
                </button>

            </div>

        </div>

        <!-- <div id="ai-result-panel" style="display:none;">
            <div class="ai-header">
                ✨ AI Search
                <span id="ai-close">✕</span>
            </div>

            <div id="ai-result-content"></div>
            <div id="ai-loading" style="display:none;">
            <div class="ai-spinner"></div>
            <div>Gemini đang phân tích...</div>

        </div> -->
</div>

        <div id="map"></div>

        <div class="map-layers-control">
            <button id="layers-toggle">
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
</div>


<div id="gmPictureDialog" class="gm-picture-dialog">

    <div class="gm-picture-modal">

        <div class="gm-picture-header">

            <h3>
                <i class="fa-solid fa-images"></i>
                Place Pictures
            </h3>
            <?php if (is_user_logged_in()) : ?>
                <button
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
            <?php endif; ?>
            <button id="gmPictureClose">
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

<?php get_footer(); ?>