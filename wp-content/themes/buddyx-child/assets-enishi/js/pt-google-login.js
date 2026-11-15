/**
 * Global Seamless Google Login (Nextend Social Login)
 * Intercepts NSL popup redirect so the page is NOT reloaded;
 * instead, verifies login status and reloads only the header/navigation menu.
 */
(function () {
  "use strict";

  var isHandlingLogin = false;

  function getAjaxUrl() {
    if (
      typeof ptGoogleLoginData !== "undefined" &&
      ptGoogleLoginData.ajaxUrl
    ) {
      return ptGoogleLoginData.ajaxUrl;
    }
    if (typeof ptMap !== "undefined" && ptMap.ajax_url) {
      return ptMap.ajax_url;
    }
    return "/wp-admin/admin-ajax.php";
  }

  function showGlobalLoginToast(message) {
    if (typeof window.showToast === "function") {
      window.showToast(message);
      return;
    }
    var existing = document.getElementById("pt-login-toast");
    if (existing) existing.remove();

    var toast = document.createElement("div");
    toast.id = "pt-login-toast";
    toast.textContent = message;
    toast.style.cssText =
      "position:fixed;bottom:24px;right:24px;z-index:999999;" +
      "background:#111827;color:#fff;padding:10px 18px;border-radius:8px;" +
      "font-size:14px;font-weight:500;box-shadow:0 8px 24px rgba(0,0,0,0.22);" +
      "opacity:0;transform:translateY(8px);transition:opacity .2s ease,transform .2s ease;";
    document.body.appendChild(toast);

    requestAnimationFrame(function () {
      toast.style.opacity = "1";
      toast.style.transform = "translateY(0)";
    });

    setTimeout(function () {
      toast.style.opacity = "0";
      toast.style.transform = "translateY(8px)";
      setTimeout(function () {
        if (toast.parentNode) toast.parentNode.removeChild(toast);
      }, 250);
    }, 3000);
  }

  function refreshHeaderMenu() {
    var fetchUrl = window.location.href.split("#")[0];

    return fetch(fetchUrl, {
      method: "GET",
      credentials: "include",
      cache: "no-store",
    })
      .then(function (r) {
        if (!r.ok) throw new Error("Failed to fetch updated menu");
        return r.text();
      })
      .then(function (html) {
        var parser = new DOMParser();
        var doc = parser.parseFromString(html, "text/html");

        // Sync body logged-in state classes
        if (doc.body) {
          if (doc.body.classList.contains("logged-in")) {
            document.body.classList.add("logged-in");
            document.body.classList.remove("logged-out");
          }
        }

        // 1. Update mobile icons + profile/login area
        var newMobileIcons = doc.querySelector(
          "#site-navigation .buddypress-icons-wrapper.buddyx-mobile-icon"
        );
        var curMobileIcons = document.querySelector(
          "#site-navigation .buddypress-icons-wrapper.buddyx-mobile-icon"
        );
        if (newMobileIcons && curMobileIcons) {
          curMobileIcons.innerHTML = newMobileIcons.innerHTML;
        }

        // 2. Update desktop icons + profile/login area
        var newDesktopIcons = doc.querySelector(
          "#site-navigation .buddypress-icons-wrapper:not(.buddyx-mobile-icon)"
        );
        var curDesktopIcons = document.querySelector(
          "#site-navigation .buddypress-icons-wrapper:not(.buddyx-mobile-icon)"
        );
        if (newDesktopIcons && curDesktopIcons) {
          curDesktopIcons.innerHTML = newDesktopIcons.innerHTML;
        }

        // 3. Update mobile menu container (user profile, primary menu, mobile user menu)
        var newMobileMenu = doc.querySelector(
          "#site-navigation .buddyx-mobile-menu"
        );
        var curMobileMenu = document.querySelector(
          "#site-navigation .buddyx-mobile-menu"
        );
        if (newMobileMenu && curMobileMenu) {
          curMobileMenu.innerHTML = newMobileMenu.innerHTML;
        }

        // 4. Update desktop menu items & member level while preserving .gtranslate_wrapper
        var newDesktopMenu = doc.querySelector(
          "#site-navigation .buddyx-desktop-menu"
        );
        var curDesktopMenu = document.querySelector(
          "#site-navigation .buddyx-desktop-menu"
        );
        if (newDesktopMenu && curDesktopMenu) {
          var newPrimaryNav = newDesktopMenu.querySelector("#primary-menu");
          var curPrimaryNav = curDesktopMenu.querySelector("#primary-menu");
          if (newPrimaryNav && curPrimaryNav) {
            curPrimaryNav.innerHTML = newPrimaryNav.innerHTML;
          }

          var newMemberLevel = newDesktopMenu.querySelector(".pt-member-level");
          var curLangWrap = curDesktopMenu.querySelector(".pt-header-language");
          if (curLangWrap) {
            var curMemberLevel = curLangWrap.querySelector(".pt-member-level");
            if (newMemberLevel) {
              if (curMemberLevel) {
                curMemberLevel.outerHTML = newMemberLevel.outerHTML;
              } else {
                curLangWrap.insertAdjacentHTML(
                  "afterbegin",
                  newMemberLevel.outerHTML
                );
              }
            } else if (curMemberLevel) {
              curMemberLevel.remove();
            }
          }
        }

        // 5. Update BuddyNext rail if present
        var newRail = doc.querySelector(".buddynext-rail");
        var curRail = document.querySelector(".buddynext-rail");
        if (newRail && curRail) {
          curRail.innerHTML = newRail.innerHTML;
        }

        // Re-init superfish dropdowns if available
        if (
          typeof window.jQuery !== "undefined" &&
          window.jQuery.fn &&
          typeof window.jQuery.fn.superfish === "function"
        ) {
          try {
            window
              .jQuery(".buddyx-desktop-menu #primary-menu")
              .superfish("destroy")
              .superfish();
          } catch (e) {
            try {
              window.jQuery(".buddyx-desktop-menu #primary-menu").superfish();
            } catch (e2) {}
          }
        }

        // Re-apply GTranslate UI dictionary translations to newly injected menu items
        if (typeof window.ptApplyMenuTranslations === "function") {
          window.ptApplyMenuTranslations();
        }
      })
      .catch(function () {});
  }

  function handleSeamlessGoogleLogin(redirectUrl) {
    if (isHandlingLogin) return;
    isHandlingLogin = true;

    var loginTextEl = document.getElementById("gmLoginRequiredText");
    var originalText = loginTextEl ? loginTextEl.innerHTML : "";
    if (loginTextEl) {
      loginTextEl.innerHTML =
        '<i class="fa-solid fa-spinner fa-spin"></i> Signing in with Google...';
    }

    // Remove Nextend redirect overlay if created
    var nslOverlay = document.getElementById("nsl-redirect-overlay");
    if (nslOverlay) nslOverlay.remove();

    var ajaxUrl = getAjaxUrl();

    fetch(ajaxUrl + "?action=pt_check_login_status", {
      method: "GET",
      credentials: "include",
      cache: "no-store",
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (res) {
        if (!res || !res.success || !res.data || !res.data.logged_in) {
          // If NSL redirected to an intermediate step (e.g. registration form), fallback to navigation
          if (redirectUrl) {
            window.location.assign(redirectUrl);
          }
          return;
        }

        if (loginTextEl && originalText) {
          loginTextEl.innerHTML = originalText;
        }

        return refreshHeaderMenu().then(function () {
          showGlobalLoginToast("Logged in successfully!");

          window.dispatchEvent(
            new CustomEvent("pt:google_login_success", {
              detail: res.data,
            })
          );
        });
      })
      .catch(function () {
        if (loginTextEl && originalText) {
          loginTextEl.innerHTML = originalText;
        }
      })
      .finally(function () {
        isHandlingLogin = false;
      });
  }

  function installSeamlessGoogleLoginHook() {
    var customRedirect = function (url) {
      handleSeamlessGoogleLogin(url);
    };

    try {
      Object.defineProperty(window, "nslRedirect", {
        configurable: true,
        enumerable: true,
        get: function () {
          return customRedirect;
        },
        set: function () {
          // Keep customRedirect so Nextend Social Login does not reload the entire page
        },
      });
    } catch (e) {
      window.nslRedirect = customRedirect;
    }
  }

  window.ptRefreshHeaderMenu = refreshHeaderMenu;
  window.ptInstallSeamlessGoogleLoginHook = installSeamlessGoogleLoginHook;

  installSeamlessGoogleLoginHook();

  document.addEventListener(
    "click",
    function (e) {
      if (
        e.target.closest('a[data-plugin="nsl"]') ||
        e.target.closest("#gmNextendGoogleLogin a") ||
        e.target.closest(".gm-login-btn")
      ) {
        installSeamlessGoogleLoginHook();
      }
    },
    true
  );
})();
