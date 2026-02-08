// This file is part of the Moodle plugin.
// It manages the dashboard interactions and charts.

/**
 * Dashboard JS.
 *
 * @module     local_hlai_grading/dashboard
 */

define(['local_hlai_grading/iksha_charts'], function(IkshaCharts) {
    const renderAdminChart = data => {
        const chartNode = document.querySelector('#admin-activity-chart');
        if (!chartNode) {
            return;
        }

        IkshaCharts.create('area', '#admin-activity-chart', {
            height: 260,
            series: [{
                name: 'Graded Items',
                data: data.data,
            }],
            xaxis: {
                categories: data.labels,
            },
            colors: [IkshaCharts.colors.primary],
        });
    };

    const renderAdminTopCoursesChart = data => {
        const chartNode = document.querySelector('#admin-top-courses-chart');
        if (!chartNode) {
            return;
        }

        IkshaCharts.create('barHorizontal', '#admin-top-courses-chart', {
            height: 260,
            series: [{
                name: 'Items Graded',
                data: data.top_courses_data,
            }],
            xaxis: {
                categories: data.top_courses_labels,
            },
            colors: [IkshaCharts.colors.info],
            dataLabels: {enabled: true},
        });
    };

    const renderTeacherGradeDistChart = data => {
        const chartNode = document.querySelector('#teacher-grade-dist-chart');
        if (!chartNode) {
            return;
        }

        IkshaCharts.create('bar', '#teacher-grade-dist-chart', {
            height: 260,
            series: [{
                name: 'Students',
                data: data.grade_data,
            }],
            xaxis: {
                categories: data.grade_labels,
                title: {text: 'Score Range (%)'},
            },
            colors: [IkshaCharts.colors.success],
            dataLabels: {enabled: false},
        });
    };

    const renderTeacherRubricChart = data => {
        const chartNode = document.querySelector('#teacher-rubric-chart');
        if (!chartNode) {
            return;
        }

        IkshaCharts.create('barHorizontal', '#teacher-rubric-chart', {
            height: 260,
            series: [{
                name: 'Avg Score (%)',
                data: data.rubric_data,
            }],
            xaxis: {
                categories: data.rubric_labels,
                max: 100,
                title: {text: 'Average Score (%)'},
            },
            colors: [IkshaCharts.colors.warning],
            dataLabels: {enabled: true},
        });
    };

    const init = () => {
        const dataContainer = document.getElementById('hlai-dashboard-data');
        if (!dataContainer) {
            return;
        }

        const rawData = dataContainer.getAttribute('data-chart');
        if (!rawData) {
            return;
        }

        try {
            const chartData = JSON.parse(rawData);

            IkshaCharts.load().then(() => {
                // Admin charts.
                if (chartData.labels) {
                    renderAdminChart(chartData);
                }
                if (chartData.top_courses_labels) {
                    renderAdminTopCoursesChart(chartData);
                }

                // Teacher charts.
                if (chartData.grade_labels) {
                    renderTeacherGradeDistChart(chartData);
                }
                if (chartData.rubric_labels) {
                    renderTeacherRubricChart(chartData);
                }
                return true;
            }).catch(() => {
                // Charts failed to load - silent failure.
                return false;
            });
        } catch (e) {
            // Failed to parse chart data - silent failure.
        }
    };

    return {
        init: init,
    };
});
