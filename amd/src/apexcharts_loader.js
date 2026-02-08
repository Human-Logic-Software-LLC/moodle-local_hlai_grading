/**
 * ApexCharts loader that bypasses AMD detection to avoid SVG.js conflicts.
 *
 * @module local_hlai_grading/apexcharts_loader
 */
define([], function() {
    let loadPromise = null;

    const restoreAmdFlag = (defineRef, originalAmd) => {
        if (defineRef) {
            defineRef.amd = originalAmd;
        }
    };

    const load = () => {
        if (window.ApexCharts) {
            return Promise.resolve(window.ApexCharts);
        }

        if (loadPromise) {
            return loadPromise;
        }

        loadPromise = new Promise((resolve, reject) => {
            const baseUrl = window.M && window.M.cfg ? window.M.cfg.wwwroot : '';
            if (!baseUrl) {
                loadPromise = null;
                reject(new Error('Moodle base URL not available for ApexCharts.'));
                return;
            }

            const script = document.createElement('script');
            const defineRef = window.define;
            const originalAmd = defineRef ? defineRef.amd : undefined;

            // Disable AMD detection for ApexCharts and its bundled SVG.js.
            if (defineRef) {
                defineRef.amd = false;
            }

            script.async = true;
            script.src = baseUrl + '/local/hlai_grading/vendor/apexcharts.min.js';

            script.onload = () => {
                restoreAmdFlag(defineRef, originalAmd);
                if (window.ApexCharts) {
                    resolve(window.ApexCharts);
                } else {
                    loadPromise = null;
                    reject(new Error('ApexCharts did not register globally.'));
                }
            };

            script.onerror = (error) => {
                restoreAmdFlag(defineRef, originalAmd);
                loadPromise = null;
                reject(error);
            };

            document.head.appendChild(script);
        });

        return loadPromise;
    };

    return {
        load: load,
    };
});
