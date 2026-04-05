<x-layouts::app :title="__('Dashboard')">
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
        <div class="grid auto-rows-min gap-4 md:grid-cols-3">
            <div
                class="relative aspect-video overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900"
            >
                <div
                    id="echarts-dashboard-demo"
                    class="size-full min-h-[200px]"
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
        function initDashboardEchartsDemo() {
            if (typeof window.echarts === 'undefined') {
                return;
            }

            const el = document.getElementById('echarts-dashboard-demo');
            if (!el) {
                return;
            }

            let chart = window.echarts.getInstanceByDom(el);
            if (chart) {
                chart.dispose();
            }

            chart = window.echarts.init(el);
            chart.setOption({
                title: {
                    text: '{{ __('ECharts (npm)') }}',
                    left: 'center',
                    textStyle: { fontSize: 14 },
                },
                tooltip: {},
                xAxis: {
                    type: 'category',
                    data: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
                },
                yAxis: { type: 'value' },
                series: [
                    {
                        name: '{{ __('Demo') }}',
                        type: 'bar',
                        data: [12, 19, 8, 15, 22],
                    },
                ],
            });
        }

        document.addEventListener('DOMContentLoaded', initDashboardEchartsDemo);
        document.addEventListener('livewire:navigated', initDashboardEchartsDemo);
    </script>
</x-layouts::app>
