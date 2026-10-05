import Alpine from 'alpinejs';
import './spa-router';
import { DiveSafety } from './dive-safety';
import datePicker from './date-picker';

window.Alpine = Alpine;
Alpine.magic('safety', () => DiveSafety);
Alpine.data('datePicker', datePicker);
Alpine.start();
