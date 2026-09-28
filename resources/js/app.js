import 'instant.page'
import Alpine from 'alpinejs'
import ajax from '@imacrayon/alpine-ajax'
import Popover from './components/popover'
import 'bootstrap/js/dist/collapse'

Alpine.plugin(ajax)
Alpine.data('popover', Popover)
Alpine.start()

const reviewChart = document.querySelector('[data-review-chart]')
if (reviewChart) {
    import('./components/market-review-chart').then(({ mountReviewChart }) => mountReviewChart(reviewChart)).catch(() => {
        reviewChart.querySelector('[data-chart-status]').textContent = 'The chart could not load. The review and candle table remain available.'
    })
}
