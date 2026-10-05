let map;
let marker;
let autocomplete;
let markers = [];
let selectedMarker = null;
let infoWindow = null;
let trafficLayer;
let transitLayer;
let bikeLayer;
let layerControlsInitialized = false;
let service;
let photoCarouselPlaces = [];

function showRestaurantMarkers(results) {
  clearMarkers();

  results.slice(0, 20).forEach(function (place) {
    const mapMarker = new google.maps.Marker({
      map: map,
      position: place.geometry.location,
      title: place.name,
    });

    markers.push(mapMarker);

    mapMarker.addListener("click", function () {
      loadPlaceDetails(place.place_id, mapMarker);
    });
  });
}

function loadNearbyPlaces(lat, lng) {
  const types = ["restaurant", "cafe", "lodging"];

  const service = new google.maps.places.PlacesService(map);

  const allPlaces = new Map();

  types.forEach((type) => {
    service.nearbySearch(
      {
        location: new google.maps.LatLng(lat, lng),
        radius: 50000,
        type: type,
      },
      function (results, status) {
        if (status !== google.maps.places.PlacesServiceStatus.OK) {
          return;
        }

        results.forEach((place) => {
          allPlaces.set(place.place_id, place);
        });

        showRestaurantMarkers([...allPlaces.values()]);
      },
    );
  });
}

const majorCities = {
  VN: {
    name: "Ho Chi Minh City",
    lat: 10.8231,
    lng: 106.6297,
    zoom: 8,
  },

  JP: {
    name: "Tokyo",
    lat: 35.6762,
    lng: 139.6503,
    zoom: 8,
  },

  KR: {
    name: "Seoul",
    lat: 37.5665,
    lng: 126.978,
    zoom: 8,
  },

  TH: {
    name: "Bangkok",
    lat: 13.7563,
    lng: 100.5018,
    zoom: 8,
  },

  SG: {
    name: "Singapore",
    lat: 1.3521,
    lng: 103.8198,
    zoom: 11,
  },

  MY: {
    name: "Kuala Lumpur",
    lat: 3.139,
    lng: 101.6869,
    zoom: 9,
  },

  ID: {
    name: "Jakarta",
    lat: -6.2088,
    lng: 106.8456,
    zoom: 8,
  },

  PH: {
    name: "Manila",
    lat: 14.5995,
    lng: 120.9842,
    zoom: 9,
  },

  CN: {
    name: "Shanghai",
    lat: 31.2304,
    lng: 121.4737,
    zoom: 7,
  },

  US: {
    name: "New York",
    lat: 40.7128,
    lng: -74.006,
    zoom: 8,
  },

  CA: {
    name: "Toronto",
    lat: 43.6532,
    lng: -79.3832,
    zoom: 8,
  },

  GB: {
    name: "London",
    lat: 51.5074,
    lng: -0.1278,
    zoom: 9,
  },

  FR: {
    name: "Paris",
    lat: 48.8566,
    lng: 2.3522,
    zoom: 9,
  },

  DE: {
    name: "Berlin",
    lat: 52.52,
    lng: 13.405,
    zoom: 8,
  },

  AU: {
    name: "Sydney",
    lat: -33.8688,
    lng: 151.2093,
    zoom: 8,
  },
};

document.addEventListener("DOMContentLoaded", function () {

  const countryLanguageMap = {

    VN: "vi",

    JP: "ja",
    KR: "ko",

    CN: "zh-CN",
    TW: "zh-TW",
    HK: "zh-TW",

    TH: "th",
    KH: "km",
    LA: "lo",

    ID: "id",
    MY: "ms",

    US: "en",
    GB: "en",
    CA: "en",
    AU: "en",
    NZ: "en",

    FR: "fr",
    DE: "de",
    ES: "es",
    IT: "it",
    PT: "pt",
    NL: "nl",
    RU: "ru"
  };


  // =====================================
  // COUNTRY -> LANGUAGE
  // =====================================

  function getLanguageByCountry(countryCode) {

    countryCode =
      String(countryCode || "")
        .toUpperCase();

    return (
      countryLanguageMap[countryCode] ||
      "en"
    );
  }


  // =====================================
  // CHANGE GTRANSLATE
  // =====================================

  function changeGTranslateLanguage(language) {

  const targetValue = "en|" + language;

  console.log(
    "[GTranslate] Target:",
    targetValue
  );


  let attempts = 0;

  const timer = setInterval(function () {

    attempts++;

    const wrapper =
      document.querySelector(
        ".gtranslate_wrapper"
      );

    const select =
      document.querySelector(
        "select.gt_selector"
      );


    if (!wrapper || !select) {

      console.log(
        "[GTranslate] Waiting...",
        attempts
      );

      return;
    }


    const option =
      select.querySelector(
        'option[value="' +
        targetValue +
        '"]'
      );


    if (!option) {

      console.log(
        "[GTranslate] Option chưa sẵn sàng:",
        targetValue
      );

      return;
    }


    console.log(
      "[GTranslate] Selector ready"
    );


    clearInterval(timer);


    // =====================================
    // KÍCH HOẠT GTRANSLATE
    // giống thao tác đưa chuột vào menu
    // =====================================

    [
      "mouseover",
      "mouseenter",
      "mousemove"
    ].forEach(function (eventName) {

      wrapper.dispatchEvent(
        new MouseEvent(
          eventName,
          {
            bubbles: true,
            cancelable: true,
            view: window
          }
        )
      );

    });


    // =====================================
    // CHỜ GTRANSLATE KHỞI TẠO
    // =====================================

    setTimeout(function () {

      console.log(
        "[GTranslate] Setting:",
        targetValue
      );


      select.focus();


      select.value =
        targetValue;


      // ===================================
      // Native change
      // ===================================

      select.dispatchEvent(
        new Event(
          "change",
          {
            bubbles: true,
            cancelable: true
          }
        )
      );


      // ===================================
      // Nếu site có jQuery
      // ===================================

      if (
        typeof jQuery !== "undefined"
      ) {

        jQuery(select).trigger("change");

      }


      console.log(
        "[GTranslate] Auto language:",
        targetValue
      );


    }, 500);


  }, 300);


  // =====================================
  // TIMEOUT
  // =====================================

  setTimeout(function () {

    clearInterval(timer);

  }, 15000);

}

  // =====================================
  // MOVE MAP TO GPS
  // =====================================

  function moveMapToGPS(lat, lng) {

    const position = {
      lat: Number(lat),
      lng: Number(lng)
    };


    console.log(
      "[MAP] GPS position:",
      position
    );


    // -------------------------------------
    // Nếu map đã tồn tại
    // -------------------------------------

    if (
      typeof map !== "undefined" &&
      map
    ) {

      map.setCenter(
        position
      );

      map.setZoom(15);


      console.log(
        "[MAP] Map moved to GPS"
      );

    } else {

      console.warn(
        "[MAP] Chưa tìm thấy biến map"
      );
    }
  }


  // =====================================
  // CHECK GPS
  // =====================================

  if (
    !navigator.geolocation
  ) {

    console.warn(
      "[GPS] Browser không hỗ trợ GPS"
    );

    return;
  }


  navigator.geolocation.getCurrentPosition(

    // ===================================
    // GPS SUCCESS
    // ===================================

    function (position) {

      const lat =
        position.coords.latitude;

      const lng =
        position.coords.longitude;

      const accuracy =
        position.coords.accuracy;


      console.log(
        "GPS:",
        lat,
        lng
      );


      console.log(
        "GPS Accuracy:",
        accuracy,
        "meters"
      );


      // ===================================
      // CHUYỂN MAP VỀ GPS
      // ===================================

      moveMapToGPS(
        lat,
        lng
      );


      // ===================================
      // REVERSE GEOCODE
      // ===================================

      if (
        typeof google === "undefined" ||
        typeof google.maps === "undefined"
      ) {

        console.warn(
          "[Google Maps] API chưa load"
        );

        return;
      }


      const geocoder =
        new google.maps.Geocoder();


      geocoder.geocode(

        {
          location: {
            lat: lat,
            lng: lng
          }
        },


        function (
          results,
          status
        ) {


          // =================================
          // CHECK RESULT
          // =================================

          if (
            status !== "OK" ||
            !results ||
            !results.length
          ) {

            console.warn(
              "Không xác định được quốc gia:",
              status
            );

            return;
          }


          // =================================
          // FIND COUNTRY
          // =================================

          const countryResult =
            results.find(
              function (result) {

                return (
                  result.types &&
                  result.types.includes(
                    "country"
                  )
                );

              }
            );


          if (!countryResult) {

            console.warn(
              "Không tìm thấy country"
            );

            return;
          }


          // =================================
          // GET COUNTRY CODE
          // =================================

          let countryCode = "";


          countryResult
            .address_components
            .forEach(
              function (component) {

                if (
                  component.types &&
                  component.types.includes(
                    "country"
                  )
                ) {

                  countryCode =
                    component.short_name
                      .toUpperCase();

                }

              }
            );


          console.log(
            "COUNTRY:",
            countryCode
          );


          // =================================
          // COUNTRY -> LANGUAGE
          // =================================

          const language =
            getLanguageByCountry(
              countryCode
            );


          console.log(
            "LANGUAGE:",
            language
          );


          // =================================
          // CHANGE GTRANSLATE
          // =================================

          changeGTranslateLanguage(
            language
          );

        }

      );

    },


    // ===================================
    // GPS ERROR
    // ===================================

    function (error) {

      console.warn(
        "Không lấy được GPS:",
        error.message
      );

    },


    // ===================================
    // GPS OPTIONS
    // ===================================

    {
      enableHighAccuracy: true,

      timeout: 20000,

      maximumAge: 0
    }

  );

});

// async function initMapByCountry(countryLat, countryLng, countryCode) {
//   let center = {
//     lat: Number(countryLat),
//     lng: Number(countryLng),
//   };

//   let zoom = 7;

//   map = new google.maps.Map(document.getElementById("map"), {
//     center: center,

//     zoom: zoom,

//     mapTypeControl: false,

//     gestureHandling: "greedy",

//     scrollwheel: true,

//     draggable: true,

//     streetViewControl: false,

//     fullscreenControl: false,
//   });

//   service = new google.maps.places.PlacesService(map);

//   // Không tạo GPS marker
//   marker = null;

//   trafficLayer = new google.maps.TrafficLayer();

//   transitLayer = new google.maps.TransitLayer();

//   bikeLayer = new google.maps.BicyclingLayer();

//   if (!layerControlsInitialized) {
//     initLayerControls();

//     layerControlsInitialized = true;
//   }
// }

async function initMapByCountry(countryLat, countryLng, countryCode) {
  // =========================
  // MẶC ĐỊNH
  // =========================

  let center = {
    lat: Number(countryLat),
    lng: Number(countryLng),
  };

  let zoom = 6;

  // =========================
  // TÌM THÀNH PHỐ MẶC ĐỊNH
  // =========================

  const city = majorCities[countryCode];

  if (city) {
    center = {
      lat: city.lat,
      lng: city.lng,
    };

    zoom = city.zoom;

    console.log("Default city:", city.name);
  } else {
    console.log("Không có thành phố mặc định cho:", countryCode);
  }

  // =========================
  // CREATE MAP
  // =========================

  map = new google.maps.Map(document.getElementById("map"), {
    center: center,

    zoom: zoom,

    mapTypeControl: false,

    gestureHandling: "greedy",

    scrollwheel: true,

    draggable: true,

    streetViewControl: false,

    fullscreenControl: false,
  });

  // =========================
  // PLACES SERVICE
  // =========================

  service = new google.maps.places.PlacesService(map);

  // =========================
  // KHÔNG TẠO GPS MARKER
  // =========================

  marker = null;

  // =========================
  // LAYERS
  // =========================

  trafficLayer = new google.maps.TrafficLayer();

  transitLayer = new google.maps.TransitLayer();

  bikeLayer = new google.maps.BicyclingLayer();

  // =========================
  // LAYER CONTROL
  // =========================

  if (!layerControlsInitialized) {
    initLayerControls();

    layerControlsInitialized = true;
  }

  console.log("Map initialized:", countryCode, center, zoom);
}

async function initMap(userLat, userLng) {
  const center = {
    lat: userLat ?? 0,
    lng: userLng ?? 0,
  };

  map = new google.maps.Map(document.getElementById("map"), {
    center: center,
    zoom: 12,
    mapTypeControl: false,
    gestureHandling: "greedy",
    scrollwheel: true,
    draggable: true,
  });

  service = new google.maps.places.PlacesService(map);

  marker = new google.maps.Marker({
    center: center,
    map: map,
  });

  const input = document.getElementById("pac-input");

  autocomplete = new google.maps.places.Autocomplete(input, {
    fields: ["formatted_address", "geometry", "name"],
  });

  autocomplete.bindTo("bounds", map);

  autocomplete.addListener("place_changed", function () {
    const place = autocomplete.getPlace();

    // if (!place.geometry || !place.geometry.location) {
    //   alert("No locations found");
    //   return;
    // }

    // Xóa toàn bộ marker kết quả cũ
    clearMarkers();

    // Xóa marker đang chọn
    if (selectedMarker) {
      selectedMarker.setMap(null);
      selectedMarker = null;
    }

    // Xóa marker GPS hiện tại
    if (marker) {
      marker.setMap(null);
    }

    if (place.geometry.viewport) {
      map.fitBounds(place.geometry.viewport);
    } else {
      map.setCenter(place.geometry.location);
      map.setZoom(15);
    }

    marker = new google.maps.Marker({
      position: place.geometry.location,
      map: map,
      title: place.name,
      animation: google.maps.Animation.DROP,
    });

    // Tự tìm quanh vị trí mới
    loadNearbyPlaces(
      place.geometry.location.lat(),
      place.geometry.location.lng(),
    );
  });

  google.maps.event.addListenerOnce(map, "idle", function () {
    loadNearbyPlaces(userLat, userLng);
  });

  if (!map) {
    map = new google.maps.Map(document.getElementById("map"), {
      center: { lat, lng },
      zoom: 15,
      gestureHandling: "greedy",
      scrollwheel: true,
      draggable: true,
    });

    trafficLayer = new google.maps.TrafficLayer();
    transitLayer = new google.maps.TransitLayer();
    bikeLayer = new google.maps.BicyclingLayer();
  }

  if (!layerControlsInitialized) {
    initLayerControls();
    layerControlsInitialized = true;
  }
}

window.initMap = initMap;

document
  .getElementById("find-btn")
  .addEventListener("click", async function () {
    const keyword = document.getElementById("pac-input").value.trim();

    const aiPanel = document.getElementById("ai-result-panel");

    if (!keyword) {
      alert("Enter a keyword.");
      return;
    }

    // Hiện loading
    await showSearchLoading();

    document.getElementById("search-wrapper").style.display = "block";

    const resultWrapper = document.querySelector(".result-wrapper");

    if (resultWrapper) {
      resultWrapper.style.display = "block";
    }

    if (aiPanel) {
      aiPanel.style.display = "none";
    }

    try {
      // Search ngay sau khi click
      await searchPlaces(keyword);

      // Search xong mới chờ 5 giây
      await new Promise((resolve) => setTimeout(resolve, 5000));
    } finally {
      hideSearchLoading();
    }
  });

function showSearchLoading() {
  let dialog = document.getElementById("search-loading-dialog");

  if (!dialog) {
    dialog = document.createElement("div");
    dialog.id = "search-loading-dialog";

    dialog.innerHTML = `
            <div class="search-loading-content">
                <span class="search-loading"></span>
                <span>Please wait, searching...</span>
            </div>
        `;

    document.body.appendChild(dialog);

    const style = document.createElement("style");
    style.id = "search-loading-style";

    style.textContent = `
            #search-loading-dialog {
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                right: 0 !important;
                bottom: 0 !important;

                width: 100vw !important;
                height: 100vh !important;

                z-index: 2147483647 !important;

                background: rgba(0, 0, 0, 0.35);

                display: flex;
                align-items: center;
                justify-content: center;
            }

            #search-loading-dialog .search-loading-content {
                position: relative;
                z-index: 2147483647 !important;

                background: #fff;
                padding: 20px 28px;
                border-radius: 10px;

                display: flex;
                align-items: center;
                gap: 12px;

                font-size: 15px;
                color: #333;

                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
            }

            #search-loading-dialog .search-loading {
                width: 18px;
                height: 18px;

                border: 2px solid #ddd;
                border-top-color: #333;
                border-radius: 50%;

                animation: searchLoadingSpin 0.7s linear infinite;
            }

            @keyframes searchLoadingSpin {
                to {
                    transform: rotate(360deg);
                }
            }
        `;

    document.head.appendChild(style);
  }

  // Hiện dialog
  dialog.style.display = "flex";

  // Đảm bảo luôn nằm trên Google Maps
  document.body.appendChild(dialog);
}

function hideSearchLoading() {
  const dialog = document.getElementById("search-loading-dialog");

  if (dialog) {
    dialog.style.display = "none";
  }
}

function initLayerControls() {
  const toggleBtn = document.getElementById("layers-toggle");

  const menu = document.getElementById("layers-menu");

  toggleBtn.addEventListener("click", function () {
    menu.classList.toggle("show");

    console.log("show");
  });

  document
    .getElementById("traffic-layer")
    .addEventListener("change", function () {
      if (this.checked) {
        trafficLayer.setMap(map);
      } else {
        trafficLayer.setMap(null);
      }
    });

  document
    .getElementById("transit-layer")
    .addEventListener("change", function () {
      if (this.checked) {
        transitLayer.setMap(map);
      } else {
        transitLayer.setMap(null);
      }
    });

  document.getElementById("bike-layer").addEventListener("change", function () {
    if (this.checked) {
      bikeLayer.setMap(map);
    } else {
      bikeLayer.setMap(null);
    }
  });

  document.querySelectorAll('input[name="maptype"]').forEach(function (radio) {
    radio.addEventListener("change", function () {
      map.setMapTypeId(this.value);
    });
  });
}

function searchPlaces() {
  const keyword = document.getElementById("pac-input").value.trim();

  if (!keyword) {
    return;
  }

  if (!navigator.geolocation) {
    alert("Trình duyệt không hỗ trợ định vị.");
    return;
  }

  navigator.geolocation.getCurrentPosition(
    function (position) {
      const userLat = position.coords.latitude;
      const userLng = position.coords.longitude;

      const userLocation = new google.maps.LatLng(userLat, userLng);

      console.log("User GPS:", userLat, userLng);

      const service = new google.maps.places.PlacesService(map);

      service.textSearch(
        {
          query: keyword,
          location: userLocation,
          radius: 5000,
        },

        function (results, status) {
          if (status !== google.maps.places.PlacesServiceStatus.OK) {
            console.error("Places Error:", status);
            renderResults([]);
            return;
          }

          // =========================
          // XÓA MARKER CŨ
          // =========================

          markers.forEach((marker) => {
            marker.setMap(null);
          });

          markers = [];

          // =========================
          // CHỈ GIỮ PLACE CÓ LOCATION
          // =========================

          const nearbyResults = results.filter((place) => {
            return place.geometry && place.geometry.location;
          });

          // =========================
          // TẠO MARKER
          // =========================

          nearbyResults.forEach((place) => {
            const marker = new google.maps.Marker({
              position: place.geometry.location,
              map: map,
              title: place.name,
            });

            markers.push(marker);

            marker.addListener("click", () => {
              loadPlaceDetails(place.place_id);
            });
          });

          // =========================
          // QUAN TRỌNG:
          // ZOOM VỀ GPS NGƯỜI DÙNG
          // =========================

          map.setCenter(userLocation);

          map.setZoom(15);

          // =========================
          // LIST BÊN TRÁI
          // =========================

          renderResults(nearbyResults);
        },
      );
    },

    function (error) {
      console.error("GPS Error:", error);

      switch (error.code) {
        case error.PERMISSION_DENIED:
          alert("Bạn chưa cho phép website sử dụng vị trí.");
          break;

        case error.POSITION_UNAVAILABLE:
          alert("Không lấy được vị trí hiện tại.");
          break;

        case error.TIMEOUT:
          alert("Lấy GPS quá thời gian.");
          break;

        default:
          alert("Không thể lấy vị trí.");
      }
    },

    {
      enableHighAccuracy: true,
      timeout: 10000,
      maximumAge: 0,
    },
  );
}

// function searchPlaces() {
//   const keyword = document.getElementById("pac-input").value.trim();

//   if (!keyword) {
//     return;
//   }

//   const service = new google.maps.places.PlacesService(map);

//   service.textSearch(
//     {
//       query: keyword,
//     },
//     function (results, status) {
//       if (status !== google.maps.places.PlacesServiceStatus.OK) {
//         console.error("Places Error:", status);
//         return;
//       }

//       // Xóa marker cũ
//       markers.forEach((marker) => {
//         marker.setMap(null);
//       });

//       markers = [];

//       const bounds = new google.maps.LatLngBounds();

//       results.forEach((place) => {
//         if (!place.geometry) {
//           return;
//         }

//         const marker = new google.maps.Marker({
//           position: place.geometry.location,
//           map: map,
//           title: place.name,
//         });

//         markers.push(marker);

//         bounds.extend(place.geometry.location);

//         marker.addListener("click", () => {
//           loadPlaceDetails(place.place_id);
//         });
//       });

//       // Zoom về khu vực kết quả
//       if (results.length > 1) {
//         map.fitBounds(bounds);
//       } else {
//         map.setCenter(results[0].geometry.location);

//         map.setZoom(16);
//       }

//       // Render list bên trái
//       renderResults(results);
//     },
//   );
// }

function renderResults(results) {
  clearMarkers();

  document.getElementById("result-panel").classList.add("show");
  document.getElementById("toggle-results").classList.add("show");

  const bounds = new google.maps.LatLngBounds();

  const panel = document.getElementById("results");

  panel.innerHTML = "";

  document.getElementById("result-panel").style.display = "block";

  results.forEach((place) => {
    const position = place.geometry.location;

    const mapMarker = new google.maps.Marker({
      position: position,
      map: map,
      title: place.name,
    });

    mapMarker.addListener("click", function () {
      loadPlaceDetails(place.place_id, mapMarker);
    });

    markers.push(mapMarker);

    bounds.extend(position);

    const photo =
      place.photos?.[0]?.getUrl({
        maxWidth: 300,
      }) || "";

    panel.innerHTML += `

            <div class="place-card"
                data-placeid="${place.place_id || ""}"
                data-name="${place.name}"
                data-rating="${place.rating || "-"}"
                data-address="${place.formatted_address || ""}"
                data-photo="${photo}"
                data-lat="${position.lat()}"
                data-lng="${position.lng()}">

                <div class="place-info">

                    <h3>${place.name}</h3>

                    <div class="rating">
                        <i class="fa-solid fa-star" style="color: #ff7200;"></i> ${
                          place.rating || "-"
                        }
                    </div>

                    <div class="address">
                        ${place.formatted_address || ""}
                    </div>

                </div>

                ${photo ? `<img src="${photo}" class="place-thumb">` : ""}

            </div>

            `;
  });

  map.fitBounds(bounds);
  bindPlaces();
}

function clearMarkers() {
  markers.forEach((marker) => marker.setMap(null));
  markers = [];
}

function bindPlaces() {
  document.querySelectorAll(".place-card").forEach(function (item) {
    item.addEventListener("click", function () {
      const placeId = this.dataset.placeid;

      const lat = parseFloat(this.dataset.lat);

      const lng = parseFloat(this.dataset.lng);

      const position = {
        lat: lat ?? 0,
        lng: lng ?? 0,
      };

      map.setCenter(position);
      map.setZoom(18);

      if (selectedMarker) {
        selectedMarker.setMap(null);
      }

      selectedMarker = new google.maps.Marker({
        position: position,
        map: map,
        animation: google.maps.Animation.DROP,
      });

      if (!placeId) {
        alert("Unable to find the Place ID.");

        return;
      }

      loadPlaceDetails(placeId, selectedMarker);
    });
  });
}

function searchByType(type) {
  const service = new google.maps.places.PlacesService(map);

  service.nearbySearch(
    {
      location: map.getCenter(),
      radius: 50000,
      type: type,
    },
    function (results, status) {
      if (status === google.maps.places.PlacesServiceStatus.OK) {
        renderResults(results);
      }
    },
  );
}

document.querySelectorAll(".cat-btn[data-type]").forEach((btn) => {
  btn.addEventListener("click", function () {
    const type = this.dataset.type;

    searchByType(type);
  });
});

function loadPlaceDetails(placeId, marker) {
  const service = new google.maps.places.PlacesService(map);

  service.getDetails(
    {
      placeId: placeId,

      fields: [
        "name",
        "formatted_address",
        "formatted_phone_number",
        "website",
        "rating",
        "user_ratings_total",
        "opening_hours",
        "photos",
        "url",
        "types",
        "reviews",
      ],
    },

    function (place, status) {
      if (status !== google.maps.places.PlacesServiceStatus.OK) {
        console.error("Place details error:", status);

        return;
      }

      /*
       * RESULT PANEL
       */
      const resultPanel = document.querySelector(".result-wrapper");

      if (!resultPanel) {
        console.error(".result-wrapper not found");

        return;
      }

      /*
       * LẤY / TẠO POPUP
       */
      let popup = document.getElementById("gm-place-popup");

      if (!popup) {
        popup = document.createElement("div");

        popup.id = "gm-place-popup";

        popup.className = "gm-place-popup";

        resultPanel.appendChild(popup);
      }

      /*
       * PHOTO
       */
      const photo = place.photos?.[0]
        ? place.photos[0].getUrl({
            maxWidth: 800,
            maxHeight: 500,
          })
        : "";

      /*
       * TYPE
       */
      let placeType = "Place";

      if (place.types?.length) {
        const type = place.types.find(
          (t) => !["point_of_interest", "establishment"].includes(t),
        );

        if (type) {
          placeType = type
            .replace(/_/g, " ")
            .replace(/\b\w/g, (c) => c.toUpperCase());
        }
      }

      /*
       * REVIEWS
       */
      let reviewsHTML = "";

      if (place.reviews && place.reviews.length) {
        reviewsHTML = `
        <div class="gm-popup-reviews">

            <div class="gm-reviews-header">

                <div class="gm-reviews-title">
                    <i class="fa-solid fa-star"></i>
                    Reviews
                </div>

                <div class="gm-reviews-summary">

                    <strong>
                        ${place.rating ? place.rating.toFixed(1) : "-"}
                    </strong>

                    <span class="gm-review-stars">
                        ★
                    </span>

                    <span>
                        (${place.user_ratings_total || 0})
                    </span>

                </div>

            </div>


            <div class="gm-reviews-list">

                ${place.reviews
                  .map(function (review) {
                    const authorName = review.author_name || "Google User";

                    const authorPhoto = review.profile_photo_url || "";

                    const authorUrl = review.author_url || "#";

                    const rating = review.rating || 0;

                    const reviewText = review.text || "";

                    const relativeTime = review.relative_time_description || "";

                    return `

                        <div class="gm-review">

                            <div class="gm-review-author">

                                ${
                                  authorPhoto
                                    ? `
                                            <img
                                                class="gm-review-avatar"
                                                src="${authorPhoto}"
                                                alt="${authorName}">
                                        `
                                    : `
                                            <div class="gm-review-avatar gm-review-avatar-default">
                                                ${authorName.charAt(0)}
                                            </div>
                                        `
                                }


                                <div class="gm-review-author-info">

                                    <a
                                        href="${authorUrl}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="gm-review-author-name">

                                        ${authorName}

                                    </a>


                                    <div class="gm-review-meta">

                                        <span class="gm-review-stars">
                                            ${"★".repeat(rating)}
                                        </span>

                                        <span class="gm-review-time">
                                            ${relativeTime}
                                        </span>

                                    </div>

                                </div>

                            </div>


                            ${
                              reviewText
                                ? `
                                        <div class="gm-review-text">
                                            ${reviewText}
                                        </div>
                                    `
                                : ""
                            }

                        </div>

                    `;
                  })
                  .join("")}

            </div>


            <div class="gm-google-attribution">

                Reviews from Google

            </div>

        </div>
    `;
      }

      /*
       * OPENING HOURS
       */
      let hoursHTML = "";

      if (place.opening_hours && place.opening_hours.weekday_text) {
        hoursHTML = `
                    <div class="gm-popup-row">

                        <i class="fa-regular fa-clock"></i>

                        <div>

                            ${place.opening_hours.weekday_text
                              .map(
                                (day) => `
                                    <div>
                                        ${day}
                                    </div>
                                `,
                              )
                              .join("")}

                        </div>

                    </div>
                `;
      }

      /*
       * WEBSITE
       */
      const websiteHTML = place.website
        ? `
                    <div class="gm-popup-row">

                        <i class="fa-solid fa-globe"></i>

                        <div>

                            <a
                                href="${place.website}"
                                target="_blank"
                                rel="noopener">
                                ${place.website}
                            </a>

                        </div>

                    </div>
                    `
        : "";

      /*
       * PHONE
       */
      const phoneHTML = place.formatted_phone_number
        ? `
                    <div class="gm-popup-row">

                        <i class="fa-solid fa-phone"></i>

                        <div>
                            ${place.formatted_phone_number}
                        </div>

                    </div>
                    `
        : "";

      /*
       * RENDER POPUP
       */
      popup.innerHTML = `

                <button
                    type="button"
                    class="gm-popup-close"
                    id="gm-popup-close">

                    <i class="fa-solid fa-xmark"></i>

                </button>


                ${
                  photo
                    ? `
                        <div class="gm-popup-cover">

                            <img
                                src="${photo}"
                                alt="${place.name}">

                        </div>
                        `
                    : ""
                }


                <div class="gm-popup-body">


                    <!-- TITLE -->

                    <h2 class="gm-popup-title">
                        ${place.name}
                    </h2>


                    <!-- RATING -->

                    <div class="gm-popup-rating">

                        <span>
                            ${place.rating || "-"}
                        </span>

                        <i class="fa-solid fa-star"></i>

                        <span>
                            ${place.user_ratings_total || 0}
                        </span>

                    </div>


                    <!-- TYPE -->

                    <div class="gm-popup-type">
                        ${placeType}
                    </div>


                    <!-- TABS -->

                    <div class="gm-popup-tabs">

                        <button
                            class="gm-popup-tab active"
                            data-popup-tab="overview">

                            Messege

                        </button>

                        <button
                            class="gm-popup-tab"
                            data-popup-tab="live">

                            Live

                        </button>

                      <button
                          class="gm-popup-tab gm-picture-btn"
                          data-tab="picture"
                          data-placeid="${placeId}">
                          Picture
                      </button>

                        <button
                            class="gm-popup-tab gm-video-btn"
                            data-popup-tab="video"
                            data-tab="video"
                            data-placeid="${placeId}" >
                            Video

                        </button>



                    </div>


                    <!-- ACTIONS -->

                    <div class="gm-popup-actions">


                        <div class="gm-popup-action">

                            <div class="gm-popup-action-icon">

                                <i class="fa-solid fa-diamond-turn-right"></i>

                            </div>

                            Directions

                        </div>


                        <div class="gm-popup-action">

                            <div class="gm-popup-action-icon">

                                <i class="fa-regular fa-bookmark"></i>

                            </div>

                            Save

                        </div>


                        <div class="gm-popup-action">

                            <div class="gm-popup-action-icon">

                                <i class="fa-solid fa-location-crosshairs"></i>

                            </div>

                            Nearby

                        </div>


                        <div class="gm-popup-action">

                            <div class="gm-popup-action-icon">

                                <i class="fa-solid fa-share-nodes"></i>

                            </div>

                            Share

                        </div>


                    </div>


                    <!-- INFO -->

                    <div class="gm-popup-info">


                        <!-- ADDRESS -->

                        <div class="gm-popup-row">

                            <i class="fa-solid fa-location-dot"></i>

                            <div>
                                ${place.formatted_address || ""}
                            </div>

                        </div>


                        <!-- PHONE -->

                        ${phoneHTML}


                        <!-- WEBSITE -->

                        ${websiteHTML}


                        <!-- HOURS -->

                        ${hoursHTML}


                        <!-- REVIEWS -->

                        ${reviewsHTML}

                        <!-- GOOGLE MAP -->

                        ${
                          place.url
                            ? `
                                <div class="gm-popup-row">

                                    <i class="fa-solid fa-map"></i>

                                    <div>

                                        <a
                                            href="${place.url}"
                                            target="_blank"
                                            rel="noopener">

                                            View on Google Maps

                                        </a>

                                    </div>

                                </div>
                                `
                            : ""
                        }


                    </div>

                </div>

            `;

      /*
       * HIỆN POPUP
       */
      popup.classList.add("show");

      /*
       * CLOSE
       */
      const closeBtn = document.getElementById("gm-popup-close");

      if (closeBtn) {
        closeBtn.addEventListener("click", function () {
          popup.classList.remove("show");
        });
      }

      /*
       * TAB
       */
      popup.querySelectorAll(".gm-popup-tab").forEach(function (tab) {
        tab.addEventListener("click", function () {
          popup
            .querySelectorAll(".gm-popup-tab")
            .forEach((t) => t.classList.remove("active"));

          this.classList.add("active");

          const tabName = this.dataset.popupTab;

          console.log("Selected tab:", tabName);
        });
      });

      /*
       * CENTER MAP VÀO MARKER
       */
      if (marker) {
        map.panTo(marker.getPosition());
      }
    },
  );
}
// function loadPlaceDetails(placeId, marker) {
//   const service = new google.maps.places.PlacesService(map);

//   service.getDetails(
//     {
//       placeId: placeId,

//       fields: [
//         "name",
//         "formatted_address",
//         "formatted_phone_number",
//         "website",
//         "rating",
//         "user_ratings_total",
//         "opening_hours",
//         "photos",
//         "url",
//       ],
//     },
//     function (place, status) {
//       if (status !== google.maps.places.PlacesServiceStatus.OK) {
//         return;
//       }

//       const photo = place.photos?.[0]
//         ? place.photos[0].getUrl({
//             maxWidth: 800,
//             maxHeight: 400,
//           })
//         : "";

//       if (infoWindow) {
//         infoWindow.close();
//       }

//       infoWindow = new google.maps.InfoWindow({
//         maxWidth: 700,
//         content: `
//       <div class="gm-place-card">

//     ${
//       photo
//         ? `
//     <div class="gm-cover">
//         <img src="${photo}">
//     </div>
//     `
//         : ""
//     }

//     <div class="gm-body">

//         <h2 class="gm-title">
//             ${place.name}
//         </h2>

//         <div class="gm-rating">
//             <i class="fa-solid fa-star"></i>
//             <span>${place.rating || "-"}</span>
//             <small>
//                 (${place.user_ratings_total || 0} reviews)
//             </small>
//         </div>

//         <div class="gm-address">
//             <i class="fa-solid fa-location-dot"></i>
//             <span>${place.formatted_address || ""}</span>
//         </div>

//         <div class="gm-tabs">

//             <button
//                 class="gm-tab active"
//                 data-tab="info">
//                 Info
//             </button>

//             <button
//                 class="gm-tab gm-picture-btn"
//                 data-tab="picture"
//                 data-placeid="${placeId}">
//                 Picture
//             </button>

//             <button
//                 class="gm-tab"
//                 data-tab="video"
//                 data-placeid="${placeId}">
//                 Video
//             </button>

//             <button
//                 class="gm-tab"
//                 data-tab="live"
//                 data-placeid="${placeId}">
//                 Live
//             </button>

//         </div>

//         <div class="gm-tab-content">

//             <div id="tab-info" class="gm-pane active">

//                 ${
//                   place.website
//                     ? `
//                     <div class="gm-link">
//                         <i class="fa-solid fa-globe"></i>
//                         <a href="${place.website}" target="_blank">
//                             Website
//                         </a>
//                     </div>
//                     `
//                     : ""
//                 }

//                 ${
//                   place.opening_hours
//                     ? `
//                     <div class="gm-hours">
//                         ${place.opening_hours.weekday_text
//                           .map(
//                             (item) => `
//                             <div class="gm-hour-row">
//                                 ${item}
//                             </div>
//                             `,
//                           )
//                           .join("")}
//                     </div>
//                     `
//                     : ""
//                 }

//                 <div class="gm-map-link">
//                     <a href="${place.url}" target="_blank">
//                         View on Google Maps
//                     </a>
//                 </div>

//             </div>

//             <div id="tab-picture" class="gm-pane">
//                 <div class="gm-loading">
//                     Click Picture to load photos
//                 </div>
//             </div>

//             <div id="tab-video" class="gm-pane">
//                 <div class="gm-loading">
//                     No videos yet
//                 </div>
//             </div>

//             <div id="tab-live" class="gm-pane">
//                 <div class="gm-loading">
//                     No livestream
//                 </div>
//             </div>

//         </div>

//     </div>

// </div>
// `,
//       });

//       infoWindow.open({
//         anchor: marker,
//         map: map,
//       });
//     },
//   );
// }

document.querySelectorAll('input[name="maptype"]').forEach((radio) => {
  radio.addEventListener("change", function () {
    map.setMapTypeId(this.value);

    document.getElementById("current-layer").textContent =
      this.nextSibling.textContent.trim();
  });
});

/* insert Videos */
document.addEventListener("click", function (e) {
  const uploadBtn = e.target.closest("#gmUploadVideoBtn");

  if (!uploadBtn) {
    return;
  }

  const fileInput = document.getElementById("gmUploadVideoFile");

  if (!fileInput) {
    console.error("gmUploadVideoFile not found");
    return;
  }

  fileInput.click();
});

document.addEventListener("change", function (e) {
  const button = e.target.closest("#gmUploadVideoFile");

  if (!button) {
    return;
  }

  console.log("gmUploadVideoFile");
  if (e.target.id !== "gmUploadVideoFile") {
    return;
  }

  const file = e.target.files[0];

  if (!file) {
    return;
  }

  const uploadBtn = document.getElementById("gmUploadVideoBtn");
  const dialog = document.getElementById("gmVideoDialog");

  if (!uploadBtn || !dialog) {
    console.error("Video upload elements not found");
    return;
  }

  // Lấy Place ID
  const placeId = dialog.dataset.placeid;

  if (!placeId) {
    alert("Place ID not found");
    return;
  }

  // Kiểm tra video
  if (!file.type.startsWith("video/")) {
    alert("Please select a video file.");
    e.target.value = "";
    return;
  }

  console.log("Upload video:", file.name);
  console.log("Place ID:", placeId);

  // Disable button
  uploadBtn.disabled = true;

  uploadBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Uploading...';

  // FormData
  const formData = new FormData();

  formData.append("action", "upload_place_video");

  formData.append("place_id", placeId);

  formData.append("video", file);

  fetch("https://dev.pt-solution.vn/wp-admin/admin-ajax.php", {
    method: "POST",
    body: formData,
    credentials: "same-origin",
  })
    .then(async (response) => {
      const text = await response.text();

      console.log("HTTP Status:", response.status);

      console.log("Response:", text);

      if (!response.ok) {
        throw new Error("HTTP " + response.status + ": " + text);
      }

      return JSON.parse(text);
    })

    .then((res) => {
      console.log("Upload video result:", res);

      if (res.success) {
        alert("Video upload success!");

        // Reset file input
        e.target.value = "";

        // Reload video
        loadPlaceVideos(placeId);
      } else {
        console.error("Upload error:", res.data);

        alert(typeof res.data === "string" ? res.data : "Upload failed");
      }
    })

    .catch((error) => {
      console.error("Video upload error:", error);

      alert("Upload failed: " + error.message);
    })

    .finally(() => {
      uploadBtn.disabled = false;

      uploadBtn.innerHTML = '<i class="fa fa-upload"></i> Upload';
    });
});

/* insert Photo */
document.addEventListener("change", function (e) {
  if (e.target.id !== "photo-upload") {
    return;
  }

  const file = e.target.files[0];

  if (!file) {
    return;
  }

  const uploadBtn = document.getElementById("btn-upload");
  const photoBtn = e.target.closest("#photo-modal-content");
  const placeId = photoBtn.dataset.placeid;

  uploadBtn.disabled = true;
  uploadBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Loading...';
  const formData = new FormData();

  formData.append("action", "upload_place_photo");
  if (photoBtn) {
    formData.append("place_id", placeId);
  }
  formData.append("photo", file);
  fetch("https://dev.pt-solution.vn/wp-admin/admin-ajax.php", {
    method: "POST",
    body: formData,
  })
    .then((response) => response.json())
    .then((res) => {
      if (res.success) {
        // reload
        loadUserPhotos(placeId);

        // reset file input
        document.getElementById("photo-upload").value = "";

        // thông báo
        alert("Upload success!");
      } else {
        console.log(res.data);
        alert(res.data || "Upload fail");
      }
    })
    .catch((error) => {
      console.error(error);
      alert("Upload fail");
    })
    .finally(() => {
      uploadBtn.disabled = false;
      uploadBtn.innerHTML = '<i class="fa fa-upload"></i> Uploading';
    });
});

document.addEventListener("click", function (e) {
  const photoBtn = e.target.closest("#photo-btn");

  if (photoBtn) {
    const placeId11 = photoBtn.dataset.placeid;

    console.log(placeId11);

    let dialog = document.getElementById("photo-dialog");

    if (!dialog) {
      document.body.insertAdjacentHTML(
        "beforeend",
        `
                <div id="photo-dialog" class="photo-modal" >

                    <div class="photo-modal-content" id="photo-modal-content" data-placeid="${placeId11}">

                        <div class="modal-header">

                            <h3>Pictures</h3>

                            <button id="close-photo-dialog">
                                ✕
                            </button>

                        </div>

                        <div id="photo-list"
                             class="photo-grid">
                        </div>

                        <div class="modal-footer">

                            <button id="btn-upload">
                                📤 Upload
                            </button>

                            <input
                                type="file"
                                id="photo-upload"
                                hidden
                                accept="image/*">

                        </div>

                    </div>

                </div>
                `,
      );
    }

    const placeId = photoBtn.dataset.placeid;

    document.getElementById("photo-dialog").classList.add("show");

    loadUserPhotos(placeId);

    return;
  }

  if (e.target.closest("#close-photo-dialog")) {
    document.getElementById("photo-dialog")?.classList.remove("show");

    return;
  }

  if (e.target.closest("#btn-upload")) {
    document.getElementById("photo-upload")?.click();
  }
});

function loadUserPhotos(placeId) {
  fetch("https://dev.pt-solution.vn/wp-admin/admin-ajax.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/x-www-form-urlencoded",
    },
    body: new URLSearchParams({
      action: "get_user_photos",
      place_id: placeId,
    }),
  })
    .then((response) => response.json())
    .then((res) => {
      if (!res.success) {
        return;
      }

      let html = "";

      res.data.forEach((photo) => {
        html += `
                <div class="photo-item">
                    <img src="${photo.url}"
                         data-url="${photo.url}">
                </div>
            `;
      });

      document.getElementById("photo-list").innerHTML = html;
    });
}

document.addEventListener("DOMContentLoaded", function () {
  const wrapper = document.querySelector(".search-wrapper");

  document.getElementById("result-panel").classList.remove("show");
  document.getElementById("toggle-results").classList.remove("show");

  const btn = document.getElementById("toggle-results");

  if (!btn) {
    return;
  }

  btn.addEventListener("click", function () {
    wrapper.classList.toggle("collapsed");

    const icon = this.querySelector("i");

    if (wrapper.classList.contains("collapsed")) {
      icon.className = "fa-solid fa-chevron-right";
    } else {
      icon.className = "fa-solid fa-chevron-left";
    }
  });
});

/* Load Place Video */
function loadPlaceVideos(placeId) {
  fetch(
    "https://dev.pt-solution.vn/wp-admin/admin-ajax.php?action=get_place_uploads_videos&place_id=" +
      placeId,
  )
    .then((r) => r.json())
    .then((res) => {
      console.log("Place videos:", res);

      if (!res.success || !res.data) {
        return;
      }

      renderVideos(res.data);
    })
    .catch((error) => {
      console.error("Load videos error:", error);
    });
}

/* Load Place Photo */
function loadPlacePhotos(placeId) {
  const service = new google.maps.places.PlacesService(map);

  service.getDetails(
    {
      placeId: placeId,

      fields: ["name", "photos"],
    },
    function (place, status) {
      if (status !== google.maps.places.PlacesServiceStatus.OK) {
        return;
      }

      fetch(
        "https://dev.pt-solution.vn/wp-admin/admin-ajax.php?action=get_place_uploads&place_id=" +
          placeId,
      )
        .then((r) => r.json())
        .then((res) => {
          renderPhotos(place, res.data || []);
        });
    },
  );
}

/* Render Video */
function renderVideos(videos) {
  const container = document.querySelector("#gmVideoContent");

  if (!container) {
    return;
  }

  if (!videos.length) {
    container.innerHTML = "";
    return;
  }

  container.innerHTML = videos
    .map(
      (video) => `
        <div class="place-video">
          <video
            src="${video.url}"
            controls
            playsinline
            preload="metadata"
            style="width:100%; border-radius:12px;"
          ></video>
        </div>
      `,
    )
    .join("");
}

/* Render Photo */
function renderPhotos(place, uploads) {
  const container = document.getElementById("gmPictureContent");

  let html = "";

  if (place.photos) {
    place.photos.forEach((photo) => {
      html += `
                <div class="gm-photo-item">

                    <img
                        src="${photo.getUrl({
                          maxWidth: 1200,
                        })}">

                    <span class="gm-badge">
                        Google
                    </span>

                </div>
            `;
    });
  }

  uploads.forEach((item) => {
    html += `
            <div class="gm-photo-item">

                <img
                    src="${item.url}">

                <span
                    class="gm-badge gm-user">

                    User

                </span>

            </div>
        `;
  });

  container.innerHTML = html;
}
function normalizeSearch() {
  return document.getElementById("pac-input").value.trim();
}

/* Dialog Video */
document.addEventListener("click", function (e) {
  const videoBtn = e.target.closest(".gm-video-btn");

  if (!videoBtn) {
    return;
  }

  const placeId = videoBtn.dataset.placeid;

  openPlaceVideoDialog(placeId);
});

/* Dialog Picture */
document.addEventListener("click", function (e) {
  const pictureBtn = e.target.closest(".gm-picture-btn");

  if (!pictureBtn) {
    return;
  }

  const placeId = pictureBtn.dataset.placeid;

  openPlacePictureDialog(placeId);
});

/* Open Video */
function openPlaceVideoDialog(placeId) {
  const dialog = document.getElementById("gmVideoDialog");

  dialog.dataset.placeid = placeId;

  dialog.classList.add("show");

  document.getElementById("gmVideoContent").innerHTML = `
        <div class="gm-empty">
            Loading videos...
        </div>
    `;

  console.log("Open Video Dialog:", placeId);

  loadPlaceVideos(placeId);
}

/* Open Picture */
function openPlacePictureDialog(placeId) {
  const dialog = document.getElementById("gmPictureDialog");

  dialog.dataset.placeid = placeId;

  dialog.classList.add("show");

  document.getElementById("gmPictureContent").innerHTML = `
        <div class="gm-empty">
            Loading pictures...
        </div>
    `;

  console.log("Open Picture Dialog:", placeId);

  loadPlacePhotos(placeId);
}
document.addEventListener("click", function (e) {
  if (e.target.id === "gmPictureClose" || e.target.closest("#gmPictureClose")) {
    document.getElementById("gmPictureDialog").classList.remove("show");
  }
});

document.addEventListener("DOMContentLoaded", function () {
  document
    .getElementById("pac-input")
    .addEventListener("keypress", function (e) {
      if (e.key === "Enter") {
        searchPlaces();
      }
    });
});

/* Upload file */
document.addEventListener("click", function (e) {
  if (
    e.target.id === "gmUploadPhotoBtn" ||
    e.target.closest("#gmUploadPhotoBtn")
  ) {
    document.getElementById("gmUploadPhotoFile").click();
  }
});

const uploadInput = document.getElementById("gmUploadPhotoFile");
if (uploadInput) {
  document
    .getElementById("gmUploadPhotoFile")
    .addEventListener("change", function () {
      const file = this.files[0];

      if (!file) {
        return;
      }

      const placeId =
        document.getElementById("gmPictureDialog").dataset.placeid;

      const formData = new FormData();

      formData.append("action", "upload_place_photo");

      formData.append("place_id", placeId);

      formData.append("photo", file);

      fetch("https://dev.pt-solution.vn/wp-admin/admin-ajax.php", {
        method: "POST",
        body: formData,
      })
        .then((r) => r.json())
        .then((res) => {
          if (res.success) {
            loadPlacePhotos(placeId);
          } else {
            alert(res.data);
          }
        });
    });
}

// document.getElementById("ai-close").addEventListener("click", function () {

//     document.getElementById("ai-result-panel").style.display = "none";

//     document.getElementById("ai-loading").style.display = "none";

//     document.getElementById("ai-result-content").innerHTML = "";

// });

document.addEventListener("DOMContentLoaded", function () {
  const aiButton = document.getElementById("ai-search-btn");
  const searchInput = document.getElementById("pac-input");
  const query = searchInput?.value.trim() || "";

  const aiPanel = document.getElementById("ai-result-panel");
  const aiContent = document.getElementById("ai-result-content");
  const closeButton = document.getElementById("close-ai-result");

  if (!aiButton) {
    console.warn("ai-search-btn not found");
    return;
  }

  /*
   * CLICK SEARCH WITH GEMINI
   */
  aiButton.addEventListener("click", function (e) {
    e.preventDefault();

    const searchInput = document.getElementById("pac-input");

    const checksearchInput = searchInput?.value?.trim() || "";

    /*
     * Không có nội dung
     */
    if (!checksearchInput) {
      alert("Please enter search content.");

      if (searchInput) {
        searchInput.focus();
      }

      // Không hiện Gemini AI panel
      return;
    }

    const resultWrapper = document.querySelector(".result-wrapper");

    if (resultWrapper) {
      resultWrapper.style.display = "none";
    }

    if (aiPanel) {
      aiPanel.style.display = "block";
    }

    console.log("Gemini button clicked");

    /*
     * Lấy nội dung từ Search Google Maps
     */
    const query = searchInput ? searchInput.value.trim() : "";

    console.log("AI Search:", query);

    /*
     * Không có nội dung
     */
    if (!query) {
      if (searchInput) {
        searchInput.focus();
      }

      return;
    }

    /*
     * Hiện AI result panel
     */
    if (aiPanel) {
      aiPanel.style.display = "block";
    }

    /*
     * Loading
     */
    if (aiContent) {
      aiContent.innerHTML = `
                <div class="ai-loading">

                    <i class="fa-solid fa-spinner fa-spin"></i>

                    <span>
                        Gemini is searching...
                    </span>

                </div>
            `;
    }

    /*
     * Disable button
     */
    aiButton.disabled = true;

    /*
     * CALL WORDPRESS AJAX
     */
    fetch("https://dev.pt-solution.vn/wp-admin/admin-ajax.php", {
      method: "POST",

      headers: {
        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
      },

      body: new URLSearchParams({
        action: "pt_ai_search",
        query: query,
      }),
    })
      /*
       * Response
       */
      .then(function (response) {
        return response.json();
      })

      /*
       * Gemini result
       */
      .then(function (data) {
        console.log("Gemini result:", data);

        /*
         * ERROR
         */
        if (!data.success) {
          aiContent.innerHTML = `
            <div class="ai-error">
                <i class="fa-solid fa-circle-exclamation"></i>

                <span>
                    ${data.data?.message || "Gemini search failed."}
                </span>
            </div>
        `;

          // Đảm bảo panel vẫn hiện để thấy lỗi
          if (aiPanel) {
            aiPanel.style.display = "block";
          }

          return;
        }

        /*
         * LẤY CONTENT GEMINI
         */
        const html = data.data?.html || "No result.";

        /*
         * HIỂN THỊ CONTENT
         */
        aiContent.innerHTML = html;

        /*
         * HIỆN PANEL
         */
        if (aiPanel) {
          aiPanel.style.display = "block";
        }
      })

      /*
       * Network error
       */
      .catch(function (error) {
        console.error("Gemini AJAX error:", error);

        aiContent.innerHTML = `
                <div class="ai-error">

                    <i class="fa-solid fa-circle-exclamation"></i>

                    <span>
                        Unable to connect to Gemini.
                    </span>

                </div>
            `;
      })

      /*
       * Finish
       */
      .finally(function () {
        aiButton.disabled = false;
      });
  });

  /*
   * CLOSE AI RESULT
   */
  if (closeButton) {
    closeButton.addEventListener("click", function () {
      if (aiPanel) {
        aiPanel.style.display = "none";
      }
    });
  }
});
/* END GEMINI SEARCH */

/* Close Dialog Video */
document.getElementById("gmVideoClose").addEventListener("click", function () {
  const dialog = document.getElementById("gmVideoDialog");

  dialog.classList.remove("show");

  // Xóa nội dung video khi đóng
  document.getElementById("gmVideoContent").innerHTML = "";
});

/* Auto Load Image TODO*/
