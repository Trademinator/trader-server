import 'instant.page'
import Alpine from 'alpinejs'
import ajax from '@imacrayon/alpine-ajax'
import Popover from './components/popover'
import 'bootstrap/js/dist/collapse'
import Tooltip from 'bootstrap/js/dist/tooltip'

Alpine.plugin(ajax)
Alpine.data('popover', Popover)
Alpine.start()

const intelligenceTooltips = [...document.querySelectorAll('[data-intelligence-tooltip]')].map(button => {
    const tooltip = new Tooltip(button, {
        container: 'body',
        boundary: document.body,
        customClass: 'intelligence-tooltip',
        animation: false,
        trigger: 'hover focus',
        template: '<div class="tooltip" role="tooltip"><div class="tooltip-inner"></div></div>',
    })
    button.addEventListener('click', () => button.focus())
    return tooltip
})
if (intelligenceTooltips.length) {
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') intelligenceTooltips.forEach(tooltip => tooltip.hide())
    })
    document.addEventListener('pointerdown', event => {
        if (!event.target.closest('[data-intelligence-tooltip], .intelligence-tooltip')) {
            intelligenceTooltips.forEach(tooltip => tooltip.hide())
        }
    })
}

const reviewChart = document.querySelector('[data-review-chart]')
if (reviewChart) {
    import('./components/market-review-chart').then(({ mountReviewChart }) => mountReviewChart(reviewChart)).catch(() => {
        reviewChart.querySelector('[data-chart-status]').textContent = 'The chart could not load. The review and candle table remain available.'
    })
}

const dashboardChart = document.querySelector('[data-dashboard-chart]')
const dashboardSuggestions = document.querySelector('[data-dashboard-suggestions]')
if (dashboardChart || dashboardSuggestions) {
    import('./components/dashboard').then(({ mountDashboardChart, loadDashboardSuggestions }) => {
        if (dashboardChart) mountDashboardChart(dashboardChart)
        if (dashboardSuggestions) loadDashboardSuggestions(dashboardSuggestions)
    }).catch(() => {
        if (dashboardChart) dashboardChart.querySelector('[data-status]').textContent = 'The chart could not load. Candle values and the signal journal remain available.'
        if (dashboardSuggestions) dashboardSuggestions.querySelector('[role="status"]').textContent = 'Open pair suggestions to see your matches.'
    })
}
