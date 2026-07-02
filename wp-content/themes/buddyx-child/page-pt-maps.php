<?php
/*
Template Name: Google Map Home
*/

get_header();
?>

<style>
    .google-map-section {
        padding: 20px;
    }

    #map {
        width: 100%;
        height: calc(100vh - 140px);
        min-height: 650px;
        border-radius: 20px;
        overflow: hidden;

        box-shadow: 0 2px 15px rgba(0, 0, 0, .12);
    }

    @media(max-width:768px) {

        #map {
            height: 70vh;
            min-height: 500px;
        }

    }

    .google-map-section {
        width: 100vw;
        margin-left: calc(50% - 50vw);
        margin-right: calc(50% - 50vw);
        padding: 0;
    }

    .google-map-container {
        position: relative;
    }


    #map {
        width: 100%;
        height: calc(100vh - 80px);
        min-height: 600px;
    }

    .google-map-wrapper {
        position: relative;
    }

    .google-map-wrapper {
        position: relative;
    }

    #map {
        width: 100%;
        height: calc(100vh - 80px);
    }

    .map-search-box {

        position: absolute;

        top: 20px;
        left: 20px;

        width: 420px;
        max-width: calc(100% - 40px);

        height: 56px;

        background: #fff;

        display: flex;

        align-items: center;

        z-index: 2;

        box-shadow: 0 2px 10px rgba(0, 0, 0, .25);

        border-radius: 8px;
    }

    .map-search-box input {

        flex: 1;

        border: none;

        outline: none;

        padding: 0 15px;

        font-size: 16px;
    }

    .find-btn {

        width: 80px;

        height: 56px;

        border: none;

        background: #ff7a00;

        color: #fff;

        cursor: pointer;
    }

    .map-layout {
        position: relative;
    }

    .result-panel {

        position: absolute;

        top: 86px;

        left: 20px;

        width: 420px;

        height: calc(100% - 106px);

        background: #fff;

        overflow-y: auto;

        z-index: 99998;

        display: none;

        box-shadow: 0 2px 10px rgba(0, 0, 0, .15);
    }

    .result-item {
        padding: 15px;
        border-bottom: 1px solid #eee;
        cursor: pointer;
    }

    .result-item:hover {
        background: #f5f5f5;
    }

    .result-item h4 {
        margin: 0 0 5px;
    }

    .place-card {

        display: flex;

        justify-content: space-between;

        gap: 10px;

        padding: 15px;

        border-bottom: 1px solid #eee;

        cursor: pointer;
    }

    .place-card img {

        width: 90px;
        height: 90px;

        border-radius: 10px;

        object-fit: cover;
    }

    .place-card h3 {
        margin: 0 0 5px;
        font-size: 18px;
    }

    .rating {
        color: #f39c12;
        margin-bottom: 5px;
    }

    .address {
        color: #666;
    }

    .place-card {

        display: flex;

        justify-content: space-between;

        align-items: flex-start;

        gap: 15px;

        padding: 15px;

        border-bottom: 1px solid #eee;

        cursor: pointer;

        transition: .2s;
    }

    .place-card:hover {
        background: #f7f7f7;
    }

    .place-card h3 {
        margin: 0 0 5px;
        color: #174ea6;
    }

    .place-thumb {

        width: 90px;

        height: 90px;

        border-radius: 10px;

        object-fit: cover;
    }

    .rating {
        color: #f39c12;
        margin-bottom: 5px;
    }

    .address {
        color: #666;
        line-height: 1.4;
    }

    @media (max-width:1024px) {

        .map-search-box,
        .result-panel {
            width: 350px;
        }

    }

    @media (max-width:768px) {

        #map {
            height: calc(100vh - 70px);
        }

        .map-search-box {

            top: 10px;
            left: 10px;

            width: calc(100% - 20px);

            max-width: none;
        }

        .result-panel {

            top: 76px;
            left: 10px;

            width: calc(100% - 20px);

            height: 45vh;
        }

        .place-thumb {

            width: 70px;
            height: 70px;
        }

        .place-card h3 {
            font-size: 16px;
        }

    }

    @media (max-width:480px) {

        .find-btn {
            width: 65px;
            font-size: 13px;
        }

        .map-search-box input {
            font-size: 14px;
        }

        .result-panel {
            height: 40vh;
        }

    }
</style>

<div class="google-map-section">

    <div class="google-map-container">


        <div class="map-search-box">

            <input
                id="pac-input"
                type="text"
                placeholder="Find now...">

            <button class="find-btn" id="find-btn">
                FIND
            </button>

        </div>

        <div class="result-panel" id="result-panel">
            <div id="results"></div>
        </div>

        <div id="map"></div>
    </div>

</div>

<script>
    let map;
    let marker;
    let autocomplete;
    let markers = [];
    let selectedMarker = null;
    let infoWindow = null;


    function getBrowserCountry() {

        const locale =
            navigator.language ||
            navigator.userLanguage ||
            'en-US';

        const parts = locale.split('-');

        return parts.length > 1 ?
            parts[1].toLowerCase() :
            'us';
    }

    function initMap() {

        const center = {
            lat: 11.0645,
            lng: 106.7200
        };

        map = new google.maps.Map(
            document.getElementById("map"), {
                center: center,
                zoom: 13,
                mapTypeControl: false,
            }
        );

        marker = new google.maps.Marker({
            position: center,
            map: map
        });

        const input = document.getElementById("pac-input");

        autocomplete = new google.maps.places.Autocomplete(input, {
            fields: [
                "formatted_address",
                "geometry",
                "name"
            ]
        });

        autocomplete.bindTo("bounds", map);

        autocomplete.addListener("place_changed", function() {

            const place = autocomplete.getPlace();

            if (!place.geometry || !place.geometry.location) {
                alert("Không tìm thấy địa điểm");
                return;
            }

            if (place.geometry.viewport) {
                map.fitBounds(place.geometry.viewport);
            } else {
                map.setCenter(place.geometry.location);
                map.setZoom(17);
            }

            marker.setMap(null);

            marker = new google.maps.Marker({
                position: place.geometry.location,
                map: map,
                title: place.name
            });

        });

    }

    window.initMap = initMap;

    document.getElementById('find-btn').addEventListener('click', function() {

        const keyword =
            document.getElementById('pac-input').value.trim();

        if (!keyword) {
            alert('Vui lòng nhập từ khóa');
            return;
        }

        searchPlaces(keyword);

    });

    function searchPlaces(keyword) {

        const service =
            new google.maps.places.PlacesService(map);


        service.textSearch({
                query: keyword
            },
            (results, status) => {

                if (
                    status !==
                    google.maps.places.PlacesServiceStatus.OK
                ) {
                    return;
                }

                renderResults(results);

            });

    }

    function renderResults(results) {

        clearMarkers();

        const bounds = new google.maps.LatLngBounds();

        const panel = document.getElementById("results");
        panel.innerHTML = "";

        document.getElementById("result-panel").style.display = "block";

        results.forEach(place => {

            const position = place.geometry.location;

            // Tạo marker
            const mapMarker = new google.maps.Marker({
                position: position,
                map: map,
                title: place.name
            });

            const infoWindow = new google.maps.InfoWindow({
                content: `
        <div style="min-width:250px">
            <h3 style="margin:0 0 8px">${place.name}</h3>

            <div>⭐ ${place.rating || '-'}</div>

            <div style="margin-top:8px">
                ${place.formatted_address || ''}
            </div>

            <br>

            <a target="_blank"
               href="https://www.google.com/maps/search/?api=1&query=${place.geometry.location.lat()},${place.geometry.location.lng()}">
               View on Google Maps
            </a>
        </div>
    `
            });

            mapMarker.addListener('click', function() {
                infoWindow.open(map, mapMarker);
            });

            markers.push(mapMarker);

            bounds.extend(position);

            const photo =
                place.photos?.[0]?.getUrl({
                    maxWidth: 300
                }) || '';

            panel.innerHTML += `
            <div class="place-card"
                data-name="${place.name}"
                data-address="${place.formatted_address || ''}"
                data-lat="${position.lat()}"
                data-lng="${position.lng()}">

                <div class="place-info">
                    <h3>${place.name}</h3>
                    <div class="rating">⭐ ${place.rating || '-'}</div>
                    <div class="address">${place.formatted_address || ''}</div>
                </div>

                ${photo ? `<img src="${photo}" class="place-thumb">` : ''}
            </div>
        `;
        });

        map.fitBounds(bounds);

        bindPlaces();
    }

    function clearMarkers() {
        markers.forEach(marker => marker.setMap(null));
        markers = [];
    }
    // function renderResults(results){

    //     const panel =
    //         document.getElementById("results");

    //     panel.innerHTML = "";

    //     document
    //         .getElementById("result-panel")
    //         .style.display = "block";

    //     results.forEach(place=>{

    //         const photo =
    //             place.photos?.[0]
    //             ?.getUrl({
    //                 maxWidth:300
    //             }) || '';

    //         panel.innerHTML += `

    //         <div class="place-card"
    //              data-lat="${place.geometry.location.lat()}"
    //              data-lng="${place.geometry.location.lng()}">

    //             <div class="place-info">

    //                 <h3>${place.name}</h3>

    //                 <div class="rating">
    //                     ⭐ ${place.rating || '-'}
    //                 </div>

    //                 <div class="address">
    //                     ${place.formatted_address || ''}
    //                 </div>

    //             </div>

    //             ${
    //                 photo
    //                 ?
    //                 `<img src="${photo}" />`
    //                 :
    //                 ''
    //             }

    //         </div>

    //         `;
    //     });

    //     bindPlaces();
    // }


    // function bindPlaces(){

    //     document.querySelectorAll('.place-card')
    //     .forEach(function(item){

    //         item.addEventListener('click', function(){

    //             const lat = parseFloat(
    //                 this.dataset.lat
    //             );

    //             const lng = parseFloat(
    //                 this.dataset.lng
    //             );

    //             const position = {
    //                 lat: lat,
    //                 lng: lng
    //             };

    //             map.setCenter(position);
    //             map.setZoom(18);

    //             if(selectedMarker){
    //                 selectedMarker.setMap(null);
    //             }

    //             selectedMarker = new google.maps.Marker({
    //                 position: position,
    //                 map: map,
    //                 animation: google.maps.Animation.DROP
    //             });

    //         });

    //     });

    // }

    // function bindPlaces(){

    //     document
    //     .querySelectorAll('.place-card')
    //     .forEach(item=>{

    //         item.addEventListener(
    //         'click',()=>{

    //             const lat =
    //                 parseFloat(
    //                 item.dataset.lat);

    //             const lng =
    //                 parseFloat(
    //                 item.dataset.lng);

    //             map.setCenter({
    //                 lat,lng
    //             });

    //             map.setZoom(18);

    //         });

    //     });

    // }

    function bindPlaces() {

        document.querySelectorAll('.place-card')
            .forEach(function(item) {

                item.addEventListener('click', function() {

                    const lat =
                        parseFloat(this.dataset.lat);

                    const lng =
                        parseFloat(this.dataset.lng);

                    const name =
                        this.dataset.name;

                    const address =
                        this.dataset.address;

                    const position = {
                        lat: lat,
                        lng: lng
                    };

                    map.setCenter(position);
                    map.setZoom(18);

                    if (selectedMarker) {
                        selectedMarker.setMap(null);
                    }

                    selectedMarker =
                        new google.maps.Marker({
                            position: position,
                            map: map,
                            animation: google.maps.Animation.DROP
                        });

                    if (infoWindow) {
                        infoWindow.close();
                    }

                    infoWindow =
                        new google.maps.InfoWindow({
                            content: `
                        <div style="min-width:220px">
                            <h4 style="margin:0 0 10px">
                                ${name}
                            </h4>

                            <div>
                                ${address}
                            </div>

                            <br>

                            <a target="_blank"
                               href="https://www.google.com/maps/search/?api=1&query=${lat},${lng}">
                                View on Google Maps
                            </a>

                        </div>
                    `
                        });

                    infoWindow.open({
                        anchor: selectedMarker,
                        map: map
                    });

                });

            });

    }
</script>

<script async defer
    src="https://maps.googleapis.com/maps/api/js?key=AIzaSyA5ip29G4RoZ_NHgOZYgKoGNb_e0JYl4wQ&libraries=places&callback=initMap">
</script>

<?php get_footer(); ?>