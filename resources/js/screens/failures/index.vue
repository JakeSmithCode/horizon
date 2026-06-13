<script type="text/ecmascript-6">
    export default {
        /**
         * The component's data.
         */
        data() {
            return {
                ready: false,
                scanned: 0,
                total: 0,
                groups: [],
                retrying: {}
            };
        },


        /**
         * Prepare the component.
         */
        mounted() {
            document.title = "Horizon - Failures";

            this.loadGroups();
        },


        methods: {
            /**
             * Load the grouped failures.
             */
            loadGroups(refreshing = false) {
                if (!refreshing) {
                    this.ready = false;
                }

                return this.$http.get(Horizon.basePath + '/api/failures/groups')
                    .then(response => {
                        this.scanned = response.data.scanned;
                        this.total = response.data.total;
                        this.groups = response.data.groups;
                        this.ready = true;
                    });
            },


            /**
             * Poll handler to refresh the failures at regular intervals.
             */
            refreshGroupsPeriodically() {
                this.loadGroups(true);
            },


            /**
             * Retry every failed job in the given group.
             */
            retryAll(group) {
                if (this.isRetrying(group)) {
                    return;
                }

                this.retrying = {...this.retrying, [group.signature]: true};

                let chain = Promise.resolve();

                group.ids.forEach(id => {
                    chain = chain.then(() => this.$http.post(Horizon.basePath + '/api/jobs/retry/' + id).catch(() => {}));
                });

                chain.then(() => {
                    this.retrying = {...this.retrying, [group.signature]: false};

                    this.loadGroups(true);
                });
            },


            /**
             * Determine if the given group is currently being retried.
             */
            isRetrying(group) {
                return !! this.retrying[group.signature];
            }
        }
    }
</script>

<template>
    <div>
        <poll @poll="refreshGroupsPeriodically" :interval="30" />

        <div class="card overflow-hidden">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0">Failures by Exception</h2>

                <small class="text-muted" v-if="ready">
                    {{ groups.length }} group{{ groups.length === 1 ? '' : 's' }} across {{ scanned.toLocaleString() }} of {{ total.toLocaleString() }} failed jobs
                </small>
            </div>

            <div v-if="!ready" class="d-flex align-items-center justify-content-center card-bg-secondary p-5 bottom-radius">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin me-2 fill-text-color">
                    <path d="M12 10a2 2 0 0 1-3.41 1.41A2 2 0 0 1 10 8V0a9.97 9.97 0 0 1 10 10h-8zm7.9 1.41A10 10 0 1 1 8.59.1v2.03a8 8 0 1 0 9.29 9.29h2.02zm-4.07 0a6 6 0 1 1-7.25-7.25v2.1a3.99 3.99 0 0 0-1.4 6.57 4 4 0 0 0 6.56-1.42h2.1z"></path>
                </svg>

                <span>Loading...</span>
            </div>

            <div v-if="ready && groups.length == 0" class="d-flex flex-column align-items-center justify-content-center card-bg-secondary p-5 bottom-radius">
                <span>There aren't any failed jobs.</span>
            </div>

            <table v-if="ready && groups.length > 0" class="table table-hover mb-0">
                <thead>
                <tr>
                    <th>Exception</th>
                    <th class="text-end" style="width: 90px;">Count</th>
                    <th style="width: 200px;">Last Failed</th>
                    <th class="text-end" style="width: 130px;">Retry</th>
                </tr>
                </thead>

                <tbody>
                <tr v-for="group in groups" :key="group.signature">
                    <td>
                        <router-link :title="group.message" :to="{ name: 'failed-jobs-preview', params: { jobId: group.sample_id }}">
                            {{ group.exception }}
                        </router-link>

                        <br>

                        <small class="text-muted text-break">{{ group.message || 'No message' }}</small>

                        <br>

                        <small class="text-muted">
                            Jobs: {{ group.jobs.map(jobBaseName).join(', ') }}
                            | Queues: {{ group.queues.join(', ') }}
                        </small>
                    </td>

                    <td class="text-end table-fit">
                        <span class="badge badge-danger">{{ group.count.toLocaleString() }}</span>
                    </td>

                    <td class="table-fit text-muted">
                        {{ readableTimestamp(group.last_seen) }}
                    </td>

                    <td class="text-end table-fit">
                        <button class="btn btn-secondary btn-sm"
                                :disabled="isRetrying(group)"
                                :title="'Retry ' + group.ids.length + ' job(s) in this group'"
                                @click="retryAll(group)">
                            <span v-if="!isRetrying(group)">Retry All</span>
                            <span v-else>Retrying...</span>
                        </button>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
