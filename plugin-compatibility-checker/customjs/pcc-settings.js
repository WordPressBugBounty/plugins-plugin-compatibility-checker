/* customjs/pcc-settings.js
 * Unified rescan behavior + mode notice updates.
 * Uses PCCSettings.licenseActive (localized by PHP) to show initial mode.
 *
 * Fix: when server returns non-2xx with JSON body (e.g. license_invalid),
 * parse the response JSON in .fail() and show the portal message instead
 * of "Network error...".
 */
(function ($) {
    'use strict';

    $(function () {
        if (typeof window.PCCSettings === 'undefined') {
            return;
        }

        var ajaxUrl = PCCSettings.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php';
        var nonce   = PCCSettings.nonce || '';
        var siteUrl = PCCSettings.siteUrl || (window.location.origin || window.location.protocol + '//' + window.location.host);
        var licenseActive = !!PCCSettings.licenseActive;

        function setMsg(text, isError) {
            var $m = $('#pcc-settings-msg');
            if (!$m.length) {
                $m = $('<span id="pcc-settings-msg" />').insertAfter('#pcc-validate-license');
            }
            $m.html(text || '');
            $m.css({ 'color': isError ? '#b71c1c' : '#155724', 'margin-left': '12px' });
        }

        function setModeNotice(selector, active, extraHtml) {
            var $n = $(selector);
            if (!$n.length) return;
            if (active) {
                $n.html('Portal mode — license validated (remote results available)' + (extraHtml ? ' ' + extraHtml : '')).css('color', '#155724');
            } else {
                var buy = '<a href="https://www.compatshield.com/" target="_blank" rel="noopener">Purchase a Portal license</a>';
                var txt = 'Fallback mode — using WPTide local results (compatibility data limited to PHP ≤ 8.0). ' + buy;
                if (extraHtml) txt = extraHtml + ' ' + txt;
                $n.html(txt).css('color', '#b71c1c');
            }
        }

        function extractMessageFromRespObject(respObj) {
            if (!respObj) return 'No response';
            // If WP-style wrapper: {success:false, data:{message:..., body_preview:...}}
            if (typeof respObj === 'object') {
                if (respObj.success && respObj.data) return respObj.data.message || respObj.data.body_preview || JSON.stringify(respObj.data);
                if (!respObj.success && respObj.data) {
                    if (respObj.data.message) return respObj.data.message;
                    if (respObj.data.body_preview) return respObj.data.body_preview;
                    if (respObj.data.detail) return respObj.data.detail;
                    // If the portal returned something like { valid:false, message:'license_not_found', ... }
                    if (respObj.data.response && typeof respObj.data.response === 'object') {
                        var r = respObj.data.response;
                        if (r.message) return r.message;
                        if (r.valid === false) return 'license_invalid';
                    }
                    return JSON.stringify(respObj.data);
                }
                // Portal might return raw object (not wrapped)
                if (respObj.message) return respObj.message;
                if (respObj.valid === false && respObj.message) return respObj.message;
                if (respObj.error) return respObj.error;
                return JSON.stringify(respObj);
            }
            return String(respObj);
        }

        function extractMessage(resp) {
            // resp may be:
            //  - already-parsed object from .done handler (the WP JSON wrapper)
            //  - a parsed object we extracted from a failed jqXHR (see .fail below)
            if (!resp) return 'No response';
            try {
                return extractMessageFromRespObject(resp);
            } catch (e) {
                return String(resp);
            }
        }

        // try to parse JSON from jqXHR.responseText (fail-safe)
        function tryParseErrorResponse(jqXHR) {
            if (!jqXHR || !jqXHR.responseText) return null;
            try {
                var parsed = JSON.parse(jqXHR.responseText);
                return parsed;
            } catch (e) {
                return null;
            }
        }

        // initialize notices
        setModeNotice('#pcc-license-mode', licenseActive);
        setModeNotice('#pcc-mode-notice', licenseActive);

        // Request scan explicit
        $('#pcc-request-scan').on('click', function (e) {
            e && e.preventDefault();
            var $btn = $(this);
            $btn.prop('disabled', true).text('Requesting...');
            setMsg('');
            $.post(ajaxUrl, { action: (PCCSettings.requestScanAction || 'pcc_request_scan'), nonce: nonce })
                .done(function (resp) {
                    if (resp && resp.success) {
                        setMsg('Portal accepted scan request. Scan will run on CompatShield and results will be available when ready.');
                        setModeNotice('#pcc-license-mode', true, 'Portal mode — scan requested.');
                        setModeNotice('#pcc-mode-notice', true, 'Portal mode — scan requested.');
                    } else {
                        setMsg('Request failed: ' + extractMessage(resp), true);
                    }
                }).fail(function (jqXHR, textStatus) {
                    var parsed = tryParseErrorResponse(jqXHR);
                    if (parsed) {
                        setMsg('Request failed: ' + extractMessage(parsed), true);
                    } else {
                        setMsg('Network error while requesting scan. Status: ' + textStatus, true);
                    }
                }).always(function () { $btn.prop('disabled', false).text('Request Scan on Portal'); });
        });

        // Fetch latest
        $('#pcc-fetch-latest').on('click', function (e) {
            e && e.preventDefault();
            var $btn = $(this);
            $btn.prop('disabled', true).text('Fetching...');
            setMsg('');
            $.post(ajaxUrl, { action: (PCCSettings.fetchRemoteAction || 'pcc_fetch_remote'), nonce: nonce })
                .done(function (resp) {
                    if (resp && resp.success) {
                        var updated = (resp.data && typeof resp.data.updated !== 'undefined') ? resp.data.updated : 0;
                        var scan_pending = (resp.data && resp.data.scan_pending);
                        if (scan_pending) {
                            setMsg('Portal scan is still in progress. Results will be updated when ready.');
                            setModeNotice('#pcc-license-mode', true, 'Portal mode — scan in progress; results will be updated soon.');
                            setModeNotice('#pcc-mode-notice', true, 'Portal mode — scan in progress; results will be updated soon.');
                        } else {
                            setMsg('Fetched latest results. Updated ' + updated + ' entries.');
                            if (updated > 0) {
                                licenseActive = true;
                                setModeNotice('#pcc-license-mode', true);
                                setModeNotice('#pcc-mode-notice', true);
                            } else {
                                if (licenseActive) {
                                    setModeNotice('#pcc-license-mode', true);
                                    setModeNotice('#pcc-mode-notice', true);
                                } else {
                                    setModeNotice('#pcc-license-mode', false);
                                    setModeNotice('#pcc-mode-notice', false);
                                }
                            }
                        }
                        if (!scan_pending) setTimeout(function () { location.reload(); }, 700);
                    } else setMsg('Fetch failed: ' + extractMessage(resp), true);
                }).fail(function (jqXHR, textStatus) {
                    var parsed = tryParseErrorResponse(jqXHR);
                    if (parsed) {
                        // parsed might be WP wrapper or portal payload
                        setMsg('Fetch failed: ' + extractMessage(parsed), true);
                    } else {
                        setMsg('Network error while fetching results. Status: ' + textStatus, true);
                    }
                }).always(function () { $btn.prop('disabled', false).text('Fetch latest result'); });
        });

        // Validate license
        $('#pcc-validate-license').on('click', function (e) {
            e && e.preventDefault();
            var $btn = $(this);
            var license = $('#pcc_license_key').val() || '';
            setMsg('');
            if (!license) { setMsg('Please enter a license key before validating.', true); return; }

            $btn.prop('disabled', true).text('Validating...');
            $.post(ajaxUrl, { action: (PCCSettings.validateAction || 'pcc_validate_license'), license: license, site_url: siteUrl, nonce: nonce })
                .done(function (resp) {
                    if (resp && resp.success) {
                        setMsg('✔ License valid', false);
                        licenseActive = true;
                        setModeNotice('#pcc-license-mode', true);
                        setModeNotice('#pcc-mode-notice', true);
                    } else {
                        var friendly = 'License invalid';
                        if (resp && resp.data) {
                            if (resp.data.message) friendly = resp.data.message;
                            else if (resp.data.body_preview) friendly = resp.data.body_preview;
                            else if (resp.data.detail) friendly = resp.data.detail;
                            // also handle nested portal response
                            if (resp.data.response && typeof resp.data.response === 'object' && resp.data.response.message) {
                                friendly = resp.data.response.message;
                            }
                        }
                        setMsg('✘ ' + friendly, true);
                        licenseActive = false;
                        setModeNotice('#pcc-license-mode', false);
                        setModeNotice('#pcc-mode-notice', false);
                    }
                }).fail(function (jqXHR, textStatus) {
                    // try parse JSON error body (portal often returns useful JSON with message even on 4xx)
                    var parsed = tryParseErrorResponse(jqXHR);
                    if (parsed) {
                        // parsed might be either WP wrapper {success:false,...} or portal payload {valid:false,...}
                        // Prefer portal message if present
                        var portalMsg = parsed.message || (parsed.response && parsed.response.message) || (parsed.data && parsed.data.message);
                        if (!portalMsg && parsed.valid === false) portalMsg = 'license_invalid';
                        if (!portalMsg && parsed.data && parsed.data.response && parsed.data.response.message) portalMsg = parsed.data.response.message;
                        if (portalMsg) {
                            setMsg('✘ ' + portalMsg, true);
                        } else {
                            setMsg('License validation failed: ' + JSON.stringify(parsed), true);
                        }
                    } else {
                        setMsg('Network error while validating license. Please check connectivity.', true);
                    }
                    // ensure UI does not flip to active
                    licenseActive = false;
                    setModeNotice('#pcc-license-mode', false);
                    setModeNotice('#pcc-mode-notice', false);
                }).always(function () { $btn.prop('disabled', false).text('Validate License'); });
        });

        // Unified Rescan -> now requests scan when validated, otherwise refreshes local
        $('#pcc-rescan').on('click', function (e) {
            e && e.preventDefault();
            var $btn = $(this);
            $btn.prop('disabled', true).text('Requesting scan...');
            setMsg('');
            var endpoint = (typeof PCCVars !== 'undefined' && PCCVars.ajaxUrl) ? PCCVars.ajaxUrl : ajaxUrl;
            var actionName = (typeof PCCVars !== 'undefined' && PCCVars.action) ? PCCVars.action : 'pcc_rescan';
            var nonceLocal = (typeof PCCVars !== 'undefined' && PCCVars.nonce) ? PCCVars.nonce : nonce;

            $.post(endpoint, { action: actionName, nonce: nonceLocal })
                .done(function (resp) {
                    if (resp && resp.success) {
                        var data = resp.data || {};
                        if (data.scan_pending) {
                            setMsg('Scan requested on Portal. Results will be available when the Portal completes scanning.');
                            setModeNotice('#pcc-mode-notice', true, 'Portal mode — scan requested; results will be updated soon.');
                            setModeNotice('#pcc-license-mode', true, 'Portal mode — scan requested.');
                        } else {
                            setMsg('Local scan refreshed (fallback) or no scan queued.');
                        }
                        if (!data.scan_pending) setTimeout(function () { location.reload(); }, 700);
                    } else {
                        setMsg('Rescan failed: ' + extractMessage(resp), true);
                    }
                }).fail(function (jqXHR, textStatus) {
                    var parsed = tryParseErrorResponse(jqXHR);
                    if (parsed) {
                        setMsg('Rescan failed: ' + extractMessage(parsed), true);
                    } else {
                        setMsg('Network error while rescanning. Status: ' + textStatus, true);
                    }
                }).always(function () {
                    $btn.prop('disabled', false).text('Rescan');
                });
        });

        $('#pcc_license_key').on('input', function () { var $m = $('#pcc-settings-msg'); if ($m.length) $m.text(''); });
    });
})(jQuery);
