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
    // PREVENT MULTIPLE TRANSLATION
    // =====================================================
    let gpsTranslateApplied = false;


    // =====================================================
    // GET COUNTRY FROM GPS
    // =====================================================
    function getGPSCountry() {

        return new Promise(function (resolve, reject) {

            if (!navigator.geolocation) {

                reject(
                    "Geolocation is not supported"
                );

                return;
            }


            navigator.geolocation.getCurrentPosition(

                function (position) {

                    const latitude =
                        position.coords.latitude;

                    const longitude =
                        position.coords.longitude;


                    console.log(
                        "[GPS] Latitude:",
                        latitude
                    );

                    console.log(
                        "[GPS] Longitude:",
                        longitude
                    );


                    // -------------------------------------------------
                    // Reverse geocoding
                    // -------------------------------------------------
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

                            throw new Error(
                                "Reverse geocoding failed"
                            );
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

                            reject(
                                "Country not found"
                            );

                            return;
                        }


                        console.log(
                            "[GPS] COUNTRY:",
                            countryCode
                        );


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
    // WAIT GOOGLE TRANSLATE
    // =====================================================
    function waitForGoogleTranslate(resolve) {

        let attempts = 0;

        const maxAttempts = 100;


        const timer = setInterval(function () {

            attempts++;


            const combo =
                document.querySelector(
                    "select.goog-te-combo"
                );


            if (combo) {

                clearInterval(timer);


                console.log(
                    "[GTranslate] Google Translate ready"
                );


                resolve(true);

                return;
            }


            if (attempts >= maxAttempts) {

                clearInterval(timer);


                console.warn(
                    "[GTranslate] Google Translate timeout"
                );


                resolve(false);

            }

        }, 200);

    }


    // =====================================================
    // LOAD GOOGLE TRANSLATE ENGINE
    // =====================================================
    function loadGoogleTranslate() {

        return new Promise(function (resolve) {


            // -------------------------------------------------
            // Already ready
            // -------------------------------------------------
            const existingCombo =
                document.querySelector(
                    "select.goog-te-combo"
                );


            if (existingCombo) {

                resolve(true);

                return;
            }


            // -------------------------------------------------
            // Script already loading
            // -------------------------------------------------
            if (window.gt_translate_script) {

                waitForGoogleTranslate(resolve);

                return;
            }


            console.log(
                "[GTranslate] Loading Google Translate..."
            );


            // -------------------------------------------------
            // Create Google Translate script
            // -------------------------------------------------
            window.gt_translate_script =
                document.createElement("script");


            window.gt_translate_script.src =
                "https://translate.google.com/translate_a/element.js" +
                "?cb=googleTranslateElementInit2";


            window.gt_translate_script.onload =
                function () {

                    waitForGoogleTranslate(resolve);

                };


            window.gt_translate_script.onerror =
                function () {

                    console.error(
                        "[GTranslate] Failed to load Google Translate"
                    );

                    resolve(false);

                };


            document.body.appendChild(
                window.gt_translate_script
            );


            // -------------------------------------------------
            // Also wait in case onload fires before combo
            // -------------------------------------------------
            waitForGoogleTranslate(resolve);

        });

    }


    // =====================================================
    // APPLY GTRANSLATE LANGUAGE
    // =====================================================
    function applyGTranslateLanguage(language) {


        // -------------------------------------------------
        // Prevent duplicate execution
        // -------------------------------------------------
        if (gpsTranslateApplied) {

            console.log(
                "[GTranslate] Already applied:",
                language
            );

            return;

        }


        const targetValue =
            "en|" + language;


        console.log(
            "[GTranslate] Preparing:",
            targetValue
        );


        // -------------------------------------------------
        // English = default language
        // No translation required
        // -------------------------------------------------
        if (language === "en") {

            console.log(
                "[GTranslate] English detected - no translation required"
            );


            gpsTranslateApplied = true;


            setTimeout(function () {

                document.documentElement
                    .classList
                    .remove(
                        "pt-gtranslate-loading"
                    );

            }, 100);


            return;
        }


        // -------------------------------------------------
        // Load Google Translate
        // -------------------------------------------------
        loadGoogleTranslate()

            .then(function (ready) {


                if (!ready) {

                    console.warn(
                        "[GTranslate] Google Translate not ready"
                    );


                    document.documentElement
                        .classList
                        .remove(
                            "pt-gtranslate-loading"
                        );


                    return;
                }


                const combo =
                    document.querySelector(
                        "select.goog-te-combo"
                    );


                if (!combo) {

                    console.warn(
                        "[GTranslate] Combo not found"
                    );


                    document.documentElement
                        .classList
                        .remove(
                            "pt-gtranslate-loading"
                        );


                    return;
                }


                if (
                    typeof window.doGTranslate !==
                    "function"
                ) {

                    console.warn(
                        "[GTranslate] doGTranslate not ready"
                    );


                    document.documentElement
                        .classList
                        .remove(
                            "pt-gtranslate-loading"
                        );


                    return;
                }


                // -------------------------------------------------
                // Mark as applied ONLY now
                // -------------------------------------------------
                gpsTranslateApplied = true;


                console.log(
                    "[GTranslate] Applying:",
                    targetValue
                );


                // -------------------------------------------------
                // Apply translation
                // -------------------------------------------------
                window.doGTranslate(
                    targetValue
                );


                // -------------------------------------------------
                // Wait for Google Translate DOM changes
                // -------------------------------------------------
                setTimeout(function () {

                    document.documentElement
                        .classList
                        .remove(
                            "pt-gtranslate-loading"
                        );


                    console.log(
                        "[GTranslate] Translation finished:",
                        language
                    );

                }, 1200);

            });

    }


    // =====================================================
    // START GPS LANGUAGE DETECTION
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


                applyGTranslateLanguage(
                    language
                );

            })


            .catch(function (error) {


                console.warn(
                    "[GPS] GPS detection failed:",
                    error
                );


                /*
                 * GPS is the authority.
                 *
                 * Do NOT automatically change
                 * to English when GPS fails.
                 */


                document.documentElement
                    .classList
                    .remove(
                        "pt-gtranslate-loading"
                    );

            });

    }


    // =====================================================
    // START
    // =====================================================
    if (
        document.readyState ===
        "loading"
    ) {

        document.addEventListener(
            "DOMContentLoaded",
            startGPSLanguage
        );

    } else {

        startGPSLanguage();

    }


})();