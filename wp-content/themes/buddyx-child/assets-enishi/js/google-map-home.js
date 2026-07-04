let map;
let marker;
let autocomplete;
let markers = [];
let selectedMarker = null;
let infoWindow = null;
let trafficLayer;
let transitLayer;
let bikeLayer;

function getBrowserCountry() {
  const locale = navigator.language || navigator.userLanguage || "en-US";

  const parts = locale.split("-");

  return parts.length > 1 ? parts[1].toLowerCase() : "us";
}

function initMap() {
  const center = {
    lat: 11.0645,
    lng: 106.72,
  };

  map = new google.maps.Map(document.getElementById("map"), {
    center: center,
    zoom: 13,
    mapTypeControl: false,
  });

  marker = new google.maps.Marker({
    position: center,
    map: map,
  });

  trafficLayer = new google.maps.TrafficLayer();
  transitLayer = new google.maps.TransitLayer();
  bikeLayer = new google.maps.BicyclingLayer();
  initLayerControls();

  const input = document.getElementById("pac-input");

  autocomplete = new google.maps.places.Autocomplete(input, {
    fields: ["formatted_address", "geometry", "name"],
  });

  autocomplete.bindTo("bounds", map);

  autocomplete.addListener("place_changed", function () {
    const place = autocomplete.getPlace();

    if (!place.geometry || !place.geometry.location) {
      alert("No locations found");
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
      title: place.name,
    });
  });
}

window.initMap = initMap;

document.getElementById("find-btn").addEventListener("click", function () {
  const keyword = document.getElementById("pac-input").value.trim();

  if (!keyword) {
    alert("Enter a keyword.");
    return;
  }

  searchPlaces(keyword);
});

function initLayerControls() {
  const toggleBtn = document.getElementById("layers-toggle");

  const menu = document.getElementById("layers-menu");

  toggleBtn.addEventListener("click", function () {
    menu.classList.toggle("show");
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

function searchPlaces(keyword) {
  const service = new google.maps.places.PlacesService(map);

  service.textSearch(
    {
      query: keyword,
    },
    (results, status) => {
      if (status !== google.maps.places.PlacesServiceStatus.OK) {
        return;
      }

      renderResults(results);
    }
  );
}

function renderResults(results) {
  clearMarkers();

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
        lat: lat,
        lng: lng,
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
      radius: 10000,
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

      const photo =
        place.photos?.[0]?.getUrl({
          maxWidth: 500,
        }) || "";

      if (infoWindow) {
        infoWindow.close();
      }

      infoWindow = new google.maps.InfoWindow({
        content: `

                <div style="width:380px">

                    ${
                      photo
                        ? `<img
                            src="${photo}"
                            style="
                                width:100%;
                                height:220px;
                                object-fit:cover;
                                border-radius:10px;
                                margin-bottom:10px;
                            ">`
                        : ""
                    }

                    <h3 style="
                        margin:0 0 10px;
                        color:#174ea6;
                    ">
                        ${place.name}
                    </h3>

                    <div style="margin-bottom:8px">
                        <i class="fa-solid fa-star" style="color: #ff7200;"></i> &nbsp; ${
                          place.rating || "-"
                        }
                        (${place.user_ratings_total || 0} reviews)
                    </div>

                    <div style="margin-bottom:8px">
                        <i class="fa-solid fa-map" style="color: #ff7200;"></i> &nbsp; ${
                          place.formatted_address || ""
                        }
                    </div>

                   ${
                     place.formatted_phone_number
                       ? `<div style="margin-bottom:8px"> <i class="fa-solid fa-phone" style="color: #ff7200;"></i> &nbsp;  
                            ${place.formatted_phone_number}
                         </div>`
                       : ""
                   }

                   ${
                     place.website
                       ? `<div style="margin-bottom:8px">
                            <i class="fa-solid fa-globe" style="color: #ff7200;"></i>&nbsp;  
                            <a
                                href="${place.website}"
                                target="_blank">
                                Website
                            </a>
                         </div>`
                       : ""
                   }

                    ${
                      place.opening_hours
                        ? `<div style="
                            max-height:120px;
                            overflow:auto;
                            margin-bottom:10px;
                            font-size:13px;
                        ">
                            ${place.opening_hours.weekday_text.join("<br>")}
                         </div>`
                        : ""
                    }

                    <a
                        target="_blank"
                        href="${place.url}">
                        View on Google Maps
                    </a>
                    
                    <div class="place-actions">
                    <button class="place-btn live-btn" id="live-btn"
                            data-placeid="${placeId}">
                        <i class="fa-solid fa-tower-broadcast"></i>
                        Live
                    </button>

                    <button class="place-btn photo-btn" id="photo-btn"
                            data-placeid="${placeId}">
                        <i class="fa-solid fa-images"></i>
                        Picture
                    </button>
                    <button class="place-btn video-btn" id="video-btn"
                            data-placeid="${placeId}">
                        <i class="fa-solid fa-video"></i>
                        Video
                    </button>

                </div>

                </div>

                `,
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

        let dialog =
            document.getElementById(
                "photo-dialog"
            );

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

        const placeId =
            photoBtn.dataset.placeid;

        document
            .getElementById("photo-dialog")
            .classList.add("show");

        loadUserPhotos(placeId);

        return;
    }

    if (
        e.target.closest(
            "#close-photo-dialog"
        )
    ) {

        document
            .getElementById("photo-dialog")
            ?.classList.remove("show");

        return;
    }

    if (
        e.target.closest(
            "#btn-upload"
        )
    ) {

        document
            .getElementById("photo-upload")
            ?.click();

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
