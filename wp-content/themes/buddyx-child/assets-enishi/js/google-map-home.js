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

function getBrowserCountry() {
  const locale = navigator.language || navigator.userLanguage || "en-US";

  const parts = locale.split("-");

  return parts.length > 1 ? parts[1].toLowerCase() : "us";
}

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
      }
    );
  });
}

document.addEventListener("DOMContentLoaded", function () {
  navigator.geolocation.getCurrentPosition(
    function (position) {
      initMap(position.coords.latitude, position.coords.longitude);
    },
    function () {
      initMap(10.8231, 106.6297);
    }
  );
});

async function initMap(userLat, userLng) {
  const center = {
    lat: userLat ?? 0,
    lng: userLng ?? 0,
  };

  map = new google.maps.Map(document.getElementById("map"), {
    center: center,
    zoom: 12,
    mapTypeControl: false,
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
      place.geometry.location.lng()
    );
  });

  google.maps.event.addListenerOnce(map, "idle", function () {
    loadNearbyPlaces(userLat, userLng);
  });

  if (!map) {
    map = new google.maps.Map(document.getElementById("map"), {
      center: { lat, lng },
      zoom: 15,
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

document.getElementById("find-btn").addEventListener("click", function () {
  
  const keyword = document.getElementById("pac-input").value.trim();
  const googleMode = document.getElementById("googleSearchMode").checked;

  if (!keyword) {
    alert("Enter a keyword.");
    return;
  }

if (googleMode) {
  
    document.getElementById("search-wrapper").style.display = "none";

    document.getElementById("ai-result-panel").style.display = "block";

    document.getElementById("ai-loading").style.display = "flex";

    document.getElementById("ai-result-content").innerHTML = "";

    fetch("https://dev.pt-solution.vn/wp-admin/admin-ajax.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded"
        },
        body: new URLSearchParams({
            action: "pt_ai_search",
            question: keyword
        })
    })
    .then(r => r.json())
    .then(res => {

        document.getElementById("ai-loading").style.display = "none";

        document.getElementById("ai-result-content").innerHTML =
            res.data.answer;

    })
    .catch(err => {

        document.getElementById("ai-loading").style.display = "none";

        document.getElementById("ai-result-content").innerHTML =
            "❌ Không thể kết nối Gemini";

        console.error(err);
    });

    return;
}
  document.getElementById("search-wrapper").style.display = "block";
  document.getElementById("ai-result-panel").style.display = "none";
  searchPlaces(keyword);
});

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
  const keyword = document
    .getElementById("pac-input")
    .value
    .trim();

  if (!keyword) {
    return;
  }

  const service =
    new google.maps.places.PlacesService(map);

  service.textSearch(
    {
      query: keyword
    },
    function (results, status) {

if (status !== google.maps.places.PlacesServiceStatus.OK) {
    console.error("Places Error:", status);
    return;
}

      // Xóa marker cũ
      markers.forEach(marker => {
        marker.setMap(null);
      });

      markers = [];

      const bounds =
        new google.maps.LatLngBounds();

      results.forEach(place => {

        if (!place.geometry) {
          return;
        }

        const marker =
          new google.maps.Marker({
            position:
              place.geometry.location,
            map: map,
            title: place.name
          });

        markers.push(marker);

        bounds.extend(
          place.geometry.location
        );

        marker.addListener("click", () => {

          loadPlaceDetails(
            place.place_id
          );

        });

      });

      // Zoom về khu vực kết quả
      if (results.length > 1) {

        map.fitBounds(bounds);

      } else {

        map.setCenter(
          results[0].geometry.location
        );

        map.setZoom(16);

      }

      // Render list bên trái
      renderResults(results);

    }
  );
}

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
                        <i class="fa-solid fa-star" style="color: #ff7200;"></i> ${place.rating || "-"
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
    }
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
      ],
    },
    function (place, status) {
      if (status !== google.maps.places.PlacesServiceStatus.OK) {
        return;
      }

      const photo = place.photos?.[0]
        ? place.photos[0].getUrl({
          maxWidth: 800,
          maxHeight: 400,
        })
        : "";

      if (infoWindow) {
        infoWindow.close();
      }

      infoWindow = new google.maps.InfoWindow({
        maxWidth: 700,
        content: `
<div class="gm-place-card">

    ${photo ? `
    <div class="gm-cover">
        <img src="${photo}">
    </div>
    ` : ""}

    <div class="gm-body">

        <h2 class="gm-title">
            ${place.name}
        </h2>

        <div class="gm-rating">
            <i class="fa-solid fa-star"></i>
            <span>${place.rating || "-"}</span>
            <small>
                (${place.user_ratings_total || 0} reviews)
            </small>
        </div>

        <div class="gm-address">
            <i class="fa-solid fa-location-dot"></i>
            <span>${place.formatted_address || ""}</span>
        </div>

        <div class="gm-tabs">

            <button
                class="gm-tab active"
                data-tab="info">
                Info
            </button>

            <button
                class="gm-tab gm-picture-btn"
                data-tab="picture"
                data-placeid="${placeId}">
                Picture
            </button>

            <button
                class="gm-tab"
                data-tab="video"
                data-placeid="${placeId}">
                Video
            </button>

            <button
                class="gm-tab"
                data-tab="live"
                data-placeid="${placeId}">
                Live
            </button>

        </div>

        <div class="gm-tab-content">

            <div id="tab-info" class="gm-pane active">

                ${place.website
            ? `
                    <div class="gm-link">
                        <i class="fa-solid fa-globe"></i>
                        <a href="${place.website}" target="_blank">
                            Website
                        </a>
                    </div>
                    `
            : ""
          }

                ${place.opening_hours
            ? `
                    <div class="gm-hours">
                        ${place.opening_hours.weekday_text
              .map(
                item => `
                            <div class="gm-hour-row">
                                ${item}
                            </div>
                            `
              )
              .join("")}
                    </div>
                    `
            : ""
          }

                <div class="gm-map-link">
                    <a href="${place.url}" target="_blank">
                        View on Google Maps
                    </a>
                </div>

            </div>

            <div id="tab-picture" class="gm-pane">
                <div class="gm-loading">
                    Click Picture to load photos
                </div>
            </div>

            <div id="tab-video" class="gm-pane">
                <div class="gm-loading">
                    No videos yet
                </div>
            </div>

            <div id="tab-live" class="gm-pane">
                <div class="gm-loading">
                    No livestream
                </div>
            </div>

        </div>

    </div>

</div>
`
      });

      infoWindow.open({
        anchor: marker,
        map: map,
      });
    }
  );
}

document.querySelectorAll('input[name="maptype"]').forEach((radio) => {
  radio.addEventListener("change", function () {
    map.setMapTypeId(this.value);

    document.getElementById("current-layer").textContent =
      this.nextSibling.textContent.trim();
  });
});

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
                `
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
function loadPlacePhotos(placeId){

    const service =
        new google.maps.places.PlacesService(map);

    service.getDetails({

        placeId: placeId,

        fields:[
            "name",
            "photos"
        ]

    }, function(place,status){

        if(
            status !==
            google.maps.places
            .PlacesServiceStatus.OK
        ){
            return;
        }

        fetch(
            "https://dev.pt-solution.vn/wp-admin/admin-ajax.php?action=get_place_uploads&place_id="
            + placeId
        )
        .then(r=>r.json())
        .then(res=>{

            renderPhotos(
                place,
                res.data || []
            );

        });

    });

}

function renderPhotos(
    place,
    uploads
){

    const container =
        document.getElementById(
            "gmPictureContent"
        );

    let html = '';

    if(place.photos){

        place.photos.forEach(photo => {

            html += `
                <div class="gm-photo-item">

                    <img
                        src="${photo.getUrl({
                            maxWidth:1200
                        })}">

                    <span class="gm-badge">
                        Google
                    </span>

                </div>
            `;

        });

    }

    uploads.forEach(item => {

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

  return document
    .getElementById("pac-input")
    .value
    .trim();

}

document.addEventListener("click", function (e) {

  const pictureBtn =
    e.target.closest(".gm-picture-btn");

  if (!pictureBtn) {
    return;
  }

  const placeId =
    pictureBtn.dataset.placeid;

  openPlacePictureDialog(placeId);

});

function openPlacePictureDialog(placeId) {

  const dialog =
    document.getElementById(
      "gmPictureDialog"
    );

  dialog.dataset.placeid =
    placeId;

  dialog.classList.add("show");

  document.getElementById(
    "gmPictureContent"
  ).innerHTML = `
        <div class="gm-empty">
            Loading pictures...
        </div>
    `;

  console.log(
    "Open Picture Dialog:",
    placeId
  );

  loadPlacePhotos(placeId);

}
document.addEventListener("click", function (e) {

  if (
    e.target.id === "gmPictureClose" ||
    e.target.closest("#gmPictureClose")
  ) {

    document
      .getElementById(
        "gmPictureDialog"
      )
      .classList.remove("show");
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
  document.getElementById("gmUploadPhotoFile").addEventListener("change", function () {

      const file = this.files[0];

      if (!file) {
        return;
      }

      const placeId = document.getElementById("gmPictureDialog").dataset.placeid;

      const formData = new FormData();

      formData.append(
        "action",
        "upload_place_photo"
      );

      formData.append(
        "place_id",
        placeId
      );

      formData.append(
        "photo",
        file
      );

      fetch(
        "https://dev.pt-solution.vn/wp-admin/admin-ajax.php",
        {
          method: "POST",
          body: formData
        }
      )
        .then(r => r.json())
        .then(res => {

          if (res.success) {

            loadPlacePhotos(placeId);

          } else {

            alert(res.data);

          }

        });

    });
}
document.getElementById("ai-close").addEventListener("click", function () {

    document.getElementById("ai-result-panel").style.display = "none";

    document.getElementById("ai-loading").style.display = "none";

    document.getElementById("ai-result-content").innerHTML = "";

});