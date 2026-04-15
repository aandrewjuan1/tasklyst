const ANALYTICS_SECTIONS = ['summary', 'trends', 'breakdowns'];

/** @type {Promise<unknown>|null} */
let echartsLoadPromise = null;

/**
 * Load ECharts once (code-split chunk) and expose on window for this component.
 */
async function ensureEchartsLoaded() {
    if (typeof window === 'undefined') {
        return;
    }

    if (window.echarts) {
        return;
    }

    if (!echartsLoadPromise) {
        echartsLoadPromise = import('echarts').then((mod) => {
            const echarts = mod.default ?? mod;
            window.echarts = echarts;

            return echarts;
        });
    }

    await echartsLoadPromise;
}

/**
 * @returns {string}
 */
function resolveSectionFromUrl() {
    if (typeof window === 'undefined') {
        return 'summary';
    }

    const raw = new URLSearchParams(window.location.search).get('section');

    return ANALYTICS_SECTIONS.includes(raw) ? raw : 'summary';
}

/**
 * Alpine.js component for dashboard analytics ECharts rendering.
 *
 * @param {{ analytics: object|null, preset: string }} config
 * @returns {object}
 */
export function dashboardAnalyticsCharts(config = {}) {
    return {
        analytics: config.analytics ?? null,
        preset: config.preset ?? '30d',
        activeAnalyticsSection: resolveSectionFromUrl(),
        echartsReady: false,
        charts: {
            trend: null,
            focusSessions: null,
            status: null,
            priority: null,
            complexity: null,
        },
        resizeHandler: null,
        renderRetryTimer: null,

        init() {
            this.activeAnalyticsSection = resolveSectionFromUrl();
            this.resizeHandler = () => this.resizeCharts();
            window.addEventListener('resize', this.resizeHandler);
            void (async () => {
                await ensureEchartsLoaded();
                this.echartsReady = true;
                this.$nextTick(() => {
                    this.safeRenderCharts();
                });
            })();
        },

        setSection(section) {
            if (!ANALYTICS_SECTIONS.includes(section)) {
                return;
            }

            this.activeAnalyticsSection = section;

            if (typeof window !== 'undefined' && window.history?.replaceState) {
                const url = new URL(window.location.href);
                url.searchParams.set('section', section);
                window.history.replaceState({}, '', url);
            }

            void (async () => {
                await ensureEchartsLoaded();
                this.echartsReady = true;
                this.$nextTick(() => {
                    this.safeRenderCharts();
                });
            })();
        },

        sync(nextAnalytics, nextPreset) {
            this.analytics = nextAnalytics ?? null;
            this.preset = nextPreset ?? this.preset;
            void (async () => {
                await ensureEchartsLoaded();
                this.echartsReady = true;
                this.$nextTick(() => {
                    this.safeRenderCharts();
                });
            })();
        },

        safeRenderCharts() {
            if (this.renderRetryTimer) {
                clearTimeout(this.renderRetryTimer);
                this.renderRetryTimer = null;
            }

            this.ensureCharts();
            this.renderCharts();
            this.resizeCharts();

            if (!this.areChartRefsReady()) {
                this.renderRetryTimer = setTimeout(() => {
                    this.renderRetryTimer = null;
                    if (!this.$el?.isConnected) {
                        return;
                    }
                    this.safeRenderCharts();
                }, 120);
            }
        },

        areChartRefsReady() {
            const refs = [
                this.$refs.trendChart,
                this.$refs.focusSessionsChart,
                this.$refs.statusChart,
                this.$refs.priorityChart,
                this.$refs.complexityChart,
            ].filter(Boolean);

            if (refs.length === 0) {
                return true;
            }

            return refs.every((element) => {
                if (!element.isConnected) {
                    return false;
                }
                return element.clientWidth > 0 && element.clientHeight > 0;
            });
        },

        getChartRefByKey(chartKey) {
            const refMap = {
                trend: this.$refs.trendChart,
                focusSessions: this.$refs.focusSessionsChart,
                status: this.$refs.statusChart,
                priority: this.$refs.priorityChart,
                complexity: this.$refs.complexityChart,
            };

            return refMap[chartKey] ?? null;
        },

        isElementRenderable(element) {
            return !!(element && element.isConnected && element.clientWidth > 0 && element.clientHeight > 0);
        },

        applyChartOption(chartKey, option) {
            const chart = this.charts[chartKey];
            const element = this.getChartRefByKey(chartKey);

            if (!chart || !this.isElementRenderable(element)) {
                return;
            }

            const hasLineSeries = Array.isArray(option?.series) && option.series.some((series) => series?.type === 'line');
            const safeOption = { ...(option ?? {}) };

            // Keep Cartesian coordinate system explicit for line-series charts.
            if (hasLineSeries) {
                if (!safeOption.grid) {
                    safeOption.grid = { left: 52, right: 52, top: 36, bottom: 56, containLabel: true };
                }
                if (!safeOption.xAxis || (Array.isArray(safeOption.xAxis) && safeOption.xAxis.length === 0)) {
                    safeOption.xAxis = [{ type: 'category', data: [] }];
                } else if (!Array.isArray(safeOption.xAxis)) {
                    safeOption.xAxis = [safeOption.xAxis];
                }
                if (!safeOption.yAxis || (Array.isArray(safeOption.yAxis) && safeOption.yAxis.length === 0)) {
                    safeOption.yAxis = [{ type: 'value' }];
                } else if (!Array.isArray(safeOption.yAxis)) {
                    safeOption.yAxis = [safeOption.yAxis];
                }
            }

            try {
                if (chartKey === 'trend') {
                    chart.clear();
                }
                chart.setOption(safeOption, { notMerge: true, lazyUpdate: false, silent: true });
            } catch (_) {
                // If Livewire swapped DOM during render, recreate chart once and retry.
                try {
                    chart.dispose();
                } catch (_) {}

                this.charts[chartKey] = this.initChartRef(element, null);

                if (!this.charts[chartKey]) {
                    return;
                }

                try {
                    this.charts[chartKey].setOption(safeOption, { notMerge: true, lazyUpdate: false, silent: true });
                } catch (_) {
                    // Keep silent here; safeRenderCharts retry loop will attempt again.
                }
            }
        },

        ensureCharts() {
            const echarts = window.echarts;
            if (!echarts) {
                return;
            }

            this.charts.trend = this.initChartRef(this.$refs.trendChart, this.charts.trend);
            this.charts.focusSessions = this.initChartRef(this.$refs.focusSessionsChart, this.charts.focusSessions);
            this.charts.status = this.initChartRef(this.$refs.statusChart, this.charts.status);
            this.charts.priority = this.initChartRef(this.$refs.priorityChart, this.charts.priority);
            this.charts.complexity = this.initChartRef(this.$refs.complexityChart, this.charts.complexity);
        },

        initChartRef(element, existingChart) {
            const echarts = window.echarts;
            if (!echarts || !element) {
                return existingChart;
            }

            if (!element.isConnected || element.clientWidth <= 0 || element.clientHeight <= 0) {
                return existingChart;
            }

            if (existingChart) {
                const dom = existingChart.getDom?.();
                if (!dom || dom !== element || !element.isConnected) {
                    existingChart.dispose();
                } else {
                    return existingChart;
                }
            }

            const existing = echarts.getInstanceByDom(element);
            if (existing) {
                return existing;
            }

            return echarts.init(element);
        },

        renderCharts() {
            if (!this.areChartRefsReady()) {
                return;
            }

            if (!this.analytics) {
                this.setNoDataOptions();
                return;
            }

            this.applyTrendChartHeight();
            this.applyFocusChartHeight();
            this.setTrendOption();
            this.setFocusSessionsOption();
            this.setStatusPieOption();
            this.setPriorityPieOption();
            this.setComplexityPieOption();
            this.resizeCharts();
        },

        setNoDataOptions() {
            const noData = this.noDataOption('No analytics data');
            Object.keys(this.charts).forEach((chartKey) => {
                this.applyChartOption(chartKey, noData);
            });
        },

        setTrendOption() {
            if (!this.charts.trend) {
                return;
            }

            const labels = this.analytics?.trends?.labels ?? [];
            const tasksCreated = this.analytics?.trends?.tasks_created ?? [];
            const tasksCompleted = this.analytics?.trends?.tasks_completed ?? [];

            this.applyChartOption('trend', {
                tooltip: { trigger: 'axis' },
                legend: {
                    data: ['Tasks Created', 'Tasks Completed'],
                    bottom: 4,
                },
                grid: { left: 52, right: 24, top: 36, bottom: 56, containLabel: true },
                xAxis: {
                    type: 'category',
                    data: labels,
                    axisLabel: { hideOverlap: true },
                },
                yAxis: { type: 'value', name: 'Tasks', nameLocation: 'middle', nameGap: 42 },
                series: [
                    {
                        name: 'Tasks Created',
                        type: 'line',
                        smooth: true,
                        data: tasksCreated,
                    },
                    {
                        name: 'Tasks Completed',
                        type: 'line',
                        smooth: true,
                        data: tasksCompleted,
                    },
                ],
            });
        },

        applyTrendChartHeight() {
            if (!this.$refs.trendChart) {
                return;
            }

            const pointCount = this.analytics?.trends?.labels?.length ?? 0;
            const height = pointCount <= 7 ? 240 : pointCount <= 14 ? 270 : 300;

            this.$refs.trendChart.style.height = `${height}px`;
            this.$refs.trendChart.style.minHeight = `${height}px`;
        },

        applyFocusChartHeight() {
            if (!this.$refs.focusSessionsChart) {
                return;
            }

            const pointCount = this.analytics?.trends?.labels?.length ?? 0;
            const height = pointCount <= 7 ? 240 : pointCount <= 14 ? 270 : 300;

            this.$refs.focusSessionsChart.style.height = `${height}px`;
            this.$refs.focusSessionsChart.style.minHeight = `${height}px`;
        },

        formatFocusDurationSeconds(totalSeconds) {
            const s = Math.max(0, Math.floor(Number(totalSeconds) || 0));
            if (s >= 3600) {
                const h = s / 3600;
                const rounded = Math.round(h * 10) / 10;
                return `${rounded} h`;
            }
            if (s >= 60) {
                return `${Math.round(s / 60)} min`;
            }
            return `${s} s`;
        },

        setFocusSessionsOption() {
            if (!this.charts.focusSessions) {
                return;
            }

            const labels = this.analytics?.trends?.labels ?? [];
            const focusSeconds = this.analytics?.trends?.focus_work_seconds ?? [];
            const focusSessions = this.analytics?.trends?.focus_sessions ?? [];
            const formatDuration = this.formatFocusDurationSeconds.bind(this);

            this.applyChartOption('focusSessions', {
                tooltip: {
                    trigger: 'axis',
                    formatter(params) {
                        if (!Array.isArray(params) || params.length === 0) {
                            return '';
                        }
                        const axisValue = params[0]?.axisValue ?? '';
                        const lines = [axisValue];
                        for (const p of params) {
                            if (p.seriesName === 'Focus time') {
                                lines.push(`${p.marker}${p.seriesName}: ${formatDuration(p.value)}`);
                            } else if (p.seriesName === 'Sessions') {
                                lines.push(`${p.marker}${p.seriesName}: ${p.value}`);
                            }
                        }
                        return lines.join('<br/>');
                    },
                },
                legend: {
                    data: ['Focus time', 'Sessions'],
                    bottom: 4,
                },
                grid: { left: 52, right: 52, top: 36, bottom: 56, containLabel: true },
                xAxis: {
                    type: 'category',
                    data: labels,
                    axisLabel: { hideOverlap: true },
                },
                yAxis: [
                    {
                        type: 'value',
                        name: 'Seconds',
                        nameLocation: 'middle',
                        nameGap: 42,
                    },
                    {
                        type: 'value',
                        name: 'Sessions',
                        nameLocation: 'middle',
                        nameGap: 42,
                    },
                ],
                series: [
                    {
                        name: 'Focus time',
                        type: 'line',
                        smooth: true,
                        data: focusSeconds,
                        yAxisIndex: 0,
                    },
                    {
                        name: 'Sessions',
                        type: 'bar',
                        data: focusSessions,
                        yAxisIndex: 1,
                    },
                ],
            });
        },

        setStatusPieOption() {
            this.setPieOption(this.charts.status, this.analytics?.breakdowns?.status ?? [], 'Status');
        },

        setPriorityPieOption() {
            this.setPieOption(this.charts.priority, this.analytics?.breakdowns?.priority ?? [], 'Priority');
        },

        setComplexityPieOption() {
            this.setPieOption(this.charts.complexity, this.analytics?.breakdowns?.complexity ?? [], 'Complexity');
        },

        setPieOption(chart, rows, seriesName) {
            if (!chart) {
                return;
            }

            const chartKey = Object.keys(this.charts).find((key) => this.charts[key] === chart);
            if (!chartKey) {
                return;
            }

            const pieData = rows
                .filter((row) => Number(row.value) > 0)
                .map((row) => ({
                    name: row.label,
                    value: Number(row.value) || 0,
                }));

            if (pieData.length === 0) {
                this.applyChartOption(chartKey, this.emptyPieOption(seriesName));
                return;
            }

            this.applyChartOption(chartKey, {
                tooltip: {
                    trigger: 'item',
                    formatter: '{b}: {c} ({d}%)',
                },
                legend: {
                    type: 'scroll',
                    bottom: 0,
                    left: 'center',
                    textStyle: { fontSize: 11 },
                },
                series: [
                    {
                        name: seriesName,
                        type: 'pie',
                        radius: ['42%', '72%'],
                        center: ['50%', '44%'],
                        avoidLabelOverlap: true,
                        itemStyle: {
                            borderRadius: 6,
                            borderColor: '#fff',
                            borderWidth: 1,
                        },
                        label: {
                            show: false,
                        },
                        emphasis: {
                            label: {
                                show: true,
                                formatter: '{b}\n{c} ({d}%)',
                                fontSize: 11,
                                fontWeight: 600,
                            },
                        },
                        labelLine: {
                            show: false,
                        },
                        data: pieData,
                    },
                ],
            });
        },

        emptyPieOption(seriesName) {
            return {
                tooltip: {
                    show: false,
                },
                legend: {
                    show: false,
                },
                series: [
                    {
                        name: seriesName,
                        type: 'pie',
                        radius: ['42%', '72%'],
                        center: ['50%', '44%'],
                        silent: true,
                        label: {
                            show: false,
                        },
                        labelLine: {
                            show: false,
                        },
                        itemStyle: {
                            borderRadius: 6,
                            borderColor: '#fff',
                            borderWidth: 1,
                            color: '#e4e4e7',
                        },
                        data: [{ name: 'Empty', value: 1 }],
                    },
                ],
            };
        },

        noDataOption(text) {
            return {
                graphic: [
                    {
                        type: 'text',
                        left: 'center',
                        top: 'middle',
                        style: { text, fill: '#9ca3af', fontSize: 14 },
                    },
                ],
                series: [],
            };
        },

        resizeCharts() {
            Object.values(this.charts).forEach((chart) => {
                if (chart) {
                    chart.resize();
                }
            });
        },

        destroy() {
            if (this.resizeHandler) {
                window.removeEventListener('resize', this.resizeHandler);
            }

            if (this.renderRetryTimer) {
                clearTimeout(this.renderRetryTimer);
                this.renderRetryTimer = null;
            }

            Object.keys(this.charts).forEach((key) => {
                if (this.charts[key]) {
                    this.charts[key].dispose();
                    this.charts[key] = null;
                }
            });
        },
    };
}
