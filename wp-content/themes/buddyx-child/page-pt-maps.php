<?php
/*
Template Name: Google Map Home
*/

get_header();
?>
<div class="google-map-section">

    <div class="google-map-container">


        <div class="map-search-box">

            <input
                id="pac-input"
                type="text"
                placeholder="Search now...">

            <button class="find-btn" id="find-btn">
                <i class="fa-solid fa-magnifying-glass"></i>
            </button>

        </div>
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

        <div class="result-panel" id="result-panel">
            <div id="results"></div>
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

<!-- <div id="photo-dialog" class="photo-modal">

    <div class="photo-modal-content">

        <div class="modal-header">
            <h3>Pictures</h3>

            <button id="close-photo-dialog">
                X
            </button>
        </div>

        <div id="photo-list" class="photo-grid">
        </div>

        <div class="modal-footer">
            <button id="btn-upload">
                📤 Upload
            </button>

            <input type="file"
                   id="photo-upload"
                   hidden
                   accept="image/*">
        </div>

    </div>

</div> -->
<script async src="https://maps.googleapis.com/maps/api/js?key=AIzaSyA5ip29G4RoZ_NHgOZYgKoGNb_e0JYl4wQ&libraries=places&loading=async&callback=initMap"></script>

<?php get_footer(); ?>