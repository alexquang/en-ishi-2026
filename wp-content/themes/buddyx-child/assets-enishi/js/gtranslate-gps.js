(function () {
    "use strict";

    console.log("[GPS] Starting GPS language detection...");

    // =====================================================
    // COUNTRY -> GTRANSLATE LANGUAGE
    // =====================================================
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
        NL: "nl",
        RU: "ru",

        US: "en",
        GB: "en",
        CA: "en",
        AU: "en",
        NZ: "en"
    };


    // =====================================================
    // GET COUNTRY FROM GPS
    // =====================================================
    function getGPSCountry() {

        return new Promise(function (resolve, reject) {

            if (!navigator.geolocation) {
                reject("Geolocation is not supported");
                return;
            }

            navigator.geolocation.getCurrentPosition(

                function (position) {

                    const latitude = position.coords.latitude;
                    const longitude = position.coords.longitude;

                    console.log("[GPS] Latitude:", latitude);
                    console.log("[GPS] Longitude:", longitude);

                    /*
                     * Reverse geocoding
                     *
                     * Replace this URL with your own API if needed.
                     *
                     * Example using Nominatim:
                     */
                    const url =
                        "https://nominatim.openstreetmap.org/reverse" +
                        "?format=json" +
                        "&lat=" + latitude +
                        "&lon=" + longitude +
                        "&zoom=3" +
                        "&addressdetails=1";

                    fetch(url, {
                        headers: {
                            "Accept": "application/json"
                        }
                    })
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error("Reverse geocoding failed");
                        }

                        return response.json();
                    })
                    .then(function (data) {

                        const countryCode =
                            data.address &&
                            data.address.country_code
                                ? data.address.country_code.toUpperCase()
                                : null;

                        if (!countryCode) {
                            reject("Country not found");
                            return;
                        }

                        console.log("[GPS] COUNTRY:", countryCode);

                        resolve(countryCode);
                    })
                    .catch(function (error) {
                        reject(error);
                    });
                },

                function (error) {

                    console.warn(
                        "[GPS] Cannot get location:",
                        error.message
                    );

                    reject(error);
                },

                {
                    enableHighAccuracy: false,
                    timeout: 10000,
                    maximumAge: 300000
                }
            );
        });
    }


    // =====================================================
    // APPLY GTRANSLATE LANGUAGE
    // =====================================================
function applyGTranslateLanguage(language) {

    console.log("[GPS] GPS LANGUAGE:", language);

    const targetValue = "en|" + language;

    let attempts = 0;

    const timer = setInterval(function () {

        attempts++;

        const select =
            document.querySelector("select.gt_selector");

        if (!select) {

            if (attempts % 5 === 0) {
                console.log(
                    "[GTranslate] Waiting for menu..."
                );
            }

            return;
        }


        // =====================================================
        // CHECK LANGUAGE OPTION
        // =====================================================

        const option = Array.from(select.options).find(
            function (option) {
                return option.value === targetValue;
            }
        );

        if (!option) {

            if (attempts % 5 === 0) {
                console.log(
                    "[GTranslate] Waiting language:",
                    targetValue
                );
            }

            return;
        }


        // =====================================================
        // STOP WAITING MENU
        // =====================================================

        clearInterval(timer);


        // =====================================================
        // SET COOKIE
        // =====================================================

        const cookieValue = "/en/" + language;

        document.cookie =
            "googtrans=" +
            cookieValue +
            "; path=/";

        document.cookie =
            "googtrans=" +
            cookieValue +
            "; path=/maps/";


        // =====================================================
        // SET MENU VALUE
        // =====================================================

        select.value = targetValue;

        console.log(
            "[GTranslate] Final select:",
            select.value
        );


        // =====================================================
        // LOAD GOOGLE TRANSLATE ENGINE
        // =====================================================

        function waitForGoogleTranslate() {

            const googleCombo =
                document.querySelector(
                    "select.goog-te-combo"
                );

            const googleContainer =
                document.getElementById(
                    "google_translate_element2"
                );


            // Google Translate đã sẵn sàng
            if (
                googleCombo &&
                googleContainer &&
                googleContainer.innerHTML.length > 0
            ) {

                console.log(
                    "[GTranslate] Google Translate ready"
                );

                // ---------------------------------------------
                // APPLY LANGUAGE
                // ---------------------------------------------

                window.doGTranslate(targetValue);

                console.log(
                    "[GTranslate] Applied:",
                    language
                );

                return;
            }


            // =================================================
            // LOAD GOOGLE TRANSLATE SCRIPT
            // =================================================

            if (!window.gt_translate_script) {

                console.log(
                    "[GTranslate] Loading Google Translate..."
                );

                window.gt_translate_script =
                    document.createElement("script");

                window.gt_translate_script.src =
                    "https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit2";

                document.body.appendChild(
                    window.gt_translate_script
                );
            }


            // =================================================
            // WAIT
            // =================================================

            setTimeout(
                waitForGoogleTranslate,
                300
            );
        }


        waitForGoogleTranslate();

    }, 300);


    // =====================================================
    // TIMEOUT
    // =====================================================

    setTimeout(function () {

        clearInterval(timer);

    }, 20000);
}

    // =====================================================
    // START
    // =====================================================
    function startGPSLanguage() {

        getGPSCountry()

            .then(function (countryCode) {

                const language =
                    languageMap[countryCode] || "en";

                console.log(
                    "[GPS] COUNTRY:",
                    countryCode
                );

                console.log(
                    "[GPS] GPS LANGUAGE:",
                    language
                );

                applyGTranslateLanguage(language);
            })

            .catch(function (error) {

                console.warn(
                    "[GPS] GPS detection failed:",
                    error
                );

                // Default English
                applyGTranslateLanguage("en");
            });
    }


    // Wait until DOM is ready
    if (document.readyState === "loading") {

        document.addEventListener(
            "DOMContentLoaded",
            startGPSLanguage
        );

    } else {

        startGPSLanguage();
    }

})();