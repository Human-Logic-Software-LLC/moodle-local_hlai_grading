define([
    'jquery',
    'core/ajax',
    'core/notification',
    'core/templates',
    'core/str'
], function($, Ajax, Notification, Templates, Str) {
    const stringDefs = [
        {key: 'explain_grade', component: 'local_hlai_grading'},
        {key: 'hide_explanation', component: 'local_hlai_grading'},
        {key: 'explanation_panel_title', component: 'local_hlai_grading'},
        {key: 'explanation_panel_intro', component: 'local_hlai_grading'},
        {key: 'explanation_loading', component: 'local_hlai_grading'},
        {key: 'explanation_error', component: 'local_hlai_grading'},
        {key: 'explanation_not_available', component: 'local_hlai_grading'},
        {key: 'explanation_overall_heading', component: 'local_hlai_grading'},
        {key: 'explanation_strengths_heading', component: 'local_hlai_grading'},
        {key: 'explanation_strengths_empty', component: 'local_hlai_grading'},
        {key: 'explanation_improvements_heading', component: 'local_hlai_grading'},
        {key: 'explanation_improvements_empty', component: 'local_hlai_grading'},
        {key: 'explanation_criteria_heading', component: 'local_hlai_grading'},
        {key: 'explanation_examples_heading', component: 'local_hlai_grading'},
        {key: 'explanation_expand_all', component: 'local_hlai_grading'},
        {key: 'explanation_collapse_all', component: 'local_hlai_grading'},
        {key: 'explanation_confidence', component: 'local_hlai_grading'},
        {key: 'explanation_points', component: 'local_hlai_grading'},
        {key: 'explanation_toggle_show', component: 'local_hlai_grading'},
        {key: 'explanation_toggle_hide', component: 'local_hlai_grading'},
    ];

    const getStrings = () => Str.get_strings(stringDefs).then(function(results) {
        const map = {};
        results.forEach(function(value, index) {
            map[stringDefs[index].key] = value;
        });
        return map;
    });

    const formatNumber = value => {
        if (value === null || typeof value === 'undefined' || value === '') {
            return '–';
        }
        const number = Number(value);
        if (Number.isNaN(number)) {
            return value;
        }
        return Number.isInteger(number) ? number.toString() : number.toFixed(2);
    };

    const toParagraphs = text => {
        if (!text) {
            return [];
        }
        return text
            .split(/\n+/)
            .map(part => part.trim())
            .filter(Boolean);
    };

    const prepareTemplateContext = (data, strings) => {
        const confidenceValue = Number(data.confidence);
        const templateData = {
            gradevalue: formatNumber(data.grade),
            grademax: formatNumber(data.maxgrade),
            hasconfidence: Number.isFinite(confidenceValue) && confidenceValue > 0,
            confidence: strings.explanation_confidence.replace('{$a}', confidenceValue.toString()),
            hasreasoning: !!data.reasoning,
            reasoningparagraphs: toParagraphs(data.reasoning),
            overallheading: strings.explanation_overall_heading,
            strengthsheading: strings.explanation_strengths_heading,
            improvementsheading: strings.explanation_improvements_heading,
            improvementsempty: strings.explanation_improvements_empty,
            criteriaheading: strings.explanation_criteria_heading,
            examplesheading: strings.explanation_examples_heading,
            expandall: strings.explanation_expand_all,
            collapseall: strings.explanation_collapse_all,
            strengthsempty: strings.explanation_strengths_empty,
            strengths: data.strengths || [],
            improvements: data.improvements || [],
            hasstrengths: Array.isArray(data.strengths) && data.strengths.length > 0,
            hasimprovements: Array.isArray(data.improvements) && data.improvements.length > 0,
        };

        templateData.criteria = (data.criteria || []).map((criterion, index) => {
            const id = criterion.id || index;
            let maxpoints = '';
            if (typeof criterion.maxpoints !== 'undefined' && criterion.maxpoints !== null) {
                maxpoints = criterion.maxpoints;
            } else if (typeof criterion.max_score !== 'undefined') {
                maxpoints = criterion.max_score;
            }
            const pointsLabel = strings.explanation_points
                .replace('{$a->points}', formatNumber(criterion.points))
                .replace('{$a->maxpoints}', formatNumber(maxpoints));

            return {
                id,
                name: criterion.name || 'Criterion',
                levelname: criterion.level_name || criterion.levelname || '',
                pointslabel: pointsLabel,
                togglelabel: strings.explanation_toggle_show,
                hasreasoning: !!criterion.reasoning,
                reasoningparagraphs: toParagraphs(criterion.reasoning),
            };
        });
        templateData.hascriteria = templateData.criteria.length > 0;

        templateData.hasexamples = Array.isArray(data.highlighted_examples) && data.highlighted_examples.length > 0;
        templateData.examples = (data.highlighted_examples || []).map(example => ({
            label: example.label || '',
            text: example.text || '',
            comment: example.comment || '',
            typeclass: (example.type || '').toLowerCase() === 'strength' ? 'strength' : 'improvement',
        }));

        return templateData;
    };

    const insertPanel = (strings) => {
        const table = document.querySelector('#page-mod-assign-view .submissionstatustable');
        if (!table) {
            return null;
        }

        const card = document.createElement('div');
        card.className = 'card ai-grade-explain-card mb-4';
        card.innerHTML = `
            <div class="card-body">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
                    <div>
                        <h4 class="card-title mb-1">${strings.explanation_panel_title}</h4>
                        <p class="text-muted mb-0">${strings.explanation_panel_intro}</p>
                    </div>
                    <button type="button"
                        id="explain-grade-btn"
                        class="btn btn-outline-primary"
                        aria-expanded="false">
                        ${strings.explain_grade}
                    </button>
                </div>
                <div id="grade-explanation-panel" class="mt-3" hidden>
                    <div class="ai-explain-loading">
                        ${strings.explanation_loading}
                    </div>
                </div>
            </div>
        `;

        table.parentNode.insertBefore(card, table.nextSibling);
        return card;
    };

    const fetchExplanation = submissionid => Ajax.call([{
        methodname: 'local_hlai_grading_get_grade_explanation',
        args: {submissionid},
    }])[0];

    const attachCriterionHandlers = (panel, strings) => {
        panel.querySelectorAll('.criterion-explain-btn').forEach(button => {
            button.addEventListener('click', () => {
                const target = panel.querySelector('#reasoning-' + button.dataset.criterionid);
                if (!target) {
                    return;
                }
                const expanded = button.getAttribute('aria-expanded') === 'true';
                if (expanded) {
                    target.style.display = 'none';
                    button.setAttribute('aria-expanded', 'false');
                    button.textContent = strings.explanation_toggle_show;
                } else {
                    target.style.display = 'block';
                    button.setAttribute('aria-expanded', 'true');
                    button.textContent = strings.explanation_toggle_hide;
                }
            });
        });

        const expandAll = panel.querySelector('#expand-all-criteria');
        const collapseAll = panel.querySelector('#collapse-all-criteria');

        if (expandAll) {
            expandAll.addEventListener('click', () => {
                panel.querySelectorAll('.criterion-reasoning').forEach(reasoning => {
                    reasoning.style.display = 'block';
                });
                panel.querySelectorAll('.criterion-explain-btn').forEach(button => {
                    button.setAttribute('aria-expanded', 'true');
                    button.textContent = strings.explanation_toggle_hide;
                });
            });
        }

        if (collapseAll) {
            collapseAll.addEventListener('click', () => {
                panel.querySelectorAll('.criterion-reasoning').forEach(reasoning => {
                    reasoning.style.display = 'none';
                });
                panel.querySelectorAll('.criterion-explain-btn').forEach(button => {
                    button.setAttribute('aria-expanded', 'false');
                    button.textContent = strings.explanation_toggle_show;
                });
            });
        }
    };

    const renderExplanation = (panel, data, strings) => {
        const context = prepareTemplateContext(data, strings);
        return Templates.render('local_hlai_grading/student_explanation', context)
            .then(function(html, js) {
                panel.innerHTML = html;
                Templates.runTemplateJS(js);
                attachCriterionHandlers(panel, strings);
                return true;
            });
    };

    const init = config => {
        if (!config || !config.submissionid) {
            return;
        }

        getStrings().then(strings => {
            const card = insertPanel(strings);
            if (!card) {
                return undefined;
            }

            const button = card.querySelector('#explain-grade-btn');
            const panel = card.querySelector('#grade-explanation-panel');
            let loaded = false;

            const toggleButtonState = expanded => {
                button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                button.textContent = expanded ? strings.hide_explanation : strings.explain_grade;
            };

            const showPanel = () => {
                panel.hidden = false;
                panel.scrollIntoView({behavior: 'smooth', block: 'start'});
            };

            const hidePanel = () => {
                panel.hidden = true;
            };

            button.addEventListener('click', () => {
                const expanded = button.getAttribute('aria-expanded') === 'true';
                if (expanded) {
                    hidePanel();
                    toggleButtonState(false);
                    return;
                }

                toggleButtonState(true);

                if (loaded) {
                    showPanel();
                    return;
                }

                panel.innerHTML = `<div class="ai-explain-loading">${strings.explanation_loading}</div>`;
                panel.hidden = false;

                // eslint-disable-next-line promise/no-nesting
                fetchExplanation(config.submissionid)
                    .then(data => {
                        loaded = true;
                        return renderExplanation(panel, data, strings);
                    })
                    .then(() => {
                        showPanel();
                        return true;
                    })
                    .catch(error => {
                        const message = error && error.errorcode === 'no_ai_grade_found'
                            ? strings.explanation_not_available
                            : strings.explanation_error;
                        panel.innerHTML = `<p class="text-danger mb-0">${message}</p>`;
                        if (!error || error.errorcode !== 'no_ai_grade_found') {
                            Notification.exception(error);
                        }
                    });
            });
            return true;
        }).catch(Notification.exception);
    };

    return {init};
});
