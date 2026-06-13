<script type="text/ecmascript-6">
    export default {
        components: {},


        /**
         * The component's data.
         */
        data() {
            return {
                ready: false,
                healthy: true,
                checks: [],
                anomalies: []
            };
        },


        /**
         * Prepare the component.
         */
        mounted() {
            this.loadHealth();
            this.loadAnomalies();
        },


        methods: {
            /**
             * Load the health check results.
             */
            loadHealth() {
                return this.$http.get(Horizon.basePath + '/api/health')
                    .then(response => {
                        this.healthy = response.data.healthy;
                        this.checks = response.data.checks;
                        this.ready = true;
                    });
            },


            /**
             * Load the detected anomalies.
             */
            loadAnomalies() {
                return this.$http.get(Horizon.basePath + '/api/anomalies')
                    .then(response => {
                        this.anomalies = response.data.anomalies;
                    })
                    .catch(() => {
                        // Anomaly detection is supplementary; ignore failures.
                    });
            },


            /**
             * Poll handler to refresh the health checks at regular intervals.
             */
            refreshHealthPeriodically() {
                this.loadHealth();
                this.loadAnomalies();
            },


            /**
             * Get the badge class for the given check status.
             */
            badgeClass(status) {
                return {
                    ok: 'badge-success',
                    warning: 'badge-warning',
                    critical: 'badge-danger',
                }[status] || 'badge-secondary';
            },


            /**
             * Get the human readable label for the given status.
             */
            statusLabel(status) {
                return {
                    ok: 'Healthy',
                    warning: 'Warning',
                    critical: 'Critical',
                }[status] || status;
            }
        }
    }
</script>

<template>
    <div>
        <div v-if="!ready" class="d-flex align-items-center justify-content-center card-bg-secondary p-5 bottom-radius">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin me-2 fill-text-color">
                <path d="M12 10a2 2 0 0 1-3.41 1.41A2 2 0 0 1 10 8V0a9.97 9.97 0 0 1 10 10h-8zm7.9 1.41A10 10 0 1 1 8.59.1v2.03a8 8 0 1 0 9.29 9.29h2.02zm-4.07 0a6 6 0 1 1-7.25-7.25v2.1a3.99 3.99 0 0 0-1.4 6.57 4 4 0 0 0 6.56-1.42h2.1z"></path>
            </svg>

            <span>Loading...</span>
        </div>

        <div v-if="ready">
            <div class="card mb-4">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h5>Health</h5>
                    <span class="badge" :class="healthy ? 'badge-success' : 'badge-danger'">
                        {{ healthy ? 'All Systems Healthy' : 'Attention Required' }}
                    </span>
                </div>

                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>Check</th>
                        <th>Status</th>
                        <th>Details</th>
                    </tr>
                    </thead>

                    <tbody>
                    <tr v-for="check in checks" :key="check.name">
                        <td class="fw-bold">{{ check.name }}</td>
                        <td>
                            <span class="badge" :class="badgeClass(check.status)">
                                {{ statusLabel(check.status) }}
                            </span>
                        </td>
                        <td class="text-muted">{{ check.message }}</td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <div class="card mb-4" v-if="anomalies.length">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h5>Anomalies</h5>
                    <span class="badge badge-warning">{{ anomalies.length }} detected</span>
                </div>

                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>Severity</th>
                        <th>Anomaly</th>
                        <th>Details</th>
                    </tr>
                    </thead>

                    <tbody>
                    <tr v-for="(anomaly, index) in anomalies" :key="index">
                        <td class="table-fit">
                            <span class="badge" :class="badgeClass(anomaly.severity)">
                                {{ statusLabel(anomaly.severity) }}
                            </span>
                        </td>
                        <td class="fw-bold">{{ anomaly.title }}</td>
                        <td class="text-muted">{{ anomaly.detail }}</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <poll @poll="refreshHealthPeriodically" :interval="10" />
    </div>
</template>
