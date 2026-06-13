<script type="text/ecmascript-6">
    export default {
        props: {
            data: {
                type: Array,
                default: () => [],
            },
            width: {
                type: Number,
                default: 90,
            },
            height: {
                type: Number,
                default: 24,
            },
        },

        computed: {
            /**
             * Build the polyline points for the sparkline.
             */
            points() {
                if (!this.data || this.data.length < 2) {
                    return '';
                }

                const max = Math.max(...this.data);
                const min = Math.min(...this.data);
                const range = (max - min) || 1;
                const step = this.width / (this.data.length - 1);

                return this.data.map((value, index) => {
                    const x = index * step;
                    const y = this.height - ((value - min) / range) * (this.height - 2) - 1;

                    return `${x.toFixed(1)},${y.toFixed(1)}`;
                }).join(' ');
            }
        }
    }
</script>

<template>
    <svg v-if="points" :width="width" :height="height" :viewBox="`0 0 ${width} ${height}`" class="sparkline" preserveAspectRatio="none">
        <polyline :points="points" fill="none" stroke="#7746ec" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" />
    </svg>
    <span v-else class="text-muted">—</span>
</template>
