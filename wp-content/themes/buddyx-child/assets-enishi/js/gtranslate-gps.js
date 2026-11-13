/**
 * GTranslate GPS & Country Language Auto-Detection
 * Automatically detects user country on page load, applies the corresponding language,
 * and synchronizes the header dropdown selector (.gt_selector) so it displays the correct language.
 */
(function () {
  "use strict";

  console.log("[GTranslate] Initializing country & language auto-detection...");

  // Country code -> GTranslate language code
  const languageMap = {
    VN: "vi",
    KR: "ko",
    JP: "ja",
    CN: "zh-CN",
    TW: "zh-TW",
    HK: "zh-TW",
    TH: "th",
    KH: "km",
    LA: "lo",
    ID: "id",
    MY: "ms",
    FR: "fr",
    DE: "de",
    ES: "es",
    IT: "it",
    PT: "pt",
    BR: "pt",
    NL: "nl",
    RU: "ru",
    US: "en",
    GB: "en",
    CA: "en",
    AU: "en",
    NZ: "en",
  };

  let activeLanguage = "en";
  let isTranslateApplied = false;

  /**
   * Đồng bộ giá trị hiển thị trên dropdown menu (.gt_selector) và Google Translate combo
   */
  function syncDropdownAndCombo(lang) {
    if (!lang) return;
    const targetValue = "en|" + lang;

    // 1. Cập nhật tất cả dropdown .gt_selector đang có trên trang
    const selectors = document.querySelectorAll(".gt_selector");
    selectors.forEach(function (sel) {
      sel.value = targetValue;
      for (let i = 0; i < sel.options.length; i++) {
        if (sel.options[i].value === targetValue) {
          sel.options[i].selected = true;
          sel.selectedIndex = i;
          break;
        }
      }
    });

    // 2. Cập nhật combo ẩn của Google Translate (nếu đã sẵn sàng)
    const combo = document.querySelector("select.goog-te-combo");
    if (combo && combo.value !== lang) {
      combo.value = lang;
      combo.dispatchEvent(new Event("change", { bubbles: true }));
    }
  }

  /**
   * Chờ Google Translate sẵn sàng
   */
  function waitForGoogleTranslate(resolve) {
    let attempts = 0;
    const maxAttempts = 80;

    const timer = setInterval(function () {
      attempts++;
      const combo = document.querySelector("select.goog-te-combo");
      if (combo) {
        clearInterval(timer);
        resolve(true);
        return;
      }
      if (attempts >= maxAttempts) {
        clearInterval(timer);
        resolve(false);
      }
    }, 150);
  }

  /**
   * Nạp script Google Translate nếu chưa có
   */
  function loadGoogleTranslate() {
    return new Promise(function (resolve) {
      if (document.querySelector("select.goog-te-combo")) {
        resolve(true);
        return;
      }

      if (window.gt_translate_script) {
        waitForGoogleTranslate(resolve);
        return;
      }

      window.gt_translate_script = document.createElement("script");
      window.gt_translate_script.src =
        "https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit2";

      window.gt_translate_script.onload = function () {
        waitForGoogleTranslate(resolve);
      };

      window.gt_translate_script.onerror = function () {
        console.warn("[GTranslate] Failed to load Google Translate engine");
        resolve(false);
      };

      document.body.appendChild(window.gt_translate_script);
      waitForGoogleTranslate(resolve);
    });
  }

  /**
   * Lấy quốc gia từ GPS (Bounding box nhanh hoặc Reverse Geocoding)
   */
  function getGPSCountry() {
    return new Promise(function (resolve, reject) {
      if (!navigator.geolocation) {
        reject("Geolocation unsupported");
        return;
      }

      navigator.geolocation.getCurrentPosition(
        function (position) {
          const lat = position.coords.latitude;
          const lng = position.coords.longitude;

          // Kiểm tra nhanh tọa độ nằm trong lãnh thổ Việt Nam
          if (lat >= 8.5 && lat <= 23.5 && lng >= 102.0 && lng <= 110.0) {
            console.log("[GPS] Coordinates inside Vietnam boundary");
            resolve("VN");
            return;
          }

          // Kiểm tra nhanh Nhật Bản
          if (lat >= 24.0 && lat <= 46.0 && lng >= 123.0 && lng <= 146.0) {
            resolve("JP");
            return;
          }

          // Kiểm tra nhanh Hàn Quốc
          if (lat >= 33.0 && lat <= 39.0 && lng >= 124.0 && lng <= 131.0) {
            resolve("KR");
            return;
          }

          // Reverse geocoding
          const url =
            "https://nominatim.openstreetmap.org/reverse?format=json&lat=" +
            lat +
            "&lon=" +
            lng +
            "&zoom=3&addressdetails=1";

          const controller =
            typeof AbortController !== "undefined"
              ? new AbortController()
              : null;
          const timeoutId = setTimeout(function () {
            if (controller) controller.abort();
          }, 3500);

          fetch(url, {
            headers: { Accept: "application/json" },
            signal: controller ? controller.signal : undefined,
          })
            .then(function (res) {
              clearTimeout(timeoutId);
              if (!res.ok) throw new Error("HTTP " + res.status);
              return res.json();
            })
            .then(function (data) {
              const code =
                data.address && data.address.country_code
                  ? data.address.country_code.toUpperCase()
                  : null;
              if (code) resolve(code);
              else reject("Country not found");
            })
            .catch(function (err) {
              clearTimeout(timeoutId);
              reject(err);
            });
        },
        function (err) {
          reject(err);
        },
        { enableHighAccuracy: false, timeout: 4000, maximumAge: 300000 }
      );
    });
  }

  /**
   * Phát hiện quốc gia của người dùng qua nhiều nguồn tin cậy
   */
  function detectCountry() {
    return new Promise(function (resolve) {
      // 1. Nguồn máy chủ (Cloudflare CF-IPCountry từ PHP)
      if (
        typeof ptGTranslateData !== "undefined" &&
        ptGTranslateData.serverCountry
      ) {
        console.log(
          "[GTranslate] Country from Cloudflare header:",
          ptGTranslateData.serverCountry
        );
        resolve(ptGTranslateData.serverCountry);
        return;
      }

      // 2. Nguồn AJAX WordPress (pt_detect_country)
      const ajaxUrl =
        typeof ptGTranslateData !== "undefined" && ptGTranslateData.ajaxUrl
          ? ptGTranslateData.ajaxUrl
          : "/wp-admin/admin-ajax.php";

      const ajaxPromise = fetch(ajaxUrl + "?action=pt_detect_country", {
        credentials: "same-origin",
      })
        .then(function (res) {
          return res.json();
        })
        .then(function (res) {
          if (res && res.success && res.data && res.data.country) {
            return res.data.country;
          }
          throw new Error("No country in AJAX");
        });

      // 3. Nguồn GPS
      const gpsPromise = getGPSCountry();

      // Sử dụng nguồn nào phản hồi sớm nhất
      let resolved = false;

      function onCountrySuccess(country) {
        if (resolved) return;
        resolved = true;
        console.log("[GTranslate] Country resolved:", country);
        resolve(country);
      }

      ajaxPromise.then(onCountrySuccess).catch(function () {});
      gpsPromise.then(onCountrySuccess).catch(function () {});

      // Fallback theo ngôn ngữ trình duyệt nếu mạng/GPS chậm
      setTimeout(function () {
        if (!resolved) {
          const navLang = (
            navigator.language ||
            navigator.userLanguage ||
            ""
          ).toLowerCase();
          let fallbackCountry = "VN"; // Mặc định ưu tiên VN cho trang Tiếng Việt
          if (navLang.startsWith("ja")) fallbackCountry = "JP";
          else if (navLang.startsWith("ko")) fallbackCountry = "KR";
          else if (navLang.startsWith("zh")) fallbackCountry = "CN";
          else if (navLang.startsWith("th")) fallbackCountry = "TH";
          else if (navLang.startsWith("en-us") || navLang === "en")
            fallbackCountry = "US";

          console.log(
            "[GTranslate] Fallback country from browser language:",
            fallbackCountry
          );
          onCountrySuccess(fallbackCountry);
        }
      }, 1500);
    });
  }

  /**
   * Áp dụng ngôn ngữ vào GTranslate và cập nhật Dropdown
   */
  function applyLanguage(language) {
    activeLanguage = language;
    const targetValue = "en|" + language;

    console.log(
      "[GTranslate] Applying language:",
      language,
      "Target:",
      targetValue
    );

    // Cập nhật giao diện dropdown ngay lập tức
    syncDropdownAndCombo(language);

    // Lưu cookie cho cả root path và current domain
    const cval = "/en/" + language;
    document.cookie = "googtrans=" + cval + "; path=/;";
    document.cookie =
      "googtrans=" + cval + "; path=/; domain=" + window.location.hostname + ";";
    document.cookie = "googtrans=" + encodeURIComponent(cval) + "; path=/;";
    document.cookie =
      "googtrans=" +
      encodeURIComponent(cval) +
      "; path=/; domain=" +
      window.location.hostname +
      ";";

    try {
      localStorage.setItem("pt_gtranslate_selected_lang", language);
    } catch (e) {}

    // Nếu là tiếng Anh mặc định
    if (language === "en") {
      isTranslateApplied = true;
      document.documentElement.classList.remove("pt-gtranslate-loading");
      syncDropdownAndCombo("en");
      return;
    }

    // Nạp engine Google Translate và kích hoạt dịch
    loadGoogleTranslate().then(function (ready) {
      document.documentElement.classList.remove("pt-gtranslate-loading");
      if (!ready) return;

      isTranslateApplied = true;

      if (typeof window.doGTranslate === "function") {
        window.doGTranslate(targetValue);
      }

      // Giữ dropdown luôn hiển thị đúng ngôn ngữ qua các mốc thời gian render DOM
      [50, 150, 400, 800, 1500, 2500, 4000].forEach(function (delay) {
        setTimeout(function () {
          syncDropdownAndCombo(language);
        }, delay);
      });
    });
  }

  /**
   * Khởi động quá trình kiểm tra và chuyển đổi ngôn ngữ
   */
  function initAutoLanguage() {
    let storedLang = null;
    let isUserManual = false;

    try {
      storedLang = localStorage.getItem("pt_gtranslate_selected_lang");
      isUserManual = !!localStorage.getItem("pt_gtranslate_user_manual");
    } catch (e) {}

    // Nếu người dùng đã tự tay bấm chọn ngôn ngữ trước đó, tôn trọng lựa chọn của người dùng
    if (isUserManual && storedLang && languageMap[storedLang] || (isUserManual && storedLang)) {
      console.log(
        "[GTranslate] Respecting user manual selection:",
        storedLang
      );
      applyLanguage(storedLang);
      return;
    }

    // Khi người dùng load vào trang: phát hiện quốc gia của người dùng
    detectCountry().then(function (countryCode) {
      const country = String(countryCode || "").toUpperCase().trim();
      const language = languageMap[country] || "vi"; // Mặc định nếu trong VN hoặc ko xác định thì chuyển sang tiếng Việt

      console.log(
        "[GTranslate] User country:",
        country,
        "=> Target language:",
        language
      );

      applyLanguage(language);
    });
  }

  // Lắng nghe khi người dùng đổi ngôn ngữ thủ công từ dropdown .gt_selector
  document.addEventListener("change", function (e) {
    if (e.target && e.target.classList.contains("gt_selector")) {
      const val = e.target.value;
      const lang = val ? val.split("|")[1] : "";
      if (lang) {
        console.log("[GTranslate] User manually selected:", lang);
        try {
          localStorage.setItem("pt_gtranslate_selected_lang", lang);
          localStorage.setItem("pt_gtranslate_user_manual", "1");
        } catch (err) {}
        activeLanguage = lang;
        syncDropdownAndCombo(lang);
      }
    }
  });

  // Quan sát DOM: khi .gt_selector xuất hiện, lập tức đồng bộ giá trị hiển thị
  if (typeof MutationObserver !== "undefined") {
    const observer = new MutationObserver(function () {
      if (activeLanguage) {
        syncDropdownAndCombo(activeLanguage);
      }
    });

    observer.observe(document.documentElement, {
      childList: true,
      subtree: true,
    });

    setTimeout(function () {
      observer.disconnect();
    }, 10000);
  }

  // Chạy khi DOM sẵn sàng
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initAutoLanguage);
  } else {
    initAutoLanguage();
  }
})();