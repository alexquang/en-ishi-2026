/**
 * GTranslate GPS & Country Language Auto-Detection + UI Dictionary Fallback
 * Automatically detects user country on page load, applies the corresponding language,
 * synchronizes the header dropdown selector (.gt_selector), and translates header menu
 * & Maps UI items immediately so menu items always change language reliably.
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

  /**
   * Từ điển dịch trực tiếp cho Menu Header & Giao diện Maps
   * Đảm bảo các mục menu luôn đổi sang Tiếng Việt / Nhật / Hàn ngay lập tức
   * kể cả khi Google Translate widget bỏ qua các cụm từ ngắn trên menu.
   */
  const uiDictionary = {
    "Business Matching": {
      vi: "Kết nối kinh doanh",
      ja: "ビジネスマッチング",
      ko: "비즈니스 매칭",
      "zh-CN": "商务配对",
      en: "Business Matching",
    },
    Category: {
      vi: "Danh mục",
      ja: "カテゴリー",
      ko: "카테고리",
      "zh-CN": "分类",
      en: "Category",
    },
    Categories: {
      vi: "Danh mục",
      ja: "カテゴリー",
      ko: "카테고리",
      "zh-CN": "分类",
      en: "Categories",
    },
    Channel: {
      vi: "Kênh",
      ja: "チャンネル",
      ko: "채널",
      "zh-CN": "频道",
      en: "Channel",
    },
    Community: {
      vi: "Cộng đồng",
      ja: "コミュニティ",
      ko: "커뮤니티",
      "zh-CN": "社区",
      en: "Community",
    },
    Video: {
      vi: "Video",
      ja: "動画",
      ko: "비디오",
      "zh-CN": "视频",
      en: "Video",
    },
    Groups: {
      vi: "Nhóm",
      ja: "グループ",
      ko: "그룹",
      "zh-CN": "群组",
      en: "Groups",
    },
    Members: {
      vi: "Thành viên",
      ja: "メンバー",
      ko: "멤버",
      "zh-CN": "成员",
      en: "Members",
    },
    Activity: {
      vi: "Hoạt động",
      ja: "アクティビティ",
      ko: "활동",
      "zh-CN": "动态",
      en: "Activity",
    },
    Feed: {
      vi: "Bảng tin",
      ja: "フィード",
      ko: "피드",
      "zh-CN": "动态",
      en: "Feed",
    },
    Explore: {
      vi: "Khám phá",
      ja: "探索",
      ko: "탐색",
      "zh-CN": "探索",
      en: "Explore",
    },
    Spaces: {
      vi: "Không gian",
      ja: "スペース",
      ko: "스페이스",
      "zh-CN": "空间",
      en: "Spaces",
    },
    Messages: {
      vi: "Tin nhắn",
      ja: "メッセージ",
      ko: "메시지",
      "zh-CN": "消息",
      en: "Messages",
    },
    Maps: {
      vi: "Bản đồ",
      ja: "マップ",
      ko: "지도",
      "zh-CN": "地图",
      en: "Maps",
    },
    Home: {
      vi: "Trang chủ",
      ja: "ホーム",
      ko: "홈",
      "zh-CN": "首页",
      en: "Home",
    },
    "My Account": {
      vi: "Tài khoản của tôi",
      ja: "マイアカウント",
      ko: "내 계정",
      "zh-CN": "我的账户",
      en: "My Account",
    },
    "My Wallet": {
      vi: "Ví của tôi",
      ja: "マイウォレット",
      ko: "내 지갑",
      "zh-CN": "我的钱包",
      en: "My Wallet",
    },
    "Log In": {
      vi: "Đăng nhập",
      ja: "ログイン",
      ko: "로그인",
      "zh-CN": "登录",
      en: "Log In",
    },
    "Log Out": {
      vi: "Đăng xuất",
      ja: "ログアウト",
      ko: "로그아웃",
      "zh-CN": "退出登录",
      en: "Log Out",
    },
    Register: {
      vi: "Đăng ký",
      ja: "登録",
      ko: "회원가입",
      "zh-CN": "注册",
      en: "Register",
    },
    // Các nút trên trang Maps
    "🍜 Restaurants": {
      vi: "🍜 Nhà hàng",
      ja: "🍜 レストラン",
      ko: "🍜 음식점",
      "zh-CN": "🍜 餐厅",
      en: "🍜 Restaurants",
    },
    "🏨 Hotels": {
      vi: "🏨 Khách sạn",
      ja: "🏨 ホテル",
      ko: "🏨 호텔",
      "zh-CN": "🏨 酒店",
      en: "🏨 Hotels",
    },
    "☕ Coffee": {
      vi: "☕ Quán cà phê",
      ja: "☕ カフェ",
      ko: "☕ 카페",
      "zh-CN": "☕ 咖啡厅",
      en: "☕ Coffee",
    },
    "🛍 Shopping": {
      vi: "🛍 Mua sắm",
      ja: "🛍 ショッピング",
      ko: "🛍 쇼핑",
      "zh-CN": "🛍 购物",
      en: "🛍 Shopping",
    },
    "⛽ Gas": {
      vi: "⛽ Trạm xăng",
      ja: "⛽ ガソリンスタンド",
      ko: "⛽ 주유소",
      "zh-CN": "⛽ 加油站",
      en: "⛽ Gas",
    },
    "💊 Pharmacy": {
      vi: "💊 Hiệu thuốc",
      ja: "💊 薬局",
      ko: "💊 약국",
      "zh-CN": "💊 药店",
      en: "💊 Pharmacy",
    },
    "🏧 ATM": {
      vi: "🏧 Cây ATM",
      ja: "🏧 ATM",
      ko: "🏧 ATM",
      "zh-CN": "🏧 ATM",
      en: "🏧 ATM",
    },
    "🅿️ Parking": {
      vi: "🅿️ Bãi đỗ xe",
      ja: "🅿️ 駐車場",
      ko: "🅿️ 주차장",
      "zh-CN": "🅿️ 停车场",
      en: "🅿️ Parking",
    },
    Layers: {
      vi: "Lớp bản đồ",
      ja: "レイヤー",
      ko: "레이어",
      "zh-CN": "图层",
      en: "Layers",
    },
  };

  const placeholderDictionary = {
    "Search Google Maps": {
      vi: "Tìm kiếm trên Google Maps",
      ja: "Google マップを検索",
      ko: "Google 지도 검색",
      "zh-CN": "搜索 Google 地图",
      en: "Search Google Maps",
    },
    "Choose starting point, or click on the map...": {
      vi: "Chọn điểm xuất phát hoặc nhấp vào bản đồ...",
      ja: "出発地を選択するか、地図上をクリック...",
      ko: "출발지를 선택하거나 지도를 클릭하세요...",
      "zh-CN": "选择起点，或在地图上点击...",
      en: "Choose starting point, or click on the map...",
    },
    "Choose destination...": {
      vi: "Chọn điểm đến...",
      ja: "目的地を選択...",
      ko: "목적지 선택...",
      "zh-CN": "选择目的地...",
      en: "Choose destination...",
    },
  };

  let activeLanguage = "en";
  let isTranslateApplied = false;

  /**
   * Dịch trực tiếp các mục trên thanh Menu Header (#primary-menu, .main-navigation)
   * và các nút công cụ trên trang Maps theo ngôn ngữ đã chọn
   */
  function applyDirectMenuAndUITranslations(lang) {
    if (!lang) return;

    // 1. Dịch các mục trên thanh Menu (#primary-menu a, .mobile-user-profile-menu a, .user-profile-menu a, .top-categories .cat-btn, #layers-toggle)
    const targetElements = document.querySelectorAll(
      "#primary-menu a, .main-navigation ul.menu li > a, .top-categories .cat-btn, #layers-toggle"
    );

    targetElements.forEach(function (el) {
      // Bỏ qua nếu là bên trong gtranslate_wrapper
      if (el.closest(".gtranslate_wrapper")) return;

      // Tìm text node đầu tiên có nội dung chữ hoặc toàn bộ text
      let textNodes = [];
      for (let i = 0; i < el.childNodes.length; i++) {
        const node = el.childNodes[i];
        if (node.nodeType === Node.TEXT_NODE && node.nodeValue.trim() !== "") {
          textNodes.push(node);
        } else if (
          node.nodeType === Node.ELEMENT_NODE &&
          !node.classList.contains("dropdown-symbol") &&
          !node.classList.contains("sub-arrow") &&
          node.tagName !== "I" &&
          node.tagName !== "SVG" &&
          node.children.length === 0 &&
          node.textContent.trim() !== ""
        ) {
          textNodes.push(node);
        }
      }

      if (textNodes.length > 0) {
        textNodes.forEach(function (tNode) {
          const currentRaw =
            tNode.nodeType === Node.TEXT_NODE
              ? tNode.nodeValue.trim()
              : tNode.textContent.trim();

          // Lưu text gốc tiếng Anh vào thuộc tính của phần tử cha
          let origKey = el.getAttribute("data-pt-orig-text");
          if (!origKey) {
            // Tìm xem currentRaw có khớp với key hoặc bất kỳ bản dịch nào trong uiDictionary không
            for (const key in uiDictionary) {
              if (key.toLowerCase() === currentRaw.toLowerCase()) {
                origKey = key;
                break;
              }
              const transMap = uiDictionary[key];
              for (const lCode in transMap) {
                if (transMap[lCode].toLowerCase() === currentRaw.toLowerCase()) {
                  origKey = key;
                  break;
                }
              }
              if (origKey) break;
            }
            if (origKey) {
              el.setAttribute("data-pt-orig-text", origKey);
            }
          }

          if (origKey && uiDictionary[origKey]) {
            const translated =
              uiDictionary[origKey][lang] ||
              (lang === "en" ? uiDictionary[origKey].en : null);
            if (translated) {
              if (tNode.nodeType === Node.TEXT_NODE) {
                const leading = tNode.nodeValue.match(/^\s*/)[0];
                const trailing = tNode.nodeValue.match(/\s*$/)[0];
                tNode.nodeValue = leading + translated + trailing;
              } else {
                tNode.textContent = translated;
              }
            }
          }
        });
      }
    });

    // 2. Dịch placeholder của ô tìm kiếm Google Maps
    const inputs = document.querySelectorAll(
      "#pac-input, #gm-dir-origin, #gm-dir-dest"
    );
    inputs.forEach(function (inp) {
      let origPh = inp.getAttribute("data-pt-orig-ph");
      if (!origPh) {
        const curPh = (inp.getAttribute("placeholder") || "").trim();
        if (placeholderDictionary[curPh]) {
          origPh = curPh;
          inp.setAttribute("data-pt-orig-ph", origPh);
        }
      }
      if (origPh && placeholderDictionary[origPh]) {
        const nextPh =
          placeholderDictionary[origPh][lang] ||
          (lang === "en" ? placeholderDictionary[origPh].en : null);
        if (nextPh) {
          inp.setAttribute("placeholder", nextPh);
        }
      }
    });
  }

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

    // 2. Dịch trực tiếp các mục menu Header & UI Maps
    applyDirectMenuAndUITranslations(lang);

    // 3. Cập nhật combo ẩn của Google Translate (nếu đã sẵn sàng)
    const combo = document.querySelector("select.goog-te-combo");
    if (combo) {
      const pageTranslated =
        document.documentElement.classList.contains("translated-ltr") ||
        document.documentElement.classList.contains("translated-rtl");

      if (combo.value !== lang || (lang !== "en" && !pageTranslated)) {
        if (combo.value === lang && !pageTranslated) {
          combo.value = "en";
          combo.dispatchEvent(new Event("change", { bubbles: true }));
        }
        combo.value = lang;
        combo.dispatchEvent(new Event("change", { bubbles: true }));
      }
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
      if (combo && combo.options && combo.options.length > 0) {
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
      const existingCombo = document.querySelector("select.goog-te-combo");
      if (
        existingCombo &&
        existingCombo.options &&
        existingCombo.options.length > 0
      ) {
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
      if (
        typeof ptGTranslateData !== "undefined" &&
        ptGTranslateData.serverCountry
      ) {
        resolve(ptGTranslateData.serverCountry);
        return;
      }

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

      const gpsPromise = getGPSCountry();

      let resolved = false;

      function onCountrySuccess(country) {
        if (resolved) return;
        resolved = true;
        resolve(country);
      }

      ajaxPromise.then(onCountrySuccess).catch(function () {});
      gpsPromise.then(onCountrySuccess).catch(function () {});

      setTimeout(function () {
        if (!resolved) {
          const navLang = (
            navigator.language ||
            navigator.userLanguage ||
            ""
          ).toLowerCase();
          let fallbackCountry = "VN";
          if (navLang.startsWith("ja")) fallbackCountry = "JP";
          else if (navLang.startsWith("ko")) fallbackCountry = "KR";
          else if (navLang.startsWith("zh")) fallbackCountry = "CN";
          else if (navLang.startsWith("th")) fallbackCountry = "TH";
          else if (navLang.startsWith("en-us") || navLang === "en")
            fallbackCountry = "US";

          onCountrySuccess(fallbackCountry);
        }
      }, 1500);
    });
  }

  /**
   * Áp dụng ngôn ngữ vào GTranslate và cập nhật Dropdown + Menu
   */
  function applyLanguage(language) {
    activeLanguage = language;
    const targetValue = "en|" + language;

    // Cập nhật giao diện dropdown và dịch menu ngay lập tức
    syncDropdownAndCombo(language);

    const cval = "/en/" + language;
    document.cookie = "googtrans=" + cval + "; path=/;";
    document.cookie =
      "googtrans=" + cval + "; path=/; domain=" + window.location.hostname + ";";

    try {
      localStorage.setItem("pt_gtranslate_selected_lang", language);
    } catch (e) {}

    if (language === "en") {
      isTranslateApplied = true;
      document.documentElement.classList.remove("pt-gtranslate-loading");
      syncDropdownAndCombo("en");
      return;
    }

    loadGoogleTranslate().then(function (ready) {
      document.documentElement.classList.remove("pt-gtranslate-loading");
      if (!ready) return;

      isTranslateApplied = true;

      if (typeof window.doGTranslate === "function") {
        window.doGTranslate(targetValue);
      }

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

    if (isUserManual && storedLang) {
      applyLanguage(storedLang);
      return;
    }

    detectCountry().then(function (countryCode) {
      const country = String(countryCode || "").toUpperCase().trim();
      const language = languageMap[country] || "vi";
      applyLanguage(language);
    });
  }

  // Lắng nghe khi người dùng đổi ngôn ngữ thủ công từ dropdown .gt_selector
  document.addEventListener("change", function (e) {
    if (e.target && e.target.classList.contains("gt_selector")) {
      const val = e.target.value;
      const lang = val ? val.split("|")[1] : "";
      if (lang) {
        try {
          localStorage.setItem("pt_gtranslate_selected_lang", lang);
          localStorage.setItem("pt_gtranslate_user_manual", "1");
        } catch (err) {}
        activeLanguage = lang;
        applyLanguage(lang);
      }
    }
  });

  if (typeof MutationObserver !== "undefined") {
    const observer = new MutationObserver(function () {
      if (activeLanguage) {
        applyDirectMenuAndUITranslations(activeLanguage);
      }
    });

    observer.observe(document.documentElement, {
      childList: true,
      subtree: true,
    });

    setTimeout(function () {
      observer.disconnect();
    }, 12000);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initAutoLanguage);
  } else {
    initAutoLanguage();
  }
})();