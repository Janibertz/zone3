import { ref } from 'vue';
import axios from 'axios';

const updateReady   = ref(false);
const knownVersion  = ref(null);

async function checkVersion() {
    try {
        const { data } = await axios.get('/app-version');
        const v = data.version;
        if (!knownVersion.value) {
            knownVersion.value = v;
        } else if (knownVersion.value !== v) {
            updateReady.value = true;
        }
    } catch { /* network unavailable — silently skip */ }
}

let intervalId = null;

function startVersionPolling() {
    checkVersion();

    if (intervalId) return;
    intervalId = setInterval(checkVersion, 5 * 60 * 1000); // every 5 min

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) checkVersion();
    });
}

export function useVersionCheck() {
    function reload() {
        window.location.reload();
    }
    return { updateReady, startVersionPolling, reload };
}
