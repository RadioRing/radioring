import * as bootstrap from 'bootstrap';
import Sortable from 'sortablejs';
import WaveSurfer from 'wavesurfer.js';
import HoverPlugin from 'wavesurfer.js/dist/plugins/hover.esm.js';
import RegionsPlugin from 'wavesurfer.js/dist/plugins/regions.esm.js';
import TimelinePlugin from 'wavesurfer.js/dist/plugins/timeline.esm.js';

window.bootstrap = bootstrap;
window.Sortable = Sortable;
window.WaveSurfer = WaveSurfer;
window.WaveSurferHover = HoverPlugin;
window.WaveSurferRegions = RegionsPlugin;
window.WaveSurferTimeline = TimelinePlugin;
