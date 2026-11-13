/**
 * Google Maps Home Integration - Full Google Maps Experience
 */

let map;
let marker;
let userLocationMarker = null;
let userLocationCircle = null;
let userCoords = null;
let autocomplete;
let originAutocomplete;
let destAutocomplete;
let markers = [];
let selectedMarker = null;
let infoWindow = null;
let trafficLayer;
let transitLayer;
let bikeLayer;
let layerControlsInitialized = false;
let service;
let directionsService;
let directionsRenderer;
let currentTravelMode = "DRIVING";
let currentPlaceDetails = null;
let lightboxImages = [];
let lightboxIndex = 0;

// AJAX URL linh hoạt theo domain hiện tại
const ajaxUrl =
  typeof ptMap !== "undefined" && ptMap.ajax_url
    ? ptMap.ajax_url
    : "/wp-admin/admin-ajax.php";

function escapeHtml(str) {
  if (!str) return "";
  return String(str)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function showToast(message) {
  let toast = document.getElementById("gm-toast-notification");
  if (!toast) {
    toast = document.createElement("div");
    toast.id = "gm-toast-notification";
    toast.className = "gm-toast-notification";
    document.body.appendChild(toast);
  }
  toast.textContent = message;
  toast.classList.add("show");
  clearTimeout(toast._hideTimer);
  toast._hideTimer = setTimeout(function () {
    toast.classList.remove("show");
  }, 2600);
}

/* =====================================================
   CHUẨN HÓA TỪ KHÓA TÌM KIẾM TIẾNG VIỆT
===================================================== */
function normalizeSearchQuery(query) {
  if (!query) return "";
  let q = query.trim();

  q = q.replace(/\b(tp\.?\s*hcm|tphcm|hcmc)\b/gi, "TP Hồ Chí Minh");

  for (let i = 1; i <= 12; i++) {
    const regex = new RegExp(`\\bq\\.?\\s*${i}\\b`, "gi");
    q = q.replace(regex, `Quận ${i}`);
  }

  q = q.replace(/\bquan\s+(\d+)\b/gi, "Quận $1");
  q = q.replace(/\bp\.?\s*(\d+)\b/gi, "Phường $1");
  q = q.replace(/\bphuong\s+(\d+)\b/gi, "Phường $1");
  q = q.replace(/\bh\.?\s*(\d+)\b/gi, "Huyện $1");
  q = q.replace(/\bhuyen\s+(\d+)\b/gi, "Huyện $1");
  q = q.replace(/\btp\.?\s*thu\s*duc\b/gi, "Thành phố Thủ Đức");
  q = q.replace(/\bxa\s+/gi, "Xã ");
  q = q.replace(/\bx\.\s+/gi, "Xã ");
  q = q.replace(/\btt\.?\s+/gi, "Thị trấn ");
  q = q.replace(/\btx\.?\s+/gi, "Thị xã ");

  const cityAliases = {
    hn: "Hà Nội",
    hanoi: "Hà Nội",
    dn: "Đà Nẵng",
    danang: "Đà Nẵng",
    hp: "Hải Phòng",
    haiphong: "Hải Phòng",
    ct: "Cần Thơ",
    cantho: "Cần Thơ",
  };

  const lower = q.toLowerCase();
  if (cityAliases[lower]) {
    q = cityAliases[lower];
  }

  return q.replace(/\s+/g, " ").trim();
}

/* =====================================================
   LOADING DIALOG KHI SEARCH
===================================================== */
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
  }

  dialog.style.display = "flex";
}

function hideSearchLoading() {
  const dialog = document.getElementById("search-loading-dialog");
  if (dialog) {
    dialog.style.display = "none";
  }
}

/* =====================================================
   QUẢN LÝ MARKER & VỊ TRÍ NGƯỜI DÙNG
===================================================== */
function clearMarkers() {
  markers.forEach((m) => m.setMap(null));
  markers = [];
}

function renderUserLocationDot(latLng) {
  if (!map) return;

  if (userLocationMarker) {
    userLocationMarker.setPosition(latLng);
  } else {
    userLocationMarker = new google.maps.Marker({
      position: latLng,
      map: map,
      title: "Your Location",
      zIndex: 999,
      icon: {
        path: google.maps.SymbolPath.CIRCLE,
        scale: 8,
        fillColor: "#4285F4",
        fillOpacity: 1,
        strokeColor: "#ffffff",
        strokeWeight: 3,
      },
    });
  }

  if (userLocationCircle) {
    userLocationCircle.setCenter(latLng);
  } else {
    userLocationCircle = new google.maps.Circle({
      map: map,
      center: latLng,
      radius: 120,
      fillColor: "#4285F4",
      fillOpacity: 0.15,
      strokeColor: "#4285F4",
      strokeOpacity: 0.3,
      strokeWeight: 1,
      clickable: false,
    });
  }
}

function syncToggleButtonPosition() {
  const wrapper = document.querySelector(".search-wrapper");
  const resultPanel = document.getElementById("result-panel");
  const popup = document.getElementById("gm-place-popup");
  const toggleBtn = document.getElementById("toggle-results");

  if (!toggleBtn) return;

  const isPanelShown = resultPanel && resultPanel.classList.contains("show");
  const isPopupShown = popup && popup.classList.contains("show");

  if (!isPanelShown && !isPopupShown) {
    toggleBtn.classList.remove("show");
    return;
  }

  toggleBtn.classList.add("show");
  toggleBtn.style.position = "fixed";
  toggleBtn.style.transform = "none";

  const icon = toggleBtn.querySelector("i");

  if (wrapper && wrapper.classList.contains("collapsed")) {
    if (icon) icon.className = "fa-solid fa-chevron-right";
    toggleBtn.style.setProperty("left", "0px", "important");
  } else {
    if (icon) icon.className = "fa-solid fa-chevron-left";
    if (isPopupShown) {
      toggleBtn.style.setProperty("left", "765px", "important");
    } else {
      toggleBtn.style.setProperty("left", "400px", "important");
    }
  }
}

function ensureResultPanelOpen() {
  const searchWrapper = document.querySelector(".search-wrapper");
  const resultWrapper = document.querySelector(".result-wrapper");
  const resultPanel = document.getElementById("result-panel");
  const aiPanel = document.getElementById("ai-result-panel");

  if (aiPanel) {
    aiPanel.style.display = "none";
  }
  if (searchWrapper) {
    searchWrapper.classList.remove("collapsed");
  }
  if (resultWrapper) {
    resultWrapper.style.display = "block";
    resultWrapper.style.width = "400px";
    resultWrapper.style.maxWidth = "400px";
  }
  if (resultPanel) {
    resultPanel.classList.add("show");
    resultPanel.style.display = "block";
  }
  syncToggleButtonPosition();
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
  const placesService = service || new google.maps.places.PlacesService(map);
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

/* =====================================================
   KHỞI TẠO BẢN ĐỒ GOOGLE MAPS
===================================================== */
function initMap(userLat, userLng) {
  const defaultLat = userLat ? Number(userLat) : 10.7769;
  const defaultLng = userLng ? Number(userLng) : 106.7009;
  const center = { lat: defaultLat, lng: defaultLng };

  const mapElement = document.getElementById("map");
  if (!mapElement) return;

  map = new google.maps.Map(mapElement, {
    center: center,
    zoom: 14,
    mapTypeControl: false,
    fullscreenControl: false,
    streetViewControl: false,
    zoomControl: false,
    gestureHandling: "greedy",
    scrollwheel: true,
    draggable: true,
  });

  service = new google.maps.places.PlacesService(map);
  directionsService = new google.maps.DirectionsService();
  directionsRenderer = new google.maps.DirectionsRenderer({
    map: map,
    suppressMarkers: false,
    polylineOptions: {
      strokeColor: "#1a73e8",
      strokeOpacity: 0.9,
      strokeWeight: 6,
    },
  });

  renderUserLocationDot(center);

  // Autocomplete cho ô tìm kiếm chính
  const input = document.getElementById("pac-input");
  if (input) {
    autocomplete = new google.maps.places.Autocomplete(input, {
      fields: ["formatted_address", "geometry", "name", "place_id", "types"],
    });

    autocomplete.bindTo("bounds", map);

    autocomplete.addListener("place_changed", function () {
      const place = autocomplete.getPlace();
      if (!place.geometry || !place.geometry.location) {
        searchPlaces(input.value);
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
        map.setZoom(16);
      }

      selectedMarker = new google.maps.Marker({
        position: place.geometry.location,
        map: map,
        title: place.name,
        animation: google.maps.Animation.DROP,
      });

      renderResults([place]);
      if (place.place_id) {
        loadPlaceDetails(place.place_id, selectedMarker);
      }
    });
  }

  // Autocomplete cho ô Điểm đi / Điểm đến trong bảng Chỉ đường
  const originInput = document.getElementById("gm-dir-origin");
  const destInput = document.getElementById("gm-dir-dest");
  if (originInput) {
    originAutocomplete = new google.maps.places.Autocomplete(originInput, {
      fields: ["formatted_address", "geometry", "name", "place_id"],
    });
    originAutocomplete.bindTo("bounds", map);
    originAutocomplete.addListener("place_changed", function () {
      if (destInput && destInput.value.trim()) {
        calculateAndDisplayRoute();
      }
    });
  }
  if (destInput) {
    destAutocomplete = new google.maps.places.Autocomplete(destInput, {
      fields: ["formatted_address", "geometry", "name", "place_id"],
    });
    destAutocomplete.bindTo("bounds", map);
    destAutocomplete.addListener("place_changed", function () {
      if (originInput && originInput.value.trim()) {
        calculateAndDisplayRoute();
      }
    });
  }

  // Tự động load địa điểm xung quanh khi bản đồ sẵn sàng
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

  // Nếu đã lấy được GPS trước khi initMap chạy xong
  if (userCoords) {
    map.setCenter(userCoords);
    map.setZoom(15);
    renderUserLocationDot(userCoords);
  }
}

window.initMap = initMap;

/* =====================================================
   ĐIỀU KHIỂN LỚP BẢN ĐỒ (LAYERS)
===================================================== */
function initLayerControls() {
  const toggleBtn = document.getElementById("layers-toggle");
  const menu = document.getElementById("layers-menu");

  if (toggleBtn && menu) {
    toggleBtn.addEventListener("click", function (e) {
      e.stopPropagation();
      menu.classList.toggle("show");
    });

    document.addEventListener("click", function (e) {
      if (!menu.contains(e.target) && e.target !== toggleBtn) {
        menu.classList.remove("show");
      }
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

/* =====================================================
   TÌM KIẾM ĐỊA ĐIỂM & LỌC DANH MỤC
===================================================== */
function searchPlaces(overrideKeyword) {
  const input = document.getElementById("pac-input");
  const rawKeyword = overrideKeyword || (input ? input.value.trim() : "");
  const keyword = normalizeSearchQuery(rawKeyword);

  if (!keyword) {
    if (input) input.focus();
    return;
  }

  // Ẩn bảng chỉ đường nếu đang mở
  closeDirectionsPanel(false);

  // Bỏ active trên các nút category
  document.querySelectorAll(".cat-btn").forEach((b) => b.classList.remove("active"));

  showSearchLoading();

  const currentCenter = map
    ? map.getCenter()
    : new google.maps.LatLng(10.7769, 106.7009);
  const placesService = service || new google.maps.places.PlacesService(map);

  placesService.textSearch(
    {
      query: keyword,
      location: currentCenter,
      radius: 5000,
    },
    function (results, status) {
      hideSearchLoading();

      if (status !== google.maps.places.PlacesServiceStatus.OK || !results) {
        console.warn("Places search status:", status);
        renderResults([]);
        return;
      }

      const nearbyResults = results.filter(
        (p) => p.geometry && p.geometry.location
      );
      renderResults(nearbyResults);
    }
  );
}

function searchByType(type, btnElement) {
  if (!map) return;

  document.querySelectorAll(".cat-btn").forEach((b) => b.classList.remove("active"));
  if (btnElement) btnElement.classList.add("active");

  closeDirectionsPanel(false);
  showSearchLoading();

  const placesService = service || new google.maps.places.PlacesService(map);
  placesService.nearbySearch(
    {
      location: map.getCenter(),
      radius: 5000,
      type: type,
    },
    function (results, status) {
      hideSearchLoading();
      if (status === google.maps.places.PlacesServiceStatus.OK && results) {
        renderResults(results);
      } else {
        renderResults([]);
      }
    }
  );
}

/* =====================================================
   HIỂN THỊ DANH SÁCH KẾT QUẢ BÊN TRÁI
===================================================== */
function renderResults(results) {
  clearMarkers();

  const popup = document.getElementById("gm-place-popup");
  const panel = document.getElementById("results");

  if (popup) {
    popup.classList.remove("show");
    popup.style.display = "";
  }

  ensureResultPanelOpen();
  syncToggleButtonPosition();

  if (!panel) return;
  panel.style.display = "block";
  panel.innerHTML = "";

  if (!results.length) {
    panel.innerHTML = '<div class="gm-empty">No results found</div>';
    return;
  }

  const bounds = new google.maps.LatLngBounds();

  results.forEach((place, idx) => {
    if (!place.geometry || !place.geometry.location) return;
    const position = place.geometry.location;

    const mapMarker = new google.maps.Marker({
      position: position,
      map: map,
      title: place.name,
    });

    mapMarker.addListener("click", function () {
      highlightPlaceCard(place.place_id);
      loadPlaceDetails(place.place_id, mapMarker);
    });

    markers.push(mapMarker);
    bounds.extend(position);

    const photo = place.photos?.[0]?.getUrl({ maxWidth: 300 }) || "";
    const address = place.formatted_address || place.vicinity || "";
    const openNow = place.opening_hours?.isOpen
      ? place.opening_hours.isOpen()
      : place.opening_hours?.open_now;
    const openBadge =
      typeof openNow === "boolean"
        ? `<div class="place-open-status ${openNow ? "is-open" : "is-closed"}">${openNow ? "Open" : "Closed"}</div>`
        : "";

    panel.innerHTML += `
      <div class="place-card"
          data-index="${idx}"
          data-placeid="${escapeHtml(place.place_id || "")}"
          data-name="${escapeHtml(place.name || "")}"
          data-rating="${place.rating || "-"}"
          data-address="${escapeHtml(address)}"
          data-photo="${escapeHtml(photo)}"
          data-lat="${position.lat()}"
          data-lng="${position.lng()}">
          <div class="place-info">
              <h3>${escapeHtml(place.name)}</h3>
              <div class="rating">
                  <span class="rating-score">${place.rating || "-"}</span>
                  <i class="fa-solid fa-star" style="color: #fbbc04;"></i>
                  ${place.user_ratings_total ? `<span class="rating-count">(${place.user_ratings_total})</span>` : ""}
              </div>
              <div class="address">${escapeHtml(address)}</div>
              ${openBadge}
          </div>
          ${photo ? `<img src="${escapeHtml(photo)}" class="place-thumb" alt="${escapeHtml(place.name)}">` : ""}
      </div>
    `;
  });

  if (map && results.length > 0) {
    if (results.length === 1) {
      map.setCenter(results[0].geometry.location);
      map.setZoom(16);
    } else {
      map.fitBounds(bounds);
    }
  }

  bindPlaces();
}

function highlightPlaceCard(placeId) {
  document.querySelectorAll(".place-card").forEach((card) => {
    card.classList.toggle("active", card.dataset.placeid === placeId);
  });
}

function bindPlaces() {
  document.querySelectorAll(".place-card").forEach(function (item) {
    item.addEventListener("click", function () {
      const placeId = this.dataset.placeid;
      const lat = parseFloat(this.dataset.lat);
      const lng = parseFloat(this.dataset.lng);
      const position = { lat: lat || 0, lng: lng || 0 };

      highlightPlaceCard(placeId);

      if (map) {
        map.panTo(position);
        map.setZoom(17);
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

/* =====================================================
   CHI TIẾT ĐỊA ĐIỂM (POPUP CHUẨN GOOGLE MAPS)
===================================================== */
function loadPlaceDetails(placeId, clickedMarker) {
  if (!map || !placeId) return;
  const placesService = service || new google.maps.places.PlacesService(map);

  placesService.getDetails(
    {
      placeId: placeId,
      fields: [
        "place_id",
        "name",
        "formatted_address",
        "formatted_phone_number",
        "international_phone_number",
        "website",
        "rating",
        "user_ratings_total",
        "opening_hours",
        "photos",
        "url",
        "types",
        "reviews",
        "geometry",
        "price_level",
      ],
    },
    function (place, status) {
      if (status !== google.maps.places.PlacesServiceStatus.OK || !place) {
        console.error("Place details error:", status);
        return;
      }

      currentPlaceDetails = place;

      const resultWrapper = document.querySelector(".result-wrapper");
      if (!resultWrapper) return;

      let popup = document.getElementById("gm-place-popup");
      if (!popup) {
        popup = document.createElement("div");
        popup.id = "gm-place-popup";
        popup.className = "gm-place-popup";
        resultWrapper.appendChild(popup);
      }

      const photoUrls = (place.photos || []).map((p) =>
        p.getUrl({ maxWidth: 1200, maxHeight: 900 })
      );
      const coverPhoto = photoUrls[0] || "";

      let placeType = "Place";
      if (place.types?.length) {
        const type = place.types.find(
          (t) => !["point_of_interest", "establishment"].includes(t)
        );
        if (type) {
          placeType = type
            .replace(/_/g, " ")
            .replace(/\b\w/g, (c) => c.toUpperCase());
        }
      }

      // Trạng thái đang mở cửa / đóng cửa
      let openStatusHTML = "";
      if (place.opening_hours) {
        let isOpenNow = undefined;
        try {
          if (typeof place.opening_hours.isOpen === "function") {
            isOpenNow = place.opening_hours.isOpen();
          } else if (typeof place.opening_hours.open_now === "boolean") {
            isOpenNow = place.opening_hours.open_now;
          }
        } catch (e) {
          isOpenNow = place.opening_hours.open_now;
        }
        if (typeof isOpenNow === "boolean") {
          openStatusHTML = isOpenNow
            ? `<span class="gm-open-badge open">Open now</span>`
            : `<span class="gm-open-badge closed">Closed</span>`;
        }
      }

      // Danh sách giờ mở cửa
      let hoursHTML = "";
      if (place.opening_hours && place.opening_hours.weekday_text) {
        hoursHTML = `
          <div class="gm-popup-row gm-hours-row">
              <i class="fa-regular fa-clock"></i>
              <div class="gm-hours-wrap">
                  <div class="gm-hours-header" id="gm-hours-toggle">
                      ${openStatusHTML || "<span>Opening hours</span>"}
                      <i class="fa-solid fa-chevron-down gm-hours-chevron"></i>
                  </div>
                  <div class="gm-hours-list" id="gm-hours-list">
                      ${place.opening_hours.weekday_text
                        .map((day) => `<div>${escapeHtml(day)}</div>`)
                        .join("")}
                  </div>
              </div>
          </div>
        `;
      }

      // Danh sách đánh giá (Reviews)
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
                  ${place.reviews
                    .map(function (rev) {
                      const authorName = escapeHtml(
                        rev.author_name || "Google User"
                      );
                      const authorPhoto = rev.profile_photo_url || "";
                      const rating = rev.rating || 0;
                      const reviewText = escapeHtml(rev.text || "");
                      const relativeTime = escapeHtml(
                        rev.relative_time_description || ""
                      );

                      return `
                        <div class="gm-review">
                            <div class="gm-review-author">
                                ${
                                  authorPhoto
                                    ? `<img class="gm-review-avatar" src="${escapeHtml(authorPhoto)}" alt="${authorName}">`
                                    : `<div class="gm-review-avatar gm-review-avatar-default">${authorName.charAt(0)}</div>`
                                }
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
                    })
                    .join("")}
              </div>
              <div class="gm-google-attribution">Reviews from Google</div>
          </div>
        `;
      }

      const lat = place.geometry?.location ? place.geometry.location.lat() : "";
      const lng = place.geometry?.location ? place.geometry.location.lng() : "";
      const shareUrl =
        place.url ||
        `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(
          place.name || ""
        )}&query_place_id=${encodeURIComponent(placeId)}`;

      popup.innerHTML = `
        <button type="button" class="gm-popup-close" id="gm-popup-close" aria-label="Close">
            <i class="fa-solid fa-xmark"></i>
        </button>
        ${
          coverPhoto
            ? `<div class="gm-popup-cover" id="gm-popup-cover-btn" title="Click to view photos">
                <img src="${escapeHtml(coverPhoto)}" alt="${escapeHtml(place.name)}">
                ${photoUrls.length > 1 ? `<span class="gm-cover-photo-count"><i class="fa-solid fa-camera"></i> ${photoUrls.length} photos</span>` : ""}
               </div>`
            : ""
        }
        <div class="gm-popup-body">
            <h2 class="gm-popup-title">${escapeHtml(place.name)}</h2>
            <div class="gm-popup-rating">
                <span>${place.rating || "-"}</span>
                <i class="fa-solid fa-star"></i>
                <span>(${place.user_ratings_total || 0})</span>
                ${openStatusHTML ? `<span class="gm-rating-dot">•</span>${openStatusHTML}` : ""}
            </div>
            <div class="gm-popup-type">${escapeHtml(placeType)}</div>

            <div class="gm-popup-tabs">
                <button type="button" class="gm-popup-tab active" data-popup-tab="overview">Overview</button>
                <button type="button" class="gm-popup-tab gm-picture-btn" data-tab="picture" data-placeid="${escapeHtml(placeId)}">Picture</button>
                <button type="button" class="gm-popup-tab gm-video-btn" data-tab="video" data-placeid="${escapeHtml(placeId)}">Video</button>
            </div>

            <!-- Thanh nút thao tác nhanh kiểu Google Maps -->
            <div class="gm-popup-actions">
                <div class="gm-popup-action" id="gm-action-directions"
                     data-name="${escapeHtml(place.name || "")}"
                     data-address="${escapeHtml(place.formatted_address || "")}"
                     data-lat="${lat}"
                     data-lng="${lng}">
                    <div class="gm-popup-action-icon primary">
                        <i class="fa-solid fa-diamond-turn-right"></i>
                    </div>
                    <span>Directions</span>
                </div>

                <div class="gm-popup-action" id="gm-action-call"
                     data-phone="${escapeHtml(place.formatted_phone_number || place.international_phone_number || "")}">
                    <div class="gm-popup-action-icon">
                        <i class="fa-solid fa-phone"></i>
                    </div>
                    <span>Call</span>
                </div>

                <div class="gm-popup-action" id="gm-action-website"
                     data-url="${escapeHtml(place.website || shareUrl)}">
                    <div class="gm-popup-action-icon">
                        <i class="fa-solid fa-earth-americas"></i>
                    </div>
                    <span>Website</span>
                </div>

                <div class="gm-popup-action" id="gm-action-share"
                     data-url="${escapeHtml(shareUrl)}"
                     data-name="${escapeHtml(place.name || "")}">
                    <div class="gm-popup-action-icon">
                        <i class="fa-solid fa-share-nodes"></i>
                    </div>
                    <span>Share</span>
                </div>
            </div>

            <div class="gm-popup-info">
                <div class="gm-popup-row">
                    <i class="fa-solid fa-location-dot"></i>
                    <div class="gm-popup-row-flex">
                        <span>${escapeHtml(place.formatted_address || "")}</span>
                        <button type="button" class="gm-copy-inline-btn" data-copy="${escapeHtml(place.formatted_address || "")}" title="Copy address">
                            <i class="fa-regular fa-copy"></i>
                        </button>
                    </div>
                </div>
                ${
                  place.formatted_phone_number
                    ? `<div class="gm-popup-row">
                        <i class="fa-solid fa-phone"></i>
                        <div><a href="tel:${escapeHtml(place.formatted_phone_number)}">${escapeHtml(place.formatted_phone_number)}</a></div>
                       </div>`
                    : ""
                }
                ${
                  place.website
                    ? `<div class="gm-popup-row">
                        <i class="fa-solid fa-globe"></i>
                        <div><a href="${escapeHtml(place.website)}" target="_blank" rel="noopener">${escapeHtml(place.website)}</a></div>
                       </div>`
                    : ""
                }
                ${hoursHTML}
                ${reviewsHTML}
                ${
                  place.url
                    ? `<div class="gm-popup-row">
                        <i class="fa-solid fa-map"></i>
                        <div><a href="${escapeHtml(place.url)}" target="_blank" rel="noopener">View on Google Maps</a></div>
                       </div>`
                    : ""
                }
            </div>
        </div>
      `;

      // Đảm bảo panel kết quả bên trái hiển thị để popup neo chuẩn ở left: 405px
      ensureResultPanelOpen();

      popup.style.display = "";
      popup.classList.add("show");
      syncToggleButtonPosition();

      // Sự kiện đóng popup
      const closeBtn = document.getElementById("gm-popup-close");
      if (closeBtn) {
        closeBtn.addEventListener("click", function () {
          popup.classList.remove("show");
          popup.style.display = "";
          syncToggleButtonPosition();
        });
      }

      // Click ảnh bìa mở Lightbox
      const coverBtn = document.getElementById("gm-popup-cover-btn");
      if (coverBtn && photoUrls.length) {
        coverBtn.addEventListener("click", function () {
          openLightbox(
            photoUrls.map((u) => ({ url: u, label: place.name || "Google Photo" })),
            0
          );
        });
      }

      // Thu gọn / mở rộng giờ mở cửa
      const hoursToggle = document.getElementById("gm-hours-toggle");
      const hoursList = document.getElementById("gm-hours-list");
      if (hoursToggle && hoursList) {
        hoursToggle.addEventListener("click", function () {
          hoursList.classList.toggle("expanded");
          hoursToggle.classList.toggle("expanded");
        });
      }

      // Nút copy địa chỉ
      popup.querySelectorAll(".gm-copy-inline-btn").forEach((btn) => {
        btn.addEventListener("click", function () {
          const text = this.dataset.copy || "";
          if (text && navigator.clipboard) {
            navigator.clipboard.writeText(text);
            showToast("Address copied to clipboard!");
          }
        });
      });

      // Các nút thao tác nhanh (Directions, Call, Website, Share)
      const dirAction = document.getElementById("gm-action-directions");
      if (dirAction) {
        dirAction.addEventListener("click", function () {
          const destText =
            this.dataset.name && this.dataset.address
              ? `${this.dataset.name}, ${this.dataset.address}`
              : this.dataset.address || this.dataset.name;
          openDirectionsToPlace(destText);
        });
      }

      const callAction = document.getElementById("gm-action-call");
      if (callAction) {
        callAction.addEventListener("click", function () {
          const phone = this.dataset.phone;
          if (phone) {
            window.location.href = `tel:${phone}`;
          } else {
            showToast("Phone number not available for this place.");
          }
        });
      }

      const webAction = document.getElementById("gm-action-website");
      if (webAction) {
        webAction.addEventListener("click", function () {
          const url = this.dataset.url;
          if (url) {
            window.open(url, "_blank", "noopener");
          }
        });
      }

      const shareAction = document.getElementById("gm-action-share");
      if (shareAction) {
        shareAction.addEventListener("click", function () {
          const url = this.dataset.url || window.location.href;
          const title = this.dataset.name || "Google Maps Place";
          if (navigator.share) {
            navigator
              .share({ title: title, url: url })
              .catch(() => {});
          } else if (navigator.clipboard) {
            navigator.clipboard.writeText(url);
            showToast("Link copied to clipboard!");
          }
        });
      }

      if (clickedMarker && map) {
        map.panTo(clickedMarker.getPosition());
      }
    }
  );
}

/* =====================================================
   CHỈ ĐƯỜNG (DIRECTIONS SERVICE & RENDERER)
===================================================== */
function openDirectionsToPlace(destinationText) {
  ensureResultPanelOpen();

  const dirPanel = document.getElementById("gm-directions-panel");
  const originInput = document.getElementById("gm-dir-origin");
  const destInput = document.getElementById("gm-dir-dest");

  if (!dirPanel) return;
  dirPanel.style.display = "block";

  if (destInput && destinationText) {
    destInput.value = destinationText;
  }

  if (originInput && !originInput.value.trim()) {
    if (userCoords) {
      originInput.value = `${userCoords.lat.toFixed(6)}, ${userCoords.lng.toFixed(6)}`;
      originInput.dataset.lat = userCoords.lat;
      originInput.dataset.lng = userCoords.lng;
      calculateAndDisplayRoute();
    } else if (navigator.geolocation) {
      originInput.placeholder = "Locating your position...";
      navigator.geolocation.getCurrentPosition(
        function (pos) {
          userCoords = {
            lat: pos.coords.latitude,
            lng: pos.coords.longitude,
          };
          renderUserLocationDot(userCoords);
          originInput.value = `${userCoords.lat.toFixed(6)}, ${userCoords.lng.toFixed(6)}`;
          originInput.dataset.lat = userCoords.lat;
          originInput.dataset.lng = userCoords.lng;
          calculateAndDisplayRoute();
        },
        function () {
          if (map) {
            const c = map.getCenter();
            originInput.value = `${c.lat().toFixed(6)}, ${c.lng().toFixed(6)}`;
            calculateAndDisplayRoute();
          }
        },
        { timeout: 8000 }
      );
    } else if (map) {
      const c = map.getCenter();
      originInput.value = `${c.lat().toFixed(6)}, ${c.lng().toFixed(6)}`;
      calculateAndDisplayRoute();
    }
  } else if (originInput && destInput && destInput.value.trim()) {
    calculateAndDisplayRoute();
  }
}

function closeDirectionsPanel(clearRoute = true) {
  const dirPanel = document.getElementById("gm-directions-panel");
  if (dirPanel) {
    dirPanel.style.display = "none";
  }
  if (clearRoute && directionsRenderer) {
    directionsRenderer.setDirections({ routes: [] });
  }
}

function calculateAndDisplayRoute() {
  if (!directionsService || !directionsRenderer) return;

  const originInput = document.getElementById("gm-dir-origin");
  const destInput = document.getElementById("gm-dir-dest");
  const summaryEl = document.getElementById("gm-dir-summary");
  const stepsEl = document.getElementById("gm-dir-steps");

  const originVal = originInput ? originInput.value.trim() : "";
  const destVal = destInput ? destInput.value.trim() : "";

  if (!originVal || !destVal) {
    showToast("Please enter both starting point and destination.");
    return;
  }

  if (summaryEl) {
    summaryEl.style.display = "block";
    summaryEl.innerHTML = `<div class="gm-dir-loading"><i class="fa-solid fa-spinner fa-spin"></i> Calculating best route...</div>`;
  }
  if (stepsEl) {
    stepsEl.innerHTML = "";
  }

  const travelMode =
    google.maps.TravelMode[currentTravelMode] ||
    google.maps.TravelMode.DRIVING;

  directionsService.route(
    {
      origin: originVal,
      destination: destVal,
      travelMode: travelMode,
      provideRouteAlternatives: true,
    },
    function (response, status) {
      if (status === "OK" && response && response.routes.length) {
        directionsRenderer.setMap(map);
        directionsRenderer.setDirections(response);

        const route = response.routes[0];
        const leg = route.legs[0];

        if (summaryEl && leg) {
          summaryEl.style.display = "block";
          summaryEl.innerHTML = `
            <div class="gm-route-overview">
              <div class="gm-route-primary">
                <span class="gm-route-duration">${escapeHtml(leg.duration?.text || "")}</span>
                <span class="gm-route-distance">(${escapeHtml(leg.distance?.text || "")})</span>
              </div>
              ${route.summary ? `<div class="gm-route-via">via ${escapeHtml(route.summary)}</div>` : ""}
              <div class="gm-route-addresses">
                <div><strong>From:</strong> ${escapeHtml(leg.start_address || originVal)}</div>
                <div><strong>To:</strong> ${escapeHtml(leg.end_address || destVal)}</div>
              </div>
            </div>
          `;
        }

        if (stepsEl && leg && leg.steps) {
          stepsEl.innerHTML = leg.steps
            .map(function (step, idx) {
              return `
                <div class="gm-dir-step" data-lat="${step.start_location.lat()}" data-lng="${step.start_location.lng()}">
                  <div class="gm-step-num">${idx + 1}</div>
                  <div class="gm-step-body">
                    <div class="gm-step-instruction">${step.instructions || ""}</div>
                    <div class="gm-step-meta">
                      <span>${escapeHtml(step.distance?.text || "")}</span>
                      ${step.duration?.text ? `<span>• ${escapeHtml(step.duration.text)}</span>` : ""}
                    </div>
                  </div>
                </div>
              `;
            })
            .join("");

          stepsEl.querySelectorAll(".gm-dir-step").forEach(function (stepItem) {
            stepItem.addEventListener("click", function () {
              const lat = parseFloat(this.dataset.lat);
              const lng = parseFloat(this.dataset.lng);
              if (!isNaN(lat) && !isNaN(lng) && map) {
                map.panTo({ lat, lng });
                map.setZoom(17);
              }
            });
          });
        }
      } else if (currentTravelMode === "TWO_WHEELER") {
        // Fallback sang DRIVING nếu khu vực không hỗ trợ TWO_WHEELER
        currentTravelMode = "DRIVING";
        calculateAndDisplayRoute();
      } else {
        if (summaryEl) {
          summaryEl.style.display = "block";
          summaryEl.innerHTML = `<div class="gm-dir-error"><i class="fa-solid fa-circle-exclamation"></i> Could not find route (${escapeHtml(status)}).</div>`;
        }
      }
    }
  );
}

/* =====================================================
   XEM & UPLOAD HÌNH ẢNH / VIDEO + LIGHTBOX
===================================================== */
function openLightbox(images, startIndex = 0) {
  if (!images || !images.length) return;
  lightboxImages = images;
  lightboxIndex = startIndex;

  const dialog = document.getElementById("gmLightboxDialog");
  if (!dialog) return;

  updateLightboxView();
  dialog.classList.add("show");
}

function updateLightboxView() {
  const imgEl = document.getElementById("gmLightboxImg");
  const captionEl = document.getElementById("gmLightboxCaption");
  const item = lightboxImages[lightboxIndex];
  if (!item || !imgEl) return;

  imgEl.src = item.url;
  if (captionEl) {
    captionEl.textContent = `${item.label || "Photo"} (${lightboxIndex + 1} / ${lightboxImages.length})`;
  }
}

function openPlacePictureDialog(placeId) {
  const dialog = document.getElementById("gmPictureDialog");
  const content = document.getElementById("gmPictureContent");
  if (!dialog || !content) return;

  dialog.dataset.placeid = placeId;
  dialog.classList.add("show");
  content.innerHTML = `<div class="gm-empty"><i class="fa-solid fa-spinner fa-spin"></i>&nbsp; Loading pictures...</div>`;

  loadPlacePhotos(placeId);
}

function loadPlacePhotos(placeId) {
  if (!map || !placeId) return;
  const placesService = service || new google.maps.places.PlacesService(map);

  placesService.getDetails(
    {
      placeId: placeId,
      fields: ["name", "photos"],
    },
    function (place, status) {
      fetch(
        `${ajaxUrl}?action=get_place_uploads&place_id=${encodeURIComponent(placeId)}`
      )
        .then((r) => r.json())
        .then((res) => {
          renderPhotos(
            status === google.maps.places.PlacesServiceStatus.OK ? place : null,
            res && res.success && Array.isArray(res.data) ? res.data : []
          );
        })
        .catch(() => {
          renderPhotos(
            status === google.maps.places.PlacesServiceStatus.OK ? place : null,
            []
          );
        });
    }
  );
}

function renderPhotos(place, uploads) {
  const container = document.getElementById("gmPictureContent");
  if (!container) return;

  const allPhotos = [];

  if (place && place.photos) {
    place.photos.forEach((photo) => {
      allPhotos.push({
        url: photo.getUrl({ maxWidth: 1200 }),
        badge: "Google",
        isUser: false,
      });
    });
  }

  if (Array.isArray(uploads)) {
    uploads.forEach((item) => {
      if (item && item.url) {
        allPhotos.push({
          url: item.url,
          badge: "User",
          isUser: true,
        });
      }
    });
  }

  if (!allPhotos.length) {
    container.innerHTML = `<div class="gm-empty">No pictures available for this place yet.</div>`;
    return;
  }

  container.innerHTML = allPhotos
    .map(
      (item, idx) => `
        <div class="gm-photo-item" data-index="${idx}">
            <img src="${escapeHtml(item.url)}" alt="Place photo ${idx + 1}" loading="lazy">
            <span class="gm-badge ${item.isUser ? "gm-user" : ""}">
                ${escapeHtml(item.badge)}
            </span>
        </div>
      `
    )
    .join("");

  container.querySelectorAll(".gm-photo-item").forEach((el) => {
    el.addEventListener("click", function () {
      const idx = parseInt(this.dataset.index, 10) || 0;
      openLightbox(
        allPhotos.map((p) => ({ url: p.url, label: `${p.badge} Photo` })),
        idx
      );
    });
  });
}

function openPlaceVideoDialog(placeId) {
  const dialog = document.getElementById("gmVideoDialog");
  const content = document.getElementById("gmVideoContent");
  if (!dialog || !content) return;

  dialog.dataset.placeid = placeId;
  dialog.classList.add("show");
  content.innerHTML = `<div class="gm-empty"><i class="fa-solid fa-spinner fa-spin"></i>&nbsp; Loading videos...</div>`;

  loadPlaceVideos(placeId);
}

function loadPlaceVideos(placeId) {
  fetch(
    `${ajaxUrl}?action=get_place_uploads_videos&place_id=${encodeURIComponent(placeId)}`
  )
    .then((r) => r.json())
    .then((res) => {
      if (!res || !res.success || !Array.isArray(res.data)) {
        renderVideos([]);
        return;
      }
      renderVideos(res.data);
    })
    .catch((error) => {
      console.error("Load videos error:", error);
      renderVideos([]);
    });
}

function renderVideos(videos) {
  const container = document.getElementById("gmVideoContent");
  if (!container) return;

  if (!videos.length) {
    container.innerHTML = `<div class="gm-empty">No videos uploaded for this place yet.</div>`;
    return;
  }

  container.innerHTML = videos
    .map(
      (video) => `
        <div class="place-video">
          <video
            src="${escapeHtml(video.url)}"
            controls
            playsinline
            preload="metadata"
            style="width:100%; border-radius:12px;"
          ></video>
        </div>
      `
    )
    .join("");
}

function showLoginRequiredDialog() {
  const dialog = document.getElementById("gmLoginRequiredDialog");
  if (dialog) {
    dialog.style.display = "flex";
  }
}

function hideLoginRequiredDialog() {
  const dialog = document.getElementById("gmLoginRequiredDialog");
  if (dialog) {
    dialog.style.display = "none";
  }
}

/* =====================================================
   KHỞI TẠO SỰ KIỆN DOM
===================================================== */
document.addEventListener("DOMContentLoaded", function () {
  const wrapper = document.querySelector(".search-wrapper");
  const resultPanelEl = document.getElementById("result-panel");
  const toggleResultsBtn = document.getElementById("toggle-results");
  const pacInput = document.getElementById("pac-input");
  const clearSearchBtn = document.getElementById("clear-search-btn");

  if (resultPanelEl) resultPanelEl.classList.remove("show");
  if (toggleResultsBtn) toggleResultsBtn.classList.remove("show");

  // 1. Nút Collapse / Expand kết quả (#toggle-results)
  if (toggleResultsBtn && wrapper) {
    toggleResultsBtn.addEventListener("click", function () {
      wrapper.classList.toggle("collapsed");

      const icon = this.querySelector("i");
      const popup = document.getElementById("gm-place-popup");
      const resultWrapper = document.querySelector(".result-wrapper");

      this.style.position = "fixed";
      this.style.transform = "none";

      if (wrapper.classList.contains("collapsed")) {
        if (icon) icon.className = "fa-solid fa-chevron-right";
        this.style.setProperty("left", "0px", "important");
        if (popup && popup.classList.contains("show")) {
          popup.style.display = "none";
        }
        if (resultWrapper) {
          resultWrapper.style.width = "0px";
        }
      } else {
        if (icon) icon.className = "fa-solid fa-chevron-left";
        if (resultWrapper) {
          resultWrapper.style.width = "400px";
          resultWrapper.style.maxWidth = "400px";
        }
        if (popup && popup.classList.contains("show")) {
          popup.style.display = "";
          this.style.setProperty("left", "765px", "important");
        } else {
          this.style.setProperty("left", "400px", "important");
        }
      }
    });
  }

  // 2. Lấy GPS ban đầu của người dùng
  if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
      function (pos) {
        userCoords = {
          lat: pos.coords.latitude,
          lng: pos.coords.longitude,
        };
        if (map) {
          map.setCenter(userCoords);
          map.setZoom(15);
          renderUserLocationDot(userCoords);
        }
      },
      function (err) {
        console.warn("GPS unavailable:", err.message);
      },
      { enableHighAccuracy: true, timeout: 10000 }
    );
  }

  // 3. Ô tìm kiếm chính, nút xóa nhanh & nút tìm kiếm
  if (pacInput) {
    pacInput.addEventListener("input", function () {
      if (clearSearchBtn) {
        clearSearchBtn.style.display = this.value.trim() ? "flex" : "none";
      }
    });

    pacInput.addEventListener("keypress", function (e) {
      if (e.key === "Enter") {
        e.preventDefault();
        searchPlaces();
      }
    });
  }

  if (clearSearchBtn && pacInput) {
    clearSearchBtn.addEventListener("click", function () {
      pacInput.value = "";
      this.style.display = "none";
      pacInput.focus();
    });
  }

  const findBtn = document.getElementById("find-btn");
  if (findBtn) {
    findBtn.addEventListener("click", function () {
      searchPlaces();
    });
  }

  // 4. Các nút lọc danh mục nhanh (.cat-btn)
  document.querySelectorAll(".cat-btn[data-type]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      const type = this.dataset.type;
      searchByType(type, this);
    });
  });

  // 5. Sự kiện bảng Chỉ đường (Directions)
  const dirOpenBtn = document.getElementById("directions-open-btn");
  if (dirOpenBtn) {
    dirOpenBtn.addEventListener("click", function () {
      const destVal = pacInput ? pacInput.value.trim() : "";
      openDirectionsToPlace(destVal);
    });
  }

  const dirCloseBtn = document.getElementById("gm-dir-close");
  if (dirCloseBtn) {
    dirCloseBtn.addEventListener("click", function () {
      closeDirectionsPanel(true);
    });
  }

  const dirUseGpsBtn = document.getElementById("gm-dir-use-gps");
  if (dirUseGpsBtn) {
    dirUseGpsBtn.addEventListener("click", function () {
      const originInput = document.getElementById("gm-dir-origin");
      if (!originInput) return;

      if (userCoords) {
        originInput.value = `${userCoords.lat.toFixed(6)}, ${userCoords.lng.toFixed(6)}`;
        calculateAndDisplayRoute();
      } else if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
          function (pos) {
            userCoords = {
              lat: pos.coords.latitude,
              lng: pos.coords.longitude,
            };
            renderUserLocationDot(userCoords);
            originInput.value = `${userCoords.lat.toFixed(6)}, ${userCoords.lng.toFixed(6)}`;
            calculateAndDisplayRoute();
          },
          function () {
            showToast("Unable to retrieve your GPS location.");
          }
        );
      }
    });
  }

  const dirSwapBtn = document.getElementById("gm-dir-swap");
  if (dirSwapBtn) {
    dirSwapBtn.addEventListener("click", function () {
      const originInput = document.getElementById("gm-dir-origin");
      const destInput = document.getElementById("gm-dir-dest");
      if (!originInput || !destInput) return;
      const tmp = originInput.value;
      originInput.value = destInput.value;
      destInput.value = tmp;
      if (originInput.value.trim() && destInput.value.trim()) {
        calculateAndDisplayRoute();
      }
    });
  }

  const dirSearchBtn = document.getElementById("gm-dir-search-btn");
  if (dirSearchBtn) {
    dirSearchBtn.addEventListener("click", function () {
      calculateAndDisplayRoute();
    });
  }

  const dirClearBtn = document.getElementById("gm-dir-clear-btn");
  if (dirClearBtn) {
    dirClearBtn.addEventListener("click", function () {
      const originInput = document.getElementById("gm-dir-origin");
      const destInput = document.getElementById("gm-dir-dest");
      const summaryEl = document.getElementById("gm-dir-summary");
      const stepsEl = document.getElementById("gm-dir-steps");
      if (originInput) originInput.value = "";
      if (destInput) destInput.value = "";
      if (summaryEl) {
        summaryEl.style.display = "none";
        summaryEl.innerHTML = "";
      }
      if (stepsEl) stepsEl.innerHTML = "";
      if (directionsRenderer) {
        directionsRenderer.setDirections({ routes: [] });
      }
    });
  }

  document.querySelectorAll(".gm-dir-mode").forEach(function (modeBtn) {
    modeBtn.addEventListener("click", function () {
      document
        .querySelectorAll(".gm-dir-mode")
        .forEach((b) => b.classList.remove("active"));
      this.classList.add("active");
      currentTravelMode = this.dataset.mode || "DRIVING";
      const originInput = document.getElementById("gm-dir-origin");
      const destInput = document.getElementById("gm-dir-dest");
      if (
        originInput &&
        originInput.value.trim() &&
        destInput &&
        destInput.value.trim()
      ) {
        calculateAndDisplayRoute();
      }
    });
  });

  // 6. Các nút điều khiển bản đồ góc phải dưới (.gm-map-controls)
  const myLocBtn = document.getElementById("gm-my-location-btn");
  if (myLocBtn) {
    myLocBtn.addEventListener("click", function () {
      if (!navigator.geolocation) {
        showToast("Geolocation is not supported by your browser.");
        return;
      }
      this.classList.add("locating");
      navigator.geolocation.getCurrentPosition(
        function (pos) {
          myLocBtn.classList.remove("locating");
          userCoords = {
            lat: pos.coords.latitude,
            lng: pos.coords.longitude,
          };
          if (map) {
            map.panTo(userCoords);
            map.setZoom(16);
            renderUserLocationDot(userCoords);
            loadNearbyPlaces(userCoords.lat, userCoords.lng);
          }
        },
        function () {
          myLocBtn.classList.remove("locating");
          showToast("Please allow location access in your browser.");
        },
        { enableHighAccuracy: true, timeout: 10000 }
      );
    });
  }

  const zoomInBtn = document.getElementById("gm-zoom-in-btn");
  if (zoomInBtn) {
    zoomInBtn.addEventListener("click", function () {
      if (map) map.setZoom((map.getZoom() || 14) + 1);
    });
  }

  const zoomOutBtn = document.getElementById("gm-zoom-out-btn");
  if (zoomOutBtn) {
    zoomOutBtn.addEventListener("click", function () {
      if (map) map.setZoom(Math.max(2, (map.getZoom() || 14) - 1));
    });
  }

  const streetViewBtn = document.getElementById("gm-streetview-btn");
  if (streetViewBtn) {
    streetViewBtn.addEventListener("click", function () {
      if (!map) return;
      const panorama = map.getStreetView();
      if (!panorama) return;

      if (panorama.getVisible()) {
        panorama.setVisible(false);
        this.classList.remove("active");
      } else {
        const targetPos =
          currentPlaceDetails?.geometry?.location || map.getCenter();
        const svService = new google.maps.StreetViewService();
        svService.getPanorama(
          { location: targetPos, radius: 100 },
          function (data, status) {
            if (status === google.maps.StreetViewStatus.OK && data?.location) {
              panorama.setPano(data.location.pano);
              panorama.setPov({ heading: 270, pitch: 0 });
              panorama.setVisible(true);
              streetViewBtn.classList.add("active");
            } else {
              showToast("Street View is not available at this location.");
            }
          }
        );
      }
    });
  }

  const fullscreenBtn = document.getElementById("gm-fullscreen-btn");
  if (fullscreenBtn) {
    fullscreenBtn.addEventListener("click", function () {
      const container =
        document.getElementById("google-map-container") ||
        document.querySelector(".google-map-section");
      if (!container) return;

      if (!document.fullscreenElement) {
        container.requestFullscreen?.().catch(() => {});
        this.querySelector("i").className = "fa-solid fa-compress";
      } else {
        document.exitFullscreen?.().catch(() => {});
        this.querySelector("i").className = "fa-solid fa-expand";
      }
    });
  }

  // 7. Tìm kiếm với Gemini AI (#ai-search-btn)
  const aiButton = document.getElementById("ai-search-btn");
  const aiPanel = document.getElementById("ai-result-panel");
  const aiContent = document.getElementById("ai-result-content");
  const closeAiButton = document.getElementById("close-ai-result");

  if (aiButton) {
    aiButton.addEventListener("click", function (e) {
      e.preventDefault();
      const query = pacInput ? pacInput.value.trim() : "";

      if (!query) {
        alert("Please enter search content.");
        if (pacInput) pacInput.focus();
        return;
      }

      const resultWrapper = document.querySelector(".result-wrapper");
      if (resultWrapper) {
        resultWrapper.style.display = "none";
      }
      if (toggleResultsBtn) {
        toggleResultsBtn.classList.remove("show");
      }
      if (aiPanel) {
        aiPanel.style.display = "block";
      }
      if (aiContent) {
        aiContent.innerHTML = `
          <div class="ai-loading">
            <i class="fa-solid fa-spinner fa-spin"></i>
            <span>Gemini is searching...</span>
          </div>
        `;
      }

      aiButton.disabled = true;

      fetch(ajaxUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        },
        body: new URLSearchParams({
          action: "pt_ai_search",
          query: query,
        }),
      })
        .then((r) => r.json())
        .then((data) => {
          if (!data || !data.success) {
            if (aiContent) {
              aiContent.innerHTML = `
                <div class="ai-error">
                  <i class="fa-solid fa-circle-exclamation"></i>
                  <span>${escapeHtml(data?.data?.message || "Gemini search failed.")}</span>
                </div>
              `;
            }
            return;
          }
          if (aiContent) {
            aiContent.innerHTML = data.data?.html || "No result.";
          }
        })
        .catch(() => {
          if (aiContent) {
            aiContent.innerHTML = `
              <div class="ai-error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <span>Unable to connect to Gemini.</span>
              </div>
            `;
          }
        })
        .finally(() => {
          aiButton.disabled = false;
        });
    });
  }

  if (closeAiButton) {
    closeAiButton.addEventListener("click", function () {
      if (aiPanel) {
        aiPanel.style.display = "none";
      }
    });
  }

  // 8. Đóng Dialog Video, Picture, Lightbox & Login
  const videoCloseBtn = document.getElementById("gmVideoClose");
  if (videoCloseBtn) {
    videoCloseBtn.addEventListener("click", function () {
      const dialog = document.getElementById("gmVideoDialog");
      if (dialog) dialog.classList.remove("show");
      const videoContent = document.getElementById("gmVideoContent");
      if (videoContent) videoContent.innerHTML = "";
    });
  }

  const pictureCloseBtn = document.getElementById("gmPictureClose");
  if (pictureCloseBtn) {
    pictureCloseBtn.addEventListener("click", function () {
      const dialog = document.getElementById("gmPictureDialog");
      if (dialog) dialog.classList.remove("show");
    });
  }

  const lightboxDialog = document.getElementById("gmLightboxDialog");
  const lightboxClose = document.getElementById("gmLightboxClose");
  const lightboxPrev = document.getElementById("gmLightboxPrev");
  const lightboxNext = document.getElementById("gmLightboxNext");

  if (lightboxClose && lightboxDialog) {
    lightboxClose.addEventListener("click", function () {
      lightboxDialog.classList.remove("show");
    });
  }
  if (lightboxPrev) {
    lightboxPrev.addEventListener("click", function () {
      if (!lightboxImages.length) return;
      lightboxIndex =
        (lightboxIndex - 1 + lightboxImages.length) % lightboxImages.length;
      updateLightboxView();
    });
  }
  if (lightboxNext) {
    lightboxNext.addEventListener("click", function () {
      if (!lightboxImages.length) return;
      lightboxIndex = (lightboxIndex + 1) % lightboxImages.length;
      updateLightboxView();
    });
  }

  // Upload Photo file change
  const uploadPhotoInput = document.getElementById("gmUploadPhotoFile");
  if (uploadPhotoInput) {
    uploadPhotoInput.addEventListener("change", function () {
      const file = this.files[0];
      if (!file) return;

      const picDialog = document.getElementById("gmPictureDialog");
      const placeId = picDialog ? picDialog.dataset.placeid : "";
      const uploadBtn = document.getElementById("gmUploadPhotoBtn");

      if (!placeId) {
        alert("Place ID not found.");
        return;
      }

      if (uploadBtn) {
        uploadBtn.disabled = true;
        uploadBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Uploading...';
      }

      const formData = new FormData();
      formData.append("action", "upload_place_photo");
      formData.append("place_id", placeId);
      formData.append("photo", file);

      fetch(ajaxUrl, {
        method: "POST",
        body: formData,
        credentials: "same-origin",
      })
        .then((r) => r.json())
        .then((res) => {
          if (res && res.success) {
            this.value = "";
            showToast("Photo uploaded successfully!");
            loadPlacePhotos(placeId);
          } else {
            alert(res?.data || "Photo upload failed.");
          }
        })
        .catch(() => {
          alert("Photo upload failed.");
        })
        .finally(() => {
          if (uploadBtn) {
            uploadBtn.disabled = false;
            uploadBtn.innerHTML = '<i class="fa-solid fa-upload"></i> Upload';
          }
        });
    });
  }

  // Upload Video file change
  const uploadVideoInput = document.getElementById("gmUploadVideoFile");
  if (uploadVideoInput) {
    uploadVideoInput.addEventListener("change", function () {
      const file = this.files[0];
      if (!file) return;

      const vidDialog = document.getElementById("gmVideoDialog");
      const placeId = vidDialog ? vidDialog.dataset.placeid : "";
      const uploadBtn = document.getElementById("gmUploadVideoBtn");

      if (!placeId) {
        alert("Place ID not found.");
        return;
      }

      if (!file.type.startsWith("video/")) {
        alert("Please select a valid video file.");
        this.value = "";
        return;
      }

      if (uploadBtn) {
        uploadBtn.disabled = true;
        uploadBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Uploading...';
      }

      const formData = new FormData();
      formData.append("action", "upload_place_video");
      formData.append("place_id", placeId);
      formData.append("video", file);

      fetch(ajaxUrl, {
        method: "POST",
        body: formData,
        credentials: "same-origin",
      })
        .then((r) => r.json())
        .then((res) => {
          if (res && res.success) {
            this.value = "";
            showToast("Video uploaded successfully!");
            loadPlaceVideos(placeId);
          } else {
            alert(typeof res?.data === "string" ? res.data : "Video upload failed.");
          }
        })
        .catch((err) => {
          alert("Upload failed: " + err.message);
        })
        .finally(() => {
          if (uploadBtn) {
            uploadBtn.disabled = false;
            uploadBtn.innerHTML = '<i class="fa-solid fa-upload"></i> Upload';
          }
        });
    });
  }
});

// Event delegation cho các nút trong Popup & Dialog
document.addEventListener("click", function (e) {
  // Mở dialog Picture
  const pictureBtn = e.target.closest(".gm-picture-btn");
  if (pictureBtn) {
    const placeId = pictureBtn.dataset.placeid;
    if (placeId) openPlacePictureDialog(placeId);
    return;
  }

  // Mở dialog Video
  const videoBtn = e.target.closest(".gm-video-btn");
  if (videoBtn) {
    const placeId = videoBtn.dataset.placeid;
    if (placeId) openPlaceVideoDialog(placeId);
    return;
  }

  // Click nút Upload Photo
  if (e.target.closest("#gmUploadPhotoBtn")) {
    if (typeof gmUserLoggedIn !== "undefined" && !gmUserLoggedIn) {
      showLoginRequiredDialog();
      return;
    }
    document.getElementById("gmUploadPhotoFile")?.click();
    return;
  }

  // Click nút Upload Video
  if (e.target.closest("#gmUploadVideoBtn")) {
    if (typeof gmUserLoggedIn !== "undefined" && !gmUserLoggedIn) {
      showLoginRequiredDialog();
      return;
    }
    document.getElementById("gmUploadVideoFile")?.click();
    return;
  }

  // Đóng dialog Login Required
  if (
    e.target.closest("#gmLoginDialogClose") ||
    e.target.closest("#gmLoginDialogCancel")
  ) {
    hideLoginRequiredDialog();
  }
});