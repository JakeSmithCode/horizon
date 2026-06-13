<script type="text/ecmascript-6">
    import LineChart from '../../components/LineChart.vue';

    export default {
        components: {
            LineChart
        },


        /**
         * The component's data.
         */
        data() {
            return {
                ready: false,
                rawData: {},
                metric: {},
                hasPercentiles: false
            };
        },


        /**
         * Prepare the component.
         */
        mounted() {
            document.title = "Horizon - Metrics";

            this.loadMetric();
        },


        methods: {
            /**
             * Load the metric.
             */
            loadMetric() {
                this.ready = false;

                this.$http.get(Horizon.basePath + '/api/metrics/' + this.$route.params.type + '/' + encodeURIComponent(this.$route.params.slug))
                    .then(response => {
                        let data = this.prepareData(response.data);

                        this.rawData = response.data;

                        this.metric.throughPutChart = this.buildChartData(data, 'throughput', 'Times');

                        this.metric.runTimeChart = this.buildChartData(data, 'runtime', 'Seconds');

                        this.hasPercentiles = data.some(entry => entry.p95 !== undefined);

                        if (this.hasPercentiles) {
                            this.metric.percentileChart = this.buildPercentileChartData(data);
                        }

                        this.ready = true;
                    });
            },


            /**
             * Prepare the response data for charts.
             */
            prepareData(data) {
                return Object.values(this.groupBy(data.map(value => ({
                    ...value,
                    time: this.formatDate(value.time).format("MMM-D hh:mmA"),
                })), 'time')).map(value => value.reduce((sum, value) => ({
                    runtime: parseFloat(sum.runtime) + parseFloat(value.runtime),
                    throughput: parseInt(sum.throughput) + parseInt(value.throughput),
                    p95: value.p95 !== undefined ? Math.max(parseFloat(sum.p95 || 0), parseFloat(value.p95)) : undefined,
                    p99: value.p99 !== undefined ? Math.max(parseFloat(sum.p99 || 0), parseFloat(value.p99)) : undefined,
                    time: value.time
                })))
            },


            /**
             * Build the given chart data.
             */
            buildChartData(data, attribute, label) {
                return {
                    labels: data.map(entry => entry.time),
                    datasets: [
                        {
                            label: label,
                            data: data.map(entry => entry[attribute]),
                            lineTension: 0,
                            backgroundColor: 'transparent',
                            pointBackgroundColor: '#fff',
                            pointBorderColor: '#7746ec',
                            borderColor: '#7746ec',
                            borderWidth: 2,
                        },
                    ],
                };
            },


            /**
             * Build the runtime percentile chart data (p95 / p99 in milliseconds).
             */
            buildPercentileChartData(data) {
                return {
                    labels: data.map(entry => entry.time),
                    datasets: [
                        {
                            label: 'Milliseconds',
                            data: data.map(entry => entry.p99 ?? null),
                            lineTension: 0,
                            backgroundColor: 'transparent',
                            pointBackgroundColor: '#fff',
                            pointBorderColor: '#e3342f',
                            borderColor: '#e3342f',
                            borderWidth: 2,
                        },
                        {
                            label: 'p95',
                            data: data.map(entry => entry.p95 ?? null),
                            lineTension: 0,
                            backgroundColor: 'transparent',
                            pointBackgroundColor: '#fff',
                            pointBorderColor: '#f6993f',
                            borderColor: '#f6993f',
                            borderWidth: 2,
                        },
                    ],
                };
            },
        }
    }
</script>

<template>
    <div>
        <div class="card overflow-hidden">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0">Throughput - {{$route.params.slug}}</h2>
            </div>

            <div v-if="!ready" class="d-flex align-items-center justify-content-center card-bg-secondary p-5 bottom-radius">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin me-2 fill-text-color">
                    <path d="M12 10a2 2 0 0 1-3.41 1.41A2 2 0 0 1 10 8V0a9.97 9.97 0 0 1 10 10h-8zm7.9 1.41A10 10 0 1 1 8.59.1v2.03a8 8 0 1 0 9.29 9.29h2.02zm-4.07 0a6 6 0 1 1-7.25-7.25v2.1a3.99 3.99 0 0 0-1.4 6.57 4 4 0 0 0 6.56-1.42h2.1z"></path>
                </svg>

                <span>Loading...</span>
            </div>

            <div class="card-body card-bg-secondary" v-if="ready">
                <p class="text-center m-0 p-5" v-if="ready && !rawData.length">
                    Not Enough Data
                </p>

                <line-chart v-if="ready && rawData.length" :data="metric.throughPutChart"/>
            </div>
        </div>

        <div class="card overflow-hidden mt-4">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0">Runtime - {{$route.params.slug}}</h2>
            </div>

            <div v-if="!ready" class="d-flex align-items-center justify-content-center card-bg-secondary p-5 bottom-radius">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin me-2 fill-text-color">
                    <path d="M12 10a2 2 0 0 1-3.41 1.41A2 2 0 0 1 10 8V0a9.97 9.97 0 0 1 10 10h-8zm7.9 1.41A10 10 0 1 1 8.59.1v2.03a8 8 0 1 0 9.29 9.29h2.02zm-4.07 0a6 6 0 1 1-7.25-7.25v2.1a3.99 3.99 0 0 0-1.4 6.57 4 4 0 0 0 6.56-1.42h2.1z"></path>
                </svg>

                <span>Loading...</span>
            </div>

            <div class="card-body card-bg-secondary" v-if="ready">
                <p class="text-center m-0 p-5" v-if="ready && !rawData.length">
                    Not Enough Data
                </p>

                <line-chart v-if="ready && rawData.length" :data="metric.runTimeChart"/>
            </div>
        </div>

        <div class="card overflow-hidden mt-4" v-if="ready && hasPercentiles && rawData.length">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0">Runtime Percentiles (p95 / p99, ms) - {{$route.params.slug}}</h2>
            </div>

            <div class="card-body card-bg-secondary">
                <line-chart :data="metric.percentileChart"/>
            </div>
        </div>
    </div>
</template>
