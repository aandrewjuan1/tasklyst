const ANALYTICS_SECTIONS = ['summary', 'trends', 'breakdowns'];

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
        charts: {
            trend: null,
            focusSessions: null,
            status: null,
            priority: null,
            complexity: null,
            project: null,
        },
        resizeHandler: null,

        init() {
            this.activeAnalyticsSection = resolveSectionFromUrl();
            this.resizeHandler = () => this.resizeCharts();
            window.addEventListener('resize', this.resizeHandler);
            this.$nextTick(() => {
                this.ensureCharts();
                this.renderCharts();
                this.resizeCharts();
            });
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

            this.$nextTick(() => {
                this.ensureCharts();
                this.renderCharts();
                this.resizeCharts();
            });
        },

        sync(nextAnalytics, nextPreset) {
            this.analytics = nextAnalytics ?? null;
            this.preset = nextPreset ?? this.preset;
            this.$nextTick(() => {
                this.ensureCharts();
                this.renderCharts();
                this.resizeCharts();
            });
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
            this.charts.project = this.initChartRef(this.$refs.projectChart, this.charts.project);
        },

        initChartRef(element, existingChart) {
            const echarts = window.echarts;
            if (!echarts || !element) {
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
            if (!this.analytics) {
                this.setNoDataOptions();
                return;
            }

            this.applyTrendChartHeight();
            this.setTrendOption();
            this.setFocusSessionsOption();
            this.setStatusDonutOption();
            this.setPriorityBarOption();
            this.setComplexityBarOption();
            this.setProjectBarOption();
            this.resizeCharts();
        },

        setNoDataOptions() {
            const noData = this.noDataOption('No analytics data');
            Object.values(this.charts).forEach((chart) => {
                if (chart) {
                    chart.setOption(noData, true);
                }
            });
        },

        setTrendOption() {
            if (!this.charts.trend) {
                return;
            }

            const labels = this.analytics?.trends?.labels ?? [];
            const tasksCreated = this.analytics?.trends?.tasks_created ?? [];
            const tasksCompleted = this.analytics?.trends?.tasks_completed ?? [];
            const focusSeconds = this.analytics?.trends?.focus_work_seconds ?? [];

            this.charts.trend.setOption(
                {
                    tooltip: { trigger: 'axis' },
                    legend: {
                        data: ['Tasks Created', 'Tasks Completed', 'Focus (seconds)'],
                        bottom: 4,
                    },
                    grid: { left: 52, right: 52, top: 36, bottom: 56, containLabel: true },
                    xAxis: {
                        type: 'category',
                        data: labels,
                        axisLabel: { hideOverlap: true },
                    },
                    yAxis: [
                        { type: 'value', name: 'Tasks', nameLocation: 'middle', nameGap: 42 },
                        { type: 'value', name: 'Seconds', nameLocation: 'middle', nameGap: 42 },
                    ],
                    series: [
                        {
                            name: 'Tasks Created',
                            type: 'line',
                            smooth: true,
                            data: tasksCreated,
                            yAxisIndex: 0,
                        },
                        {
                            name: 'Tasks Completed',
                            type: 'line',
                            smooth: true,
                            data: tasksCompleted,
                            yAxisIndex: 0,
                        },
                        {
                            name: 'Focus (seconds)',
                            type: 'line',
                            smooth: true,
                            data: focusSeconds,
                            yAxisIndex: 1,
                        },
                    ],
                },
                true,
            );
        },

        applyTrendChartHeight() {
            if (!this.$refs.trendChart) {
                return;
            }

            const pointCount = this.analytics?.trends?.labels?.length ?? 0;
            const height = pointCount <= 7 ? 240 : pointCount <= 14 ? 270 : 300;

            this.$refs.trendChart.style.height = `${height}px`;
        },

        setFocusSessionsOption() {
            if (!this.charts.focusSessions) {
                return;
            }

            const labels = this.analytics?.trends?.labels ?? [];
            const focusSessions = this.analytics?.trends?.focus_sessions ?? [];

            this.charts.focusSessions.setOption(
                {
                    tooltip: { trigger: 'axis' },
                    grid: { left: 40, right: 20, top: 20, bottom: 30, containLabel: true },
                    xAxis: {
                        type: 'category',
                        data: labels,
                        axisLabel: { hideOverlap: true },
                    },
                    yAxis: { type: 'value' },
                    series: [
                        {
                            name: 'Focus sessions',
                            type: 'bar',
                            data: focusSessions,
                        },
                    ],
                },
                true,
            );
        },

        setStatusDonutOption() {
            if (!this.charts.status) {
                return;
            }

            const rows = this.analytics?.breakdowns?.status ?? [];
            const seriesData = rows.map((row) => ({ name: row.label, value: row.value }));

            this.charts.status.setOption(
                {
                    tooltip: { trigger: 'item' },
                    legend: { bottom: 0, left: 'center' },
                    series: [
                        {
                            name: 'Status',
                            type: 'pie',
                            radius: ['45%', '70%'],
                            avoidLabelOverlap: true,
                            data: seriesData,
                        },
                    ],
                },
                true,
            );
        },

        setPriorityBarOption() {
            this.setBarOption(this.charts.priority, this.analytics?.breakdowns?.priority ?? [], 'Priority');
        },

        setComplexityBarOption() {
            this.setBarOption(this.charts.complexity, this.analytics?.breakdowns?.complexity ?? [], 'Complexity');
        },

        setProjectBarOption() {
            const rows = this.analytics?.breakdowns?.project ?? [];
            this.setBarOption(this.charts.project, rows.slice(0, 10), 'Project');
        },

        setBarOption(chart, rows, seriesName) {
            if (!chart) {
                return;
            }

            const labels = rows.map((row) => row.label);
            const values = rows.map((row) => row.value);

            chart.setOption(
                {
                    tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
                    grid: { left: 20, right: 20, top: 20, bottom: 20, containLabel: true },
                    xAxis: { type: 'value' },
                    yAxis: { type: 'category', data: labels, inverse: true, axisLabel: { width: 140, overflow: 'truncate' } },
                    series: [{ name: seriesName, type: 'bar', data: values }],
                },
                true,
            );
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
                xAxis: { show: false },
                yAxis: { show: false },
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

            Object.keys(this.charts).forEach((key) => {
                if (this.charts[key]) {
                    this.charts[key].dispose();
                    this.charts[key] = null;
                }
            });
        },
    };
}
