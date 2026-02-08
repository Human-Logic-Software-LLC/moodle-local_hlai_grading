/**
 * Iksha chart presets for ApexCharts.
 *
 * @module local_hlai_grading/iksha_charts
 */
define(['local_hlai_grading/apexcharts_loader'], function(ApexLoader) {
    let Apex = window.ApexCharts || null;

    const load = () => ApexLoader.load().then(library => {
        Apex = library;
        return library;
    });
    const colors = {
        primary: '#3B82F6',
        success: '#10B981',
        warning: '#F59E0B',
        danger: '#EF4444',
        info: '#06B6D4',
        gray: '#64748B',
        palette: [
            '#3B82F6',
            '#10B981',
            '#F59E0B',
            '#EF4444',
            '#8B5CF6',
            '#EC4899',
            '#06B6D4',
            '#F97316',
            '#6366F1',
            '#14B8A6',
        ],
    };

    const formatNumber = value => {
        if (value === null || typeof value === 'undefined') {
            return '';
        }
        if (value >= 1000000) {
            return (value / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
        }
        if (value >= 1000) {
            return (value / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
        }
        return value.toLocaleString();
    };

    const deepMerge = (target, source) => {
        Object.keys(source).forEach(key => {
            if (source[key] && typeof source[key] === 'object' && !Array.isArray(source[key])) {
                target[key] = target[key] || {};
                deepMerge(target[key], source[key]);
            } else {
                target[key] = source[key];
            }
        });
        return target;
    };

    const mergeConfig = (base, preset, options) => {
        const merged = deepMerge(deepMerge(JSON.parse(JSON.stringify(base)), preset), options || {});
        return merged;
    };

    const getBaseConfig = () => ({
        chart: {
            fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
            toolbar: {show: false},
            animations: {enabled: true, easing: 'easeinout', speed: 400},
            dropShadow: {enabled: false},
        },
        colors: colors.palette,
        stroke: {curve: 'smooth', width: 2},
        grid: {
            borderColor: '#E2E8F0',
            strokeDashArray: 4,
            xaxis: {lines: {show: false}},
            yaxis: {lines: {show: true}},
            padding: {top: 0, right: 0, bottom: 0, left: 0},
        },
        dataLabels: {enabled: false},
        legend: {
            fontFamily: 'inherit',
            fontSize: '12px',
            fontWeight: 500,
            labels: {colors: '#64748B'},
            markers: {width: 8, height: 8, radius: 2},
            itemMargin: {horizontal: 12, vertical: 4},
        },
        tooltip: {
            theme: 'light',
            style: {fontSize: '12px', fontFamily: 'inherit'},
            x: {show: true},
            marker: {show: true},
        },
        xaxis: {
            labels: {
                style: {colors: '#64748B', fontSize: '11px', fontWeight: 500},
            },
            axisBorder: {show: false},
            axisTicks: {show: false},
        },
        yaxis: {
            labels: {
                style: {colors: '#64748B', fontSize: '11px', fontWeight: 500},
                formatter: val => formatNumber(val),
            },
        },
    });

    const areaConfig = options => mergeConfig(getBaseConfig(), {
        chart: {type: 'area', height: options.height || 240},
        fill: {
            type: 'gradient',
            gradient: {
                shadeIntensity: 1,
                opacityFrom: 0.4,
                opacityTo: 0.05,
                stops: [0, 90, 100],
            },
        },
    }, options);

    const barConfig = options => mergeConfig(getBaseConfig(), {
        chart: {type: 'bar', height: options.height || 240},
        plotOptions: {
            bar: {
                borderRadius: 4,
                columnWidth: '60%',
                dataLabels: {position: 'top'},
            },
        },
    }, options);

    const barHorizontalConfig = options => mergeConfig(getBaseConfig(), {
        chart: {type: 'bar', height: options.height || 240},
        plotOptions: {
            bar: {
                horizontal: true,
                borderRadius: 4,
                barHeight: '70%',
            },
        },
        grid: {
            xaxis: {lines: {show: true}},
            yaxis: {lines: {show: false}},
        },
    }, options);

    const getStatusColors = statuses => {
        const map = {
            completed: colors.success,
            passed: colors.success,
            active: colors.success,
            healthy: colors.success,
            connected: colors.success,
            inprogress: colors.warning,
            'in_progress': colors.warning,
            pending: colors.warning,
            'at_risk': colors.warning,
            expiring: colors.warning,
            failed: colors.danger,
            overdue: colors.danger,
            critical: colors.danger,
            suspended: colors.danger,
            'not_started': colors.danger,
            notstarted: colors.danger,
            info: colors.info,
            processing: colors.info,
            unknown: colors.gray,
            draft: colors.gray,
        };
        return statuses.map(status => {
            const key = status.toLowerCase().replace(/\s+/g, '_');
            return map[key] || colors.gray;
        });
    };

    const create = (type, selector, options) => {
        if (!Apex) {
            return null;
        }
        const node = document.querySelector(selector);
        if (!node) {
            return null;
        }
        let config = null;
        switch (type) {
            case 'area':
                config = areaConfig(options);
                break;
            case 'bar':
                config = barConfig(options);
                break;
            case 'barHorizontal':
                config = barHorizontalConfig(options);
                break;
            default:
                config = areaConfig(options);
        }
        const chart = new Apex(node, config);
        chart.render();
        return chart;
    };

    return {
        colors,
        formatNumber,
        getBaseConfig,
        getStatusColors,
        load,
        create,
    };
});
