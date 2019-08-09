(function (settings) {
  if (!settings || !settings.redirects) {
    return;
  }

  // Cookie name.
  var cookieName = 'JJCFGEOCC';
  // Cookie TTL in seconds.
  var cookieTtl = 3600;
  // Code empty values regexp.
  var codeEmpty = /^XX$/;
  // The script runs early, jQuery.cookie is not defined.
  var getCookie = function (name) {
    // escape non-alphanumeric characters
    name = name.replace(/([\.$?*|{}\(\)\[\]\\\/\+^])/g, '\\$1');

    // convert lowercase alpha to uppercase alpha
    name = name.replace(/[a-z]/g, lowerToUpper);

    //inner helper function for conversion
    function lowerToUpper(match) {
      return match.toUpperCase();
    }

    // match expression e.g. "JJCFGEOCC=" or "; JJCFGEOCC="
    var re = new RegExp(
        "(?:^|; )" + name + "=([^;]*)"
    );

    var matches = document.cookie.match(re);
    return matches ? decodeURIComponent(matches[1]) : undefined;
  };
  // Try to find Cloudflare country code in cookie.
  var cloudflareCode = getCookie(cookieName);

  // Try to redirect user by the cookie value.
  if (cloudflareCode) {
    //convert code to uppercase
    cloudflareCode = cloudflareCode.toUpperCase();
    if (!cloudflareCode.match(codeEmpty) && settings.redirects[cloudflareCode]) {
      window.location.href = settings.redirects[cloudflareCode];
    }
    return;
  }
  // Optional - if backend script is defined and XMLHttpRequest is available,
  // try to get Cloudflare country code from backend if cookie is empty.
  if (!settings.script || !window.XMLHttpRequest) {
    return;
  }
  settings.script += settings.script.indexOf('?') >= 0 ? '&' : '?';
  settings.script += new Date().getTime();
  XMLHttpRequest.DONE = XMLHttpRequest.DONE || 4;
  var xhr = new XMLHttpRequest();
  xhr.open('GET', settings.script, true);
  xhr.onreadystatechange = function () {
    if (xhr.readyState != XMLHttpRequest.DONE || xhr.status != 200) {
      return;
    }
    if (xhr.responseText && settings.redirects[xhr.responseText]) {
      window.location.href = settings.redirects[xhr.responseText];
    }
    var expireDate = new Date(new Date().getTime() + cookieTtl * 1000);
    cloudflareCode = xhr.responseText || 'XX';

    // set cookie, enable 'secure' attribute, if https
    document.cookie = cookieName + "=" + cloudflareCode + "; path=" + Drupal.settings.basePath + "; secure=" + isHttps() + "; expires=" + expireDate.toUTCString();
  }
  xhr.send();

  // Check protocol
  function isHttps() {
    return location.protocol == 'https:';
  }
})(window._jjbosCloudflare);
