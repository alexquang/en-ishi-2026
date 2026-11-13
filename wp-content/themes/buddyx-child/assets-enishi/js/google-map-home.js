/**
 * Google Maps Home Integration
 */

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

// AJAX URL linh hoạt theo domain hiện tại
const ajaxUrl = (typeof ptMap !== 'undefined' && ptMap.ajax_url) ? ptMap.ajax_url : '/wp-admin/admin-ajax.php';

function escapeHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function clearMarkers() {
  markers.forEach((m) => m.setMap(null));
  markers = [];
}

function showRestaurantMarkers(results) {
  clearMarkers();

  const places = results
    .slice(0, 20)
    .filter((place) => place.geometry && place.geometry.location);

  if (places.length === 0) return;

  const bounds = new google.maps.LatLngBounds();

  places.forEach(function (place) {
    const mapMarker = new google.maps.Marker({
      map: map,
      position: place.geometry.location,
      title: place.name,
    });

    markers.push(mapMarker);
    bounds.extend(place.geometry.location);

    mapMarker.addListener("click", function () {
      loadPlaceDetails(place.place_id, mapMarker);
    });
  });

  if (places.length === 1) {
    map.setCenter(places[0].geometry.location);
    map.setZoom(16);
  } else {
    map.fitBounds(bounds);
    google.maps.event.addListenerOnce(map, "idle", function () {
      if (map.getZoom() > 16) {
        map.setZoom(16);
      }
    });
  }
}

function loadNearbyPlaces(lat, lng) {
  if (!map) return;
  const types = ["restaurant", "cafe", "lodging"];
  const placesService = new google.maps.places.PlacesService(map);
  const allPlaces = new Map();
  let completed = 0;

  types.forEach((type) => {
    placesService.nearbySearch(
      {
        location: new google.maps.LatLng(lat, lng),
        radius: 3000,
        type: type,
      },
      function (results, status) {
        if (status === google.maps.places.PlacesServiceStatus.OK && results) {
          results.forEach((place) => {
            if (place.place_id) {
              allPlaces.set(place.place_id, place);
            }
          });
        }
        completed++;
        if (completed === types.length) {
          showRestaurantMarkers([...allPlaces.values()]);
        }
      }
    );
  });
}

function initMap(userLat, userLng) {
  const defaultLat = userLat ? Number(userLat) : 10.8231;
  const defaultLng = userLng ? Number(userLng) : 106.6297;
  const center = { lat: defaultLat, lng: defaultLng };

  const mapElement = document.getElementById("map");
  if (!mapElement) return;

  map = new google.maps.Map(mapElement, {
    center: center,
    zoom: 13,
    mapTypeControl: false,
    gestureHandling: "greedy",
    scrollwheel: true,
    draggable: true,
  });

  service = new google.maps.places.PlacesService(map);

  marker = new google.maps.Marker({
    position: center, // Đã sửa: 'position' thay vì 'center'
    map: map,
    title: "Your Location",
  });

  const input = document.getElementById("pac-input");
  if (input) {
    autocomplete = new google.maps.places.Autocomplete(input, {
      fields: ["formatted_address", "geometry", "name", "place_id", "types"],
    });

    autocomplete.bindTo("bounds", map);

    autocomplete.addListener("place_changed", function () {
      const place = autocomplete.getPlace();
      if (!place.geometry || !place.geometry.location) {
        alert("No locations found");
        return;
      }

      clearMarkers();

      if (selectedMarker) {
        selectedMarker.setMap(null);
        selectedMarker = null;
      }

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

      loadNearbyPlaces(
        place.geometry.location.lat(),
        place.geometry.location.lng()
      );
    });
  }

  // Tự động load địa điểm xung quanh
  google.maps.event.addListenerOnce(map, "idle", function () {
    loadNearbyPlaces(defaultLat, defaultLng);
  });

  trafficLayer = new google.maps.TrafficLayer();
  transitLayer = new google.maps.TransitLayer();
  bikeLayer = new google.maps.BicyclingLayer();

  if (!layerControlsInitialized) {
    initLayerControls();
    layerControlsInitialized = true;
  }
}

window.initMap = initMap;

function initLayerControls() {
  const toggleBtn = document.getElementById("layers-toggle");
  const menu = document.getElementById("layers-menu");

  if (toggleBtn && menu) {
    toggleBtn.addEventListener("click", function () {
      menu.classList.toggle("show");
    });
  }

  const trafficCheck = document.getElementById("traffic-layer");
  if (trafficCheck) {
    trafficCheck.addEventListener("change", function () {
      if (trafficLayer) trafficLayer.setMap(this.checked ? map : null);
    });
  }

  const transitCheck = document.getElementById("transit-layer");
  if (transitCheck) {
    transitCheck.addEventListener("change", function () {
      if (transitLayer) transitLayer.setMap(this.checked ? map : null);
    });
  }

  const bikeCheck = document.getElementById("bike-layer");
  if (bikeCheck) {
    bikeCheck.addEventListener("change", function () {
      if (bikeLayer) bikeLayer.setMap(this.checked ? map : null);
    });
  }

  document.querySelectorAll('input[name="maptype"]').forEach(function (radio) {
    radio.addEventListener("change", function () {
      if (map) map.setMapTypeId(this.value);
    });
  });
}

function searchPlaces(overrideKeyword) {
  const input = document.getElementById("pac-input");
  const keyword = overrideKeyword || (input ? input.value.trim() : "");

  if (!keyword) return;

  const currentCenter = map ? map.getCenter() : new google.maps.LatLng(10.8231, 106.6297);
  const placesService = service || new google.maps.places.PlacesService(map);

  placesService.textSearch(
    {
      query: keyword,
      location: currentCenter,
      radius: 5000,
    },
    function (results, status) {
      if (status !== google.maps.places.PlacesServiceStatus.OK || !results) {
        console.error("Places Error:", status);
        renderResults([]);
        return;
      }

      clearMarkers();

      const nearbyResults = results.filter((p) => p.geometry && p.geometry.location);

      nearbyResults.forEach((place) => {
        const itemMarker = new google.maps.Marker({
          position: place.geometry.location,
          map: map,
          title: place.name,
        });

        markers.push(itemMarker);
        itemMarker.addListener("click", () => {
          loadPlaceDetails(place.place_id, itemMarker);
        });
      });

      renderResults(nearbyResults);
    }
  );
}

function renderResults(results) {
  clearMarkers();

  const resultPanel = document.getElementById("result-panel");
  const toggleBtn = document.getElementById("toggle-results");
  const panel = document.getElementById("results");

  if (resultPanel) {
    resultPanel.classList.add("show");
    resultPanel.style.display = "block";
  }
  if (toggleBtn) toggleBtn.classList.add("show");
  if (!panel) return;

  panel.innerHTML = "";
  if (!results.length) {
    panel.innerHTML = '<div class="gm-empty">No results found</div>';
    return;
  }

  const bounds = new google.maps.LatLngBounds();

  results.forEach((place) => {
    const position = place.geometry.location;

    const mapMarker = new google.maps.Marker({
      position: position,
      map: map,
      title: place.name,
    });

    mapMarker.addListener("click", function () {
      loadPlaceDetails(place.place_id, mapMarker);
      setTimeout(function () {
        if (toggleBtn) {
          toggleBtn.style.position = "fixed";
          toggleBtn.style.setProperty("left", "765px", "important");
          toggleBtn.style.transform = "none";
        }
      }, 100);
    });

    markers.push(mapMarker);
    bounds.extend(position);

    const photo = place.photos?.[0]?.getUrl({ maxWidth: 300 }) || "";

    panel.innerHTML += `
      <div class="place-card"
          data-placeid="${escapeHtml(place.place_id || "")}"
          data-name="${escapeHtml(place.name || "")}"
          data-rating="${place.rating || "-"}"
          data-address="${escapeHtml(place.formatted_address || "")}"
          data-photo="${escapeHtml(photo)}"
          data-lat="${position.lat()}"
          data-lng="${position.lng()}">
          <div class="place-info">
              <h3>${escapeHtml(place.name)}</h3>
              <div class="rating">
                  <i class="fa-solid fa-star" style="color: #ff7200;"></i> ${place.rating || "-"}
              </div>
              <div class="address">${escapeHtml(place.formatted_address || "")}</div>
          </div>
          ${photo ? `<img src="${escapeHtml(photo)}" class="place-thumb" alt="${escapeHtml(place.name)}">` : ""}
      </div>
    `;
  });

  if (map) map.fitBounds(bounds);
  bindPlaces();
}

function bindPlaces() {
  document.querySelectorAll(".place-card").forEach(function (item) {
    item.addEventListener("click", function () {
      const placeId = this.dataset.placeid;
      const lat = parseFloat(this.dataset.lat);
      const lng = parseFloat(this.dataset.lng);
      const position = { lat: lat || 0, lng: lng || 0 };

      if (map) {
        map.setCenter(position);
        map.setZoom(18);
      }

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

function loadPlaceDetails(placeId, clickedMarker) {
  if (!map) return;
  const placesService = service || new google.maps.places.PlacesService(map);

  placesService.getDetails(
    {
      placeId: placeId,
      fields: [
        "name", "formatted_address", "formatted_phone_number",
        "website", "rating", "user_ratings_total", "opening_hours",
        "photos", "url", "types", "reviews"
      ],
    },
    function (place, status) {
      if (status !== google.maps.places.PlacesServiceStatus.OK || !place) {
        console.error("Place details error:", status);
        return;
      }

      const resultPanel = document.querySelector(".result-wrapper");
      if (!resultPanel) return;

      let popup = document.getElementById("gm-place-popup");
      if (!popup) {
        popup = document.createElement("div");
        popup.id = "gm-place-popup";
        popup.className = "gm-place-popup";
        resultPanel.appendChild(popup);
      }

      const photo = place.photos?.[0] ? place.photos[0].getUrl({ maxWidth: 800, maxHeight: 500 }) : "";

      let placeType = "Place";
      if (place.types?.length) {
        const type = place.types.find((t) => !["point_of_interest", "establishment"].includes(t));
        if (type) {
          placeType = type.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
        }
      }

      // Reviews
      let reviewsHTML = "";
      if (place.reviews && place.reviews.length) {
        reviewsHTML = `
          <div class="gm-popup-reviews">
              <div class="gm-reviews-header">
                  <div class="gm-reviews-title"><i class="fa-solid fa-star"></i> Reviews</div>
                  <div class="gm-reviews-summary">
                      <strong>${place.rating ? place.rating.toFixed(1) : "-"}</strong>
                      <span class="gm-review-stars">★</span>
                      <span>(${place.user_ratings_total || 0})</span>
                  </div>
              </div>
              <div class="gm-reviews-list">
                  ${place.reviews.map(function (rev) {
                    const authorName = escapeHtml(rev.author_name || "Google User");
                    const authorPhoto = rev.profile_photo_url || "";
                    const rating = rev.rating || 0;
                    const reviewText = escapeHtml(rev.text || "");
                    const relativeTime = escapeHtml(rev.relative_time_description || "");

                    return `
                      <div class="gm-review">
                          <div class="gm-review-author">
                              ${authorPhoto ? `<img class="gm-review-avatar" src="${escapeHtml(authorPhoto)}" alt="${authorName}">` : `<div class="gm-review-avatar gm-review-avatar-default">${authorName.charAt(0)}</div>`}
                              <div class="gm-review-author-info">
                                  <div class="gm-review-author-name">${authorName}</div>
                                  <div class="gm-review-meta">
                                      <span class="gm-review-stars">${"★".repeat(rating)}</span>
                                      <span class="gm-review-time">${relativeTime}</span>
                                  </div>
                              </div>
                          </div>
                          ${reviewText ? `<div class="gm-review-text">${reviewText}</div>` : ""}
                      </div>
                    `;
                  }).join("")}
              </div>
              <div class="gm-google-attribution">Reviews from Google</div>
          </div>
        `;
      }

      // Hours
      let hoursHTML = "";
      if (place.opening_hours && place.opening_hours.weekday_text) {
        hoursHTML = `
          <div class="gm-popup-row">
              <i class="fa-regular fa-clock"></i>
              <div>${place.opening_hours.weekday_text.map((day) => `<div>${escapeHtml(day)}</div>`).join("")}</div>
          </div>
        `;
      }

      popup.innerHTML = `
        <button type="button" class="gm-popup-close" id="gm-popup-close" aria-label="Close">
            <i class="fa-solid fa-xmark"></i>
        </button>
        ${photo ? `<div class="gm-popup-cover"><img src="${escapeHtml(photo)}" alt="${escapeHtml(place.name)}"></div>` : ""}
        <div class="gm-popup-body">
            <h2 class="gm-popup-title">${escapeHtml(place.name)}</h2>
            <div class="gm-popup-rating">
                <span>${place.rating || "-"}</span>
                <i class="fa-solid fa-star"></i>
                <span>${place.user_ratings_total || 0}</span>
            </div>
            <div class="gm-popup-type">${escapeHtml(placeType)}</div>
            <div class="gm-popup-tabs">
                <button type="button" class="gm-popup-tab active" data-popup-tab="overview">Overview</button>
                <button type="button" class="gm-popup-tab gm-picture-btn" data-tab="picture" data-placeid="${placeId}">Picture</button>
                <button type="button" class="gm-popup-tab gm-video-btn" data-tab="video" data-placeid="${placeId}">Video</button>
            </div>
            <div class="gm-popup-info">
                <div class="gm-popup-row"><i class="fa-solid fa-location-dot"></i><div>${escapeHtml(place.formatted_address || "")}</div></div>
                ${place.formatted_phone_number ? `<div class="gm-popup-row"><i class="fa-solid fa-phone"></i><div>${escapeHtml(place.formatted_phone_number)}</div></div>` : ""}
                ${place.website ? `<div class="gm-popup-row"><i class="fa-solid fa-globe"></i><div><a href="${escapeHtml(place.website)}" target="_blank" rel="noopener">${escapeHtml(place.website)}</a></div></div>` : ""}
                ${hoursHTML}
                ${reviewsHTML}
                ${place.url ? `<div class="gm-popup-row"><i class="fa-solid fa-map"></i><div><a href="${escapeHtml(place.url)}" target="_blank" rel="noopener">View on Google Maps</a></div></div>` : ""}
            </div>
        </div>
      `;

      popup.classList.add("show");

      const closeBtn = document.getElementById("gm-popup-close");
      if (closeBtn) {
        closeBtn.addEventListener("click", function () {
          popup.classList.remove("show");
          const toggleBtn = document.getElementById("toggle-results");
          if (toggleBtn) {
            toggleBtn.style.position = "fixed";
            toggleBtn.style.setProperty("left", "400px", "important");
            toggleBtn.style.transform = "none";
          }
        });
      }

      if (clickedMarker && map) {
        map.panTo(clickedMarker.getPosition());
      }
    }
  );
}

// Khởi tạo các sự kiện DOM
document.addEventListener("DOMContentLoaded", function () {
  // Lấy GPS ban đầu
  if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
      function (pos) {
        const lat = pos.coords.latitude;
        const lng = pos.coords.longitude;
        if (map) {
          map.setCenter({ lat, lng });
          map.setZoom(15);
        }
      },
      function (err) {
        console.warn("GPS unavailable:", err.message);
      },
      { timeout: 10000 }
    );
  }

  // Nút tìm kiếm
  const findBtn = document.getElementById("find-btn");
  if (findBtn) {
    findBtn.addEventListener("click", function () {
      searchPlaces();
    });
  }

  // Enter trong input search
  const pacInput = document.getElementById("pac-input");
  if (pacInput) {
    pacInput.addEventListener("keypress", function (e) {
      if (e.key === "Enter") {
        searchPlaces();
      }
    });
  }

  // Đóng dialog Video an toàn
  const videoCloseBtn = document.getElementById("gmVideoClose");
  if (videoCloseBtn) {
    videoCloseBtn.addEventListener("click", function () {
      const dialog = document.getElementById("gmVideoDialog");
      if (dialog) dialog.classList.remove("show");
      const videoContent = document.getElementById("gmVideoContent");
      if (videoContent) videoContent.innerHTML = "";
    });
  }

  // Đóng dialog Picture an toàn
  const pictureCloseBtn = document.getElementById("gmPictureClose");
  if (pictureCloseBtn) {
    pictureCloseBtn.addEventListener("click", function () {
      const dialog = document.getElementById("gmPictureDialog");
      if (dialog) dialog.classList.remove("show");
    });
  }
});