/**
 * 12h time controls synced to formData.schoolClass.startTime / endTime as "HH:mm" (24h).
 * Expects an ancestor with [data-workspace-creation-form] and Alpine x-data containing formData + isSubmitting.
 */
export function makeSchoolClassTime(binding) {
    return {
        hour: '',
        minute: '',
        ampm: 'AM',
        rootScope: null,

        init() {
            const root = this.$el.closest('[data-workspace-creation-form]');
            this.rootScope = root ? window.Alpine.$data(root) : null;
            this.syncFromModel();
            this.$watch(
                () =>
                    binding === 'start'
                        ? this.rootScope?.formData?.schoolClass?.startTime
                        : this.rootScope?.formData?.schoolClass?.endTime,
                () => this.syncFromModel(),
            );
        },

        get rawModel() {
            if (!this.rootScope?.formData?.schoolClass) {
                return null;
            }

            return binding === 'start'
                ? this.rootScope.formData.schoolClass.startTime
                : this.rootScope.formData.schoolClass.endTime;
        },

        set rawModel(value) {
            if (!this.rootScope?.formData?.schoolClass) {
                return;
            }
            if (binding === 'start') {
                this.rootScope.formData.schoolClass.startTime = value;
            } else {
                this.rootScope.formData.schoolClass.endTime = value;
            }
        },

        get disabled() {
            return Boolean(this.rootScope?.isSubmitting);
        },

        syncFromModel() {
            const v = this.rawModel;
            if (!v || typeof v !== 'string') {
                this.hour = '';
                this.minute = '';
                this.ampm = 'AM';

                return;
            }

            const m = /^(\d{1,2}):(\d{2})(?::\d{2})?$/.exec(v.trim());
            if (!m) {
                return;
            }

            const hours24 = parseInt(m[1], 10);
            const minutes = parseInt(m[2], 10);
            let hours12 = hours24 % 12;
            let ampm = 'AM';

            if (hours24 === 0) {
                hours12 = 12;
                ampm = 'AM';
            } else if (hours24 === 12) {
                hours12 = 12;
                ampm = 'PM';
            } else if (hours24 > 12) {
                hours12 = hours24 - 12;
                ampm = 'PM';
            } else {
                ampm = 'AM';
            }

            this.hour = String(hours12).padStart(2, '0');
            this.minute = String(minutes).padStart(2, '0');
            this.ampm = ampm;
        },

        normalizeHour() {
            let h = parseInt(this.hour || '0', 10);
            if (isNaN(h) || h <= 0) {
                h = 12;
            }
            if (h > 12) {
                h = 12;
            }
            this.hour = String(h).padStart(2, '0');
        },

        normalizeMinute() {
            let min = parseInt(this.minute || '0', 10);
            if (isNaN(min) || min < 0) {
                min = 0;
            }
            if (min > 59) {
                min = 59;
            }
            this.minute = String(min).padStart(2, '0');
        },

        updateTime() {
            this.normalizeHour();
            this.normalizeMinute();

            let hours12 = parseInt(this.hour || '0', 10);
            if (isNaN(hours12) || hours12 <= 0) {
                hours12 = 12;
            }
            if (hours12 > 12) {
                hours12 = 12;
            }

            const minutes = parseInt(this.minute || '0', 10);
            const usePm = this.ampm === 'PM';

            let hours24 = hours12 % 12;
            if (usePm) {
                hours24 += 12;
            }

            this.rawModel = `${String(hours24).padStart(2, '0')}:${String(isNaN(minutes) ? 0 : minutes).padStart(2, '0')}`;
        },
    };
}
