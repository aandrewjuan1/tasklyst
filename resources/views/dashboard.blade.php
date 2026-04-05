<x-layouts::app :title="__('Dashboard')">
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
        <div class="grid auto-rows-min gap-4 md:grid-cols-3">
            <div
                class="relative flex flex-col gap-2 overflow-hidden rounded-xl border border-neutral-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900 md:col-span-2 md:aspect-auto md:min-h-[280px]"
            >
                <div class="flex flex-wrap items-baseline justify-between gap-2 px-1">
                    <div>
                        <p class="text-sm font-medium text-neutral-900 dark:text-neutral-100">
                            {{ __('Tasks completed (analytics)') }}
                        </p>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400">
                            {{ $analyticsPeriodLabel }} · {{ __('UserAnalyticsService') }}
                        </p>
                    </div>
                    <dl class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-neutral-600 dark:text-neutral-300">
                        <div>
                            <dt class="inline text-neutral-500 dark:text-neutral-400">{{ __('Done') }}:</dt>
                            <dd class="inline font-medium tabular-nums">{{ $analyticsTotals['tasksCompleted'] }}</dd>
                        </div>
                        <div>
                            <dt class="inline text-neutral-500 dark:text-neutral-400">{{ __('Created') }}:</dt>
                            <dd class="inline font-medium tabular-nums">{{ $analyticsTotals['tasksCreated'] }}</dd>
                        </div>
                        <div>
                            <dt class="inline text-neutral-500 dark:text-neutral-400">{{ __('Focus') }}:</dt>
                            <dd class="inline font-medium tabular-nums">
                                {{ $analyticsTotals['focusWorkMinutes'] }} {{ __('min') }}
                                <span class="text-neutral-500">({{ $analyticsTotals['focusSessions'] }} {{ __('sessions') }})</span>
                            </dd>
                        </div>
                    </dl>
                </div>
                <div
                    id="echarts-dashboard-analytics"
                    class="min-h-[220px] w-full flex-1"
                    wire:ignore
                ></div>
            </div>
            <div class="relative aspect-video overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20" />
            </div>
            <div class="relative aspect-video overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20" />
            </div>
        </div>
        <div class="relative h-full flex-1 overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20" />
        </div>
    </div>

    <script>
        window.__dashboardAnalyticsChart = @json($analyticsChart);

        function initDashboardAnalyticsChart() {
            if (typeof window.echarts === 'undefined') {
                return;
            }

            const el = document.getElementById('echarts-dashboard-analytics');
            if (!el) {
                return;
            }

            const payload = window.__dashboardAnalyticsChart;
            if (!payload || !Array.isArray(payload.labels) || !Array.isArray(payload.values)) {
                return;
            }

            let chart = window.echarts.getInstanceByDom(el);
            if (chart) {
                chart.dispose();
            }

            chart = window.echarts.init(el);
            chart.setOption({
                tooltip: {
                    trigger: 'axis',
                },
                grid: {
                    left: '3%',
                    right: '4%',
                    bottom: '3%',
                    containLabel: true,
                },
                xAxis: {
                    type: 'category',
                    data: payload.labels,
                    axisLabel: {
                        rotate: payload.labels.length > 14 ? 45 : 0,
                        fontSize: 10,
                    },
                },
                yAxis: {
                    type: 'value',
                    minInterval: 1,
                },
                series: [
                    {
                        name: '{{ __('Tasks') }}',
                        type: 'bar',
                        data: payload.values,
                    },
                ],
            });
        }

        document.addEventListener('DOMContentLoaded', initDashboardAnalyticsChart);
        document.addEventListener('livewire:navigated', initDashboardAnalyticsChart);
    </script>
</x-layouts::app>
